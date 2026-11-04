<?php

namespace CrzSourceResolver\Support;

use App\Models\Video;
use App\Support\MediaSourceMetadata;
use App\Support\VideoImportSettings;

/**
 * Provider-neutral "mirror" (original source) description for imported videos.
 *
 * The frontend never knows provider names: it only receives mode/embed_url and
 * renders an iframe or an external link. Adding a provider means extending the
 * provider detection/embed mapping in PlayHub (or this class), nothing else.
 */
final class Mirror
{
    /** Providers whose official player can be embedded in an iframe. */
    private const EMBEDDABLE = ['youtube', 'vimeo', 'dailymotion', 'twitch'];

    /**
     * @return array{provider:string,label:string,mode:string,embed_url:?string,external_url:?string}|null
     */
    public static function forVideo(Video $video, string $host): ?array
    {
        if (($video->source_type ?: '') !== 'import') {
            return null;
        }

        $provider = MediaSourceMetadata::originProvider($video);
        // The import URL is the source of truth. PlayHub's stored reference
        // (video_path) can point at an older/duplicate provider id.
        $providerId = in_array($provider, self::EMBEDDABLE, true)
            ? (MediaSourceMetadata::providerId($provider, (string) $video->import_url)
                ?? MediaSourceMetadata::providerIdForVideo($video, $provider))
            : null;
        $label = self::label($provider);

        $embed = in_array($provider, self::EMBEDDABLE, true)
            ? self::decorate($provider, MediaSourceMetadata::embedUrl($provider, $providerId, $host))
            : null;

        // Validated provider URL (e.g. keeps Vimeo's unlisted hash).
        $external = MediaSourceMetadata::providerUrlForVideo($video, $provider);
        if ($external === null) {
            $candidate = trim((string) $video->import_url);
            $external = filter_var($candidate, FILTER_VALIDATE_URL) !== false ? $candidate : null;
        }

        if ($embed === null && $external === null) {
            return null;
        }

        return [
            'provider' => $provider,
            'label' => $label,
            'mode' => $embed !== null ? 'iframe' : 'link',
            'embed_url' => $embed,
            'external_url' => $external,
        ];
    }

    private static function label(string $provider): string
    {
        $providers = VideoImportSettings::providers();
        $label = $providers[$provider]['label'] ?? null;

        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        return $provider === 'direct' ? 'Original' : ucfirst($provider);
    }

    /**
     * Add provider-specific autoplay parameters. Kept server-side so the
     * frontend stays generic.
     */
    private static function decorate(string $provider, ?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return match ($provider) {
            'youtube' => $url.$separator.'autoplay=1&rel=0',
            'vimeo', 'dailymotion' => $url.$separator.'autoplay=1',
            'twitch' => $url.$separator.'autoplay=true',
            default => $url,
        };
    }
}
