<?php

namespace App\Services;

use App\Plugins\PluginManager;
use App\Support\AuthSettings;
use App\Support\Client\ClientContext;
use App\Support\GeneralSettings;
use App\Support\IntegrationSettings;
use App\Support\PlatformFeatureSettings;
use App\Support\PlayerAdSettings;

final class ApplicationBootstrapService
{
    public function __construct(
        private readonly ClientVersionService $versions,
        private readonly ApplicationFeatureService $features,
        private readonly PlaybackCapabilityService $playback,
        private readonly PluginManager $plugins,
    ) {}

    public function build(ClientContext $client, ?string $ip = null): array
    {
        $general = GeneralSettings::publicSettings();
        $auth = AuthSettings::publicSettings();
        $platform = PlatformFeatureSettings::publicSettings($ip);
        $integrations = IntegrationSettings::publicSettings();
        $ads = PlayerAdSettings::publicSettings();
        $registrationAvailable = AuthSettings::registrationDeniedMessage($ip) === null;

        return [
            'api' => [
                'version' => (string) config('playhub-clients.api_version', 'v1'),
            ],
            'client' => $client->toPublicArray(),
            'branding' => [
                'name' => (string) ($general['platformName'] ?? config('app.name', 'PlayHub')),
                'title' => (string) ($general['websiteTitle'] ?? config('app.name', 'PlayHub')),
                'logo_text' => (string) ($general['logoText'] ?? 'PlayHub'),
                'logo_url' => $this->publicUrl($general['logoUrl'] ?? null),
                'favicon_url' => $this->publicUrl($general['faviconUrl'] ?? null),
                'navigation' => [
                    'show_sidebar_toggle' => (bool) ($general['showSidebarToggle'] ?? true),
                ],
                'theme' => [
                    'mode' => in_array($general['defaultThemeMode'] ?? null, ['light', 'dark', 'system'], true)
                        ? $general['defaultThemeMode']
                        : 'system',
                    'primary' => $this->color($general['defaultPrimaryColor'] ?? null, '#d4b477'),
                    'secondary' => $this->color($general['defaultSecondaryColor'] ?? null, '#bb9e69'),
                    'primary_soft' => $this->color($general['defaultPrimarySoftColor'] ?? null, '#fff2cf'),
                ],
            ],
            'authentication' => [
                'password' => collect((array) ($auth['login'] ?? []))->contains(true),
                'login_identifiers' => collect((array) ($auth['login'] ?? []))
                    ->filter(static fn (mixed $enabled): bool => $enabled === true)
                    ->keys()
                    ->values()
                    ->all(),
                'registration' => [
                    'enabled' => (bool) data_get($auth, 'registration.enabled', false) && $registrationAvailable,
                    'confirmation_method' => (string) data_get($auth, 'registration.confirmationMethod', 'none'),
                    'phone_enabled' => (bool) data_get($auth, 'registration.phoneEnabled', false),
                    'username_required' => (bool) data_get($auth, 'registration.usernameRequired', false),
                    'password_strength' => [
                        'enabled' => (bool) data_get($auth, 'registration.passwordStrengthEnabled', true),
                        'level' => max(0, min(10, (int) data_get($auth, 'registration.passwordStrengthLevel', 0))),
                    ],
                    'captcha' => [
                        'enabled' => (bool) data_get($auth, 'registration.captchaEnabled', false),
                        'provider' => (string) data_get($auth, 'registration.captchaProvider', 'simple'),
                        'site_key' => (string) data_get($auth, 'registration.googleCaptchaSiteKey', ''),
                    ],
                    'verification_code' => [
                        'digits' => max(4, min(8, (int) data_get($auth, 'registration.codeDigits', 4))),
                        'expires_seconds' => max(60, min(3600, (int) data_get($auth, 'registration.codeExpiresSeconds', 300))),
                        'max_resends' => max(0, min(10, (int) data_get($auth, 'registration.maxResends', 3))),
                    ],
                ],
                'social' => collect((array) ($auth['social'] ?? []))
                    ->map(static fn (mixed $provider): bool => (bool) data_get($provider, 'enabled', false))
                    ->all(),
            ],
            'maintenance' => [
                'enabled' => (bool) data_get($platform, 'maintenance.enabled', false),
                'message' => (string) data_get($platform, 'maintenance.message', ''),
            ],
            'version' => $this->versions->forContext($client),
            'features' => $this->features->publicFeatures($ip, $client),
            'plugins' => $this->plugins->mobileCapabilities(),
            'playback' => $this->playback->forClient($client->capabilities),
            'advertising' => [
                'enabled' => (bool) ($ads['enabled'] ?? false),
                'load_timeout_seconds' => (int) ($ads['load_timeout_seconds'] ?? 0),
                'placements' => collect((array) ($ads['ads'] ?? []))
                    ->filter(static fn (mixed $ad): bool => is_array($ad) && ($ad['enabled'] ?? false))
                    ->map(static fn (array $ad): array => [
                        'id' => (string) ($ad['id'] ?? ''),
                        'engine' => (string) ($ad['engine'] ?? ''),
                        'placement' => (string) ($ad['placement'] ?? ''),
                        'offset_seconds' => isset($ad['offset_seconds']) ? (int) $ad['offset_seconds'] : null,
                    ])
                    ->values()
                    ->all(),
            ],
            'payments' => [
                'currency' => (string) data_get($integrations, 'payments.currency', 'usd'),
                'native_purchases_supported' => false,
                'existing_purchases_playable' => true,
                'web_checkout_available' => collect((array) data_get($integrations, 'payments.providers', []))
                    ->contains(static fn (mixed $provider): bool => (bool) data_get($provider, 'enabled', false)),
                'web_providers' => collect((array) data_get($integrations, 'payments.providers', []))
                    ->map(static fn (mixed $provider): bool => (bool) data_get($provider, 'enabled', false))
                    ->all(),
            ],
            'uploads' => [
                'max_size_mb' => max(1, (int) config('playhub.max_upload_mb', 2048)),
                'chunk_size_mb' => max(1, (int) config('playhub.chunk_size_mb', 8)),
                'default_quality' => (string) data_get($platform, 'uploads.default_quality', 'auto'),
                'default_visibility' => PlatformFeatureSettings::defaultVideoVisibility(),
                'chapters_enabled' => (bool) data_get($platform, 'uploads.chapters_enabled', true),
            ],
            'links' => [
                'privacy' => url('/privacy'),
                'terms' => url('/terms'),
                'cookies' => url('/cookies'),
                'support' => url('/support'),
                'contact' => url('/contact'),
            ],
        ];
    }

    private function publicUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (str_starts_with($value, '/')) {
            return url($value);
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL)
            ? $value
            : null;
    }

    private function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : $fallback;
    }
}
