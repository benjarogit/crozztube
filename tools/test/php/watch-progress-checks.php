<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\V1\HistoryController;
use App\Http\Controllers\Api\VideoController;
use App\Models\User;
use App\Models\Video;
use App\Services\Api\V1\WatchProgressService;
use App\Support\WatchProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$check(Schema::hasTable('video_watch_progress'), 'Watch progress table must exist.');
$check(Schema::hasColumn('video_watch_progress', 'hidden_from_continue_at'), 'hidden_from_continue_at must exist.');

$source = Video::query()->whereNotNull('slug')->first();
$check($source !== null, 'Watch progress checks require one video.');

DB::beginTransaction();
try {
    $user = User::query()->create([
        'name' => 'Progress viewer',
        'username' => 'progress-'.bin2hex(random_bytes(4)),
        'email' => bin2hex(random_bytes(4)).'@example.invalid',
        'password' => 'secret',
    ]);
    $suffix = bin2hex(random_bytes(4));
    $video = $source->replicate();
    $video->forceFill([
        'slug' => 'progress-check-'.$suffix,
        'duration_seconds' => 200,
        'visibility' => 'public',
        'status' => 'ready',
        'approval_status' => 'approved',
        'sale_enabled' => false,
        'import_url' => 'https://example.invalid/progress-'.$suffix,
        'video_path' => 'external:test:progress'.$suffix,
    ]);
    $video->save();

    $recorded = WatchProgress::record($user, $video, [
        'position_seconds' => 40,
        'duration_seconds' => 200,
        'state' => 'playing',
        'event_id' => (string) Str::uuid(),
    ]);
    $check((int) $recorded['progress_percent'] === 20 && ! $recorded['hidden_from_continue'], 'Playing progress is stored for continue watching.');

    $continue = WatchProgress::applyContinueScope(
        DB::table('video_watch_progress')->where('user_id', $user->id)->where('video_id', $video->id)
    )->exists();
    $check($continue, 'In-progress videos appear in continue watching.');

    $historyBefore = DB::table('video_watch_progress')->where('user_id', $user->id)->where('video_id', $video->id)->first();
    $dismissed = WatchProgress::dismiss($user, $video);
    $check($dismissed['hidden_from_continue'] === true, 'Dismiss hides the row from continue watching.');
    $historyAfter = DB::table('video_watch_progress')->where('user_id', $user->id)->where('video_id', $video->id)->first();
    $check($historyAfter !== null && (int) $historyAfter->progress === (int) $historyBefore->progress, 'Dismiss keeps the history row and progress.');
    $check(! WatchProgress::applyContinueScope(
        DB::table('video_watch_progress')->where('user_id', $user->id)->where('video_id', $video->id)
    )->exists(), 'Dismissed rows leave the continue list.');

    $stale = WatchProgress::record($user, $video, [
        'position_seconds' => 55,
        'duration_seconds' => 200,
        'state' => 'playing',
        'event_id' => (string) Str::uuid(),
    ]);
    $check($stale['hidden_from_continue'] === true, 'Stale playing reports do not undo dismiss.');
    $check(! WatchProgress::applyContinueScope(
        DB::table('video_watch_progress')->where('user_id', $user->id)->where('video_id', $video->id)
    )->exists(), 'Continue watching stays closed after a stale playing report.');

    $eventId = (string) Str::uuid();
    $first = WatchProgress::record($user, $video, [
        'position_seconds' => 60,
        'duration_seconds' => 200,
        'state' => 'playing',
        'event_id' => $eventId,
    ]);
    $replay = WatchProgress::record($user, $video, [
        'position_seconds' => 90,
        'duration_seconds' => 200,
        'state' => 'playing',
        'event_id' => $eventId,
    ]);
    $check($first['persisted'] === true && $replay['idempotent_replay'] === true, 'Duplicate event IDs stay idempotent.');
    $check((int) $replay['position_seconds'] === (int) $first['position_seconds'], 'Idempotent replays do not advance position.');

    $watched = WatchProgress::markWatched($user, $video);
    $check($watched['completed'] === true && $watched['hidden_from_continue'] === false, 'Mark as watched completes the row.');
    $check((int) DB::table('video_watch_progress')->where('user_id', $user->id)->where('video_id', $video->id)->value('progress') === 100, 'Watched rows remain in history at 100%.');
    $check(! WatchProgress::applyContinueScope(
        DB::table('video_watch_progress')->where('user_id', $user->id)->where('video_id', $video->id)
    )->exists(), 'Completed videos leave continue watching.');

    $restarted = WatchProgress::restart($user, $video);
    $check((int) $restarted['progress_percent'] === 0 && $restarted['hidden_from_continue'] === false, 'Restart clears progress and the hide flag.');

    WatchProgress::record($user, $video, [
        'position_seconds' => 30,
        'duration_seconds' => 200,
        'state' => 'playing',
        'event_id' => (string) Str::uuid(),
    ]);
    WatchProgress::dismiss($user, $video);
    $started = WatchProgress::record($user, $video, [
        'position_seconds' => 35,
        'duration_seconds' => 200,
        'state' => 'started',
        'event_id' => (string) Str::uuid(),
    ]);
    $check($started['hidden_from_continue'] === false, 'An intentional start clears dismiss.');
    $check(WatchProgress::applyContinueScope(
        DB::table('video_watch_progress')->where('user_id', $user->id)->where('video_id', $video->id)
    )->exists(), 'Started playback can return the video to continue watching.');

    // Concurrent-style lock: dismiss under one lock, then a playing write under another
    // must keep the hide flag once the first transaction commits.
    $lockedUser = $user;
    DB::transaction(function () use ($lockedUser, $video): void {
        WatchProgress::dismiss($lockedUser, $video);
    });
    $afterRace = WatchProgress::record($lockedUser, $video, [
        'position_seconds' => 48,
        'duration_seconds' => 200,
        'state' => 'playing',
        'event_id' => (string) Str::uuid(),
    ]);
    $check($afterRace['hidden_from_continue'] === true, 'Locked dismiss wins against a later playing report.');

    Auth::guard('sanctum')->setUser($user);
    $web = app(VideoController::class);
    $webProgress = $web->updateWatchProgress(Request::create('/api/videos/'.$video->slug.'/progress', 'POST', [
        'current_time' => 70,
        'duration_seconds' => 200,
        'state' => 'playing',
        'event_id' => (string) Str::uuid(),
    ])->setUserResolver(fn () => $user), $video);
    $webBody = json_decode($webProgress->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $check(($webBody['hidden_from_continue'] ?? false) === true, 'Web progress updates keep a dismiss hide.');

    $v1 = app(WatchProgressService::class);
    $apiBody = $v1->update($user, $video, [
        'position_seconds' => 75,
        'duration_seconds' => 200,
        'state' => 'playing',
        'event_id' => (string) Str::uuid(),
    ]);
    $check(($apiBody['hidden_from_continue'] ?? false) === true, 'V1 progress updates keep the same dismiss hide.');

    $history = app(HistoryController::class);
    $dismissedViaApi = json_decode(
        $history->dismissContinue(Request::create('/api/v1/history/continue-watching/'.$video->slug, 'DELETE')->setUserResolver(fn () => $user), $video, $v1)->getContent(),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $check(($dismissedViaApi['hidden_from_continue'] ?? false) === true, 'V1 dismiss endpoint hides continue watching.');

    $watchedViaWeb = json_decode(
        $web->markWatchProgressWatched(Request::create('/api/videos/'.$video->slug.'/progress/watched', 'POST')->setUserResolver(fn () => $user), $video)->getContent(),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $check(($watchedViaWeb['completed'] ?? false) === true, 'Web mark-watched completes the shared row.');

    $restartViaApi = json_decode(
        $history->restart(Request::create('/api/v1/videos/'.$video->slug.'/progress/restart', 'POST')->setUserResolver(fn () => $user), $video, $v1)->getContent(),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $check((int) ($restartViaApi['progress_percent'] ?? -1) === 0, 'V1 restart clears progress through the shared rules.');
} finally {
    DB::rollBack();
}

echo "Watch progress checks passed: {$checks}\n";
