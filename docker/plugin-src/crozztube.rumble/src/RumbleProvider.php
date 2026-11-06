<?php

namespace CrzRumble;

use App\Support\YtDlp;
use App\Support\VideoCaptions;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class RumbleProvider
{
    public static function definition(bool $enrich = true): array
    {
        return [
            'label' => 'Rumble',
            'mirror_label' => 'Rumble',
            'enabled_by_default' => true,
            'requires_downloader' => false,
            'api_key_label' => null,
            'domains' => ['rumble.com', 'www.rumble.com'],
            'help' => 'Import a Rumble video or embed URL. Playback uses the original stream when available, with the official Rumble player as fallback.',
            'id_from_url' => [self::class, 'idFromUrl'],
            'embed_url' => [self::class, 'embedUrl'],
            'metadata' => static fn (string $url): array => self::metadata($url, $enrich),
        ];
    }

    /** Watch-page IDs and player IDs differ; only embed URLs reveal a player ID. */
    public static function idFromUrl(string $url): ?string
    {
        if (! self::validOrigin($url)) {
            return null;
        }

        return preg_match('~^/embed/(?:[a-z0-9]+\.)?([a-z0-9]+)/?$~i', (string) parse_url($url, PHP_URL_PATH), $match)
            ? $match[1] : null;
    }

    public static function embedUrl(string $id, string $parent = ''): ?string
    {
        return preg_match('/^[a-z0-9]+$/i', $id) ? 'https://rumble.com/embed/'.$id.'/' : null;
    }

    public static function metadata(string $url, bool $enrich = true): array
    {
        $url = self::canonicalUrl($url);

        return Cache::remember('rumble:metadata:v2:'.sha1($url.':'.(int) $enrich), now()->addMinutes(10), static function () use ($url, $enrich): array {
            $oembed = self::oembed($url);
            $page = self::idFromUrl($url) ? [] : self::pageMetadata($url);
            $embedId = self::idFromHtml((string) ($oembed['html'] ?? '')) ?? ($page['provider_id'] ?? null) ?? self::idFromUrl($url);
            $player = $embedId ? self::playerMetadata($embedId) : [];
            $needsEnrichment = empty($page['description']) || empty($page['tags']) || empty($player['captions']);
            $metadata = ($enrich && $needsEnrichment) || ! $embedId ? YtDlp::metadataQuietly($url) : [];
            $extractedId = $metadata['provider_id'] ?? null;
            if (! str_starts_with(strtolower((string) ($metadata['extractor'] ?? '')), 'rumble')
                || ! is_string($extractedId) || ! preg_match('/^[a-z0-9]+$/i', $extractedId)) {
                $extractedId = null;
            }
            $id = $embedId ?? $extractedId ?? self::idFromUrl($url);
            if (! $id) {
                throw new RuntimeException('Rumble video metadata is currently unavailable. Check the video URL or try its Rumble embed URL.');
            }

            $fallback = [
                'provider_id' => $id,
                'title' => self::text($oembed['title'] ?? null, 160),
                'author_name' => self::text($oembed['author_name'] ?? null, 255),
                'thumbnail_url' => self::text($oembed['thumbnail_url'] ?? null, 60000),
                'duration_seconds' => self::positiveInt($oembed['duration'] ?? null),
                'width' => self::positiveInt($oembed['width'] ?? null),
                'height' => self::positiveInt($oembed['height'] ?? null),
            ];

            $result = array_replace($metadata, $player, $page, array_filter($fallback, static fn ($value): bool => $value !== null));
            $result['tags'] = array_values(array_slice(array_unique(array_merge($page['tags'] ?? [], $metadata['tags'] ?? [])), 0, 10));
            $result['captions'] = array_values(array_merge($player['captions'] ?? [], $metadata['captions'] ?? []));

            return $result;
        });
    }

    private static function validOrigin(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && in_array(strtolower($parts['host'] ?? ''), ['rumble.com', 'www.rumble.com'], true)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === (strtolower($parts['scheme']) === 'https' ? 443 : 80));
    }

    private static function canonicalUrl(string $url): string
    {
        $url = trim($url);
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (! self::validOrigin($url)
            || (! self::idFromUrl($url) && ! preg_match('~^/v(?!ideos)[a-z0-9]+(?:-[^/\s]+)?(?:\.html)?/?$~i', $path))) {
            throw new RuntimeException('Use a Rumble video page or embed URL, not a channel, search, or playlist URL.');
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);

        parse_str($query, $parameters);
        unset($parameters['e9s'], $parameters['pri']);

        return 'https://rumble.com'.$path.($parameters !== [] ? '?'.http_build_query($parameters) : '');
    }

    private static function oembed(string $url): array
    {
        try {
            // Fixed HTTPS origin, no redirects or user-supplied endpoint.
            $response = Http::connectTimeout(5)->timeout(12)->withoutRedirecting()
                ->acceptJson()->withUserAgent('CrozzTube-Rumble/1.0')
                ->get('https://rumble.com/api/Media/oembed.json', ['url' => $url]);
            $data = $response->successful() ? $response->json() : null;

            return is_array($data) && ($data['type'] ?? null) === 'video' ? $data : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function idFromHtml(string $html): ?string
    {
        if (! preg_match('~<iframe\b[^>]*\bsrc\s*=\s*([\'\"])(.*?)\1~is', $html, $match)) {
            return null;
        }

        return self::idFromUrl(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function playerMetadata(string $id): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()->acceptJson()
                ->withUserAgent('CrozzTube-Rumble/1.1')->get('https://rumble.com/embedJS/u3/', ['request' => 'video', 'ver' => 2, 'v' => $id]);
            $data = $response->successful() ? $response->json() : null;
            if (! is_array($data)) return [];
            $subtitles = $automatic = [];
            foreach ((array) ($data['cc'] ?? []) as $language => $caption) {
                if (! is_array($caption) || ! is_string($caption['path'] ?? null)) continue;
                $format = ['url' => $caption['path'], 'name' => $caption['language'] ?? $language, 'ext' => 'vtt'];
                if (str_contains($language, 'auto')) $automatic[$language] = [$format];
                else $subtitles[$language] = [$format];
            }

            return ['captions' => VideoCaptions::candidates(['subtitles' => $subtitles, 'automatic_captions' => $automatic])];
        } catch (Throwable) {
            return [];
        }
    }

    private static function pageMetadata(string $url): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(12)->withUserAgent('CrozzTube-Rumble/1.1')
                ->withOptions(['version' => 2.0, 'cookies' => new CookieJar, 'allow_redirects' => [
                    'max' => 3, 'protocols' => ['https'],
                    'on_redirect' => static function ($request, $response, $next): void {
                        if (! self::validOrigin((string) $next)) throw new RuntimeException('Invalid Rumble redirect.');
                    },
                ]])->get($url);
            if (! $response->successful() || strlen($response->body()) > 2 * 1024 * 1024) return [];
            return self::parsePage($response->body());
        } catch (Throwable) {
            return [];
        }
    }

    public static function parsePage(string $html): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $paragraphs = [];
        foreach ($xpath->query('//p[contains(concat(" ", normalize-space(@class), " "), " media-description ")]') as $paragraph) {
            $text = self::text(preg_replace('~<br\s*/?>~i', "\n", $document->saveHTML($paragraph)), 10000);
            if ($text) $paragraphs[] = $text;
        }
        $tags = [];
        foreach ($xpath->query('//meta[@name="keywords"]/@content | //div[contains(@class,"media-description-tags-container")]//a') as $tag) {
            foreach (explode(',', $tag->textContent) as $value) {
                $value = self::text($value, 100);
                if ($value) $tags[] = $value;
            }
        }
        $description = $paragraphs !== [] ? mb_substr(implode("\n\n", $paragraphs), 0, 10000) : null;
        if (! $description) {
            $node = $xpath->query('//meta[@property="og:description" or @name="description"]/@content')->item(0);
            $description = $node ? self::text($node->textContent, 10000) : null;
        }
        $id = self::idFromHtml($html);
        if (! $id && preg_match('~\bRumble\(\s*["\']play["\']\s*,\s*\{[^}]*["\']?video["\']?\s*:\s*["\']([a-z0-9]+)["\']~i', $html, $match)) $id = $match[1];

        return array_filter(['description' => $description, 'tags' => $tags !== [] ? array_values(array_unique($tags)) : null, 'provider_id' => $id], static fn ($value): bool => $value !== null);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (float) $value > 0 ? (int) ceil((float) $value) : null;
    }
}
