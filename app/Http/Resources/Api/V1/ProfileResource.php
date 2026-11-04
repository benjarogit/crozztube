<?php

namespace App\Http\Resources\Api\V1;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->relationLoaded('user') ? $this->user : null;
        $settings = is_array($user?->settings) ? $user->settings : [];
        $profile = is_array($settings['profile'] ?? null) ? $settings['profile'] : [];
        $preferences = is_array($settings['preferences'] ?? null) ? $settings['preferences'] : [];
        $isOwner = (int) ($request->user()?->id ?? 0) === (int) $this->user_id;
        $statistics = [
            'subscribers' => (int) $this->subscribers_count,
            'videos' => (int) ($this->videos_count ?? 0),
            'views' => (int) ($this->public_views_count ?? 0),
        ];
        if ($this->public_subscriptions_count !== null) {
            $statistics['subscriptions'] = (int) $this->public_subscriptions_count;
        }

        return [
            'id' => (int) $this->id,
            'owner_user_id' => (int) $this->user_id,
            'slug' => (string) $this->slug,
            'name' => (string) $this->name,
            'bio' => (string) ($this->bio ?? ''),
            'about' => (string) ($this->about ?? ''),
            'avatar_url' => MediaStorage::url($this->avatar_path),
            'cover_url' => MediaStorage::url($this->cover_path),
            'verified' => (bool) $user?->verified_at,
            'created_at' => $user?->created_at?->toIso8601String(),
            'statistics' => $statistics,
            'contact' => [
                'website' => trim((string) ($profile['website'] ?? '')) ?: null,
                'location' => trim((string) ($profile['location'] ?? '')) ?: null,
                'email' => (bool) ($preferences['showEmail'] ?? false) && ($isOwner || $user?->email_verified_at !== null)
                    ? $user?->email : null,
                'socials' => collect((array) ($profile['socials'] ?? []))
                    ->only(['instagram', 'youtube', 'x', 'twitter', 'facebook', 'tiktok', 'linkedin'])
                    ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
                    ->all(),
            ],
            'viewer' => [
                'subscribed' => (bool) ($this->viewer_subscribed ?? false),
                'favorite' => (bool) ($this->viewer_favorite ?? false),
                'is_owner' => $isOwner,
                'reported' => (bool) ($this->viewer_reported ?? false),
                'muted' => (bool) ($this->viewer_muted ?? false),
                'can_report' => $request->user() !== null && (int) $request->user()->id !== (int) $this->user_id,
            ],
        ];
    }
}
