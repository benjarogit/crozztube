<?php

namespace App\Support;

use App\Models\Video;

final class MediaSourceMetadata
{
    private const EXTERNAL_PROVIDERS = ['youtube', 'vimeo', 'dailymotion', 'twitch'];

    /**
     * Return the provider adapter and source format that a client should use.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array{provider: string, source_type: string, provider_id: ?string}
     */
    public static function forVideo(Video $video, array $sources = []): array
    {
        $originProvider = self::originProvider($video);

        if ($originProvider === 'direct' || ! in_array($originProvider, self::EXTERNAL_PROVIDERS, true)) {
            return [
                'provider' => 'native',
                'source_type' => self::nativeSourceType($video, $sources),
                'provider_id' => null,
            ];
        }

        $providerId = self::providerIdForVideo($video, $originProvider);

        // An authenticated provider API may return a legitimate direct media URL.
        // In that case the native adapter owns playback while provider_id preserves
        // the external record identity.
        $usesProviderAdapter = $sources === [];
        foreach ($sources as $source) {
            if (($source['delivery'] ?? null) === 'provider') {
                $usesProviderAdapter = true;
                break;
            }
        }

        return [
            'provider' => $usesProviderAdapter ? $originProvider : 'native',
            'source_type' => $usesProviderAdapter ? 'embed' : self::nativeSourceType($video, $sources),
            'provider_id' => $providerId,
        ];
    }

    public static function originProvider(Video $video): string
    {
        $stored = self::storedReference((string) $video->video_path);

        return self::normalizeProvider((string) $video->import_provider)
            ?? ($stored['provider'] ?? null)
            ?? self::providerForUrl((string) $video->import_url)
            ?? ($video->source_type === 'import' ? 'unknown' : 'native');
    }

    public static function providerIdForVideo(Video $video, ?string $provider = null): ?string
    {
        $provider ??= self::originProvider($video);
        if (! in_array($provider, self::EXTERNAL_PROVIDERS, true)) {
            return null;
        }

        $stored = self::storedReference((string) $video->video_path);
        if (($stored['provider'] ?? null) === $provider && filled($stored['id'] ?? null)) {
            return (string) $stored['id'];
        }

        return self::providerId($provider, (string) $video->import_url);
    }

    public static function providerUrlForVideo(Video $video, ?string $provider = null): ?string
    {
        $provider ??= self::originProvider($video);
        if (! in_array($provider, self::EXTERNAL_PROVIDERS, true)) {
            return null;
        }

        $url = trim((string) $video->import_url);
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || ! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || self::providerForUrl($url) !== $provider) {
            return null;
        }

        // Official provider playback is HTTPS. Preserve every other component,
        // including Vimeo's unlisted `h` query parameter or path privacy hash.
        return preg_replace('/^http:/i', 'https:', $url);
    }

    public static function providerForUrl(string $url): ?string
    {
        $host = strtolower(rtrim((string) parse_url(trim($url), PHP_URL_HOST), '.'));
        if ($host === '') {
            return null;
        }

        return match (true) {
            self::hostMatches($host, 'youtube.com'),
            self::hostMatches($host, 'youtube-nocookie.com'),
            $host === 'youtu.be' => 'youtube',
            self::hostMatches($host, 'vimeo.com') => 'vimeo',
            self::hostMatches($host, 'dailymotion.com'),
            $host === 'dai.ly' => 'dailymotion',
            self::hostMatches($host, 'twitch.tv') => 'twitch',
            default => null,
        };
    }

    public static function providerId(string $provider, string $url): ?string
    {
        $provider = strtolower(trim($provider));
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if ($provider === 'youtube') {
            $id = isset($query['v']) && is_scalar($query['v']) ? (string) $query['v'] : null;
            if (! $id && $host === 'youtu.be') {
                $id = $segments[0] ?? null;
            }
            if (! $id && in_array(strtolower((string) ($segments[0] ?? '')), ['embed', 'shorts', 'live', 'v'], true)) {
                $id = $segments[1] ?? null;
            }

            return is_string($id) && preg_match('/^[A-Za-z0-9_-]{6,}$/', $id) ? $id : null;
        }

        if ($provider === 'vimeo') {
            foreach (array_reverse($segments) as $segment) {
                if (ctype_digit($segment)) {
                    return $segment;
                }
            }

            return null;
        }

        if ($provider === 'dailymotion') {
            $id = null;
            if ($host === 'dai.ly') {
                $id = $segments[0] ?? null;
            } else {
                $videoIndex = array_search('video', array_map('strtolower', $segments), true);
                if ($videoIndex !== false) {
                    $id = $segments[$videoIndex + 1] ?? null;
                }
            }
            $id = is_string($id) ? explode('_', $id, 2)[0] : null;

            return is_string($id) && preg_match('/^[A-Za-z0-9]+$/', $id) ? $id : null;
        }

        if ($provider === 'twitch') {
            $queryVideo = isset($query['video']) && is_scalar($query['video'])
                ? preg_replace('/^v/i', '', (string) $query['video'])
                : null;
            if (is_string($queryVideo) && ctype_digit($queryVideo)) {
                return 'video:'.$queryVideo;
            }
            if (isset($query['clip']) && is_scalar($query['clip']) && preg_match('/^[A-Za-z0-9_-]+$/', (string) $query['clip'])) {
                return 'clip:'.$query['clip'];
            }
            if (isset($query['channel']) && is_scalar($query['channel']) && preg_match('/^[A-Za-z0-9_]{1,25}$/', (string) $query['channel'])) {
                return 'channel:'.strtolower((string) $query['channel']);
            }
            if (in_array(strtolower((string) ($segments[0] ?? '')), ['videos', 'v'], true)
                && isset($segments[1]) && ctype_digit($segments[1])) {
                return 'video:'.$segments[1];
            }
            if (self::hostMatches($host, 'clips.twitch.tv') && isset($segments[0]) && strtolower($segments[0]) !== 'embed'
                && preg_match('/^[A-Za-z0-9_-]+$/', $segments[0])) {
                return 'clip:'.$segments[0];
            }
            $clipIndex = array_search('clip', array_map('strtolower', $segments), true);
            if ($clipIndex !== false && isset($segments[$clipIndex + 1]) && preg_match('/^[A-Za-z0-9_-]+$/', $segments[$clipIndex + 1])) {
                return 'clip:'.$segments[$clipIndex + 1];
            }

            $channel = $segments[0] ?? null;
            $reserved = ['directory', 'downloads', 'jobs', 'p', 'settings', 'subscriptions', 'turbo', 'videos', 'wallet'];
            if (is_string($channel)
                && ! in_array(strtolower($channel), $reserved, true)
                && preg_match('/^[A-Za-z0-9_]{1,25}$/', $channel)) {
                return 'channel:'.strtolower($channel);
            }
        }

        return null;
    }

    public static function embedUrl(string $provider, ?string $providerId, string $parent): ?string
    {
        if (! $providerId) {
            return null;
        }

        $parent = strtolower(rtrim(trim($parent), '.'));

        return match ($provider) {
            'youtube' => 'https://www.youtube-nocookie.com/embed/'.rawurlencode($providerId),
            'vimeo' => 'https://player.vimeo.com/video/'.rawurlencode($providerId),
            'dailymotion' => 'https://www.dailymotion.com/embed/video/'.rawurlencode($providerId),
            'twitch' => self::twitchEmbedUrl($providerId, $parent),
            default => null,
        };
    }

    /**
     * @return array{provider: string, id: ?string}|null
     */
    private static function storedReference(string $path): ?array
    {
        if (! preg_match('/^external:([^:]+)(?::(.+))?$/i', trim($path), $matches)) {
            return null;
        }

        $provider = self::normalizeProvider($matches[1]);

        return $provider ? ['provider' => $provider, 'id' => isset($matches[2]) && $matches[2] !== '' ? $matches[2] : null] : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private static function nativeSourceType(Video $video, array $sources): string
    {
        $selected = null;
        foreach ($sources as $source) {
            if (($source['default'] ?? false) === true) {
                $selected = $source;
                break;
            }
            $selected ??= $source;
        }

        $container = strtolower(trim((string) ($selected['container'] ?? '')));
        if (in_array($container, ['mp4', 'hls', 'dash', 'webm'], true)) {
            return $container;
        }

        $mime = strtolower(trim((string) ($selected['mime_type'] ?? '')));
        if (str_contains($mime, 'mpegurl')) {
            return 'hls';
        }
        if ($mime === 'application/dash+xml') {
            return 'dash';
        }
        if ($mime === 'video/webm') {
            return 'webm';
        }

        $path = (string) ($selected['url'] ?? '');
        if ($path === '') {
            $path = $video->source_type === 'import' ? (string) $video->import_url : (string) $video->video_path;
        }

        return match (strtolower(pathinfo((string) parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'm3u8' => 'hls',
            'mpd' => 'dash',
            'webm' => 'webm',
            default => 'mp4',
        };
    }

    private static function normalizeProvider(string $provider): ?string
    {
        $provider = strtolower(trim($provider));

        return in_array($provider, [...self::EXTERNAL_PROVIDERS, 'direct'], true) ? $provider : null;
    }

    private static function hostMatches(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    private static function twitchEmbedUrl(string $providerId, string $parent): ?string
    {
        if ($parent === '') {
            return null;
        }
        if (str_starts_with($providerId, 'clip:')) {
            return 'https://clips.twitch.tv/embed?'.http_build_query([
                'clip' => substr($providerId, 5),
                'parent' => $parent,
            ]);
        }
        if (str_starts_with($providerId, 'video:')) {
            return 'https://player.twitch.tv/?'.http_build_query([
                'video' => 'v'.substr($providerId, 6),
                'parent' => $parent,
            ]);
        }
        if (str_starts_with($providerId, 'channel:')) {
            return 'https://player.twitch.tv/?'.http_build_query([
                'channel' => substr($providerId, 8),
                'parent' => $parent,
            ]);
        }

        return null;
    }
}
