<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Api\V1\PlaybackSessionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShortResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $playback = app(PlaybackSessionService::class)->forVideo($this->resource, $request, compact: true);

        return [
            'id' => (int) $this->id,
            'slug' => (string) $this->slug,
            'caption' => (string) ($this->title ?: mb_substr((string) $this->description, 0, 160)),
            'thumbnail_candidates' => $this->thumbnail_candidates,
            'video_width' => $this->video_width, 'video_height' => $this->video_height,
            'poster_url' => $this->thumbnail_url,
            'duration_seconds' => $this->duration_seconds !== null ? (int) $this->duration_seconds : null,
            'creator' => $this->relationLoaded('channel') && $this->channel
                ? (new ChannelSummaryResource($this->channel))->resolve($request)
                : null,
            'playback' => [
                'provider' => $playback['provider'],
                'source_type' => $playback['source_type'],
                'provider_id' => $playback['provider_id'],
                'provider_url' => $playback['provider_url'],
                'sources' => $playback['sources'],
                'expires_at' => $playback['expires_at'],
            ],
            'engagement' => [
                'liked' => (bool) ($this->viewer_liked ?? false),
                'watch_later' => (bool) ($this->viewer_watch_later ?? false),
                'subscribed' => (bool) ($this->viewer_subscribed ?? false),
                'likes_count' => (int) $this->likes_count,
                'comments_count' => (int) $this->comments_count,
                'views_count' => (int) $this->views_count,
            ],
            'resume_position_seconds' => (int) ($this->viewer_position_seconds ?? 0),
            'share' => [
                'url' => url('/shorts/'.$this->slug),
                'title' => (string) $this->title,
            ],
        ];
    }
}
