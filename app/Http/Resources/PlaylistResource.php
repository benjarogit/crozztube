<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaylistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $videos = $this->whenLoaded('videos');
        $coverVideo = $this->whenLoaded('coverVideo');
        $firstVideo = $coverVideo instanceof \App\Models\Video ? $coverVideo : ($videos instanceof \Illuminate\Support\Collection ? $videos->first() : null);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'channel_id' => $this->channel_id,
            'title' => $this->title,
            'description' => $this->description,
            'privacy' => $this->privacy,
            'color' => $this->color,
            'cover_video_id' => $this->cover_video_id,
            'videos_count' => $this->videos_count ?? ($videos instanceof \Illuminate\Support\Collection ? $videos->count() : 0),
            'video' => $firstVideo ? (new VideoResource($firstVideo))->resolve($request) : null,
            'videos' => $videos instanceof \Illuminate\Support\Collection ? VideoResource::collection($videos)->resolve($request) : [],
            'video_ids' => $videos instanceof \Illuminate\Support\Collection ? $videos->pluck('id')->values() : [],
            'channel' => $this->relationLoaded('channel') && $this->channel ? (new ChannelResource($this->channel))->resolve($request) : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
