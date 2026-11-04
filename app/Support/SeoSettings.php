<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class SeoSettings
{
    public const SECTIONS = ['pages', 'videos', 'shorts', 'channels', 'playlists', 'live'];

    public const CHANGE_FREQUENCIES = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    public const META_ATTRIBUTES = ['name', 'property'];

    private const MANAGED_META_TAGS = [
        'name' => [
            'description', 'robots', 'keywords', 'google-site-verification', 'msvalidate.01',
            'twitter:card', 'twitter:site', 'twitter:title', 'twitter:description', 'twitter:url',
            'twitter:image', 'twitter:image:alt',
        ],
        'property' => [
            'og:site_name', 'og:locale', 'og:type', 'og:title', 'og:description', 'og:url',
            'og:image', 'og:image:url', 'og:image:secure_url', 'og:image:type', 'og:image:width',
            'og:image:height', 'og:image:alt', 'profile:username', 'article:published_time',
            'video:release_date', 'video:duration',
        ],
    ];

    public static function settings(): array
    {
        $path = self::settingsPath();
        $stored = is_file($path) && is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];

        return self::normalize(array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []));
    }

    public static function defaultSettings(): array
    {
        return self::defaults();
    }

    public static function adminSettings(): array
    {
        return self::settings();
    }

    public static function publicSettings(): array
    {
        return self::settings();
    }

    public static function save(array $settings): void
    {
        $path = self::settingsPath();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $json = json_encode(self::normalize(array_replace_recursive(self::defaults(), $settings)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to save SEO settings.');
        }
        @chmod($path, 0600);
    }

    public static function isManagedMetaTag(string $attribute, string $key): bool
    {
        return in_array(strtolower($key), self::MANAGED_META_TAGS[$attribute] ?? [], true);
    }

    public static function updatedAt(): Carbon
    {
        $modified = max(array_map(
            static fn (string $path): int => (int) @filemtime($path),
            [
                self::settingsPath(),
                base_path('VERSION'),
                base_path('build/manifest.json'),
                __FILE__,
                base_path('app/Support/SitemapData.php'),
                base_path('app/Http/Controllers/SitemapController.php'),
                base_path('app/Http/Controllers/SpaController.php'),
                base_path('resources/views/app.blade.php'),
            ],
        ));

        return Carbon::createFromTimestamp($modified ?: time());
    }

    private static function normalize(array $settings): array
    {
        foreach (['siteTitle', 'siteDescription', 'keywords', 'titleSeparator', 'canonicalBaseUrl', 'defaultImageUrl', 'twitterSite', 'googleSiteVerification', 'bingSiteVerification'] as $key) {
            $settings[$key] = trim((string) ($settings[$key] ?? ''));
        }

        $settings['titleSeparator'] = mb_substr($settings['titleSeparator'] ?: '|', 0, 5);
        $settings['appendSiteName'] = (bool) ($settings['appendSiteName'] ?? true);
        $settings['canonicalBaseUrl'] = self::normalizeBaseUrl($settings['canonicalBaseUrl']);
        if ($settings['twitterSite'] !== '' && ! str_starts_with($settings['twitterSite'], '@')) {
            $settings['twitterSite'] = '@'.$settings['twitterSite'];
        }

        $customMetaTags = is_array($settings['customMetaTags'] ?? null) ? $settings['customMetaTags'] : [];
        $settings['customMetaTags'] = collect($customMetaTags)
            ->map(static function (mixed $tag): ?array {
                if (! is_array($tag)) return null;
                $attribute = strtolower(trim((string) ($tag['attribute'] ?? 'name')));
                $key = trim((string) ($tag['key'] ?? ''));
                $content = trim(str_replace("\0", '', (string) ($tag['content'] ?? '')));
                if (! in_array($attribute, self::META_ATTRIBUTES, true)
                    || ! preg_match('/\A[A-Za-z][A-Za-z0-9._:-]{0,127}\z/D', $key)
                    || self::isManagedMetaTag($attribute, $key)
                    || $content === '') {
                    return null;
                }

                return [
                    'attribute' => $attribute,
                    'key' => $key,
                    'content' => mb_substr($content, 0, 2048),
                ];
            })
            ->filter()
            ->unique(static fn (array $tag): string => $tag['attribute'].'\0'.strtolower($tag['key']))
            ->take(50)
            ->values()
            ->all();

        $settings['indexing']['enabled'] = (bool) data_get($settings, 'indexing.enabled', true);
        $settings['sitemap']['enabled'] = (bool) data_get($settings, 'sitemap.enabled', true);
        $settings['sitemap']['urlsPerFile'] = max(100, min(50000, (int) data_get($settings, 'sitemap.urlsPerFile', 10000)));

        foreach (self::SECTIONS as $section) {
            $settings['indexing']['include'][$section] = (bool) data_get($settings, 'indexing.include.'.$section, true);
            $settings['sitemap']['include'][$section] = (bool) data_get($settings, 'sitemap.include.'.$section, true);
            $frequency = (string) data_get($settings, 'sitemap.changeFrequency.'.$section, self::defaults()['sitemap']['changeFrequency'][$section]);
            $settings['sitemap']['changeFrequency'][$section] = in_array($frequency, self::CHANGE_FREQUENCIES, true) ? $frequency : 'weekly';
            $settings['sitemap']['priority'][$section] = round(max(0, min(1, (float) data_get($settings, 'sitemap.priority.'.$section, self::defaults()['sitemap']['priority'][$section]))), 1);
        }

        $disallow = preg_split('/\R/u', (string) data_get($settings, 'robots.customDisallow', '')) ?: [];
        $settings['robots']['customDisallow'] = collect($disallow)
            ->map(fn (string $path): string => trim($path))
            ->filter(fn (string $path): bool => str_starts_with($path, '/') && ! preg_match('/[\s#]/u', $path))
            ->unique()
            ->take(200)
            ->implode("\n");

        return $settings;
    }

    private static function normalizeBaseUrl(string $url): string
    {
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) return '';
        $parts = parse_url($url);
        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return '';

        return rtrim($url, '/');
    }

    private static function settingsPath(): string
    {
        return storage_path('app/playhub-seo-settings.json');
    }

    private static function defaults(): array
    {
        return [
            'siteTitle' => '',
            'siteDescription' => '',
            'keywords' => '',
            'titleSeparator' => '|',
            'appendSiteName' => true,
            'canonicalBaseUrl' => '',
            'defaultImageUrl' => '',
            'twitterSite' => '',
            'googleSiteVerification' => '',
            'bingSiteVerification' => '',
            'customMetaTags' => [],
            'indexing' => [
                'enabled' => true,
                'include' => array_fill_keys(self::SECTIONS, true),
            ],
            'sitemap' => [
                'enabled' => true,
                'urlsPerFile' => 10000,
                'include' => array_fill_keys(self::SECTIONS, true),
                'changeFrequency' => [
                    'pages' => 'weekly',
                    'videos' => 'daily',
                    'shorts' => 'daily',
                    'channels' => 'weekly',
                    'playlists' => 'weekly',
                    'live' => 'hourly',
                ],
                'priority' => [
                    'pages' => 0.8,
                    'videos' => 0.9,
                    'shorts' => 0.8,
                    'channels' => 0.7,
                    'playlists' => 0.6,
                    'live' => 0.9,
                ],
            ],
            'robots' => [
                'customDisallow' => '',
            ],
        ];
    }
}
