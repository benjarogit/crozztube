<?php

namespace App\Support\Client;

use JsonException;

final readonly class ClientCapabilities
{
    private const MAX_HEADER_BYTES = 4096;

    private const VIDEO_CODECS = ['h264', 'h265', 'hevc', 'vp9', 'av1'];

    private const AUDIO_CODECS = ['aac', 'ac3', 'eac3', 'opus', 'mp3'];

    private const CONTAINERS = ['mp4', 'hls', 'dash', 'webm'];

    private const HDR_MODES = ['sdr', 'hdr10', 'hdr10+', 'hlg', 'dolby-vision'];

    private const DRM_SYSTEMS = ['widevine', 'fairplay', 'playready'];

    private const SUBTITLE_FORMATS = ['mov_text', 'srt', 'webvtt', 'vtt', 'ttml'];

    private const RESOLUTIONS = ['480p', '720p', '1080p', '1440p', '2160p'];

    private const FEATURE_FLAGS = [
        'airplay',
        'background_audio',
        'chromecast',
        'offline_downloads',
        'picture_in_picture',
        'remote_control',
        'touch',
    ];

    /**
     * @param  list<string>  $videoCodecs
     * @param  list<string>  $audioCodecs
     * @param  list<string>  $containers
     * @param  list<string>  $hdrModes
     * @param  list<string>  $drmSystems
     * @param  list<string>  $subtitleFormats
     * @param  array<string, bool>  $features
     */
    public function __construct(
        public array $videoCodecs = [],
        public array $audioCodecs = [],
        public array $containers = [],
        public array $hdrModes = [],
        public array $drmSystems = [],
        public array $subtitleFormats = [],
        public ?string $maximumResolution = null,
        public array $features = [],
    ) {}

    public static function fromHeader(?string $header): self
    {
        if (! is_string($header) || $header === '' || strlen($header) > self::MAX_HEADER_BYTES) {
            return new self;
        }

        try {
            $decoded = json_decode($header, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self;
        }

        if (! is_array($decoded)) {
            return new self;
        }

        $video = is_array($decoded['video'] ?? null) ? $decoded['video'] : [];
        $audio = is_array($decoded['audio'] ?? null) ? $decoded['audio'] : [];
        $features = is_array($decoded['features'] ?? null) ? $decoded['features'] : [];
        $maximumResolution = strtolower(trim((string) ($video['max_resolution'] ?? $decoded['max_resolution'] ?? '')));

        return new self(
            videoCodecs: self::allowedList($video['codecs'] ?? $decoded['video_codecs'] ?? [], self::VIDEO_CODECS),
            audioCodecs: self::allowedList($audio['codecs'] ?? $decoded['audio_codecs'] ?? [], self::AUDIO_CODECS),
            containers: self::allowedList($video['containers'] ?? $decoded['containers'] ?? [], self::CONTAINERS),
            hdrModes: self::allowedList($video['hdr'] ?? $decoded['hdr'] ?? [], self::HDR_MODES),
            drmSystems: self::allowedList($video['drm'] ?? $decoded['drm'] ?? [], self::DRM_SYSTEMS),
            subtitleFormats: self::allowedList($decoded['subtitle_formats'] ?? $decoded['subtitles'] ?? [], self::SUBTITLE_FORMATS),
            maximumResolution: in_array($maximumResolution, self::RESOLUTIONS, true) ? $maximumResolution : null,
            features: self::featureFlags($features),
        );
    }

    public function toArray(): array
    {
        return [
            'video_codecs' => $this->videoCodecs,
            'audio_codecs' => $this->audioCodecs,
            'containers' => $this->containers,
            'hdr' => $this->hdrModes,
            'drm' => $this->drmSystems,
            'subtitle_formats' => $this->subtitleFormats,
            'max_resolution' => $this->maximumResolution,
            'features' => $this->features,
        ];
    }

    public function wasReported(): bool
    {
        return $this->hasPlaybackReport()
            || $this->features !== [];
    }

    public function hasPlaybackReport(): bool
    {
        return $this->videoCodecs !== []
            || $this->audioCodecs !== []
            || $this->containers !== []
            || $this->hdrModes !== []
            || $this->drmSystems !== []
            || $this->subtitleFormats !== []
            || $this->maximumResolution !== null;
    }

    public function supportsContainer(string $container): bool
    {
        return in_array(strtolower($container), $this->containers, true);
    }

    public function supportsVideoCodec(string $codec): bool
    {
        $codec = strtolower($codec);

        return in_array($codec, $this->videoCodecs, true)
            || ($codec === 'h265' && in_array('hevc', $this->videoCodecs, true))
            || ($codec === 'hevc' && in_array('h265', $this->videoCodecs, true));
    }

    public function supportsAudioCodec(string $codec): bool
    {
        return in_array(strtolower($codec), $this->audioCodecs, true);
    }

    public function supportsSubtitleFormat(string $format): bool
    {
        $format = strtolower($format);

        return in_array($format, $this->subtitleFormats, true)
            || ($format === 'vtt' && in_array('webvtt', $this->subtitleFormats, true))
            || ($format === 'webvtt' && in_array('vtt', $this->subtitleFormats, true));
    }

    public function supportsDrm(string $drm): bool
    {
        return in_array(strtolower($drm), $this->drmSystems, true);
    }

    private static function allowedList(mixed $values, array $allowed): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach (array_slice($values, 0, 20) as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = strtolower(trim($value));
            if (in_array($value, $allowed, true)) {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    private static function featureFlags(array $features): array
    {
        $normalized = [];
        foreach (self::FEATURE_FLAGS as $feature) {
            if (array_key_exists($feature, $features) && is_bool($features[$feature])) {
                $normalized[$feature] = $features[$feature];
            }
        }

        return $normalized;
    }
}
