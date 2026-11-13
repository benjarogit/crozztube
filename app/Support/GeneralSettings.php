<?php

namespace App\Support;

use App\Plugins\PluginHooks;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Config;

class GeneralSettings
{
    public static function settings(): array
    {
        $path = self::settingsPath();
        $stored = is_file($path) && is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];
        if (is_array($stored) && ! isset($stored['design']['radiusScaleVersion'])) {
            $radii = is_array($stored['design']['radii'] ?? null) ? $stored['design']['radii'] : [];
            if (($radii['radius'] ?? '14px') === '14px') {
                $radii['radius'] = '10px';
            }
            if (($radii['radiusLg'] ?? '18px') === '18px') {
                $radii['radiusLg'] = '12px';
            }
            $radii['radiusMedia'] ??= '8px';
            $stored['design']['radii'] = $radii;
            $stored['design']['radiusScaleVersion'] = 2;
        }
        $stored = InterfacePalette::migrate(is_array($stored) ? $stored : []);
        $settings = array_replace_recursive(self::defaults(), $stored);
        $settings['logoText'] = (string) ($settings['logoText'] ?? '');
        $settings['showLogoText'] = (bool) $settings['showLogoText'];
        $settings['logoSize'] = max(16, min(64, (int) ($settings['logoSize'] ?? 40)));
        $settings['loadingDisplay'] = ($settings['loadingDisplay'] ?? null) === 'bar' ? 'bar' : 'instant';
        $settings['disabledLanguages'] = self::normalizeDisabledLanguages($settings['disabledLanguages'] ?? []);
        $settings['defaultLanguage'] = self::normalizeLanguage((string) ($settings['defaultLanguage'] ?? config('app.locale', 'en')), $settings['disabledLanguages']);

        return $settings;
    }

    public static function defaultSettings(): array
    {
        return self::defaults();
    }

    public static function adminSettings(): array
    {
        return array_replace_recursive(self::settings(), [
            'fontFamilyOptions' => self::fontFamilyOptions(),
            'timezoneOptions' => self::timezoneOptions(),
        ]);
    }

    public static function publicSettings(): array
    {
        $settings = self::settings();

        $public = [
            'platformName' => $settings['platformName'],
            'websiteTitle' => $settings['websiteTitle'],
            'logoText' => $settings['logoText'],
            'showLogoText' => $settings['showLogoText'],
            'logoSize' => $settings['logoSize'],
            'logoUrl' => $settings['logoUrl'],
            'faviconUrl' => $settings['faviconUrl'],
            'authPreview' => $settings['authPreview'],
            'defaultPrimaryColor' => $settings['defaultPrimaryColor'],
            'defaultSecondaryColor' => $settings['defaultSecondaryColor'],
            'defaultPrimarySoftColor' => $settings['defaultPrimarySoftColor'],
            'defaultThemeMode' => $settings['defaultThemeMode'],
            'loadingDisplay' => $settings['loadingDisplay'],
            'defaultLanguage' => $settings['defaultLanguage'],
            'languageAliases' => (object) LanguageLocales::ALIASES,
            'fontFamily' => $settings['fontFamily'],
            'fontImportUrl' => $settings['fontImportUrl'],
            'showSidebarToggle' => (bool) $settings['showSidebarToggle'],
            'design' => $settings['design'],
            'seo' => SeoSettings::publicSettings(),
        ];

        if (app()->bound(PluginHooks::class)) {
            $filtered = app(PluginHooks::class)->filter('playhub.public_settings', $public);
            if (is_array($filtered)) return $filtered;
        }

        return $public;
    }

    public static function save(array $settings): void
    {
        $defaults = self::defaults();
        $settings = array_replace_recursive($defaults, $settings);
        $settings['logoText'] = (string) ($settings['logoText'] ?? '');
        $settings['showLogoText'] = (bool) ($settings['showLogoText'] ?? true);
        $settings['logoSize'] = max(16, min(64, (int) ($settings['logoSize'] ?? 40)));
        foreach (array_keys($defaults['authPreview']) as $key) {
            $settings['authPreview'][$key] = is_string($settings['authPreview'][$key] ?? null) ? $settings['authPreview'][$key] : '';
        }
        $settings['timezone'] = self::normalizeTimezone((string) ($settings['timezone'] ?? 'UTC'));
        $settings['defaultThemeMode'] = in_array($settings['defaultThemeMode'] ?? 'system', ['light', 'dark', 'system'], true) ? $settings['defaultThemeMode'] : 'system';
        $settings['loadingDisplay'] = ($settings['loadingDisplay'] ?? null) === 'bar' ? 'bar' : 'instant';
        $settings['disabledLanguages'] = self::normalizeDisabledLanguages($settings['disabledLanguages'] ?? []);
        $settings['defaultLanguage'] = self::normalizeLanguage((string) ($settings['defaultLanguage'] ?? config('app.locale', 'en')), $settings['disabledLanguages']);
        $settings['showSidebarToggle'] = (bool) ($settings['showSidebarToggle'] ?? true);
        $path = self::settingsPath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    public static function apply(): void
    {
        $settings = self::settings();
        $timezone = self::normalizeTimezone((string) ($settings['timezone'] ?? 'UTC'));
        $language = self::normalizeLanguage((string) ($settings['defaultLanguage'] ?? config('app.locale', 'en')), $settings['disabledLanguages'] ?? []);

        $previousAppName = (string) config('app.name', 'PlayHub');
        $appName = $settings['platformName'] ?: 'PlayHub';
        $mailFromName = trim((string) config('mail.from.name'));
        Config::set('app.name', $appName);
        if ($mailFromName === '' || in_array($mailFromName, ['PlayHub', $previousAppName], true)) {
            Config::set('mail.from.name', $appName);
        }
        Config::set('app.timezone', $timezone);
        Config::set('app.locale', $language);
        if (app()->bound('translator')) app()->setLocale($language);
        date_default_timezone_set($timezone);
    }

    public static function languageIdentifiers(): array
    {
        $root = realpath((string) config('playhub.language_path', base_path('lang')));
        if ($root === false) return [];

        static $cache = [];
        if (isset($cache[$root])) return $cache[$root];

        $languages = [];
        foreach (glob($root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $locale = strtolower(basename(dirname($file)));
            if (preg_match('/^[a-z0-9_-]+$/', $locale) && ! LanguageLocales::isAlias($locale)) $languages[$locale] = true;
        }
        $languages = array_keys($languages);
        sort($languages);

        return $cache[$root] = $languages;
    }

    public static function timezoneIdentifiers(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    private static function timezoneOptions(): array
    {
        return array_map(function (string $timezone): array {
            $offset = (new DateTimeZone($timezone))->getOffset(new DateTimeImmutable('now', new DateTimeZone($timezone)));
            $hours = intdiv(abs($offset), 3600);
            $minutes = intdiv(abs($offset) % 3600, 60);
            $prefix = $offset >= 0 ? '+' : '-';

            return [$timezone, sprintf('%s (UTC%s%02d:%02d)', $timezone, $prefix, $hours, $minutes)];
        }, self::timezoneIdentifiers());
    }

    private static function fontFamilyOptions(): array
    {
        return [
            ['Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif', 'Inter'],
            ['Roboto,Arial,sans-serif', 'Roboto'],
            ['"Open Sans",Arial,sans-serif', 'Open Sans'],
            ['Montserrat,Arial,sans-serif', 'Montserrat'],
            ['Poppins,Arial,sans-serif', 'Poppins'],
            ['Lato,Arial,sans-serif', 'Lato'],
            ['Nunito,Arial,sans-serif', 'Nunito'],
            ['"Source Sans 3",Arial,sans-serif', 'Source Sans 3'],
            ['"Work Sans",Arial,sans-serif', 'Work Sans'],
            ['Arial,Helvetica,sans-serif', 'Arial'],
            ['Georgia,"Times New Roman",serif', 'Georgia'],
            ['system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif', 'System UI'],
        ];
    }

    private static function normalizeTimezone(string $timezone): string
    {
        return in_array($timezone, self::timezoneIdentifiers(), true) ? $timezone : 'UTC';
    }

    private static function normalizeDisabledLanguages(mixed $languages): array
    {
        $installed = self::languageIdentifiers();
        $disabled = collect(is_array($languages) ? $languages : [])
            ->filter(fn (mixed $language): bool => is_string($language))
            ->map(fn (string $language): string => strtolower(str_replace('-', '_', trim($language))))
            ->filter(fn (string $language): bool => in_array($language, $installed, true))
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($installed !== [] && count($disabled) >= count($installed)) {
            $fallback = in_array('en', $installed, true) ? 'en' : $installed[0];
            $disabled = array_values(array_diff($disabled, [$fallback]));
        }

        return $disabled;
    }

    private static function normalizeLanguage(string $language, array $disabled = []): string
    {
        $languages = array_values(array_diff(self::languageIdentifiers(), $disabled));
        $language = LanguageLocales::canonical($language);
        if (in_array($language, $languages, true)) return $language;

        $shortCode = explode('_', $language)[0] ?? '';
        if (in_array($shortCode, $languages, true)) return $shortCode;
        foreach ($languages as $candidate) {
            if (explode('_', $candidate)[0] === $shortCode) return $candidate;
        }

        $configured = LanguageLocales::canonical((string) config('app.locale', 'en'));
        if (in_array($configured, $languages, true)) return $configured;
        if (in_array('en', $languages, true)) return 'en';

        return $languages[0] ?? 'en';
    }

    private static function settingsPath(): string
    {
        return storage_path('app/playhub-general-settings.json');
    }

    private static function defaults(): array
    {
        return [
            'platformName' => config('app.name', 'PlayHub'),
            'websiteTitle' => config('app.name', 'PlayHub'),
            'logoText' => 'PlayHub',
            'showLogoText' => true,
            'logoSize' => 40,
            'logoUrl' => '/branding/logo.svg',
            'faviconUrl' => '/branding/favicon.svg',
            'authPreview' => [
                'title' => 'Video CMS & Streaming Platform UI Kit',
                'description' => 'Clean light and true black dark layouts for creators, communities and streaming products.',
                'feature1' => 'Scalable design system',
                'feature2' => 'Light & dark themes',
                'feature3' => 'Accessible components',
                'feature4' => 'Built for creator workflows',
            ],
            'defaultPrimaryColor' => '#1a73b8',
            'defaultSecondaryColor' => '#165f99',
            'defaultPrimarySoftColor' => '#e2edf7',
            'defaultThemeMode' => 'system',
            'loadingDisplay' => 'instant',
            'defaultLanguage' => config('app.locale', 'en'),
            'disabledLanguages' => [],
            'fontFamily' => 'Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
            'fontImportUrl' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap',
            'showSidebarToggle' => true,
            'design' => [
                'colorPaletteVersion' => InterfacePalette::VERSION,
                'colors' => InterfacePalette::colors(),
                'darkColors' => InterfacePalette::colors(true),
                'adminColors' => InterfacePalette::colors(false, true),
                'adminDarkColors' => InterfacePalette::colors(true, true),
                'fontSizes' => [
                    'base' => '14px',
                    'button' => '13px',
                    'nav' => '14px',
                    'sectionTitle' => '20px',
                    'logo' => '25px',
                    'adminBase' => '13px',
                    'adminTitle' => '28px',
                ],
                'fontWeights' => [
                    'normal' => '500',
                    'medium' => '650',
                    'bold' => '800',
                    'heavy' => '900',
                ],
                'radiusScaleVersion' => 2,
                'radii' => [
                    'radiusMedia' => '8px',
                    'radius' => '10px',
                    'radiusLg' => '12px',
                ],
                'layout' => [
                    'sidebarExpanded' => '264px',
                    'sidebarCollapsed' => '0px',
                    'topbar' => '72px',
                    'adminSidebarWidth' => '258px',
                ],
            ],
            'timezone' => config('app.timezone', 'UTC'),
            'itemsPerPage' => '25',
        ];
    }
}
