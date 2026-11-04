<?php

namespace App\Services\Api\V1;

use App\Models\Video;
use App\Support\MediaStorage;
use App\Support\VideoSales;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ReceiverPlaybackService
{
    public function __construct(private readonly VideoAccessService $access) {}

    public function forVideo(Video $video, Request $request, array $input): array
    {
        $this->assertAuthorized($video, $request);
        $video->loadMissing(['channel.user', 'language']);

        $metadata = $this->metadata($video);
        $allowedOrigins = $this->allowedOrigins();
        if ($allowedOrigins === []) {
            return $this->unavailable($metadata, 'receiver_origins_not_configured');
        }

        $candidate = $this->selectSource($video, isset($input['source_id']) ? (string) $input['source_id'] : null);
        if ($candidate === null) {
            return $this->unavailable($metadata, 'no_receiver_compatible_source');
        }

        if (filled($candidate['external_url'] ?? null) && VideoSales::requiresSignedMedia($video)) {
            return $this->unavailable($metadata, 'protected_source_not_signable');
        }

        if (filled($candidate['external_url'] ?? null)
            && $this->externalUrlHasUnknownExpiry((string) $candidate['external_url'])) {
            return $this->unavailable($metadata, 'external_source_expiry_unknown');
        }

        $needsExpiringUrls = $this->needsExpiringUrls($video, $candidate);
        $expiry = null;
        if ($needsExpiringUrls) {
            $duration = $video->duration_seconds !== null ? max(0, (int) $video->duration_seconds) : null;
            if ($duration === null || $duration === 0) {
                return $this->unavailable($metadata, 'duration_required_for_expiring_receiver_url');
            }

            $position = min($duration, max(0, (int) floor((float) ($input['position_seconds'] ?? 0))));
            $remainingSeconds = max(0, $duration - $position);
            $minimumMinutes = max(5, (int) config('playhub.receiver_playback.minimum_ttl_minutes', 60));
            $maximumMinutes = max($minimumMinutes, (int) config('playhub.receiver_playback.maximum_ttl_minutes', 1440));
            $safetySeconds = max(60, (int) config('playhub.receiver_playback.safety_minutes', 15) * 60);
            $requiredSeconds = $remainingSeconds + $safetySeconds;
            if ($requiredSeconds > $maximumMinutes * 60) {
                return $this->unavailable($metadata, 'duration_exceeds_receiver_url_lifetime');
            }

            $expiry = now()->addSeconds(max($minimumMinutes * 60, $requiredSeconds));
        }

        $sourceUrl = $this->sourceUrl($video, $candidate, $expiry);
        if (! $sourceUrl || ! $this->urlUsesAllowedOrigin($sourceUrl, $allowedOrigins)) {
            return $this->unavailable($metadata, 'source_origin_not_allowed');
        }

        $caption = $this->caption($video, $expiry, $allowedOrigins);
        $poster = $this->poster($video, $expiry, $allowedOrigins);

        return [
            'receiver_session_id' => (string) Str::uuid(),
            'target' => 'google_cast',
            'castable' => true,
            'reason' => null,
            'issued_at' => now()->toIso8601String(),
            'expires_at' => $expiry?->toIso8601String(),
            'source' => [
                'id' => $candidate['id'],
                'url' => $sourceUrl,
                'mime_type' => $candidate['mime_type'],
                'stream_type' => 'buffered',
            ],
            'captions' => $caption ? [$caption] : [],
            'poster_url' => $poster,
            'metadata' => $metadata,
            'advertising' => [
                'mode' => 'none',
                'reason' => 'default_receiver_has_no_playhub_ad_integration',
            ],
        ];
    }

    private function assertAuthorized(Video $video, Request $request): void
    {
        if ($request->bearerToken() !== null) {
            abort_unless(
                $request->user()?->currentAccessToken()?->can('playback:read'),
                403,
                'This device session cannot perform this action.',
            );
        }

        $viewer = $request->user();
        $this->access->assertViewable($video, $viewer);
        abort_unless(VideoSales::hasAccess($video, $viewer), 403, 'Purchase this video to watch it.');
    }

    private function metadata(Video $video): array
    {
        return [
            'media_id' => 'video:'.$video->id,
            'video_id' => (int) $video->id,
            'content_type' => $video->isShort() ? 'short' : ($video->source_type === 'live_replay' ? 'replay' : 'video'),
            'title' => (string) $video->title,
            'subtitle' => $video->relationLoaded('channel') ? $video->channel?->name : null,
            'duration_seconds' => $video->duration_seconds !== null ? (int) $video->duration_seconds : null,
            'live' => false,
        ];
    }

    private function unavailable(array $metadata, string $reason): array
    {
        return [
            'receiver_session_id' => (string) Str::uuid(),
            'target' => 'google_cast',
            'castable' => false,
            'reason' => $reason,
            'issued_at' => now()->toIso8601String(),
            'expires_at' => null,
            'source' => null,
            'captions' => [],
            'poster_url' => null,
            'metadata' => $metadata,
            'advertising' => [
                'mode' => 'none',
                'reason' => 'default_receiver_has_no_playhub_ad_integration',
            ],
        ];
    }

    private function selectSource(Video $video, ?string $requestedId): ?array
    {
        if ($video->source_type === 'import') {
            if (strtolower((string) $video->import_provider) !== 'direct' || ! filled($video->import_url)) {
                return null;
            }

            $format = $this->formatForPath((string) parse_url((string) $video->import_url, PHP_URL_PATH));

            return $format ? [
                'id' => 'provider',
                'quality' => 'provider',
                'path' => null,
                'external_url' => (string) $video->import_url,
                'mime_type' => $this->mimeForFormat($format),
            ] : null;
        }

        $candidates = collect($video->renditions ?: [])
            ->filter(fn (mixed $rendition): bool => is_array($rendition)
                && filled($rendition['quality'] ?? null)
                && filled($rendition['path'] ?? null)
                && MediaStorage::exists((string) $rendition['path']))
            ->map(function (array $rendition): ?array {
                $format = $this->formatForPath((string) $rendition['path']);
                if ($format === null) {
                    return null;
                }

                return [
                    'id' => (string) $rendition['quality'],
                    'quality' => (string) $rendition['quality'],
                    'path' => (string) $rendition['path'],
                    'external_url' => null,
                    'mime_type' => $this->mimeForFormat($format),
                ];
            })
            ->filter()
            ->values();

        if ($candidates->isEmpty()
            && filled($video->video_path)
            && MediaStorage::exists((string) $video->video_path)) {
            $format = $this->formatForPath((string) $video->video_path);
            if ($format !== null) {
                $candidates->push([
                    'id' => 'original',
                    'quality' => null,
                    'path' => (string) $video->video_path,
                    'external_url' => null,
                    'mime_type' => $this->mimeForFormat($format),
                ]);
            }
        }

        if ($requestedId !== null && $requestedId !== '') {
            return $candidates->firstWhere('id', $requestedId);
        }

        $rank = ['high' => 40, 'medium' => 30, 'low' => 20, 'original' => 10];

        return $candidates
            ->sortByDesc(fn (array $source): int => $rank[strtolower((string) $source['id'])] ?? 0)
            ->first();
    }

    private function sourceUrl(Video $video, array $source, ?DateTimeInterface $expiry): ?string
    {
        if (filled($source['external_url'] ?? null)) {
            return (string) $source['external_url'];
        }

        if (! MediaStorage::isLocal() && filled($source['path'] ?? null) && MediaStorage::exists((string) $source['path'])) {
            return MediaStorage::temporaryStreamUrl((string) $source['path'], $expiry ?? now()->addHour());
        }

        return VideoSales::streamUrl($video, $source['quality'], $expiry);
    }

    private function caption(Video $video, ?DateTimeInterface $expiry, array $allowedOrigins): ?array
    {
        if (! filled($video->captions_path)
            || strtolower(pathinfo((string) parse_url((string) $video->captions_path, PHP_URL_PATH), PATHINFO_EXTENSION)) !== 'vtt') {
            return null;
        }

        if (preg_match('#^https://#i', (string) $video->captions_path)) {
            if (VideoSales::requiresSignedMedia($video)
                || $this->externalUrlHasUnknownExpiry((string) $video->captions_path)) {
                return null;
            }
            $url = (string) $video->captions_path;
        } elseif (! MediaStorage::exists((string) $video->captions_path)) {
            return null;
        } elseif (! MediaStorage::isLocal()) {
            $url = MediaStorage::temporaryStreamUrl((string) $video->captions_path, $expiry ?? now()->addHour());
        } else {
            $url = VideoSales::captionsUrl($video, $expiry);
        }

        if (! $url || ! $this->urlUsesAllowedOrigin($url, $allowedOrigins)) {
            return null;
        }

        return [
            'id' => 'default',
            'url' => $url,
            'mime_type' => 'text/vtt',
            'language' => $video->relationLoaded('language') ? $video->language?->code : null,
            'label' => $video->relationLoaded('language') ? ($video->language?->name ?: 'Captions') : 'Captions',
            'default' => false,
        ];
    }

    private function poster(Video $video, ?DateTimeInterface $expiry, array $allowedOrigins): ?string
    {
        if (! filled($video->thumbnail_path)) {
            return null;
        }

        if (preg_match('#^https://#i', (string) $video->thumbnail_path)) {
            if (VideoSales::requiresSignedMedia($video)
                || $this->externalUrlHasUnknownExpiry((string) $video->thumbnail_path)) {
                return null;
            }
            $url = (string) $video->thumbnail_path;
        } elseif (! MediaStorage::exists((string) $video->thumbnail_path)) {
            return null;
        } elseif (! MediaStorage::isLocal()) {
            $url = MediaStorage::temporaryStreamUrl((string) $video->thumbnail_path, $expiry ?? now()->addHour());
        } else {
            $url = VideoSales::thumbnailUrl($video, $expiry);
        }

        return $url && $this->urlUsesAllowedOrigin($url, $allowedOrigins) ? $url : null;
    }

    private function needsExpiringUrls(Video $video, array $source): bool
    {
        if (! MediaStorage::isLocal() && empty($source['external_url'])) {
            return true;
        }

        return VideoSales::requiresSignedMedia($video);
    }

    private function allowedOrigins(): array
    {
        return collect((array) config('playhub.receiver_playback.allowed_origins', []))
            ->filter(fn (mixed $origin): bool => is_string($origin))
            ->map(fn (string $origin): ?string => $this->publicHttpsOrigin($origin, requireOriginOnly: true))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function urlUsesAllowedOrigin(string $url, array $allowedOrigins): bool
    {
        $origin = $this->publicHttpsOrigin($url);

        return $origin !== null && in_array($origin, $allowedOrigins, true);
    }

    private function publicHttpsOrigin(string $value, bool $requireOriginOnly = false): ?string
    {
        $parts = parse_url(trim($value));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! filled($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }
        if ($requireOriginOnly
            && ((isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))
                || isset($parts['query'])
                || isset($parts['fragment']))) {
            return null;
        }

        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        if ($host === ''
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.invalid')) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        } elseif ($this->looksLikeLegacyIpv4Literal($host)
            || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return 'https://'.(str_contains($host, ':') ? '['.$host.']' : $host).($port === 443 ? '' : ':'.$port);
    }

    private function looksLikeLegacyIpv4Literal(string $host): bool
    {
        return preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}$/i', $host) === 1;
    }

    private function externalUrlHasUnknownExpiry(string $url): bool
    {
        $parts = parse_url($url);

        // There is no trusted provider expiry field in the current video schema.
        // Treat only query- and fragment-free external URLs as permanent.
        return ! is_array($parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts);
    }

    private function formatForPath(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp4', 'm4v' => 'mp4',
            'm3u8' => 'hls',
            'mpd' => 'dash',
            default => null,
        };
    }

    private function mimeForFormat(string $format): string
    {
        return match ($format) {
            'hls' => 'application/vnd.apple.mpegurl',
            'dash' => 'application/dash+xml',
            default => 'video/mp4',
        };
    }
}
