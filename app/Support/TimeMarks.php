<?php

namespace App\Support;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoTimeMark;
use App\Models\VideoTimeMarkVote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class TimeMarks
{
    public const WEEK_DAYS = 7;

    public const SEASON_DAYS = 180;

    public const OVERLAY_DAYS = 30;

    public static function create(User $user, Video $video, int $startSeconds, ?string $body, string $visibility): VideoTimeMark
    {
        self::assertRange($video, $startSeconds);
        if (! in_array($visibility, ['private', 'public'], true)) {
            throw ValidationException::withMessages(['visibility' => 'Choose private or public.']);
        }

        return VideoTimeMark::query()->create([
            'video_id' => $video->id,
            'user_id' => $user->id,
            'start_seconds' => $startSeconds,
            'body' => self::body($body),
            'visibility' => $visibility,
        ]);
    }

    public static function vote(User $user, VideoTimeMark $mark, int $value = 1): ?VideoTimeMarkVote
    {
        $isOwner = (int) $mark->user_id === (int) $user->id;
        $existing = VideoTimeMarkVote::query()
            ->where('video_time_mark_id', $mark->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $mark->isPublic()) {
            throw ValidationException::withMessages(['vote' => 'Private notes cannot be voted on.']);
        }
        if ($isOwner && $existing) {
            throw ValidationException::withMessages(['vote' => 'Your vote on your own time mark is final.']);
        }

        if (! in_array($value, [-1, 0, 1], true)) {
            throw ValidationException::withMessages(['value' => 'Choose a valid vote.']);
        }
        if ($value === 0) {
            $existing?->delete();

            return null;
        }

        return VideoTimeMarkVote::query()->updateOrCreate(
            ['video_time_mark_id' => $mark->id, 'user_id' => $user->id],
            ['value' => $value]
        );
    }

    public static function visible(Video $video, ?User $viewer, CarbonImmutable $now): Collection
    {
        return VideoTimeMark::query()
            ->withCount('votes')
            ->withCount(['votes as likes_count' => fn ($query) => $query->where('value', 1)])
            ->withCount(['votes as dislikes_count' => fn ($query) => $query->where('value', -1)])
            ->withSum('votes as score', 'value')
            ->when($viewer, fn ($query) => $query->with(['votes' => fn ($vote) => $vote->where('user_id', $viewer->id)]))
            ->when($viewer, fn ($query) => $query->withExists([
                'votes as voted' => fn ($vote) => $vote->where('user_id', $viewer->id),
            ]))
            ->where('video_id', $video->id)
            ->where(function ($query) use ($viewer): void {
                $query->where('visibility', 'public');
                if ($viewer) {
                    $query->orWhere(fn ($own) => $own->where('visibility', 'private')->where('user_id', $viewer->id));
                }
            })
            ->orderBy('start_seconds')
            ->get()
            ->map(fn (VideoTimeMark $mark): array => self::payload($mark, $viewer))
            ->values();
    }

    public static function share(Video $video, CarbonImmutable $now): array
    {
        return [
            'video_slug' => $video->slug,
            'time_marks' => self::overlay($video, $now)
                ->map(fn (VideoTimeMark $mark): array => [
                    'id' => $mark->id,
                    'start_seconds' => $mark->start_seconds,
                    'body' => $mark->body,
                ])->values()->all(),
        ];
    }

    public static function ranked(Video $video, string $window, CarbonImmutable $now): Collection
    {
        $days = match ($window) {
            'week' => self::WEEK_DAYS,
            'season' => self::SEASON_DAYS,
            default => throw ValidationException::withMessages(['window' => 'Choose week or season.']),
        };
        $since = $now->subDays($days);

        return self::overlay($video, $now)
            ->map(function (VideoTimeMark $mark) use ($since): array {
                $recent = $mark->votes->filter(fn (VideoTimeMarkVote $vote): bool => $vote->updated_at !== null && $vote->updated_at->greaterThanOrEqualTo($since));
                $votes = $recent->sum('value');

                return [
                    'id' => $mark->id,
                    'start_seconds' => $mark->start_seconds,
                    'body' => $mark->body,
                    'votes' => $votes,
                    'likes_count' => $recent->where('value', 1)->count(),
                    'dislikes_count' => $recent->where('value', -1)->count(),
                ];
            })
            ->sortBy([['votes', 'desc'], ['likes_count', 'desc'], ['id', 'desc']])
            ->values();
    }

    private static function overlay(Video $video, CarbonImmutable $now): Collection
    {
        $since = $now->subDays(self::OVERLAY_DAYS);

        return VideoTimeMark::query()
            ->with('votes')
            ->where('video_id', $video->id)
            ->where('visibility', 'public')
            ->get()
            ->filter(function (VideoTimeMark $mark) use ($since): bool {
                $recentVote = $mark->votes->contains(fn (VideoTimeMarkVote $vote): bool => $vote->created_at !== null && $vote->created_at->greaterThanOrEqualTo($since));

                return $recentVote || ($mark->created_at !== null && $mark->created_at->greaterThanOrEqualTo($since));
            })
            ->sortBy('start_seconds')
            ->values();
    }

    private static function payload(VideoTimeMark $mark, ?User $viewer): array
    {
        return [
            'id' => $mark->id,
            'start_seconds' => $mark->start_seconds,
            'body' => $mark->body,
            'visibility' => $mark->visibility,
            'owned' => $viewer !== null && (int) $viewer->id === (int) $mark->user_id,
            'votes_count' => (int) ($mark->votes_count ?? 0),
            'voted' => (bool) ($mark->voted ?? false),
            'viewer_vote' => (int) ($viewer ? $mark->votes->firstWhere('user_id', $viewer->id)?->value : 0),
            'likes_count' => (int) $mark->likes_count,
            'dislikes_count' => (int) $mark->dislikes_count,
            'score' => (int) $mark->score,
        ];
    }

    private static function assertRange(Video $video, int $startSeconds): void
    {
        $duration = (int) ($video->duration_seconds ?? 0);
        if ($startSeconds < 0 || ($duration > 0 && $startSeconds > $duration)) {
            throw ValidationException::withMessages(['start_seconds' => 'Choose a time inside the video.']);
        }
    }

    private static function body(?string $body): ?string
    {
        $body = trim((string) $body);

        return $body === '' ? null : mb_substr($body, 0, 280);
    }
}
