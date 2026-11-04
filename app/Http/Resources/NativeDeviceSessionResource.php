<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

class NativeDeviceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accessToken = $request->user()?->currentAccessToken();
        $currentTokenId = $accessToken instanceof PersonalAccessToken ? (int) $accessToken->getKey() : 0;

        return [
            'id' => $this->uuid,
            'client_type' => $this->client_type,
            'platform' => $this->platform,
            'device_name' => $this->device_name,
            'app_version' => $this->app_version,
            'os_version' => $this->os_version,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_active_at' => ($this->last_seen_at ?: $this->created_at)?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'refresh_expires_at' => $this->refresh_expires_at?->toIso8601String(),
            'is_current' => $currentTokenId > 0 && (int) $this->personal_access_token_id === $currentTokenId,
        ];
    }
}
