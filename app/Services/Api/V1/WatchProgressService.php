<?php

namespace App\Services\Api\V1;

use App\Models\User;
use App\Models\Video;
use App\Support\VideoSales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WatchProgressService
{
    public function __construct(private readonly VideoAccessService $access) {}

    public function update(User $user, Video $video, array $data): array
    {
        $this->access->assertViewable($video, $user);
        abort_unless(VideoSales::hasAccess($video, $user), 403, 'Purchase this video to watch it.');

        $serverDuration = max(0, (int) ($video->duration_seconds ?? 0));
        $duration = $serverDuration > 0 ? $serverDuration : max(1, (int) floor((float) ($data['duration_seconds'] ?? 0)));
        abort_if($duration < 1, 422, 'The video duration is not available yet.');

        $position = min($duration, max(0, (int) floor((float) $data['position_seconds'])));
        $state = (string) ($data['state'] ?? 'playing');
        $completed = $state === 'completed'
            || ($duration > 0 && ($position / $duration) >= .95)
            || ($duration >= 60 && ($duration - $position) <= 15);
        $restarted = $state === 'restarted';
        if ($completed) {
            $position = $duration;
        } elseif ($restarted) {
            $position = 0;
        }
        $percent = $completed ? 100 : max(0, min(99, (int) round(($position / $duration) * 100)));

        return DB::transaction(function () use ($user, $video, $data, $duration, $position, $percent, $completed, $state): array {
            // Seed the unique user/video row before locking it. A SELECT ... FOR UPDATE
            // cannot reliably lock a row that does not exist, so simultaneous first
            // progress events could otherwise race into the unique constraint.
            DB::table('video_watch_progress')->insertOrIgnore([
                'user_id' => $user->id,
                'video_id' => $video->id,
                'progress' => 0,
                'current_time_seconds' => 0,
                'duration_seconds' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existing = DB::table('video_watch_progress')
                ->where('user_id', $user->id)
                ->where('video_id', $video->id)
                ->lockForUpdate()
                ->first();

            if ($existing && hash_equals((string) ($existing->last_event_id ?? ''), (string) $data['event_id'])) {
                return $this->payload($existing, false, true);
            }

            $lastPosition = (int) ($existing->current_time_seconds ?? 0);
            $lastReported = isset($existing->last_reported_at) && $existing->last_reported_at
                ? Carbon::parse($existing->last_reported_at)
                : null;
            $suppress = $existing
                && $state === 'playing'
                && $lastReported?->gt(now()->subSeconds(8))
                && abs($position - $lastPosition) < 10;

            if ($suppress) {
                return [
                    ...$this->payload($existing, false, false),
                    'next_update_after_seconds' => max(1, 8 - $lastReported->diffInSeconds(now())),
                ];
            }

            $values = [
                'progress' => $percent,
                'current_time_seconds' => $position,
                'duration_seconds' => $duration,
                'last_event_id' => $data['event_id'],
                'last_reported_at' => now(),
                'completed_at' => $completed ? ($existing->completed_at ?? now()) : null,
                'updated_at' => now(),
            ];

            DB::table('video_watch_progress')->where('id', $existing->id)->update($values);
            $id = $existing->id;

            $row = DB::table('video_watch_progress')->where('id', $id)->first();

            return $this->payload($row, true, false);
        });
    }

    private function payload(object $row, bool $persisted, bool $idempotent): array
    {
        return [
            'video_id' => (int) $row->video_id,
            'progress_percent' => (int) $row->progress,
            'position_seconds' => (int) $row->current_time_seconds,
            'duration_seconds' => (int) $row->duration_seconds,
            'completed' => (int) $row->progress >= 100,
            'completed_at' => isset($row->completed_at) && $row->completed_at
                ? Carbon::parse($row->completed_at)->toIso8601String()
                : null,
            'persisted' => $persisted,
            'idempotent_replay' => $idempotent,
            'next_update_after_seconds' => 10,
        ];
    }
}
