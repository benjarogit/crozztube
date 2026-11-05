<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class VideoImportSettings
{
    public static function settings(): array
    {
        $path = self::settingsPath();
        $stored = is_file($path) && is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];

        return array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []);
    }

    public static function adminSettings(): array
    {
        $settings = self::settings();
        $settings['provider_definitions'] = self::publicSettings()['providers'];
        foreach (array_keys(self::providers()) as $provider) {
            if (Arr::get($settings, "providers.{$provider}.apiKey")) {
                Arr::set($settings, "providers.{$provider}.apiKey", '');
            }
        }

        return $settings;
    }

    public static function publicSettings(): array
    {
        $settings = self::settings();

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'providers' => collect(self::providers())->map(function (array $provider, string $key) use ($settings): array {
                return [
                    'key' => $key,
                    'label' => $provider['label'],
                    'enabled' => (bool) Arr::get($settings, "providers.{$key}.enabled", false),
                    'requires_downloader' => (bool) ($provider['requires_downloader'] ?? false),
                    'api_key_label' => $provider['api_key_label'] ?? null,
                    'domains' => $provider['domains'] ?? [],
                    'help' => $provider['help'] ?? null,
                ];
            })->values()->all(),
        ];
    }

    public static function providers(): array
    {
        return [
            'direct' => [
                'label' => 'Video URL (direct or yt-dlp)',
                'mirror_label' => 'Original',
                'requires_downloader' => true,
                'api_key_label' => null,
                'domains' => [],
                'help' => 'Accepts a direct video file (MP4/WebM) or any site supported by yt-dlp. Title, description, tags and thumbnail are read with yt-dlp; playback stays at the original host.',
            ],
            'youtube' => [
                'label' => 'YouTube',
                'requires_downloader' => false,
                'api_key_label' => 'YouTube Data API key',
                'domains' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com'],
                'help' => 'Fetches YouTube details and plays with the YouTube embed player.',
            ],
            'vimeo' => [
                'label' => 'Vimeo',
                'requires_downloader' => false,
                'api_key_label' => 'Vimeo access token',
                'domains' => ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'],
                'help' => 'Fetches Vimeo details and plays with the Vimeo embed player.',
            ],
            'dailymotion' => [
                'label' => 'Dailymotion',
                'requires_downloader' => false,
                'api_key_label' => 'Dailymotion API key',
                'domains' => ['dailymotion.com', 'www.dailymotion.com', 'dai.ly'],
                'help' => 'Fetches Dailymotion details and plays with the Dailymotion embed player.',
            ],
            'twitch' => [
                'label' => 'Twitch',
                'requires_downloader' => false,
                'api_key_label' => 'Twitch Client ID|App access token',
                'domains' => ['twitch.tv', 'www.twitch.tv', 'm.twitch.tv', 'clips.twitch.tv', 'www.clips.twitch.tv'],
                'help' => 'Imports Twitch live channels, videos, and clips and plays them with the Twitch embed player.',
            ],
        ];
    }

    public static function save(array $settings): void
    {
        $settings = array_replace_recursive(self::defaults(), $settings);
        $path = self::settingsPath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    /**
     * Binary used for metadata extraction (imports) and by the source resolver.
     * One setting so both paths always use the same yt-dlp build.
     */
    public static function downloaderCommand(): string
    {
        $configured = trim((string) (self::settings()['downloaderCommand'] ?? ''));

        return $configured !== '' ? $configured : 'yt-dlp';
    }

    public static function providerEnabled(string $provider): bool
    {
        $settings = self::settings();

        return (bool) ($settings['enabled'] ?? false)
            && array_key_exists($provider, self::providers())
            && (bool) Arr::get($settings, "providers.{$provider}.enabled", false);
    }

    /**
     * Avoid a cross-origin www redirect for a direct import hosted by this site.
     * This is a playback-only value: the customer's original import stays intact.
     * External hosts, other subdomains and non-site ports must not be rewritten.
     */
    public static function directPlaybackUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $source = parse_url($url);
        $canonical = parse_url((string) config('app.url'));
        if (! is_array($source) || ! is_array($canonical)
            || ! in_array(strtolower($source['scheme'] ?? ''), ['http', 'https'], true)
            || ! in_array(strtolower($canonical['scheme'] ?? ''), ['http', 'https'], true)
            || empty($source['host']) || empty($canonical['host'])
            || isset($source['user']) || isset($source['pass'])
            || isset($canonical['user']) || isset($canonical['pass'])) {
            return $url;
        }

        $host = strtolower($source['host']);
        $canonicalHost = strtolower($canonical['host']);
        $siteHost = preg_replace('/^www\./', '', $canonicalHost);
        if ($host !== $siteHost && $host !== 'www.'.$siteHost) {
            return $url;
        }

        $sourceDefaultPort = strtolower($source['scheme']) === 'https' ? 443 : 80;
        $canonicalPort = $canonical['port'] ?? (strtolower($canonical['scheme']) === 'https' ? 443 : 80);
        if (isset($source['port']) && $source['port'] !== $sourceDefaultPort && $source['port'] !== $canonicalPort) {
            return $url;
        }

        $origin = strtolower($canonical['scheme']).'://'.$canonicalHost
            .(isset($canonical['port']) ? ':'.$canonical['port'] : '');

        return preg_replace('~^https?://[^/?#]+~i', $origin, $url, 1) ?? $url;
    }

    public static function youtubeVideoIsShort(string $videoId): ?bool
    {
        $videoId = trim($videoId);
        if (! preg_match('/^[A-Za-z0-9_-]{6,}$/', $videoId)) {
            return null;
        }

        $cacheKey = 'playhub:youtube:is-short:'.$videoId;
        if (Cache::has($cacheKey)) {
            return (bool) Cache::get($cacheKey);
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->withoutRedirecting()
                ->withUserAgent('Mozilla/5.0')
                ->withHeaders(['Cookie' => 'SOCS=CAI'])
                ->head('https://www.youtube.com/shorts/'.rawurlencode($videoId));
        } catch (\Throwable) {
            return null;
        }

        $isShort = $response->successful() ? true : null;
        if ($response->redirect()) {
            $location = (string) $response->header('Location');
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
            if (trim((string) parse_url($location, PHP_URL_PATH), '/') === 'watch' && ($query['v'] ?? null) === $videoId) {
                $isShort = false;
            }
        }

        if ($isShort !== null) {
            Cache::put($cacheKey, $isShort, now()->addHours(6));
        }

        return $isShort;
    }

    private static function settingsPath(): string
    {
        return storage_path('app/playhub-video-import.json');
    }

    private static function defaults(): array
    {
        return [
            'enabled' => true,
            'downloaderCommand' => 'yt-dlp',
            'providers' => [
                'direct' => ['enabled' => true, 'apiKey' => ''],
                'youtube' => ['enabled' => false, 'apiKey' => ''],
                'vimeo' => ['enabled' => false, 'apiKey' => ''],
                'dailymotion' => ['enabled' => false, 'apiKey' => ''],
                'twitch' => ['enabled' => true, 'apiKey' => ''],
            ],
        ];
    }
}
