<?php

namespace App\Services\Api\V1;

use App\Models\Video;
use App\Services\PlaybackCapabilityService;
use App\Support\Client\ClientContext;
use App\Support\MediaSourceMetadata;
use App\Support\PlatformFeatureSettings;
use App\Support\PlayerAdSettings;
use App\Support\VideoChapters;
use App\Support\VideoSales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PlaybackSessionService
{
    public function __construct(
        private readonly VideoAccessService $access,
        private readonly ViewerStateService $viewerState,
        private readonly PlaybackCapabilityService $capabilities,
        private readonly VimeoPlaybackSourceService $vimeoPlayback,
    ) {}

    public function forVideo(Video $video, Request $request, bool $compact = false): array
    {
        $viewer = $request->user();
        $this->access->assertViewable($video, $viewer);
        abort_unless(VideoSales::hasAccess($video, $viewer), 403, 'Purchase this video to watch it.');

        $video->loadMissing(['channel.user', 'category', 'language']);
        if ($viewer && ! array_key_exists('viewer_position_seconds', $video->getAttributes())) {
            $this->viewerState->hydrateOne($video, $viewer, includeReportState: false);
        }

        $client = ClientContext::fromRequest($request);
        $sources = $this->sources($video, $client, resolveProviderDirectSources: ! $compact);
        abort_if($sources === [], 406, 'No playback source is compatible with the reported device capabilities.');
        $media = MediaSourceMetadata::forVideo($video, $sources);

        $expiresAt = collect($sources)
            ->map(fn (array $source) => $this->sourceExpiration($source))
            ->filter()
            ->min();

        $base = [
            'session_id' => (string) Str::uuid(),
            'video_id' => (int) $video->id,
            'content_type' => $video->isShort() ? 'short' : ($video->source_type === 'live_replay' ? 'replay' : 'video'),
            'provider' => $media['provider'],
            'source_type' => $media['source_type'],
            'provider_id' => $media['provider_id'],
            'provider_url' => $media['source_type'] === 'embed'
                ? MediaSourceMetadata::providerUrlForVideo($video)
                : null,
            'issued_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'sources' => $sources,
            'poster_url' => VideoSales::thumbnailUrl($video),
            'duration_seconds' => $video->duration_seconds !== null ? (int) $video->duration_seconds : null,
            'resume_position_seconds' => (int) ($video->viewer_position_seconds ?? 0),
            'restrictions' => [
                'playable' => true,
                'paid' => VideoSales::isPaid($video),
                'drm_required' => false,
                'download_allowed' => false,
                'casting_allowed' => $media['provider'] === 'native'
                    && ((bool) ($client->capabilities->features['chromecast'] ?? false)
                        || (bool) ($client->capabilities->features['airplay'] ?? false)),
            ],
        ];

        if ($compact) {
            return $base;
        }

        $caption = $this->caption($video, $client);
        $advertising = ['enabled' => false, 'resolve_url' => null, 'ads' => []];
        if ($media['provider'] === 'native' && $video->monetization_enabled && ! VideoSales::isPaid($video)) {
            $adSettings = PlayerAdSettings::publicSettings();
            $adPlacements = collect($adSettings['ads'] ?? [])
                ->filter(fn (array $ad): bool => (bool) ($ad['enabled'] ?? false))
                ->values()
                ->all();
            $advertising = [
                'enabled' => (bool) ($adSettings['enabled'] ?? false) && $adPlacements !== [],
                'sdk' => collect($adPlacements)->contains(fn (array $ad): bool => ($ad['engine'] ?? null) === 'google_ima') ? 'google_ima' : 'vast',
                'resolve_url' => route('api.v1.ads.resolve'),
                'ads' => $adPlacements,
            ];
        }

        return [
            ...$base,
            'captions' => $caption ? [$caption] : [],
            'audio_tracks' => [[
                'id' => 'default',
                'language' => $video->relationLoaded('language') ? $video->language?->code : null,
                'label' => $video->relationLoaded('language') ? $video->language?->name : null,
                'default' => true,
            ]],
            'chapters' => PlatformFeatureSettings::videoChaptersEnabled() && $video->chapters_enabled
                ? VideoChapters::forResource($video->chapters, $video->duration_seconds)
                : [],
            'advertising' => $advertising,
            'capabilities' => $this->capabilities->forClient($client->capabilities),
            'progress' => [
                'update_url' => route('api.v1.videos.progress', ['video' => $video->slug]),
                'minimum_interval_seconds' => 10,
                'completion_threshold_percent' => 95,
            ],
        ];
    }

    private function sources(Video $video, ClientContext $client, bool $resolveProviderDirectSources): array
    {
        if ($video->source_type === 'import') {
            return $this->externalSources($video, $client, $resolveProviderDirectSources);
        }

        $reported = $client->capabilities;
        $maximumHeight = $this->resolutionHeight($reported->maximumResolution);
        $sources = collect($video->renditions ?: [])
            ->filter(fn ($rendition): bool => is_array($rendition) && filled($rendition['quality'] ?? null) && filled($rendition['path'] ?? null))
            ->map(function (array $rendition) use ($video): array {
                $quality = (string) $rendition['quality'];
                $dimensions = $this->dimensions($quality, $rendition);
                $isOriginal = $quality === 'original';
                $container = $isOriginal ? $this->containerForPath((string) $rendition['path']) : 'mp4';

                return [
                    'id' => $quality,
                    'url' => VideoSales::streamUrl($video, $quality),
                    'delivery' => 'progressive',
                    'container' => $container,
                    'mime_type' => $this->mimeForContainer($container),
                    'video_codec' => $isOriginal ? null : 'h264',
                    'audio_codec' => $isOriginal ? null : 'aac',
                    'quality' => $quality,
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'bitrate' => isset($rendition['video_bitrate']) ? (string) $rendition['video_bitrate'] : null,
                ];
            })
            ->filter(fn (array $source): bool => $reported->containers === [] || $reported->supportsContainer($source['container']))
            ->filter(fn (array $source): bool => $source['video_codec'] === null
                || $reported->videoCodecs === []
                || $reported->supportsVideoCodec($source['video_codec']))
            ->filter(fn (array $source): bool => $source['audio_codec'] === null
                || $reported->audioCodecs === []
                || $reported->supportsAudioCodec($source['audio_codec']))
            ->filter(fn (array $source): bool => $maximumHeight === null || $source['height'] === null || $source['height'] <= $maximumHeight)
            ->sortBy(fn (array $source): int => (int) ($source['height'] ?? -1))
            ->values();

        if ($sources->isEmpty() && $video->video_path) {
            $container = $this->containerForPath((string) $video->video_path);
            if ($reported->containers === [] || $reported->supportsContainer($container)) {
                $sources->push([
                    'id' => 'original',
                    'url' => VideoSales::streamUrl($video),
                    'delivery' => 'progressive',
                    'container' => $container,
                    'mime_type' => $this->mimeForContainer($container),
                    'video_codec' => null,
                    'audio_codec' => null,
                    'quality' => 'original',
                    'width' => null,
                    'height' => null,
                    'bitrate' => null,
                ]);
            }
        }

        $defaultQuality = (string) data_get(PlatformFeatureSettings::settings(), 'uploads.defaultQuality', 'auto');
        $defaultId = $sources->firstWhere('quality', $defaultQuality)['id'] ?? $sources->last()['id'] ?? null;

        return $sources
            ->map(fn (array $source): array => [...$source, 'default' => $source['id'] === $defaultId])
            ->values()
            ->all();
    }

    private function externalSources(Video $video, ClientContext $client, bool $resolveProviderDirectSources): array
    {
        $provider = MediaSourceMetadata::originProvider($video);
        $importUrl = trim((string) $video->import_url);
        $scheme = strtolower((string) parse_url($importUrl, PHP_URL_SCHEME));
        $host = trim((string) parse_url($importUrl, PHP_URL_HOST));
        $hasCredentials = parse_url($importUrl, PHP_URL_USER) !== null || parse_url($importUrl, PHP_URL_PASS) !== null;
        $publicHost = ! in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)
            && ! str_ends_with(strtolower($host), '.local')
            && ! str_ends_with(strtolower($host), '.internal')
            && (! filter_var($host, FILTER_VALIDATE_IP)
                || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
        if ($provider === 'direct'
            && $scheme === 'https'
            && $host !== ''
            && $publicHost
            && ! $hasCredentials
            && filter_var($importUrl, FILTER_VALIDATE_URL)) {
            $container = $this->containerForPath((string) parse_url($importUrl, PHP_URL_PATH));
            if ($client->capabilities->containers !== []
                && ! $client->capabilities->supportsContainer($container)) {
                return [];
            }

            return [[
                'id' => 'provider',
                'url' => $importUrl,
                'delivery' => 'remote',
                'container' => $container,
                'mime_type' => $this->mimeForContainer($container),
                'video_codec' => null,
                'audio_codec' => null,
                'quality' => 'provider',
                'width' => null,
                'height' => null,
                'bitrate' => null,
                'default' => true,
            ]];
        }

        $providerId = MediaSourceMetadata::providerIdForVideo($video, $provider);
        if (! in_array($provider, ['youtube', 'vimeo', 'dailymotion', 'twitch'], true) || ! $providerId) {
            return [];
        }

        if ($provider === 'vimeo' && $resolveProviderDirectSources) {
            $directSources = $this->vimeoPlayback->forVideo($video, $client->capabilities);
            if ($directSources !== []) {
                return $directSources;
            }
        }

        return [[
            'id' => 'provider',
            'url' => null,
            'delivery' => 'provider',
            'provider' => $provider,
            'provider_video_id' => $providerId,
            'container' => null,
            'mime_type' => 'video/'.$provider,
            'video_codec' => null,
            'audio_codec' => null,
            'quality' => 'provider',
            'width' => null,
            'height' => null,
            'bitrate' => null,
            'default' => true,
        ]];
    }

    private function caption(Video $video, ClientContext $client): ?array
    {
        $url = VideoSales::captionsUrl($video);
        if (! $url) {
            return null;
        }

        $format = match (strtolower(pathinfo((string) $video->captions_path, PATHINFO_EXTENSION))) {
            'vtt' => 'webvtt',
            'srt' => 'srt',
            default => null,
        };
        if ($format === null) {
            return null;
        }

        if ($client->capabilities->subtitleFormats !== [] && ! $client->capabilities->supportsSubtitleFormat($format)) {
            return null;
        }

        return [
            'id' => 'default',
            'url' => $url,
            'format' => $format,
            'mime_type' => $this->subtitleMime($format),
            'language' => $video->caption_metadata['language'] ?? ($video->relationLoaded('language') ? $video->language?->code : null),
            'label' => $video->caption_metadata['label'] ?? ($video->relationLoaded('language') ? $video->language?->name : 'Captions'),
            'default' => (bool) ($video->caption_metadata['default'] ?? false),
        ];
    }

    private function signedExpiration(?string $url): mixed
    {
        if (! $url) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return isset($query['expires']) && ctype_digit((string) $query['expires'])
            ? now()->setTimestamp((int) $query['expires'])
            : null;
    }

    private function sourceExpiration(array $source): mixed
    {
        $expiration = trim((string) ($source['expires_at'] ?? ''));
        if ($expiration !== '') {
            try {
                $parsed = Carbon::parse($expiration);
                if ($parsed->isFuture()) {
                    return $parsed;
                }
            } catch (\Throwable) {
                // Fall through to expiration metadata embedded in the URL.
            }
        }

        return $this->signedExpiration(isset($source['url']) && is_string($source['url']) ? $source['url'] : null);
    }

    private function dimensions(string $quality, array $rendition): array
    {
        $defaults = match ($quality) {
            'low' => ['width' => 426, 'height' => 240],
            'medium' => ['width' => 854, 'height' => 480],
            'high' => ['width' => 1280, 'height' => 720],
            'original' => ['width' => null, 'height' => null],
            default => ['width' => null, 'height' => null],
        };

        return [
            'width' => isset($rendition['width']) ? (int) $rendition['width'] : $defaults['width'],
            'height' => isset($rendition['height']) ? (int) $rendition['height'] : $defaults['height'],
        ];
    }

    private function resolutionHeight(?string $resolution): ?int
    {
        return $resolution && preg_match('/^(\d+)p$/', $resolution, $matches) ? (int) $matches[1] : null;
    }

    private function containerForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'm3u8' => 'hls',
            'mpd' => 'dash',
            'webm' => 'webm',
            default => 'mp4',
        };
    }

    private function mimeForContainer(string $container): string
    {
        return match ($container) {
            'hls' => 'application/vnd.apple.mpegurl',
            'dash' => 'application/dash+xml',
            'webm' => 'video/webm',
            default => 'video/mp4',
        };
    }

    private function subtitleMime(string $format): string
    {
        return match ($format) {
            'srt' => 'application/x-subrip',
            'mov_text' => 'text/mp4',
            default => 'text/vtt',
        };
    }
}
