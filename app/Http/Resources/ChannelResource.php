<?php

namespace App\Http\Resources;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->relationLoaded('user') ? $this->user : null;
        if (! $user && $this->user_id) {
            $user = $this->user()->select(['id', 'email', 'settings', 'verified_at'])->first();
        }

        $settings = $user && is_array($user->settings) ? $user->settings : [];
        $profile = is_array($settings['profile'] ?? null) ? $settings['profile'] : [];
        $preferences = is_array($settings['preferences'] ?? null) ? $settings['preferences'] : [];
        $showEmail = (bool) ($preferences['showEmail'] ?? false);
        $privateMessagesPolicy = data_get($preferences, 'privateMessages', 'anyone');
        $privateMessages = in_array($privateMessagesPolicy, ['anyone', 'subscribers', 'disabled'], true)
            ? $privateMessagesPolicy
            : 'anyone';

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'bio' => $this->bio,
            'about' => $this->about,
            'avatar_path' => $this->avatar_path,
            'avatar_url' => MediaStorage::url($this->avatar_path),
            'cover_path' => $this->cover_path,
            'cover_url' => MediaStorage::url($this->cover_path),
            'cover_position_y' => $this->cover_position_y ?? 50,
            'featured_video_id' => $this->featured_video_id,
            'verified' => (bool) $user?->verified_at,
            'verified_at' => optional($user?->verified_at)->toIso8601String(),
            'featured_video' => new VideoResource($this->whenLoaded('featuredVideo')),
            'profile' => $this->when($user, fn () => [
                'website' => trim((string) ($profile['website'] ?? '')),
                'location' => trim((string) ($profile['location'] ?? '')),
                'socials' => collect(['instagram', 'youtube', 'twitter', 'facebook', 'tiktok', 'linkedin'])
                    ->mapWithKeys(fn (string $key): array => [$key => trim((string) data_get($profile, "socials.$key", ''))])
                    ->all(),
                'email' => $showEmail ? (string) $user->email : '',
                'show_email' => $showEmail,
                'private_messages' => $privateMessages,
            ]),
            'subscribers_count' => $this->subscribers_count,
            'videos_count' => $this->videos_count,
            'is_subscribed' => $this->when(isset($this->is_subscribed), (bool) $this->is_subscribed),
            'is_favorite' => $this->when(isset($this->is_favorite), (bool) $this->is_favorite),
            'notifications_muted' => $this->when(isset($this->notifications_muted), (bool) $this->notifications_muted),
            'reported' => $this->when(isset($this->reported), (bool) $this->reported),
            'can_report' => $this->when(isset($this->can_report), (bool) $this->can_report),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
