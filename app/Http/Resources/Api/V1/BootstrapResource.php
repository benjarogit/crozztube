<?php

namespace App\Http\Resources\Api\V1;

use App\Support\UgcConsent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

class BootstrapResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'api' => (array) data_get($this->resource, 'api', []),
            'client' => (array) data_get($this->resource, 'client', []),
            'branding' => (array) data_get($this->resource, 'branding', []),
            'authentication' => (array) data_get($this->resource, 'authentication', []),
            'maintenance' => (array) data_get($this->resource, 'maintenance', []),
            'version' => (array) data_get($this->resource, 'version', []),
            'features' => (array) data_get($this->resource, 'features', []),
            'plugins' => (object) data_get($this->resource, 'plugins', []),
            'playback' => (array) data_get($this->resource, 'playback', []),
            'advertising' => (array) data_get($this->resource, 'advertising', []),
            'payments' => (array) data_get($this->resource, 'payments', []),
            'uploads' => (array) data_get($this->resource, 'uploads', []),
            'links' => (array) data_get($this->resource, 'links', []),
            'safety' => [
                'contract_version' => 1,
                'user_blocking' => Schema::hasTable('user_blocks'),
                'terms_acceptance' => false,
                'policy_available' => UgcConsent::policy()['available'],
            ],
        ];
    }
}
