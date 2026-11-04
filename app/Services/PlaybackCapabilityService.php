<?php

namespace App\Services;

use App\Support\Client\ClientCapabilities;
use App\Support\PlatformFeatureSettings;

final class PlaybackCapabilityService
{
    public function forClient(ClientCapabilities $client): array
    {
        $server = array_replace([
            'delivery' => [],
            'containers' => [],
            'video_codecs' => [],
            'audio_codecs' => [],
            'subtitle_formats' => [],
            'qualities' => [],
            'drm' => [],
            'hdr' => [],
        ], (array) config('playhub-clients.playback', []));
        $enabledQualities = (array) data_get(PlatformFeatureSettings::settings(), 'uploads.renditions', []);
        if ($enabledQualities !== []) {
            $availableQualities = array_values(array_intersect(
                $this->strings($server['qualities']),
                $this->strings($enabledQualities),
            ));
            if ($availableQualities !== []) {
                $server['qualities'] = $availableQualities;
            }
        }

        $reported = $client->hasPlaybackReport();
        $negotiated = [
            'containers' => $this->negotiate($server['containers'], $client->containers, $reported),
            'video_codecs' => $this->negotiateVideoCodecs($server['video_codecs'], $client, $reported),
            'audio_codecs' => $this->negotiate($server['audio_codecs'], $client->audioCodecs, $reported),
            'subtitle_formats' => $this->negotiateSubtitles($server['subtitle_formats'], $client, $reported),
            'drm' => $this->negotiate($server['drm'], $client->drmSystems, $reported),
            'hdr' => $this->negotiate($server['hdr'], $client->hdrModes, $reported),
            'max_resolution' => $client->maximumResolution,
        ];

        return [
            'server' => $server,
            'client_reported' => $reported,
            'negotiated' => $negotiated,
            'compatible' => $reported
                ? $negotiated['containers'] !== [] && $negotiated['video_codecs'] !== [] && $negotiated['audio_codecs'] !== []
                : null,
        ];
    }

    private function negotiate(mixed $serverValues, mixed $clientValues, bool $reported): array
    {
        $serverValues = $this->strings($serverValues);
        if (! $reported || $clientValues === []) {
            return $serverValues;
        }

        return array_values(array_intersect($serverValues, $this->strings($clientValues)));
    }

    private function negotiateVideoCodecs(mixed $serverValues, ClientCapabilities $client, bool $reported): array
    {
        $serverValues = $this->strings($serverValues);
        if (! $reported || $client->videoCodecs === []) {
            return $serverValues;
        }

        return array_values(array_filter($serverValues, $client->supportsVideoCodec(...)));
    }

    private function negotiateSubtitles(mixed $serverValues, ClientCapabilities $client, bool $reported): array
    {
        $serverValues = $this->strings($serverValues);
        if (! $reported || $client->subtitleFormats === []) {
            return $serverValues;
        }

        return array_values(array_filter($serverValues, $client->supportsSubtitleFormat(...)));
    }

    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', array_filter($values, 'is_string'))));
    }
}
