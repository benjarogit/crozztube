<?php

namespace App\Support;

use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Continue-watching rules shared by the web API and V1.
 * Dismiss keeps the history row; stale playing reports must not undo that hide.
 */
final class WatchProgress
{
    public static function applyContinueScope($query)
    {
        return $query->whereBetween('video_watch_progress.progress', [1, 94])
            ->whereNull('video_watch_progress.hidden_from_continue_at');
    }

    public static function dismiss(User $user, Video $video): array
    {
        return DB::transaction(function () use ($user, $video): array {
            $row = self::lockRow($user, $video, create: false);
            if (! $row) {
                throw ValidationException::withMessages([
                    'progress' => 'Nothing to remove from continue watching.',
                ]);
            }

            DB::table('video_watch_progress')->where('id', $row->id)->update([
                'hidden_from_continue_at' => $row->hidden_from_continue_at ?? now(),
                'updated_at' => now(),
            ]);

            return self::payload(DB::table('video_watch_progress')->where('id', $row->id)->first(), true);
        });
    }

    public static function markWatched(User $user, Video $video): array
    {
        return DB::transaction(function () use ($user, $video): array {
            $duration = max(1, (int) ($video->duration_seconds ?? 0) ?: 1);
            $row = self::lockRow($user, $video, create: true);
            $duration = max((int) ($row->duration_seconds ?? 0), $duration);

            DB::table('video_watch_progress')->where('id', $row->id)->update([
                'progress' => 100,
                'current_time_seconds' => $duration,
                'duration_seconds' => $duration,
                'completed_at' => $row->completed_at ?? now(),
                'hidden_from_continue_at' => null,
                'last_reported_at' => now(),
                'updated_at' => now(),
            ]);

            return self::payload(DB::table('video_watch_progress')->where('id', $row->id)->first(), true);
        });
    }

    public static function restart(User $user, Video $video): array
    {
        return DB::transaction(function () use ($user, $video): array {
            $row = self::lockRow($user, $video, create: true);

            DB::table('video_watch_progress')->where('id', $row->id)->update([
                'progress' => 0,
                'current_time_seconds' => 0,
                'completed_at' => null,
                'hidden_from_continue_at' => null,
                'last_event_id' => null,
                'last_reported_at' => now(),
                'updated_at' => now(),
            ]);

            return self::payload(DB::table('video_watch_progress')->where('id', $row->id)->first(), true);
        });
    }

    /**
     * @param  array{current_time?:int|float,duration_seconds?:int|float,position_seconds?:int|float,state?:string,event_id?:string}  $data
     */
    public static function record(User $user, Video $video, array $data): array
    {
        $serverDuration = max(0, (int) ($video->duration_seconds ?? 0));
        $duration = $serverDuration > 0
            ? $serverDuration
            : max(1, (int) floor((float) ($data['duration_seconds'] ?? 0)));
        if ($duration < 1) {
            throw ValidationException::withMessages([
                'duration_seconds' => 'The video duration is not available yet.',
            ]);
        }

        $state = (string) ($data['state'] ?? 'playing');
        $position = array_key_exists('position_seconds', $data)
            ? (int) floor((float) $data['position_seconds'])
            : (int) floor((float) ($data['current_time'] ?? 0));
        $position = min($duration, max(0, $position));
        $completed = $state === 'completed'
            || ($duration > 0 && ($position / $duration) >= 0.95)
            || ($duration >= 60 && ($duration - $position) <= 15);
        $restarted = $state === 'restarted';
        if ($completed) {
            $position = $duration;
        } elseif ($restarted) {
            $position = 0;
        }

        if ($position < 3 && ! $completed && ! $restarted && $state !== 'started') {
            return self::clearEarly($user, $video);
        }

        $percent = $completed ? 100 : max(0, min(99, (int) round(($position / $duration) * 100)));

        return DB::transaction(function () use ($user, $video, $data, $duration, $position, $percent, $completed, $restarted, $state): array {
            $row = self::lockRow($user, $video, create: true);
            $eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
            if ($eventId !== '' && hash_equals((string) ($row->last_event_id ?? ''), $eventId)) {
                return self::payload($row, false, true);
            }

            $hidden = $row->hidden_from_continue_at ?? null;
            $clearHide = $restarted || $completed || $state === 'started';
            if ($hidden && ! $clearHide) {
                $values = [
                    'progress' => $percent,
                    'current_time_seconds' => $position,
                    'duration_seconds' => $duration,
                    'last_reported_at' => now(),
                    'updated_at' => now(),
                    'hidden_from_continue_at' => $hidden,
                    'completed_at' => $completed ? ($row->completed_at ?? now()) : $row->completed_at,
                ];
                if ($eventId !== '') {
                    $values['last_event_id'] = $eventId;
                }
                DB::table('video_watch_progress')->where('id', $row->id)->update($values);

                return self::payload(DB::table('video_watch_progress')->where('id', $row->id)->first(), true);
            }

            $lastReported = isset($row->last_reported_at) && $row->last_reported_at
                ? Carbon::parse($row->last_reported_at)
                : null;
            $lastPosition = (int) ($row->current_time_seconds ?? 0);
            if ($state === 'playing'
                && $lastReported?->gt(now()->subSeconds(8))
                && abs($position - $lastPosition) < 10
                && ! $completed
                && ! $restarted) {
                return [
                    ...self::payload($row, false, false),
                    'next_update_after_seconds' => max(1, 8 - $lastReported->diffInSeconds(now())),
                ];
            }

            $values = [
                'progress' => $percent,
                'current_time_seconds' => $position,
                'duration_seconds' => $duration,
                'completed_at' => $completed ? ($row->completed_at ?? now()) : null,
                'hidden_from_continue_at' => null,
                'last_reported_at' => now(),
                'updated_at' => now(),
            ];
            if ($eventId !== '') {
                $values['last_event_id'] = $eventId;
            }
            DB::table('video_watch_progress')->where('id', $row->id)->update($values);

            return self::payload(DB::table('video_watch_progress')->where('id', $row->id)->first(), true);
        });
    }

    private static function clearEarly(User $user, Video $video): array
    {
        return DB::transaction(function () use ($user, $video): array {
            $row = self::lockRow($user, $video, create: false);
            if (! $row) {
                return self::emptyPayload(false);
            }
            if ($row->hidden_from_continue_at) {
                return self::payload($row, false);
            }
            DB::table('video_watch_progress')->where('id', $row->id)->delete();

            return self::emptyPayload(true);
        });
    }

    private static function lockRow(User $user, Video $video, bool $create): ?object
    {
        if ($create) {
            // Seed before locking so concurrent first writes cannot race the unique key.
            DB::table('video_watch_progress')->insertOrIgnore([
                'user_id' => $user->id,
                'video_id' => $video->id,
                'progress' => 0,
                'current_time_seconds' => 0,
                'duration_seconds' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $row = DB::table('video_watch_progress')
            ->where('user_id', $user->id)
            ->where('video_id', $video->id)
            ->lockForUpdate()
            ->first();

        if (! $row && $create) {
            throw ValidationException::withMessages([
                'progress' => 'Watch progress could not be saved.',
            ]);
        }

        return $row;
    }

    private static function emptyPayload(bool $persisted): array
    {
        return [
            'progress' => 0,
            'current_time' => 0,
            'position_seconds' => 0,
            'progress_percent' => 0,
            'duration_seconds' => 0,
            'completed' => false,
            'hidden_from_continue' => false,
            'persisted' => $persisted,
            'idempotent_replay' => false,
        ];
    }

    private static function payload(?object $row, bool $persisted, bool $idempotent = false): array
    {
        if (! $row) {
            return self::emptyPayload($persisted);
        }

        return [
            'video_id' => (int) $row->video_id,
            'progress' => (int) $row->progress,
            'progress_percent' => (int) $row->progress,
            'current_time' => (int) $row->current_time_seconds,
            'position_seconds' => (int) $row->current_time_seconds,
            'duration_seconds' => (int) $row->duration_seconds,
            'completed' => (int) $row->progress >= 100,
            'completed_at' => isset($row->completed_at) && $row->completed_at
                ? Carbon::parse($row->completed_at)->toIso8601String()
                : null,
            'hidden_from_continue' => $row->hidden_from_continue_at !== null,
            'persisted' => $persisted,
            'idempotent_replay' => $idempotent,
            'next_update_after_seconds' => 10,
        ];
    }
}
