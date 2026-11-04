<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PushDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'provider' => $this->provider,
            'platform' => $this->platform,
            'environment' => $this->environment,
            'device_name' => $this->device_name,
            'app_version' => $this->app_version,
            'app_identifier' => $this->app_identifier,
            'locale' => $this->locale,
            'active' => $this->isActive(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
