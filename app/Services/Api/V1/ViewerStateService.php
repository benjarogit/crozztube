<?php

namespace App\Services\Api\V1;

use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ViewerStateService
{
    public function hydrate(Collection $videos, ?User $viewer, bool $includeReportState = false): Collection
    {
        if (! $viewer || $videos->isEmpty()) {
            return $videos;
        }

        $videoIds = $videos->pluck('id')->filter()->unique()->values();
        $channelIds = $videos->pluck('channel_id')->filter()->unique()->values();
        $liked = DB::table('likes')
            ->where('user_id', $viewer->id)
            ->where('likeable_type', Video::class)
            ->whereIn('likeable_id', $videoIds)
            ->pluck('likeable_id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true]);
        $saved = DB::table('watch_laters')
            ->where('user_id', $viewer->id)
            ->whereIn('video_id', $videoIds)
            ->pluck('video_id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true]);
        $progress = DB::table('video_watch_progress')
            ->where('user_id', $viewer->id)
            ->whereIn('video_id', $videoIds)
            ->get()
            ->keyBy('video_id');
        $subscriptions = DB::table('subscriptions')
            ->where('user_id', $viewer->id)
            ->whereIn('channel_id', $channelIds)
            ->pluck('channel_id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true]);
        $reports = $includeReportState && Schema::hasTable('video_reports')
            ? DB::table('video_reports')
                ->where('user_id', $viewer->id)
                ->whereIn('video_id', $videoIds)
                ->pluck('video_id')
                ->mapWithKeys(static fn ($id): array => [(int) $id => true])
            : collect();

        $videos->each(function (Video $video) use ($liked, $saved, $progress, $subscriptions, $reports, $includeReportState): void {
            $row = $progress->get($video->id);
            $video->setAttribute('viewer_liked', $liked->has((int) $video->id));
            $video->setAttribute('viewer_watch_later', $saved->has((int) $video->id));
            if ($includeReportState) {
                $video->setAttribute('viewer_reported', $reports->has((int) $video->id));
            }
            $video->setAttribute('viewer_subscribed', $subscriptions->has((int) $video->channel_id));
            $video->setAttribute('viewer_progress_percent', $row ? (int) $row->progress : 0);
            $video->setAttribute('viewer_position_seconds', $row ? (int) $row->current_time_seconds : 0);
            $video->setAttribute('viewer_progress_updated_at', $row?->updated_at);
        });

        return $videos;
    }

    public function hydrateOne(Video $video, ?User $viewer, bool $includeReportState = true): Video
    {
        $this->hydrate(collect([$video]), $viewer, $includeReportState);

        return $video;
    }
}
