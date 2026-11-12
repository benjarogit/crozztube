<?php

namespace App\Support\Client;

use DateTimeZone;
use Illuminate\Http\Request;

final class ClientContextResolver
{
    public function resolve(Request $request): ClientContext
    {
        $client = strtolower($this->header($request, 'client', 40) ?? '');
        $platform = ClientPlatform::tryFrom($client);
        $version = $this->header($request, 'app_version', 40);
        $deviceId = $this->header($request, 'device_id', 128);
        $locale = $this->locale($request);
        $timezone = $this->header($request, 'timezone', 64);

        if ($version !== null && ! preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9a-z.-]+)?$/i', $version)) {
            $version = null;
        }

        if ($deviceId !== null && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $deviceId)) {
            $deviceId = null;
        }

        if ($timezone !== null && $timezone !== 'UTC' && ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $timezone = null;
        }

        return new ClientContext(
            platform: $platform,
            appVersion: $version,
            operatingSystemVersion: $this->header($request, 'os_version', 80),
            deviceId: $deviceId,
            deviceModel: $this->header($request, 'device_model', 120),
            locale: $locale,
            timezone: $timezone,
            capabilities: ClientCapabilities::fromHeader($this->rawHeader($request, 'capabilities')),
        );
    }

    private function locale(Request $request): ?string
    {
        $locale = $this->header($request, 'locale', 35)
            ?? $this->cleanScalar($request->header('X-PlayHub-Language'), 35);

        if ($locale === null) {
            $acceptLanguage = $request->getPreferredLanguage();
            $locale = $this->cleanScalar($acceptLanguage, 35);
        }

        $locale = is_string($locale) ? str_replace('_', '-', $locale) : null;
        if ($locale === null || ! preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i', $locale)) {
            return null;
        }

        return str_replace('_', '-', \App\Support\LanguageLocales::canonical($locale));
    }

    private function header(Request $request, string $key, int $maximumLength): ?string
    {
        return $this->cleanScalar($this->rawHeader($request, $key), $maximumLength);
    }

    private function rawHeader(Request $request, string $key): ?string
    {
        $headerName = (string) config('playhub-clients.headers.'.$key, $this->fallbackHeader($key));
        $value = $request->headers->get($headerName);

        return is_string($value) ? $value : null;
    }

    private function cleanScalar(mixed $value, int $maximumLength): ?string
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maximumLength);
    }

    private function fallbackHeader(string $key): string
    {
        return match ($key) {
            'client' => 'X-PlayHub-Client',
            'app_version' => 'X-PlayHub-App-Version',
            'os_version' => 'X-PlayHub-OS-Version',
            'device_id' => 'X-PlayHub-Device-Id',
            'device_model' => 'X-PlayHub-Device-Model',
            'locale' => 'X-PlayHub-Locale',
            'timezone' => 'X-PlayHub-Timezone',
            'capabilities' => 'X-PlayHub-Capabilities',
            default => 'X-PlayHub-'.ucfirst($key),
        };
    }
}
