<?php

namespace App\Http\Requests\Concerns;

use App\Support\Client\ClientContext;

trait UsesNativeClientContext
{
    protected function mergeNativeClientContext(): void
    {
        $context = ClientContext::fromRequest($this);

        $this->merge([
            'platform' => $context->platform?->value
                ?? strtolower(trim((string) $this->input('platform'))),
            'device_id' => $context->deviceId
                ?? trim((string) $this->input('device_id')),
            'device_name' => trim((string) $this->input('device_name'))
                ?: $context->deviceModel,
            'app_version' => $context->appVersion
                ?? (trim((string) $this->input('app_version')) ?: null),
            'os_version' => $context->operatingSystemVersion
                ?? (trim((string) $this->input('os_version')) ?: null),
        ]);
    }
}
