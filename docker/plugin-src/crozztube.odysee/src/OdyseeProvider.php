<?php

namespace CrzOdysee;

use App\Support\YtDlp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class OdyseeProvider
{
    public static function definition(bool $enrich = true): array
    {
        return [
            'label' => 'Odysee', 'mirror_label' => 'Odysee',
            'enabled_by_default' => true, 'requires_downloader' => false, 'api_key_label' => null,
            'domains' => ['odysee.com', 'www.odysee.com', 'lbry.tv', 'www.lbry.tv'],
            'help' => 'Import an Odysee video URL. Uses the original stream when available, with the official Odysee player as fallback.',
            'id_from_url' => [self::class, 'idFromUrl'],
            'embed_url' => [self::class, 'embedUrl'],
            'metadata' => static fn (string $url): array => self::metadata($url, $enrich),
        ];
    }

    /** Abbreviated claim IDs need resolution; never mistake them for permanent identities. */
    public static function idFromUrl(string $url): ?string
    {
        try {
            $parts = self::reference($url);
            $stream = end($parts);

            return strlen($stream['id'] ?? '') === 40 ? $stream['name'].':'.$stream['id'] : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    public static function embedUrl(string $id, string $parent = ''): ?string
    {
        if (! preg_match('/^(.+):([a-f0-9]{40})$/D', $id, $match) || ! self::validName($match[1])) return null;

        // Same permanent-URI encoding used by Odysee's generateEmbedUrlEncoded.
        return 'https://odysee.com/$/embed/'.rawurlencode($id);
    }

    public static function metadata(string $url, bool $enrich = true): array
    {
        $parts = self::reference(trim($url));
        $uri = 'lbry://'.implode('/', array_map(static fn (array $part): string =>
            $part['name'].($part['id'] !== null ? '#'.$part['id'] : ''), $parts));
        $canonical = 'https://odysee.com/'.implode('/', array_map(static fn (array $part): string =>
            rawurlencode($part['name']).($part['id'] !== null ? ':'.$part['id'] : ''), $parts));

        return Cache::remember('odysee:metadata:v1:'.sha1($canonical.':'.(int) $enrich), now()->addMinutes(10), static function () use ($parts, $uri, $canonical, $enrich): array {
            $claim = self::resolve($uri);
            if ($claim !== [] && ($claim['value']['stream_type'] ?? null) !== 'video') {
                throw new RuntimeException('Use an Odysee video URL, not a channel, collection, or non-video claim.');
            }
            $extra = $enrich || $claim === [] ? YtDlp::metadataQuietly($canonical) : [];
            $stream = end($parts);
            $name = $claim['name'] ?? $stream['name'];
            $claimId = $claim['claim_id'] ?? null;
            if (! $claimId && strtolower((string) ($extra['extractor'] ?? '')) === 'lbry') $claimId = $extra['provider_id'] ?? null;
            $claimId ??= $stream['id'];
            if (! is_string($name) || ! self::validName($name) || ! is_string($claimId) || ! preg_match('/^[a-f0-9]{40}$/D', $claimId)) {
                throw new RuntimeException('Odysee video metadata is currently unavailable. Check the URL or try its permanent video URL.');
            }
            $value = $claim['value'] ?? [];
            $video = $value['video'] ?? [];
            $api = $claim === [] ? [] : YtDlp::normalize([
                'title' => $value['title'] ?? null, 'description' => $value['description'] ?? null,
                'tags' => $value['tags'] ?? [], 'thumbnail' => $value['thumbnail']['url'] ?? null,
                'duration' => $video['duration'] ?? null, 'width' => $video['width'] ?? null, 'height' => $video['height'] ?? null,
                'channel' => $claim['signing_channel']['value']['title'] ?? $claim['signing_channel']['name'] ?? null,
                'language' => $value['languages'][0] ?? null,
            ]);

            return array_replace($extra, array_filter($api, static fn ($value): bool => $value !== null && $value !== []), [
                'provider_id' => $name.':'.$claimId,
                'captions' => $extra['captions'] ?? [],
                'chapters' => $extra['chapters'] ?? [],
            ]);
        });
    }

    private static function resolve(string $uri): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->acceptJson()
                ->withUserAgent('CrozzTube-Odysee/1.0')->contentType('application/json-rpc')
                ->post('https://api.lbry.tv/api/v1/proxy', ['method' => 'resolve', 'params' => ['urls' => [$uri]]]);
            // URI names can contain dots; don't interpret them as Laravel data paths.
            $claim = $response->successful() ? (($response->json()['result'] ?? [])[$uri] ?? null) : null;

            return is_array($claim) && ! isset($claim['error']) ? $claim : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function reference(string $url): array
    {
        $url = trim($url);
        $parsed = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! is_array($parsed)
            || ! in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true)
            || ! in_array(strtolower($parsed['host'] ?? ''), ['odysee.com', 'www.odysee.com', 'lbry.tv', 'www.lbry.tv'], true)
            || isset($parsed['user']) || isset($parsed['pass'])
            || (isset($parsed['port']) && $parsed['port'] !== (strtolower($parsed['scheme']) === 'https' ? 443 : 80))) {
            throw new RuntimeException('Use an Odysee video URL.');
        }
        $path = trim(rawurldecode($parsed['path'] ?? ''), '/');
        $path = preg_replace('~^\$/(?:embed|download)/~', '', $path);
        if (isset($parsed['fragment']) && preg_match('/^[a-f0-9]{1,40}$/D', $parsed['fragment'])) $path .= ':'.$parsed['fragment'];
        $segments = explode('/', $path);
        if (count($segments) === 2 && ! str_starts_with($segments[0], '@') && preg_match('/^[a-f0-9]{1,40}$/D', $segments[1])) {
            $segments = [$segments[0].':'.$segments[1]];
        }
        if (count($segments) < 1 || count($segments) > 2) throw new RuntimeException('Use an Odysee video page or embed URL.');
        $parts = [];
        foreach ($segments as $index => $segment) {
            $tokens = explode(':', $segment);
            $name = $tokens[0];
            $channel = str_starts_with($name, '@');
            if (count($tokens) > 2 || ! self::validName($channel ? substr($name, 1) : $name)
                || ($channel !== (count($segments) === 2 && $index === 0))
                || (isset($tokens[1]) && ! preg_match('/^[a-f0-9]{1,40}$/D', $tokens[1]))
                || in_array(strtolower($name), ['search', 'discover', 'following', 'settings', 'upload', 'playlists', 'wallet', 'signin', 'signup'], true)) {
                throw new RuntimeException('Use an Odysee video URL, not a channel, search, or collection URL.');
            }
            $parts[] = ['name' => $name, 'id' => $tokens[1] ?? null];
        }

        return $parts;
    }

    private static function validName(string $name): bool
    {
        return mb_check_encoding($name, 'UTF-8') && mb_strlen($name) <= 200 && ! in_array($name, ['', '.', '..'], true)
            && ! preg_match('~[\s\x00-\x20\x7f/\\\\:@?#&%$<>]~u', $name);
    }
}
