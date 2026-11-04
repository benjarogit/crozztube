<?php

namespace App\Http\Resources;

use App\Support\MediaStorage;
use App\Support\PublishingAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class UserResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly bool $includeAccount = true)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $security = is_array($settings['security'] ?? null) ? $settings['security'] : [];
        $preferences = is_array($settings['preferences'] ?? null) ? $settings['preferences'] : [];
        $isSelf = $this->includeAccount && ((int) ($request->user()?->id ?? 0) === (int) $this->id || $request->is('api/auth/*') || $request->is('auth/*'));
        $showEmail = (bool) ($preferences['showEmail'] ?? false);

        $settings['security'] = [
            'two_factor_enabled' => (bool) ($security['two_factor_enabled'] ?? false),
            'two_factor_method' => (string) ($security['two_factor_method'] ?? 'email'),
            'two_factor_contact' => $security['two_factor_contact'] ?? null,
        ];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => ($isSelf || $showEmail) ? $this->email : null,
            'gender' => $this->when($isSelf, $this->gender),
            'date_of_birth' => $this->when($isSelf, optional($this->date_of_birth)->format('Y-m-d')),
            'avatar_path' => $this->avatar_path,
            'avatar_url' => MediaStorage::url($this->avatar_path),
            'verified' => (bool) $this->verified_at,
            'verified_at' => optional($this->verified_at)->toIso8601String(),
            'settings' => $this->when($isSelf, $settings),
            'settings_stats' => $this->when($isSelf, fn () => [
                'muted_users_count' => DB::table('user_mutes')->where('user_id', $this->id)->count(),
            ]),
            'storage' => $this->when($isSelf, fn () => MediaStorage::storageSummaryFor($this->resource)),
            'publishing_access' => $this->when($isSelf, fn () => PublishingAccess::status($this->resource)),
            'channel' => new ChannelResource($this->whenLoaded('channel')),
        ];
    }
}
