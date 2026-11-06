<?php

namespace CrzBitChute;

use App\Support\YtDlp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class BitChuteProvider
{
    public static function definition(bool $enrich = true): array
    {
        return [
            'label' => 'BitChute', 'mirror_label' => 'BitChute', 'enabled_by_default' => true,
            'requires_downloader' => false, 'api_key_label' => null,
            'domains' => ['bitchute.com', 'www.bitchute.com', 'old.bitchute.com'],
            'help' => 'Import a BitChute video or embed URL. Uses the original stream when available, with the official BitChute player as fallback.',
            'id_from_url' => [self::class, 'idFromUrl'], 'embed_url' => [self::class, 'embedUrl'],
            'metadata' => static fn (string $url): array => self::metadata($url, $enrich),
        ];
    }

    public static function idFromUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! filter_var(trim($url), FILTER_VALIDATE_URL) || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ! in_array(strtolower($parts['host'] ?? ''), ['bitchute.com', 'www.bitchute.com', 'old.bitchute.com'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== (strtolower($parts['scheme']) === 'https' ? 443 : 80))) return null;

        return preg_match('~^/(?:video|embed)/([A-Za-z0-9_-]{6,80})/?$~D', $parts['path'] ?? '', $match) ? $match[1] : null;
    }

    public static function embedUrl(string $id, string $parent = ''): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{6,80}$/D', $id) ? 'https://www.bitchute.com/embed/'.$id.'/' : null;
    }

    public static function metadata(string $url, bool $enrich = true): array
    {
        $id = self::idFromUrl($url);
        if (! $id) throw new RuntimeException('Use a BitChute video or embed URL, not a channel, playlist, or search URL.');

        return Cache::remember('bitchute:metadata:v1:'.sha1($id.':'.(int) $enrich), now()->addMinutes(10), static function () use ($id, $enrich): array {
            $video = self::video($id);
            $extra = $enrich || $video === [] ? YtDlp::metadataQuietly('https://www.bitchute.com/video/'.$id.'/') : [];
            // The public video endpoint exposes metadata independently of a CDN
            // stream. Media availability is handled by the shared resolver.
            $duration = self::duration($video['duration'] ?? null);
            $description = is_string($video['description'] ?? null) ? html_entity_decode(strip_tags(preg_replace(
                '~<br\s*/?>|</p>~i', "\n", $video['description'])), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
            $api = $video === [] ? [] : YtDlp::normalize([
                'title' => $video['video_name'] ?? null, 'description' => $description,
                'thumbnail' => $video['thumbnail_url'] ?? null, 'duration' => $duration,
                'tags' => array_values(array_filter((array) ($video['hashtags'] ?? []), 'is_string')),
                'channel' => $video['channel']['channel_name'] ?? null,
            ]);

            return array_replace($extra, array_filter($api, static fn ($value): bool => $value !== null && $value !== []), [
                'provider_id' => $id,
                'title' => $api['title'] ?? $extra['title'] ?? 'BitChute video '.$id,
                'captions' => $extra['captions'] ?? [],
                'chapters' => $extra['chapters'] ?? [], 'chapters_source' => $extra['chapters_source'] ?? null,
            ]);
        });
    }

    private static function video(string $id): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(12)->withoutRedirecting()->acceptJson()
                ->withUserAgent('CrozzTube-BitChute/1.0')
                ->post('https://api.bitchute.com/api/beta/video', ['video_id' => $id]);
            $data = $response->successful() ? $response->json() : null;

            return is_array($data) && ($data['video_id'] ?? null) === $id && ! empty($data['video_name']) ? $data : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function duration(mixed $value): ?int
    {
        if (is_numeric($value)) return (float) $value > 0 ? (int) ceil((float) $value) : null;
        if (! is_string($value) || ! preg_match('/^\d+(?::\d{2}){1,2}$/D', $value)) return null;
        $parts = array_map('intval', explode(':', $value));
        if (end($parts) > 59 || (count($parts) === 3 && $parts[1] > 59)) return null;

        return array_reduce($parts, static fn (int $total, int $part): int => $total * 60 + $part, 0) ?: null;
    }
}
