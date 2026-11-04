<?php

namespace App\Http\Resources\Api\V1;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthenticatedUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $profile = is_array($settings['profile'] ?? null) ? $settings['profile'] : [];
        $preferences = is_array($settings['preferences'] ?? null) ? $settings['preferences'] : [];
        $playback = is_array($settings['playback'] ?? null) ? $settings['playback'] : [];
        $appearance = is_array($settings['appearance'] ?? null) ? $settings['appearance'] : [];
        $security = is_array($settings['security'] ?? null) ? $settings['security'] : [];
        $channel = $this->relationLoaded('channel') ? $this->channel : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'avatar_url' => MediaStorage::url($this->avatar_path),
            'verified' => (bool) $this->verified_at,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'settings' => [
                'profile' => [
                    'website' => trim((string) ($profile['website'] ?? '')),
                    'location' => trim((string) ($profile['location'] ?? '')),
                    'socials' => collect(['instagram', 'youtube', 'twitter', 'facebook', 'tiktok', 'linkedin'])
                        ->mapWithKeys(fn (string $network): array => [
                            $network => trim((string) data_get($profile, 'socials.'.$network, '')),
                        ])->all(),
                ],
                'preferences' => [
                    'public_profile' => (bool) ($preferences['publicProfile'] ?? true),
                    'show_subscriptions' => (bool) ($preferences['showSubscriptions'] ?? true),
                    'show_email' => (bool) ($preferences['showEmail'] ?? false),
                    'accept_tips' => (bool) ($preferences['acceptTips'] ?? true),
                    'comments' => (bool) ($preferences['comments'] ?? true),
                    'replies' => (bool) ($preferences['replies'] ?? true),
                    'mentions' => (bool) ($preferences['mentions'] ?? true),
                    'live_reminders' => (bool) ($preferences['liveReminders'] ?? true),
                    'uploads' => (bool) ($preferences['uploads'] ?? true),
                    'subscriptions' => (bool) ($preferences['subscriptions'] ?? true),
                    'newsletter' => (bool) ($preferences['newsletter'] ?? false),
                    'autoplay' => (bool) ($preferences['autoplay'] ?? true),
                    'reduced_motion' => (bool) ($preferences['reducedMotion'] ?? false),
                    'private_messages' => $this->privateMessagesPreference($preferences),
                ],
                'playback' => [
                    'default_quality' => (string) ($playback['defaultQuality'] ?? '1080p'),
                    'captions' => (string) ($playback['captions'] ?? 'On'),
                    'language' => (string) ($playback['language'] ?? 'English'),
                ],
                'appearance' => [
                    'theme_mode' => (string) ($appearance['themeMode'] ?? 'light'),
                    'accent' => isset($appearance['accent']) && is_string($appearance['accent']) ? $appearance['accent'] : null,
                ],
                'security' => [
                    'two_factor_enabled' => (bool) ($security['two_factor_enabled'] ?? false),
                    'two_factor_method' => (string) ($security['two_factor_method'] ?? 'email'),
                    'two_factor_contact' => $security['two_factor_contact'] ?? null,
                ],
            ],
            'channel' => $channel ? [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
                'bio' => $channel->bio,
                'avatar_url' => MediaStorage::url($channel->avatar_path),
                'cover_url' => MediaStorage::url($channel->cover_path),
            ] : null,
        ];
    }

    private function privateMessagesPreference(array $preferences): string
    {
        $value = (string) ($preferences['privateMessages'] ?? 'anyone');

        return in_array($value, ['anyone', 'subscribers', 'disabled'], true) ? $value : 'anyone';
    }
}
