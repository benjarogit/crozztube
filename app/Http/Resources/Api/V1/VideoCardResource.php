<?php

namespace App\Http\Resources\Api\V1;

use App\Support\VideoSales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'slug' => (string) $this->slug,
            'title' => (string) $this->title,
            'thumbnail_url' => VideoSales::thumbnailUrl($this->resource),
            'duration_seconds' => $this->duration_seconds !== null ? (int) $this->duration_seconds : null,
            'content_type' => $this->isShort() ? 'short' : ($this->source_type === 'live_replay' ? 'replay' : 'video'),
            'source_type' => (string) $this->source_type,
            'import_provider' => $this->import_provider ? (string) $this->import_provider : null,
            'published_at' => optional($this->published_at)->toIso8601String(),
            'statistics' => [
                'views' => (int) $this->views_count,
                'likes' => (int) $this->likes_count,
                'comments' => (int) $this->comments_count,
            ],
            'channel' => $this->relationLoaded('channel') && $this->channel
                ? (new ChannelSummaryResource($this->channel))->resolve($request)
                : null,
            'viewer' => [
                'liked' => (bool) ($this->viewer_liked ?? $this->is_liked ?? false),
                'watch_later' => (bool) ($this->viewer_watch_later ?? $this->is_watch_later ?? false),
                'subscribed' => (bool) ($this->viewer_subscribed ?? false),
                'progress_percent' => (int) ($this->viewer_progress_percent ?? $this->progress ?? 0),
                'position_seconds' => (int) ($this->viewer_position_seconds ?? $this->current_time ?? 0),
            ],
        ];
    }
}
