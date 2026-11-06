<?php

namespace App\Support;

use App\Models\User;
use App\Models\Video;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Provider identity and normalized URL are independent, globally unique import keys. */
final class VideoImportIdentity
{
    public static function keys(string $provider, string $url, ?string $storedPath = null): array
    {
        $urlKey = self::urlKey($url);
        $stored = explode(':', $storedPath ?? '', 3);
        $actual = MediaSourceMetadata::providerForUrl($url) ?? $provider;
        $id = MediaSourceMetadata::providerId($actual, $url);
        if (! $id && ($stored[0] ?? '') === 'external' && in_array($stored[1] ?? '', [$actual, $provider], true)) {
            $id = $stored[2] ?? null;
        }
        // Odysee's playback identity also includes the name; claim IDs alone
        // identify content and match direct/yt-dlp imports of the same claim.
        if ($actual === 'odysee' && is_string($id) && preg_match('/(?:^|:|\/)([a-f0-9]{40})$/D', $id, $match)) $id = $match[1];
        if ($actual === 'rumble' && is_string($id)) $id = strtolower($id);
        $sourceKey = $actual !== 'direct' && filled($id) ? hash('sha256', $actual."\0".$id) : $urlKey;

        return ['import_source_key' => $sourceKey, 'import_url_key' => $urlKey];
    }

    public static function forVideo(Video $video): array
    {
        return $video->source_type === 'import' && filled($video->import_url)
            ? self::keys((string) $video->import_provider, (string) $video->import_url, (string) $video->video_path)
            : ['import_source_key' => null, 'import_url_key' => null];
    }

    public static function urlKey(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) return hash('sha256', trim($url));
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? null;
        $authority = $host.($port && $port !== ($scheme === 'https' ? 443 : 80) ? ':'.$port : '');
        $path = $parts['path'] ?? '/';
        // Known video URLs are identity links: slugs, timestamps and tracking
        // don't create another video. Unknown direct URL query values matter.
        $provider = MediaSourceMetadata::providerForUrl($url);
        if ($provider === 'rumble' && preg_match('~^/(v[a-z0-9]+)(?:-|\.html|/?$)~i', $path, $match)) {
            $path = '/'.$match[1];
            $host = $authority = 'rumble.com';
        }
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $item) {
            if ($item === '') continue;
            $key = strtolower(rawurldecode(explode('=', $item, 2)[0]));
            if (str_starts_with($key, 'utm_') || in_array($key, ['fbclid', 'gclid', 'msclkid'], true)) continue;
            if ($provider && in_array($key, ['t', 'start', 'time_continue', 'si', 'feature', 'e9s', 'pri', 'src', 'r'], true)) continue;
            $query[] = $item;
        }
        sort($query, SORT_STRING);

        return hash('sha256', $scheme.'://'.$authority.($path ?: '/').($query ? '?'.implode('&', $query) : ''));
    }

    public static function duplicate(array $keys): ?Video
    {
        return Video::query()->where('source_type', 'import')->where(function ($query) use ($keys): void {
            $query->where('import_source_key', $keys['import_source_key'])->orWhere('import_url_key', $keys['import_url_key']);
        })->orderBy('id')->first();
    }

    public static function duplicatePayload(array $keys, ?User $user): ?array
    {
        $video = self::duplicate($keys);
        if (! $video) return null;
        $visible = $user?->id === $video->user_id || Video::query()->public()->whereKey($video->id)->exists();

        return ['code' => 'duplicate_video', 'message' => 'This video has already been added.',
            'existing_video' => $visible ? ['slug' => $video->slug, 'url' => '/watch/'.rawurlencode($video->slug)] : null];
    }

    public static function ensureAvailable(array $keys, ?User $user): void
    {
        if ($payload = self::duplicatePayload($keys, $user)) throw new HttpResponseException(response()->json($payload, 409));
    }
}
