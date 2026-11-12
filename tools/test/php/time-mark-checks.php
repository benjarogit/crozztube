<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Video;
use App\Models\VideoTimeMark;
use App\Models\VideoTimeMarkVote;
use App\Support\TimeMarks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$check(Schema::hasTable('video_time_marks') && Schema::hasTable('video_time_mark_votes'), 'Time mark tables must exist.');
$video = Video::query()->whereNotNull('slug')->first();
$check($video !== null, 'Time mark checks require one video.');
$now = CarbonImmutable::parse('2026-11-12 12:00:00');

DB::beginTransaction();
try {
    $owner = User::query()->create(['name' => 'Mark owner', 'username' => 'mark-owner-'.bin2hex(random_bytes(4)), 'email' => bin2hex(random_bytes(4)).'@example.invalid', 'password' => 'secret']);
    $voter = User::query()->create(['name' => 'Mark voter', 'username' => 'mark-voter-'.bin2hex(random_bytes(4)), 'email' => bin2hex(random_bytes(4)).'@example.invalid', 'password' => 'secret']);
    $private = TimeMarks::create($owner, $video, 12, 'private note', 'private');
    $public = TimeMarks::create($owner, $video, 40, 'public mark', 'public');
    $share = json_encode(TimeMarks::share($video, $now), JSON_THROW_ON_ERROR);
    $check(! str_contains($share, 'private note'), 'A share payload must not reveal private notes.');
    $check(str_contains($share, 'public mark'), 'A share payload must include the public mark.');
    $guest = TimeMarks::visible($video, null, $now)->pluck('body');
    $check(! $guest->contains('private note') && $guest->contains('public mark'), 'Guests see published marks only.');
    $owned = TimeMarks::visible($video, $owner, $now)->pluck('body');
    $check($owned->contains('private note') && $owned->contains('public mark'), 'The author keeps both private notes and public marks.');

    $rejected = false;
    try {
        TimeMarks::vote($owner, $public);
    } catch (ValidationException) {
        $rejected = true;
    }
    $check($rejected, 'Authors cannot vote on their own mark.');
    $first = TimeMarks::vote($voter, $public);
    $second = TimeMarks::vote($voter, $public);
    $check((int) $first->id === (int) $second->id, 'A viewer has one changeable vote.');
    $check(VideoTimeMarkVote::query()->where('video_time_mark_id', $public->id)->count() === 1, 'Repeat votes must not create a second row.');
    $first->forceFill(['created_at' => $now->subDays(10), 'updated_at' => $now->subDays(10)])->save();

    $stale = VideoTimeMark::query()->create([
        'video_id' => $video->id,
        'user_id' => $owner->id,
        'start_seconds' => 90,
        'body' => 'stale public mark',
        'visibility' => 'public',
    ]);
    $stale->forceFill(['created_at' => $now->subDays(40), 'updated_at' => $now->subDays(40)])->save();
    $week = TimeMarks::ranked($video, 'week', $now)->firstWhere('id', $public->id);
    $season = TimeMarks::ranked($video, 'season', $now)->firstWhere('id', $public->id);
    $check(($week['votes'] ?? -1) === 0, 'Votes older than 7 days stay out of the weekly rank.');
    $check(($season['votes'] ?? -1) === 1, 'Votes inside 180 days remain in the season rank.');
    $overlayIds = collect(TimeMarks::share($video, $now)['time_marks'])->pluck('id');
    $check(! $overlayIds->contains($stale->id), 'Public marks older than 30 days without a recent vote leave the overlay.');
} finally {
    DB::rollBack();
}

echo "Time mark checks passed: {$checks}\n";
