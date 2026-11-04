<?php

namespace App\Http\Resources\Api\V1;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'username' => (string) $this->username,
            'avatar_url' => MediaStorage::url($this->avatar_path),
            'verified' => (bool) $this->verified_at,
            'channel' => $this->relationLoaded('channel') && $this->channel
                ? (new ChannelSummaryResource($this->channel))->resolve($request)
                : null,
        ];
    }
}
