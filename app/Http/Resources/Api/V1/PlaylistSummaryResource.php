<?php

namespace App\Http\Resources\Api\V1;

use App\Support\VideoSales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaylistSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cover = $this->relationLoaded('coverVideo') ? $this->coverVideo : null;

        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'description' => (string) ($this->description ?? ''),
            'is_series' => (bool) $this->is_series,
            'privacy' => (string) $this->privacy,
            'color' => (string) $this->color,
            'videos_count' => (int) ($this->videos_count ?? 0),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'thumbnail_url' => $cover ? VideoSales::thumbnailUrl($cover) : null,
            'cover_video_id' => $cover ? (int) $cover->id : null,
            'channel' => $this->relationLoaded('channel') && $this->channel
                ? (new ChannelSummaryResource($this->channel))->resolve($request)
                : null,
            'viewer' => [
                'is_owner' => (int) ($request->user()?->id ?? 0) === (int) $this->user_id,
            ],
        ];
    }
}
