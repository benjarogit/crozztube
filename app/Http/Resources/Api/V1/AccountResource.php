<?php

namespace App\Http\Resources\Api\V1;

use App\Support\MediaStorage;
use App\Support\PublishingAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $security = is_array($settings['security'] ?? null) ? $settings['security'] : [];

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'username' => (string) $this->username,
            'email' => (string) $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'avatar_url' => MediaStorage::url($this->avatar_path),
            'verified' => $this->verified_at !== null,
            'channel' => $this->relationLoaded('channel') && $this->channel
                ? (new ChannelSummaryResource($this->channel))->resolve($request)
                : null,
            'settings' => [
                'profile' => is_array($settings['profile'] ?? null) ? $settings['profile'] : [],
                'preferences' => is_array($settings['preferences'] ?? null) ? $settings['preferences'] : [],
                'playback' => is_array($settings['playback'] ?? null) ? $settings['playback'] : [],
                'appearance' => is_array($settings['appearance'] ?? null) ? $settings['appearance'] : [],
                'membership' => MediaStorage::membershipFor($this->resource),
                'security' => [
                    'two_factor_enabled' => (bool) ($security['two_factor_enabled'] ?? false),
                    'two_factor_method' => (string) ($security['two_factor_method'] ?? 'email'),
                    'two_factor_contact' => $security['two_factor_contact'] ?? null,
                ],
            ],
            'publishing_access' => PublishingAccess::status($this->resource),
            'storage' => MediaStorage::storageSummaryFor($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
