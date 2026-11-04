<?php

namespace App\Services;

use App\Models\PlatformAppVersion;
use App\Support\Client\ClientContext;
use App\Support\Client\ClientPlatform;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ClientVersionService
{
    public function forContext(ClientContext $context): array
    {
        return $this->forPlatform($context->platform, $context->appVersion);
    }

    public function forPlatform(?ClientPlatform $platform, ?string $currentVersion): array
    {
        if ($platform === null) {
            return [
                'platform' => null,
                'current_version' => $currentVersion,
                'minimum_version' => null,
                'latest_version' => null,
                'supported' => false,
                'update_available' => false,
                'update_required' => false,
                'update_url' => null,
                'release_notes_url' => null,
                'update_message' => null,
            ];
        }

        $settings = $this->settingsFor($platform);
        $versions = $this->evaluateVersion(
            $currentVersion,
            (string) $settings['minimum_version'],
            (string) $settings['latest_version'],
        );

        return [
            'platform' => $platform->value,
            'current_version' => $currentVersion,
            'minimum_version' => $settings['minimum_version'],
            'latest_version' => $settings['latest_version'],
            'supported' => (bool) $settings['supported'],
            'update_available' => (bool) $settings['supported'] && $versions['update_available'],
            'update_required' => (bool) $settings['supported'] && $versions['update_required'],
            'update_url' => $this->safeUrl($settings['update_url'] ?? null),
            'release_notes_url' => $this->safeUrl($settings['release_notes_url'] ?? null),
            'update_message' => filled($settings['update_message'] ?? null) ? (string) $settings['update_message'] : null,
        ];
    }

    /** @return array{update_available: bool, update_required: bool} */
    public function evaluateVersion(?string $currentVersion, string $minimumVersion, string $latestVersion): array
    {
        if (! $this->validVersion($currentVersion)
            || ! $this->validVersion($minimumVersion)
            || ! $this->validVersion($latestVersion)) {
            return ['update_available' => false, 'update_required' => false];
        }

        return [
            'update_available' => version_compare($currentVersion, $latestVersion, '<'),
            'update_required' => version_compare($currentVersion, $minimumVersion, '<'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(
            fn (ClientPlatform $platform): array => $this->forPlatform($platform, null),
            ClientPlatform::cases(),
        );
    }

    public function update(PlatformAppVersion $setting, array $attributes): array
    {
        $setting->fill($attributes);
        $setting->save();

        return $this->forPlatform(ClientPlatform::from($setting->platform), null);
    }

    private function settingsFor(ClientPlatform $platform): array
    {
        $fallback = array_replace([
            'platform' => $platform->value,
            'latest_version' => '1.0.0',
            'minimum_version' => '1.0.0',
            'supported' => true,
            'update_url' => null,
            'release_notes_url' => null,
            'update_message' => null,
        ], (array) config('playhub-clients.platforms.'.$platform->value, []));

        try {
            $stored = Cache::remember(
                'playhub:client-version:'.$platform->value,
                now()->addMinutes(5),
                static fn (): ?array => PlatformAppVersion::query()
                    ->where('platform', $platform->value)
                    ->first()
                    ?->only([
                        'platform',
                        'latest_version',
                        'minimum_version',
                        'supported',
                        'update_url',
                        'release_notes_url',
                        'update_message',
                    ]),
            );
        } catch (Throwable) {
            try {
                $stored = PlatformAppVersion::query()
                    ->where('platform', $platform->value)
                    ->first()
                    ?->only([
                        'platform',
                        'latest_version',
                        'minimum_version',
                        'supported',
                        'update_url',
                        'release_notes_url',
                        'update_message',
                    ]);
            } catch (Throwable) {
                return $fallback;
            }
        }

        $settings = is_array($stored) ? array_replace($fallback, $stored) : $fallback;
        foreach (['minimum_version', 'latest_version'] as $key) {
            if (! $this->validVersion((string) ($settings[$key] ?? ''))) {
                $settings[$key] = $this->validVersion((string) ($fallback[$key] ?? ''))
                    ? $fallback[$key]
                    : '1.0.0';
            }
        }
        $settings['supported'] = (bool) ($settings['supported'] ?? true);
        $message = is_string($settings['update_message'] ?? null) ? $settings['update_message'] : '';
        $settings['update_message'] = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $message) ?? ''), 0, 500) ?: null;

        return $settings;
    }

    private function validVersion(?string $version): bool
    {
        return is_string($version)
            && strlen($version) <= 40
            && preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9a-z.-]+)?$/i', $version) === 1;
    }

    private function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
