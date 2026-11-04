<?php

namespace App\Support;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlayerAdSettings
{
    private const MAX_VAST_BYTES = 1048576;

    private const MAX_VAST_FETCHES = 4;

    private const MAX_VAST_WRAPPER_DEPTH = 3;

    private const MAX_VAST_URLS_PER_EVENT = 8;

    private const MAX_VAST_MEDIA_FILES = 32;

    private const MAX_VAST_URL_LENGTH = 4000;

    private const VAST_RESOLUTION_DEADLINE_SECONDS = 8;

    private const IMPRESSION_COMPLETION_WINDOW_MINUTES = 15;

    private const MAX_GROSS_CPM = 250.0;

    public static function settings(): array
    {
        $path = self::settingsPath();
        $stored = is_file($path) && is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];

        return self::normalize(array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []));
    }

    public static function adminSettings(): array
    {
        return self::settings();
    }

    public static function publicSettings(): array
    {
        $settings = self::settings();
        $ads = array_values(array_filter(array_map(fn (array $ad): array => [
            'id' => $ad['id'],
            'name' => $ad['name'],
            'engine' => $ad['engine'],
            'placement' => $ad['placement'],
            'offset_seconds' => $ad['offsetSeconds'],
            'skip_after' => $ad['engine'] === 'generic_vast' ? $ad['skipAfter'] : null,
            'enabled' => (bool) $ad['enabled'] && self::publicUrl($ad['vastUrl']) !== '',
        ], $settings['ads'] ?? []), fn (array $ad): bool => (bool) $ad['enabled']));

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false) && count($ads) > 0,
            'load_timeout_seconds' => $settings['loadTimeoutSeconds'],
            'ads' => $ads,
        ];
    }

    public static function save(array $settings): void
    {
        $settings = self::normalize(array_replace_recursive(self::defaults(), $settings));
        $path = self::settingsPath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    public static function resolve(array $adIds = [], ?Video $video = null, ?Request $request = null, ?int $viewerId = null): array
    {
        $settings = self::settings();
        if (! (bool) ($settings['enabled'] ?? false)) {
            return [];
        }

        $requestedIds = array_filter(array_map('strval', $adIds));
        $ads = collect($settings['ads'] ?? [])
            ->filter(fn (array $ad): bool => (bool) $ad['enabled'] && self::publicUrl($ad['vastUrl']) !== '')
            ->when($requestedIds, fn ($items) => $items->filter(fn (array $ad): bool => in_array($ad['id'], $requestedIds, true)))
            ->values()
            ->take(8);

        $vastState = [
            'deadline' => microtime(true) + self::VAST_RESOLUTION_DEADLINE_SECONDS,
            'fetches' => 0,
            'visited' => [],
        ];

        return $ads->map(function (array $ad) use ($video, $request, $viewerId, &$vastState): ?array {
            $resolved = self::resolveAd($ad, $vastState);
            if (! $resolved) {
                return null;
            }

            if ($video && $request) {
                $impression = self::createVideoImpression($video, $resolved, $request, $viewerId);
                if ($impression) {
                    $resolved['impression'] = $impression;
                }
            }
            unset($resolved['gross_cpm']);

            return $resolved;
        })->filter()->values()->all();
    }

    public static function completeVideoImpression(string $uuid, string $token, ?int $viewerId = null): array
    {
        abort_unless(
            Schema::hasTable('video_ad_impressions')
                && Schema::hasTable('creator_ad_accruals')
                && Schema::hasTable('wallet_transactions'),
            422,
            'VAST monetization storage is not ready.'
        );
        abort_unless(hash_equals(self::impressionToken($uuid), $token), 403, 'Invalid ad completion token.');

        return DB::transaction(function () use ($uuid, $viewerId): array {
            $impression = DB::table('video_ad_impressions')->where('uuid', $uuid)->lockForUpdate()->first();
            abort_unless($impression, 404, 'Ad impression not found.');
            abort_unless(
                $viewerId && (int) $impression->viewer_id === $viewerId,
                403,
                'This ad impression belongs to another viewer.'
            );
            if ($impression->status === 'completed') {
                return ['status' => 'completed'];
            }
            abort_unless($impression->status === 'served', 409, 'This ad impression cannot be completed.');
            abort_if(
                Carbon::parse($impression->served_at)->lt(now()->subMinutes(self::IMPRESSION_COMPLETION_WINDOW_MINUTES)),
                410,
                'This ad impression has expired.'
            );

            $duration = max(0, (int) $impression->duration_seconds);
            $requiredSeconds = $duration > 0 ? max(1, (int) ceil($duration * .9)) : 5;
            $elapsedSeconds = max(0, now()->timestamp - Carbon::parse($impression->served_at)->timestamp);
            abort_if($elapsedSeconds < $requiredSeconds, 409, 'The ad has not played long enough to complete.');

            $currency = strtolower((string) $impression->currency);
            DB::table('creator_ad_accruals')->insertOrIgnore([
                'user_id' => $impression->creator_id,
                'currency' => $currency,
                'pending_micros' => 0,
                'lifetime_micros' => 0,
                'credited_cents' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $accrual = DB::table('creator_ad_accruals')
                ->where('user_id', $impression->creator_id)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            $totalMicros = (int) $accrual->pending_micros + (int) $impression->creator_amount_micros;
            $creditedCents = intdiv($totalMicros, 10000);
            $pendingMicros = $totalMicros % 10000;
            DB::table('creator_ad_accruals')->where('id', $accrual->id)->update([
                'pending_micros' => $pendingMicros,
                'lifetime_micros' => (int) $accrual->lifetime_micros + (int) $impression->creator_amount_micros,
                'credited_cents' => (int) $accrual->credited_cents + $creditedCents,
                'updated_at' => now(),
            ]);
            DB::table('video_ad_impressions')->where('id', $impression->id)->update([
                'status' => 'completed',
                'credited_cents' => $creditedCents,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            if ($creditedCents > 0) {
                DB::table('wallet_transactions')->insertOrIgnore([
                    'user_id' => $impression->creator_id,
                    'type' => 'earning',
                    'amount_cents' => $creditedCents,
                    'currency' => $currency,
                    'status' => 'completed',
                    'provider' => 'vast_ad',
                    'provider_reference' => 'impression:'.$uuid,
                    'note' => 'VAST ad revenue share',
                    'payload' => json_encode([
                        'event' => 'vast_ad',
                        'impression_id' => $uuid,
                        'video_id' => (int) $impression->video_id,
                        'ad_id' => (string) $impression->ad_id,
                        'placement' => (string) $impression->placement,
                        'gross_micros' => (int) $impression->gross_micros,
                        'creator_share_percent' => (float) $impression->creator_share_percent,
                        'creator_micros' => (int) $impression->creator_amount_micros,
                        'credited_cents' => $creditedCents,
                        'carry_micros' => $pendingMicros,
                    ], JSON_UNESCAPED_SLASHES),
                    'processed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return ['status' => 'completed'];
        });
    }

    private static function resolveAd(array $ad, ?array &$vastState = null): ?array
    {
        if (($ad['engine'] ?? 'generic_vast') === 'google_ima') {
            $adTagUrl = self::safeExternalUrl($ad['vastUrl']);
            if ($adTagUrl === '') {
                return null;
            }

            return [
                'id' => $ad['id'],
                'name' => $ad['name'],
                'engine' => 'google_ima',
                'placement' => $ad['placement'],
                'offset_seconds' => $ad['offsetSeconds'],
                'ad_tag_url' => $adTagUrl,
                'duration' => 0,
                'gross_cpm' => self::grossCpm($ad),
            ];
        }

        $vastState ??= [
            'deadline' => microtime(true) + self::VAST_RESOLUTION_DEADLINE_SECONDS,
            'fetches' => 0,
            'visited' => [],
        ];
        $vast = self::fetchVast($ad['vastUrl'], $vastState);
        if (! $vast) {
            return null;
        }

        $resolved = self::parseVast($vast, 0, $vastState);
        if (! $resolved || empty($resolved['media_url'])) {
            return null;
        }

        $fallbackSkip = max(0, min(60, (int) ($ad['skipAfter'] ?? 5)));

        return [
            'id' => $ad['id'],
            'name' => $ad['name'],
            'engine' => 'generic_vast',
            'title' => $resolved['title'] ?: $ad['name'],
            'placement' => $ad['placement'],
            'offset_seconds' => $ad['offsetSeconds'],
            'media_url' => $resolved['media_url'],
            'mime_type' => $resolved['mime_type'] ?: self::mimeType($resolved['media_url']),
            'click_url' => $resolved['click_url'],
            'duration' => $resolved['duration'],
            'skip_after' => $resolved['skip_after'] ?? $fallbackSkip,
            'tracking' => $resolved['tracking'],
            // Remote VAST pricing is informational and must never control creator settlement.
            'gross_cpm' => self::grossCpm($ad),
        ];
    }

    private static function parseVast(string $xml, int $depth, array &$state): ?array
    {
        if ($depth > self::MAX_VAST_WRAPPER_DEPTH || strlen($xml) > self::MAX_VAST_BYTES) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $vast = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $vast) {
            return null;
        }

        foreach ($vast->Ad ?? [] as $ad) {
            if (isset($ad->Wrapper)) {
                if ($depth >= self::MAX_VAST_WRAPPER_DEPTH) {
                    continue;
                }
                $wrapperTracking = self::trackingFromVastAd($ad->Wrapper);
                $tagUrl = trim((string) ($ad->Wrapper->VASTAdTagURI ?? ''));
                $child = $tagUrl ? self::fetchVast($tagUrl, $state) : null;
                $resolved = $child ? self::parseVast($child, $depth + 1, $state) : null;
                if ($resolved) {
                    $resolved['tracking'] = self::mergeTracking($wrapperTracking, $resolved['tracking']);
                    $resolved['pricing'] ??= self::pricingFromVastAd($ad->Wrapper);

                    return $resolved;
                }
            }

            if (isset($ad->InLine)) {
                return self::parseInlineAd($ad->InLine);
            }
        }

        return null;
    }

    private static function parseInlineAd(\SimpleXMLElement $inline): ?array
    {
        foreach ($inline->Creatives->Creative ?? [] as $creative) {
            if (! isset($creative->Linear)) {
                continue;
            }

            $linear = $creative->Linear;
            $duration = self::secondsFromVastTime((string) ($linear->Duration ?? ''));
            $media = self::bestMediaFile($linear->MediaFiles->MediaFile ?? []);
            if (! $media) {
                continue;
            }

            $tracking = self::trackingFromVastAd($inline);
            $tracking['click'] = self::safeExternalUrls(
                array_map('strval', iterator_to_array($linear->VideoClicks->ClickTracking ?? []))
            );

            return [
                'title' => trim((string) ($inline->AdTitle ?? '')),
                'media_url' => $media['url'],
                'mime_type' => $media['type'],
                'click_url' => self::safeExternalUrl((string) ($linear->VideoClicks->ClickThrough ?? '')) ?: null,
                'duration' => $duration,
                'skip_after' => self::secondsFromVastOffset((string) ($linear['skipoffset'] ?? ''), $duration),
                'tracking' => $tracking,
                'pricing' => self::pricingFromVastAd($inline),
            ];
        }

        return null;
    }

    private static function bestMediaFile(iterable $mediaFiles): ?array
    {
        $candidates = [];
        foreach ($mediaFiles as $file) {
            if (count($candidates) >= self::MAX_VAST_MEDIA_FILES) {
                break;
            }

            $url = self::safeExternalUrl((string) $file);
            if ($url === '') {
                continue;
            }

            $type = strtolower((string) ($file['type'] ?? self::mimeType($url)));
            $bitrate = (int) ($file['bitrate'] ?? 0);
            $candidates[] = ['url' => $url, 'type' => $type, 'score' => (str_contains($type, 'mp4') ? 100000 : 0) + $bitrate];
        }

        if (! $candidates) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return ['url' => $candidates[0]['url'], 'type' => $candidates[0]['type'] ?: self::mimeType($candidates[0]['url'])];
    }

    private static function trackingFromVastAd(\SimpleXMLElement $ad): array
    {
        $tracking = [
            'impression' => self::safeExternalUrls(array_map('strval', iterator_to_array($ad->Impression ?? []))),
            'start' => [],
            'firstQuartile' => [],
            'midpoint' => [],
            'thirdQuartile' => [],
            'complete' => [],
            'click' => [],
        ];

        foreach ($ad->Creatives->Creative ?? [] as $creative) {
            foreach ($creative->Linear->TrackingEvents->Tracking ?? [] as $event) {
                $name = (string) ($event['event'] ?? '');
                if (isset($tracking[$name])) {
                    if (count($tracking[$name]) < self::MAX_VAST_URLS_PER_EVENT) {
                        $tracking[$name][] = (string) $event;
                    }
                }
            }
        }

        return array_map(fn (array $urls): array => self::safeExternalUrls($urls), $tracking);
    }

    private static function mergeTracking(array $first, array $second): array
    {
        foreach ($second as $key => $urls) {
            $first[$key] = array_slice(
                array_values(array_unique(array_merge($first[$key] ?? [], $urls))),
                0,
                self::MAX_VAST_URLS_PER_EVENT,
            );
        }

        return $first;
    }

    private static function fetchVast(string $url, array &$state): ?string
    {
        if (($state['fetches'] ?? 0) >= self::MAX_VAST_FETCHES || microtime(true) >= ($state['deadline'] ?? 0)) {
            return null;
        }

        $target = self::safeExternalTarget($url);
        if (! $target || isset($state['visited'][$target['url']])) {
            return null;
        }

        // A second DNS lookup by a stream-based HTTP transport could be rebound
        // after validation. Hostname fetches therefore require cURL DNS pinning.
        if ($target['host_is_name'] && ! defined('CURLOPT_RESOLVE')) {
            return null;
        }

        $state['visited'][$target['url']] = true;
        $state['fetches']++;

        try {
            $remainingSeconds = max(1, min(4, (int) ceil(($state['deadline'] ?? 0) - microtime(true))));
            $options = ['allow_redirects' => false, 'stream' => true];
            if (defined('CURLOPT_RESOLVE') && $target['host_is_name']) {
                $address = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];
                $options['curl'] = [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], $address)]];
            }

            $response = Http::withOptions($options)
                ->connectTimeout(min(2, $remainingSeconds))
                ->timeout($remainingSeconds)
                ->accept('application/xml,text/xml')
                ->get($target['url']);
            $contentLength = (int) $response->header('Content-Length');
            if (! $response->successful() || $contentLength > self::MAX_VAST_BYTES) {
                return null;
            }

            $stream = $response->toPsrResponse()->getBody();
            $contents = '';
            while (! $stream->eof()) {
                if (microtime(true) >= ($state['deadline'] ?? 0)) {
                    return null;
                }
                $remainingBytes = self::MAX_VAST_BYTES - strlen($contents);
                if ($remainingBytes <= 0) {
                    return null;
                }
                $contents .= $stream->read(min(8192, $remainingBytes + 1));
                if (strlen($contents) > self::MAX_VAST_BYTES) {
                    return null;
                }
            }

            return $contents !== '' ? $contents : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function secondsFromVastTime(string $value): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2}):(\d{2})(?:\.(\d+))?$/', trim($value), $matches)) {
            return null;
        }

        return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
    }

    private static function secondsFromVastOffset(string $value, ?int $duration): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_ends_with($value, '%') && $duration) {
            return max(0, (int) round($duration * ((float) rtrim($value, '%') / 100)));
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        return self::secondsFromVastTime($value);
    }

    private static function mimeType(string $url): string
    {
        return match (strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION))) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'm3u8' => 'application/vnd.apple.mpegurl',
            default => 'video/mp4',
        };
    }

    private static function publicUrl(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return str_starts_with($value, '/') || preg_match('/^https?:\/\//i', $value) ? $value : '';
    }

    /**
     * Return an absolute public HTTP(S) URL that is safe for a client to fetch.
     */
    private static function safeExternalUrl(?string $value): string
    {
        return self::safeExternalTarget($value)['url'] ?? '';
    }

    private static function safeExternalUrls(array $values): array
    {
        $urls = [];
        foreach ($values as $value) {
            if (count($urls) >= self::MAX_VAST_URLS_PER_EVENT) {
                break;
            }

            $url = self::safeExternalUrl(is_scalar($value) ? (string) $value : '');
            if ($url !== '' && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Resolve and validate the host before any server-side request. All returned
     * addresses must be globally routable; the selected address is pinned for
     * cURL requests in fetchVast to close the DNS rebinding window.
     */
    private static function safeExternalTarget(?string $value): ?array
    {
        $url = trim((string) $value);
        if ($url === '' || strlen($url) > self::MAX_VAST_URL_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }

        $hostIsName = filter_var($host, FILTER_VALIDATE_IP) === false;
        if ($hostIsName) {
            try {
                $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            } catch (\Throwable) {
                return null;
            }
            $addresses = array_values(array_unique(array_filter(array_map(
                fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
                $records,
            ))));
        } else {
            $addresses = [$host];
        }

        if ($addresses === [] || ! collect($addresses)->every(fn (string $address): bool => (bool) filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ))) {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return [
            'url' => $url,
            'host' => $host,
            'host_is_name' => $hostIsName,
            'address' => $addresses[0],
            'port' => $port,
        ];
    }

    private static function normalize(array $settings): array
    {
        $ads = $settings['ads'] ?? null;
        if (! is_array($ads) || ! $ads) {
            $legacy = $settings['preroll'] ?? [];
            $ads = [[
                'id' => 'ad-1',
                'enabled' => (bool) ($legacy['enabled'] ?? true),
                'name' => trim((string) ($legacy['title'] ?? 'Pre-roll Ad')) ?: 'Pre-roll Ad',
                'engine' => 'generic_vast',
                'placement' => 'preroll',
                'vastUrl' => '',
                'skipAfter' => (int) ($legacy['skipAfter'] ?? 5),
                'offsetSeconds' => 30,
            ]];
        }

        $settings['ads'] = array_values(collect(array_map(function (array $ad, int $index): array {
            return [
                'id' => trim((string) ($ad['id'] ?? '')) ?: (string) Str::uuid(),
                'enabled' => (bool) ($ad['enabled'] ?? true),
                'name' => trim((string) ($ad['name'] ?? '')) ?: 'Player Ad '.($index + 1),
                'engine' => in_array($ad['engine'] ?? 'generic_vast', ['generic_vast', 'google_ima'], true) ? ($ad['engine'] ?? 'generic_vast') : 'generic_vast',
                'placement' => in_array($ad['placement'] ?? 'preroll', ['preroll', 'midroll', 'postroll'], true) ? $ad['placement'] : 'preroll',
                'vastUrl' => trim((string) ($ad['vastUrl'] ?? '')),
                'skipAfter' => max(0, min(60, (int) ($ad['skipAfter'] ?? 5))),
                'offsetSeconds' => max(1, min(86400, (int) ($ad['offsetSeconds'] ?? 30))),
                'grossCpm' => self::grossCpm($ad),
            ];
        }, array_values(array_filter($ads, 'is_array')), array_keys(array_values(array_filter($ads, 'is_array')))))->unique('id')->values()->all());
        $settings['loadTimeoutSeconds'] = max(3, min(30, (int) ($settings['loadTimeoutSeconds'] ?? 10)));

        unset($settings['preroll']);

        return $settings;
    }

    private static function settingsPath(): string
    {
        return storage_path('app/playhub-player-ads.json');
    }

    private static function createVideoImpression(Video $video, array $ad, Request $request, ?int $viewerId): ?array
    {
        $settings = MonetizationSettings::vastAdSettings();
        $grossCpmMicros = (int) round(max(0, (float) ($ad['gross_cpm'] ?? 0)) * 1000000);
        $grossMicros = intdiv($grossCpmMicros, 1000);
        $creatorMicros = (int) round($grossMicros * ((float) $settings['creator_share_percent'] / 100));
        if (! $settings['enabled'] || ! $video->monetization_enabled || $video->user_id <= 0 || ! $viewerId || $viewerId === (int) $video->user_id || $grossMicros <= 0 || $creatorMicros <= 0) {
            return null;
        }

        $identity = $viewerId
            ? 'user:'.$viewerId
            : 'guest:'.hash_hmac('sha256', (string) $request->ip().'|'.(string) $request->userAgent(), (string) config('app.key'));
        $visitorHash = hash('sha256', $identity);
        $dedupeKey = hash('sha256', implode('|', [$video->id, $ad['id'], $ad['placement'], $visitorHash, now()->format('YmdH')]));
        $uuid = (string) Str::uuid();
        DB::table('video_ad_impressions')->insertOrIgnore([
            'uuid' => $uuid,
            'video_id' => $video->id,
            'creator_id' => $video->user_id,
            'viewer_id' => $viewerId,
            'ad_id' => (string) $ad['id'],
            'placement' => (string) $ad['placement'],
            'visitor_hash' => $visitorHash,
            'dedupe_key' => $dedupeKey,
            'status' => 'served',
            'currency' => $settings['currency'],
            'gross_micros' => $grossMicros,
            'creator_share_percent' => $settings['creator_share_percent'],
            'creator_amount_micros' => $creatorMicros,
            'duration_seconds' => max(0, (int) ($ad['duration'] ?? 0)),
            'served_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $impression = DB::table('video_ad_impressions')->where('dedupe_key', $dedupeKey)->first();
        if (! $impression || $impression->status !== 'served') {
            return null;
        }

        return ['id' => (string) $impression->uuid, 'token' => self::impressionToken((string) $impression->uuid)];
    }

    private static function pricingFromVastAd(\SimpleXMLElement $ad): ?array
    {
        if (! isset($ad->Pricing)) {
            return null;
        }

        $value = (float) trim((string) $ad->Pricing);
        $model = strtolower(trim((string) ($ad->Pricing['model'] ?? '')));
        $currency = strtolower(trim((string) ($ad->Pricing['currency'] ?? '')));

        return $value > 0 && $model !== '' && $currency !== '' ? compact('value', 'model', 'currency') : null;
    }

    private static function grossCpm(array $ad): float
    {
        return max(0, min(self::MAX_GROSS_CPM, (float) ($ad['grossCpm'] ?? 0)));
    }

    private static function impressionToken(string $uuid): string
    {
        return hash_hmac('sha256', 'vast-ad-impression:'.$uuid, (string) config('app.key'));
    }

    private static function defaults(): array
    {
        return [
            'enabled' => false,
            'loadTimeoutSeconds' => 10,
            'ads' => [],
        ];
    }
}
