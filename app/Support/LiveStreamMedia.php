<?php

namespace App\Support;

use App\Models\LiveStream;
use App\Services\Api\V1\LiveStreamAccessService;
use Illuminate\Support\Facades\URL;

class LiveStreamMedia
{
    private const SIGNED_URL_LIFETIME_MINUTES = 15;

    /**
     * Return a routed thumbnail URL without exposing its storage path.
     *
     * Callers must authorize access to the stream before exposing a signed URL.
     * Guest-accessible streams use a stable route; every other stream uses a
     * short-lived signature so browsers and native clients do not need cookies.
     */
    public static function thumbnailUrl(LiveStream $stream, bool $forceSigned = false): ?string
    {
        if (! filled($stream->thumbnail_path)) {
            return null;
        }

        $parameters = [
            'liveStream' => $stream,
            'v' => self::version((string) $stream->thumbnail_path),
        ];

        if ($forceSigned || ! self::isPubliclyAccessible($stream)) {
            $stream->loadMissing('user');
            if (! $stream->user?->hasPublicProfileEnabled() && (int) request()->user()?->id === (int) $stream->user_id) {
                $parameters['profile_owner'] = $stream->user_id;
            }

            return URL::temporarySignedRoute(
                'media.live-stream-thumbnail',
                now()->addMinutes(self::SIGNED_URL_LIFETIME_MINUTES),
                $parameters,
            );
        }

        return route('media.live-stream-thumbnail', $parameters);
    }

    public static function isPubliclyAccessible(LiveStream $stream): bool
    {
        return app(LiveStreamAccessService::class)->canView($stream, null);
    }

    public static function version(string $path): string
    {
        return substr(hash('sha256', $path), 0, 12);
    }

    public static function safeExternalUrl(string $value): ?string
    {
        $url = trim($value);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        $hasCredentials = parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null;
        $unsafeName = in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
        $unsafeIp = filter_var($host, FILTER_VALIDATE_IP)
            && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        return in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && ! $hasCredentials
            && ! $unsafeName
            && ! $unsafeIp
            && filter_var($url, FILTER_VALIDATE_URL)
                ? $url
                : null;
    }
}
