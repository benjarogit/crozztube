<?php

namespace CrzAuf1;

use App\Support\VideoChapters;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Uses only data published by AUF1's own website; the internal API is undocumented. */
final class Auf1Provider
{
    private const UUID = '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}';

    private const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    public static function definition(): array
    {
        return [
            'label' => 'AUF1', 'mirror_label' => 'AUF1', 'enabled_by_default' => true,
            'requires_downloader' => false, 'api_key_label' => null,
            'domains' => ['auf1.tv', 'www.auf1.tv'],
            'help' => 'Import an AUF1 video page. Uses the published original stream when available, with the official player as fallback.',
            'id_from_url' => [self::class, 'idFromUrl'],
            'embed_url' => [self::class, 'embedUrl'],
            'metadata' => [self::class, 'metadata'],
            'source_candidates' => [self::class, 'sourceCandidates'],
        ];
    }

    /** Slugs and hosting UUIDs are not the stable numeric AUF1 content identity. */
    public static function idFromUrl(string $url): ?string
    {
        return null;
    }

    public static function metadata(string $url): array
    {
        return self::content($url)['metadata'];
    }

    public static function sourceCandidates(string $url): array
    {
        $content = self::content($url);

        return ['playback_candidates' => $content['playback_candidates'], 'metadata' => $content['metadata']];
    }

    /** The source URL supplies the slug; never manufacture an embed from a content ID. */
    public static function embedUrl(string $id, string $parent = '', ?string $sourceUrl = null): ?string
    {
        if (! preg_match('/^[1-9][0-9]{0,17}$/D', $id) || $sourceUrl === null) return null;
        try {
            $content = self::content($sourceUrl);

            return $content['metadata']['provider_id'] === $id ? $content['embed_url'] : null;
        } catch (Throwable) {
            // Schema changes or an unavailable API leave the original page link usable.
            return null;
        }
    }

    public static function canonicalUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! self::origin($url, ['auf1.tv', 'www.auf1.tv'], false)
            || ! preg_match('~^/([a-z0-9]+(?:-[a-z0-9]+)*)/([a-z0-9]+(?:-[a-z0-9]+)*)/?$~D', $parts['path'] ?? '', $match)
            || strlen($match[1]) > 120 || strlen($match[2]) > 220
            || in_array($match[1], ['api', 'eilt', 'suche', 'sendungen', 'storage', 'embed', 'live', 'radio'], true)) {
            throw new RuntimeException('Use an AUF1 video page URL, not a channel, search, news post, radio, or live URL.');
        }

        return 'https://auf1.tv/'.$match[1].'/'.$match[2].'/';
    }

    private static function content(string $url): array
    {
        $url = self::canonicalUrl($url);

        return Cache::remember('auf1:content:v1:'.sha1($url), now()->addMinutes(10), static function () use ($url): array {
            $segments = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));
            try {
                $response = Http::connectTimeout(5)->timeout(12)->withoutRedirecting()->acceptJson()
                    ->withHeaders(['Accept-Encoding' => 'identity'])->withOptions([
                        // Avoid unbounded decompression before either byte limit can run.
                        'decode_content' => false,
                        'on_headers' => static function (\Psr\Http\Message\ResponseInterface $response): void {
                            $length = $response->getHeaderLine('Content-Length');
                            if (ctype_digit($length) && (float) $length > self::MAX_RESPONSE_BYTES) {
                                throw new RuntimeException('AUF1 video metadata is currently unavailable. Try again later.');
                            }
                        },
                        'progress' => static function ($total, $downloaded, $uploadTotal, $uploaded): void {
                            // Also abort chunked responses and misleading/missing Content-Length.
                            if ($total > self::MAX_RESPONSE_BYTES || $downloaded > self::MAX_RESPONSE_BYTES) {
                                throw new RuntimeException('AUF1 video metadata is currently unavailable. Try again later.');
                            }
                        },
                    ])
                    ->withUserAgent('CrozzTube-AUF1/1.0')->get('https://auf1.tv/api/getContent/'.$segments[1]);
                if (! $response->successful() || strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('AUF1 video metadata is currently unavailable. Try again later.');
                }

                return self::parseContent($response->json(), $segments[0], $segments[1]);
            } catch (RuntimeException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new RuntimeException('AUF1 video metadata is currently unavailable. Try again later.');
            }
        });
    }

    /** Fail closed on a changed schema instead of importing an unrelated page or guessed identity. */
    public static function parseContent(mixed $data, string $show, string $slug): array
    {
        $video = is_array($data) ? ($data['videoData'] ?? null) : null;
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if (! is_array($data) || ! is_array($video) || ! is_scalar($id)
            || ! preg_match('/^[1-9][0-9]{0,17}$/D', (string) $id)
            || ($data['public_id'] ?? null) !== $slug || ($data['show']['public_id'] ?? null) !== $show
            || ! is_string($data['title'] ?? null) || trim($data['title']) === ''
            || ! array_key_exists('is_live', $data) || ! in_array($data['is_live'], [false, 0, '0'], true)
            || ! array_key_exists('is_live', $video) || ! in_array($video['is_live'], [false, 0, '0'], true)
            || ! is_array($video['urls'] ?? null) || ! is_array($video['embeddableUrls'] ?? null)) {
            throw new RuntimeException('AUF1 video metadata could not be verified. Use an available recorded video page.');
        }

        $urls = $video['urls'];
        $embeds = $video['embeddableUrls'];
        $peertube = self::embed($embeds['peertube'] ?? null, 'peertube');
        $bunny = self::embed($embeds['bunny'] ?? null, 'bunny');
        $candidates = [];
        foreach (is_array($urls['peertube'] ?? null) ? $urls['peertube'] : [] as $source) {
            $src = is_array($source) ? ($source['src'] ?? null) : null;
            if ($peertube && self::media($src, $peertube, 'peertube', 'hls')) $candidates[] = $src;
        }
        $src = is_array($urls['bunny'] ?? null) ? ($urls['bunny']['src'] ?? null) : null;
        if ($bunny && self::media($src, $bunny, 'bunny', 'hls')) $candidates[] = $src;

        // MP4s with an explicit video resolution are fallback candidates, never the Audio download.
        $downloads = [];
        foreach (['peertube', 'bunny'] as $backend) {
            $embed = $backend === 'peertube' ? $peertube : $bunny;
            foreach (is_array($urls[$backend.'_download'] ?? null) ? $urls[$backend.'_download'] : [] as $source) {
                if (! is_array($source) || ! preg_match('/^(360|480|720|1080|1440|2160)p$/D', (string) ($source['label'] ?? ''))) continue;
                if ($embed && self::media($source['src'] ?? null, $embed, $backend, 'mp4')) {
                    $downloads[] = ['url' => $source['src'], 'height' => (int) $source['label']];
                }
            }
        }
        usort($downloads, static fn (array $a, array $b): int => $b['height'] <=> $a['height']);
        if ($downloads !== []) $candidates[] = $downloads[0]['url'];
        $candidates = array_values(array_unique($candidates));
        $embed = $peertube ?? $bunny;
        if ($candidates === [] && $embed === null) {
            throw new RuntimeException('AUF1 video playback is currently unavailable. Try again later.');
        }

        $description = self::text($data['text'] ?? null, 10000) ?? self::text($data['preview_text'] ?? null, 10000);
        $additional = self::text($data['additional_text'] ?? null, 10000);
        if ($additional) $description = mb_substr(trim(($description ?? '')."\n\n".$additional), 0, 10000);
        $duration = self::duration($data['duration'] ?? null);
        $chapters = [];
        foreach (is_array($video['chapters'] ?? null) ? $video['chapters'] : [] as $start => $title) {
            if (! ctype_digit((string) $start) || ! is_string($title)) continue;
            $chapters[] = ['start_time' => (int) $start, 'title' => $title];
        }
        $tags = [];
        foreach (is_array($data['tags'] ?? null) ? $data['tags'] : [] as $tag) {
            $value = self::text(is_array($tag) ? ($tag['name'] ?? null) : $tag, 100);
            if ($value !== null) $tags[mb_strtolower($value)] ??= $value;
        }
        $thumbnail = $data['thumbnail_url'] ?? $video['thumbnail'] ?? null;
        if (! self::origin($thumbnail, ['auf1.tv', 'www.auf1.tv']) || ! str_starts_with((string) parse_url((string) $thumbnail, PHP_URL_PATH), '/storage/')) $thumbnail = null;

        return [
            'metadata' => [
                'provider_id' => (string) $id, 'title' => self::text($data['title'], 160),
                'description' => $description, 'tags' => array_slice(array_values($tags), 0, 10),
                'thumbnail_url' => $thumbnail, 'duration_seconds' => $duration,
                'author_name' => 'AUF1', 'captions' => [], 'language_code' => null, 'original_language_code' => null,
                ...VideoChapters::discover($chapters, $description, $duration, 'provider'),
            ],
            'playback_candidates' => array_slice($candidates, 0, 3), 'embed_url' => $embed,
        ];
    }

    private static function embed(mixed $url, string $backend): ?string
    {
        $hosts = $backend === 'peertube' ? ['gegenstimme.tv'] : ['iframe.mediadelivery.net'];
        if (! self::origin($url, $hosts)) return null;
        $pattern = $backend === 'peertube' ? '~^/videos/embed/'.self::UUID.'/?$~D' : '~^/(?:play|embed)/171363/'.self::UUID.'/?$~D';
        if (! preg_match($pattern, (string) parse_url($url, PHP_URL_PATH))) return null;

        // Only approved controls are included, never arbitrary upstream query parameters.
        return 'https://'.parse_url($url, PHP_URL_HOST).rtrim(parse_url($url, PHP_URL_PATH), '/')
            .($backend === 'peertube' ? '?title=0&warningTitle=0&peertubeLink=0' : '?autoplay=false&loop=false&muted=false');
    }

    private static function media(mixed $url, string $embed, string $backend, string $kind): bool
    {
        $hosts = $backend === 'peertube' ? ['gegenstimme.tv'] : ['vz-0b49e78d-475.b-cdn.net'];
        if (! self::origin($url, $hosts)) return false;
        $path = (string) parse_url($url, PHP_URL_PATH);
        $uuid = basename((string) parse_url($embed, PHP_URL_PATH));
        $pattern = match (true) {
            $backend === 'peertube' && $kind === 'hls' => '~^/static/streaming-playlists/hls/'.preg_quote($uuid, '~').'/'.self::UUID.'-master\.m3u8$~D',
            $backend === 'peertube' => '~^/download/streaming-playlists/hls/videos/'.preg_quote($uuid, '~').'-(?:360|480|720|1080|1440|2160)-fragmented\.mp4$~D',
            $kind === 'hls' => '~^/'.preg_quote($uuid, '~').'/playlist\.m3u8$~D',
            default => '~^/'.preg_quote($uuid, '~').'/play_(?:360|480|720|1080|1440|2160)p\.mp4$~D',
        };

        return (bool) preg_match($pattern, $path);
    }

    private static function origin(mixed $url, array $hosts, bool $httpsOnly = true): bool
    {
        if (! is_string($url) || strlen($url) > 4000 || ! filter_var(trim($url), FILTER_VALIDATE_URL)) return false;
        $parts = parse_url(trim($url));
        $scheme = strtolower($parts['scheme'] ?? '');

        return in_array($scheme, $httpsOnly ? ['https'] : ['http', 'https'], true)
            && in_array(strtolower($parts['host'] ?? ''), $hosts, true)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === ($scheme === 'https' ? 443 : 80));
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) return null;
        $value = preg_replace('~<br\s*/?>|</p>|</div>~i', "\n", $value);
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    private static function duration(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(?:([0-9]{1,3}):)?([0-9]{1,3}):([0-5][0-9])$/D', $value, $match)) return null;
        if ($match[1] !== '' && (int) $match[2] > 59) return null;
        $seconds = (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3];

        return $seconds > 0 ? $seconds : null;
    }
}
