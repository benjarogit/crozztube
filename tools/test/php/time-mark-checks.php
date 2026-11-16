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

    $ownVoteMark = TimeMarks::create($owner, $video, 52, 'owner voted mark', 'public');
    $ownerVote = TimeMarks::vote($owner, $ownVoteMark, 1);
    $check($ownerVote !== null && $ownerVote->value === 1, 'Authors can vote on their own mark.');
    $rejected = false;
    try {
        TimeMarks::vote($owner, $ownVoteMark, -1);
    } catch (ValidationException) {
        $rejected = true;
    }
    $check($rejected, 'An author vote on the own mark is final.');
    $rejected = false;
    try {
        TimeMarks::vote($owner, $ownVoteMark, 0);
    } catch (ValidationException) {
        $rejected = true;
    }
    $check($rejected, 'An author vote on the own mark cannot be withdrawn.');
    $check(VideoTimeMarkVote::query()->where('video_time_mark_id', $ownVoteMark->id)->where('user_id', $owner->id)->value('value') === 1, 'The author vote keeps its original direction.');
    $ownerPayload = TimeMarks::visible($video, $owner, $now)->firstWhere('id', $ownVoteMark->id);
    $check($ownerPayload['owned'] && $ownerPayload['voted'] && $ownerPayload['viewer_vote'] === 1 && $ownerPayload['likes_count'] === 1 && $ownerPayload['score'] === 1, 'An author vote counts like any other vote.');
    $first = TimeMarks::vote($voter, $public);
    $second = TimeMarks::vote($voter, $public);
    $check((int) $first->id === (int) $second->id, 'A viewer has one changeable vote.');
    $check(VideoTimeMarkVote::query()->where('video_time_mark_id', $public->id)->count() === 1, 'Repeat votes must not create a second row.');
    $down = TimeMarks::vote($voter, $public, -1);
    $check($down->id === $first->id, 'Changing direction updates the existing vote.');
    $payload = TimeMarks::visible($video, $voter, $now)->firstWhere('id', $public->id);
    $check($payload['viewer_vote'] === -1 && $payload['dislikes_count'] === 1 && $payload['likes_count'] === 0 && $payload['score'] === -1, 'Dislikes and the current viewer vote are explicit.');
    $check(TimeMarks::vote($voter, $public, 0) === null, 'A vote can be removed.');
    $check(VideoTimeMarkVote::where('video_time_mark_id', $public->id)->count() === 0, 'Removing the vote removes its row.');
    foreach ([$private, $public] as $candidate) {
        $rejected = false;
        try { TimeMarks::vote($voter, $candidate, $candidate->isPublic() ? 5 : 1); } catch (ValidationException) { $rejected = true; }
        $check($rejected, 'Private notes and invalid vote directions are rejected.');
    }
    $first = TimeMarks::vote($voter, $public, 1);
    $payload = TimeMarks::visible($video, $owner, $now)->firstWhere('id', $public->id);
    $check($payload['owned'] && $payload['viewer_vote'] === 0 && $payload['likes_count'] === 1, 'The owner sees other viewers counts on an unvoted own mark.');
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
    $seasonJson = json_encode(TimeMarks::ranked($video, 'season', $now), JSON_THROW_ON_ERROR);
    $check(str_contains($seasonJson, 'public mark') && ! str_contains($seasonJson, 'private note'), 'Ranking keeps public mark text and hides private notes.');
    $check(! collect(TimeMarks::ranked($video, 'week', $now))->pluck('id')->contains($stale->id), 'Stale public marks stay out of the weekly rank.');
    $privateVideo = clone $video;
    $privateVideo->visibility = 'private';
    $request = Illuminate\Http\Request::create('/');
    $request->setUserResolver(fn () => null);
    $blocked = false;
    try { app(App\Http\Controllers\Api\TimeMarkController::class)->index($request, $privateVideo); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { $blocked = $error->getStatusCode() === 404; }
    $check($blocked, 'Time mark endpoints honor private video access.');
} finally {
    DB::rollBack();
}

echo "Time mark checks passed: {$checks}\n";
