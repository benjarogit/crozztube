<?php

namespace App\Http\Resources\Api\V1;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'slug' => (string) $this->slug,
            'name' => (string) $this->name,
            'avatar_url' => MediaStorage::url($this->avatar_path),
            'cover_url' => MediaStorage::url($this->cover_path),
            'verified' => (bool) ($this->relationLoaded('user') && $this->user?->verified_at),
            'subscribers_count' => (int) $this->subscribers_count,
            'videos_count' => (int) ($this->videos_count ?? 0),
            'viewer' => [
                'subscribed' => (bool) ($this->viewer_subscribed ?? $this->is_subscribed ?? false),
            ],
        ];
    }
}
