<?php

namespace App\Services\Api\V1;

use App\Models\Video;
use App\Support\Client\ClientCapabilities;
use App\Support\MediaSourceMetadata;
use App\Support\VideoImportSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class VimeoPlaybackSourceService
{
    /**
     * Resolve Vimeo's authenticated, temporary `play` links when the site owns
     * or can access them. Missing entitlement or API failure safely falls back
     * to the official embedded Vimeo provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forVideo(Video $video, ClientCapabilities $client): array
    {
        if (MediaSourceMetadata::originProvider($video) !== 'vimeo') {
            return [];
        }

        $providerId = MediaSourceMetadata::providerIdForVideo($video, 'vimeo');
        $token = trim((string) data_get(VideoImportSettings::settings(), 'providers.vimeo.apiKey', ''));
        if (! $providerId || $token === '') {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.vimeo.*+json;version=3.4',
            ])
                ->withToken($token)
                ->connectTimeout(5)
                ->timeout(10)
                ->get('https://api.vimeo.com/videos/'.rawurlencode($providerId), [
                    'fields' => 'play',
                ]);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return $this->fromPlayPayload((array) $response->json('play'), $client);
    }

    /**
     * @param  array<string, mixed>  $play
     * @return array<int, array<string, mixed>>
     */
    public function fromPlayPayload(array $play, ClientCapabilities $client): array
    {
        if (isset($play['status']) && strtolower((string) $play['status']) !== 'playable') {
            return [];
        }

        $sources = [];
        if ($client->containers === [] || $client->supportsContainer('mp4')) {
            foreach ((array) ($play['progressive'] ?? []) as $index => $rendition) {
                if (! is_array($rendition) || ! $this->isHttpsUrl($rendition['link'] ?? null)) {
                    continue;
                }

                $expiresAt = $this->expiration($rendition, (string) $rendition['link'])
                    ?? now()->addMinutes(15)->toIso8601String();
                if ($this->isExpired($expiresAt)) {
                    continue;
                }

                $height = isset($rendition['height']) && is_numeric($rendition['height']) ? (int) $rendition['height'] : null;
                $quality = trim((string) ($rendition['quality'] ?? $rendition['rendition'] ?? '')) ?: ($height ? $height.'p' : 'provider');
                $sources[] = $this->source(
                    id: 'vimeo-progressive-'.($height ?: $index),
                    url: (string) $rendition['link'],
                    container: 'mp4',
                    mimeType: 'video/mp4',
                    quality: $quality,
                    width: isset($rendition['width']) && is_numeric($rendition['width']) ? (int) $rendition['width'] : null,
                    height: $height,
                    bitrate: isset($rendition['bitrate']) && is_scalar($rendition['bitrate']) ? (string) $rendition['bitrate'] : null,
                    expiresAt: $expiresAt,
                );
            }
        }

        foreach ([
            'hls' => 'application/vnd.apple.mpegurl',
            'dash' => 'application/dash+xml',
        ] as $container => $mimeType) {
            $adaptive = $play[$container] ?? null;
            if (! is_array($adaptive)
                || ($client->containers !== [] && ! $client->supportsContainer($container))
                || ! $this->isHttpsUrl($adaptive['link'] ?? null)) {
                continue;
            }

            $expiresAt = $this->expiration($adaptive, (string) $adaptive['link'])
                ?? now()->addMinutes(15)->toIso8601String();
            if ($this->isExpired($expiresAt)) {
                continue;
            }

            $sources[] = $this->source(
                id: 'vimeo-'.$container,
                url: (string) $adaptive['link'],
                container: $container,
                mimeType: $mimeType,
                quality: 'auto',
                expiresAt: $expiresAt,
            );
        }

        usort($sources, static function (array $left, array $right): int {
            $rank = ['mp4' => 10, 'dash' => 20, 'hls' => 30];
            $containerRank = ($rank[$left['container']] ?? 0) <=> ($rank[$right['container']] ?? 0);

            return $containerRank !== 0 ? $containerRank : ((int) ($left['height'] ?? 0) <=> (int) ($right['height'] ?? 0));
        });

        if ($sources !== []) {
            $sources[array_key_last($sources)]['default'] = true;
        }

        return $sources;
    }

    /**
     * @return array<string, mixed>
     */
    private function source(
        string $id,
        string $url,
        string $container,
        string $mimeType,
        string $quality,
        ?int $width = null,
        ?int $height = null,
        ?string $bitrate = null,
        ?string $expiresAt = null,
    ): array {
        return [
            'id' => $id,
            'url' => $url,
            'delivery' => 'remote',
            'container' => $container,
            'mime_type' => $mimeType,
            'video_codec' => null,
            'audio_codec' => null,
            'quality' => $quality,
            'width' => $width,
            'height' => $height,
            'bitrate' => $bitrate,
            'expires_at' => $expiresAt,
            'default' => false,
        ];
    }

    private function expiration(array $source, string $url): ?string
    {
        foreach (['link_expiration_time', 'link_expiration', 'expires_at'] as $key) {
            if (isset($source[$key]) && is_scalar($source[$key]) && trim((string) $source[$key]) !== '') {
                return $this->normalizeExpiration((string) $source[$key]);
            }
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (isset($query['expires']) && is_scalar($query['expires'])) {
            return $this->normalizeExpiration((string) $query['expires']);
        }

        return null;
    }

    private function normalizeExpiration(string $value): ?string
    {
        try {
            $expiration = ctype_digit($value)
                ? Carbon::createFromTimestampUTC((int) $value)
                : Carbon::parse($value);

            return $expiration->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isExpired(?string $expiresAt): bool
    {
        return $expiresAt !== null && Carbon::parse($expiresAt)->lessThanOrEqualTo(now()->addSeconds(30));
    }

    private function isHttpsUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && filled($parts['host'] ?? null)
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
