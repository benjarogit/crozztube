<?php

namespace App\Support\Thumbnails;

use App\Models\ThumbnailMirror;
use App\Models\ThumbnailProvider;
use App\Models\Video;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ThumbnailService
{
    private ?array $imageCache = null;

    public function __construct(private RemoteImages $http, private Hosters $hosters) {}

    public static function providers(): array
    {
        return array_filter(config('thumbnails.providers'), fn ($p) => $p['supported'] && trim((string) $p['key']) !== '');
    }

    public function ensureSource(Video $video, ?string $url = null): void
    {
        if ($url && RemoteImages::urlAllowed($url) && ! $video->thumbnail_is_custom) {
            $video->forceFill([
                'original_thumbnail_url' => $video->original_thumbnail_url ?: $url,
                'thumbnail_source_url' => $url,
                'thumbnail_source_version' => hash('sha256', $url),
            ])->save();
        }
        if (! $video->thumbnail_source_version) {
            return;
        }
        ThumbnailMirror::where('video_id', $video->id)->where('source_version', '!=', $video->thumbnail_source_version)->where('status', 'pending')->delete();
        foreach (config('thumbnails.providers') as $id => $provider) {
            ThumbnailMirror::firstOrCreate([
                'video_id' => $video->id, 'provider_id' => $id, 'source_version' => $video->thumbnail_source_version,
            ], ['priority' => $provider['priority'], 'status' => 'pending', 'next_attempt_at' => now()]);
        }
        $original = $video->thumbnail_is_custom ? $video->original_thumbnail_url : $video->thumbnail_source_url;
        if ($original) {
            ThumbnailMirror::firstOrCreate(['video_id' => $video->id, 'provider_id' => 'original', 'source_version' => $video->thumbnail_source_version],
                ['priority' => 4, 'thumbnail_url' => $original, 'status' => 'available', 'next_check_at' => NightWindow::next()]);
        }
        $video->unsetRelation('thumbnailMirrors');
    }

    /** No requests, probes, or writes on the display path. */
    public static function candidates(Video $video): array
    {
        $version = $video->thumbnail_source_version;
        $urls = [];
        if ($version) {
            $mirrors = $video->thumbnailMirrors;
            foreach ($mirrors->where('source_version', $version)->where('status', 'available')->sortBy('priority') as $mirror) {
                $provider = $mirror->provider;
                if ($provider && $provider->circuit_open) {
                    continue;
                }
                $urls[] = $mirror->thumbnail_url;
            }
        }
        if (! $video->thumbnail_is_custom) $urls[] = $video->thumbnail_source_url;
        $urls[] = $video->original_thumbnail_url;
        if (RemoteImages::urlAllowed($video->thumbnail_path)) {
            $urls[] = $video->thumbnail_path;
        }
        return array_values(array_unique(array_filter($urls, fn ($url) => RemoteImages::urlAllowed($url))));
    }

    public function initial(Video $video): void
    {
        $this->ensureSource($video, $video->thumbnail_source_url ?: $video->original_thumbnail_url);
        $lock = Cache::lock('thumbnails:transfer', 90);
        if (! $lock->get()) {
            return;
        }
        $deadline = microtime(true) + (float) config('thumbnails.import_budget');
        try {
            foreach (array_keys(self::providers()) as $id) {
                if (microtime(true) >= $deadline) break;
                $mirror = ThumbnailMirror::where('video_id', $video->id)->where('source_version', $video->thumbnail_source_version)->where('provider_id', $id)->first();
                if ($mirror && ($mirror->status === 'available' || $this->copy($mirror, $deadline))) break;
            }
        } finally {
            $lock->release();
            $video->unsetRelation('thumbnailMirrors');
        }
    }

    /** Raw image bytes never enter storage, job payloads, or database columns. */
    public function replace(Video $video, string $bytes): bool
    {
        $image = RemoteImages::inspect($bytes);
        $version = hash('sha256', $bytes);
        if ($video->thumbnail_is_custom && $video->thumbnail_source_version === $version && self::candidates($video)) return true;
        $lock = Cache::lock('thumbnails:transfer', 90);
        if (! $lock->get()) return false;
        $deadline = microtime(true) + (float) config('thumbnails.import_budget');
        try {
            foreach (array_keys(self::providers()) as $id) {
                if (microtime(true) >= $deadline) break;
                $provider = ThumbnailProvider::find($id);
                if (! $provider || $provider->paused_until?->isFuture()) continue;
                try {
                    $existing = ThumbnailMirror::where(fn ($q) => $q->where('source_version', $version)->orWhere('content_hash', $version))->where('provider_id', $id)->where('status', 'available')->where('next_check_at', '>', now())->first();
                    $result = $existing ? ['url' => $existing->thumbnail_url, 'result' => 'reused', 'server_failure' => false]
                        : $this->hosters->upload($id, $image, $version, $deadline);
                    $this->providerResult($provider, $video->id, $result);
                    if (! $result['url']) continue;
                    DB::transaction(function () use ($video, $version, $result, $id): void {
                        $video->forceFill(['thumbnail_source_version' => $version, 'thumbnail_source_url' => $result['url'],
                            'thumbnail_is_custom' => true, 'thumbnail_path' => $video->thumbnail_path && ! RemoteImages::urlAllowed($video->thumbnail_path) ? $video->thumbnail_path : null])->save();
                        ThumbnailMirror::updateOrCreate(['video_id' => $video->id, 'provider_id' => $id, 'source_version' => $version],
                            ['priority' => config('thumbnails.providers.'.$id.'.priority'), 'thumbnail_url' => $result['url'],
                                'status' => 'available', 'content_hash' => $version, 'last_checked_at' => null, 'next_check_at' => NightWindow::next(), 'failure_count' => 0]);
                    });
                    $this->ensureSource($video);
                    return true;
                } catch (Throwable) {
                    // Provider exceptions can include account secrets: do not log their bodies.
                    $provider->forceFill(['paused_until' => now()->addMinutes(30), 'last_result' => 'invalid-response'])->save();
                }
            }
        } finally {
            $lock->release();
        }
        return false;
    }

    /** Caller holds the global transfer lock; returns true once a usable copy exists. */
    public function copy(ThumbnailMirror $mirror, float $deadline): bool
    {
        $provider = ThumbnailProvider::find($mirror->provider_id);
        $video = $mirror->video;
        if (! $provider || ! $video || $video->thumbnail_source_version !== $mirror->source_version
            || ! isset(self::providers()[$provider->id]) || $provider->paused_until?->isFuture()) return false;
        if ($mirror->status === 'available') return true;
        try {
            // Reuse an already verified copy of the identical source across videos.
            $existing = ThumbnailMirror::where('source_version', $mirror->source_version)->where('provider_id', $provider->id)
                ->where('status', 'available')->where('next_check_at', '>', now())->first();
            if ($existing) {
                $this->success($mirror, $existing->thumbnail_url, $existing->next_check_at);
                return true;
            }
            $copies = $video->thumbnailMirrors->where('source_version', $mirror->source_version)->where('status', 'available')
                ->filter(fn ($copy) => $copy->provider_id !== 'original' && ! $copy->provider?->circuit_open)->pluck('thumbnail_url')->all();
            $sources = array_values(array_unique(array_filter([
                $video->thumbnail_source_url, $video->thumbnail_is_custom ? null : $video->original_thumbnail_url, ...$copies,
            ])));
            $cacheKey = $video->id.':'.$mirror->source_version;
            $image = ($this->imageCache['key'] ?? null) === $cacheKey ? $this->imageCache['image'] : null;
            foreach ($image ? [] : $sources as $source) {
                if (microtime(true) >= $deadline) break;
                try { $image = $this->http->image($source, $deadline); break; } catch (Throwable) {}
            }
            // Migration of old local thumbnails: read bounded bytes; never create a new file.
            $local = $video->thumbnail_path && ! RemoteImages::urlAllowed($video->thumbnail_path) ? $video->thumbnail_path : null;
            if (! $image && $local && \App\Support\MediaStorage::exists($local)) {
                $stream = \App\Support\MediaStorage::disk()->readStream($local);
                if (is_resource($stream)) {
                    try { $image = RemoteImages::inspect(stream_get_contents($stream, config('thumbnails.max_bytes') + 1)); }
                    finally { fclose($stream); }
                }
            }
            if (! $image) {
                $this->failure($mirror, 'source-unavailable');
                return false;
            }
            $this->imageCache = ['key' => $cacheKey, 'image' => $image];
            $contentHash = hash('sha256', $image['bytes']);
            $sameContent = ThumbnailMirror::where('content_hash', $contentHash)->where('provider_id', $provider->id)
                ->where('status', 'available')->where('next_check_at', '>', now())->first();
            $mirror->forceFill(['content_hash' => $contentHash])->save();
            $result = $sameContent ? ['url' => $sameContent->thumbnail_url, 'result' => 'reused', 'server_failure' => false]
                : $this->hosters->upload($provider->id, $image, $mirror->source_version, $deadline);
            $this->providerResult($provider, $video->id, $result);
            if (! $result['url']) {
                $this->failure($mirror, $result['result']);
                return false;
            }
            $probe = $local ? $this->http->probe($result['url'], $deadline) : ['ok' => true];
            if (! $probe['ok']) {
                // Keep the returned URL: a verification timeout must not cause another upload.
                $mirror->forceFill(['thumbnail_url' => $result['url'], 'status' => 'unverified',
                    'next_check_at' => NightWindow::next(), 'next_attempt_at' => null, 'last_result' => 'verification-pending'])->save();
                return false;
            }
            $this->success($mirror, $result['url'], $local ? null : NightWindow::next());
            if (! $local) $mirror->forceFill(['last_checked_at' => null, 'last_result' => 'uploaded'])->save();
            if ($local) {
                $updated = Video::whereKey($video->id)->where('thumbnail_source_version', $mirror->source_version)
                    ->where('thumbnail_path', $local)->update(['thumbnail_path' => null, 'thumbnail_source_url' => $result['url'], 'thumbnail_is_custom' => true]);
                if ($updated) \App\Support\MediaStorage::delete([$local]);
            }
            return true;
        } catch (Throwable $e) {
            $result = in_array($e->getMessage(), ['timeout', 'dns-failure', 'source-unavailable', 'size-limit', 'invalid-image'], true)
                ? $e->getMessage() : 'provider-error';
            $this->failure($mirror, $result);
            if (in_array($result, ['timeout', 'dns-failure'], true)) $this->providerResult($provider, $video->id, ['url' => null, 'server_failure' => true, 'result' => $result]);
            if ($result === 'provider-error') $provider->forceFill(['paused_until' => now()->addMinutes(30), 'last_result' => $result])->save();
            return false;
        }
    }

    public function check(ThumbnailMirror $mirror, float $deadline): void
    {
        try {
            $result = $this->http->probe($mirror->thumbnail_url, $deadline);
        } catch (Throwable) {
            $result = ['ok' => false, 'server_failure' => true, 'result' => 'network-error'];
        }
        if ($mirror->provider_id !== 'original') $this->providerResult($mirror->provider, $mirror->video_id, ['url' => $result['ok'] ? $mirror->thumbnail_url : null, ...$result]);
        if ($result['ok']) {
            $this->success($mirror, $mirror->thumbnail_url);
            $video = $mirror->video;
            $local = $video?->thumbnail_path;
            if ($local && ! RemoteImages::urlAllowed($local) && $video->thumbnail_source_version === $mirror->source_version && $mirror->provider_id !== 'original') {
                $updated = Video::whereKey($video->id)->where('thumbnail_source_version', $mirror->source_version)->where('thumbnail_path', $local)
                    ->update(['thumbnail_path' => null, 'thumbnail_source_url' => $mirror->thumbnail_url, 'thumbnail_is_custom' => true]);
                if ($updated) \App\Support\MediaStorage::delete([$local]);
            }
        }
        else {
            $this->failure($mirror, $result['result']);
            if ($mirror->provider_id !== 'original' && in_array($result['result'], ['http:404', 'http:410'], true)) {
                $mirror->forceFill(['thumbnail_url' => null, 'next_check_at' => null, 'next_attempt_at' => now()])->save();
            }
        }
    }

    public function providerResult(ThumbnailProvider $provider, int $videoId, array $result): void
    {
        if (! empty($result['url'])) {
            $provider->forceFill(['failed_video_ids' => [], 'paused_until' => null, 'circuit_open' => false, 'last_result' => 'ok'])->save();
            return;
        }
        if ($result['server_failure'] ?? false) {
            $ids = array_slice(array_values(array_unique([...($provider->failed_video_ids ?: []), $videoId])), -3);
            $open = $provider->circuit_open || count($ids) >= 3;
            $provider->forceFill(['failed_video_ids' => $ids, 'circuit_open' => $open,
                'paused_until' => $open ? now()->addMinutes(30) : null, 'last_result' => $result['result']])->save();
        } else {
            $pause = in_array($result['result'], ['http:401', 'http:403', 'http:429'], true);
            $provider->forceFill(['failed_video_ids' => [], 'circuit_open' => false,
                'paused_until' => $pause ? NightWindow::next() : null, 'last_result' => $result['result']])->save();
        }
    }

    private function success(ThumbnailMirror $mirror, string $url, mixed $nextCheck = null): void
    {
        $mirror->forceFill(['thumbnail_url' => $url, 'status' => 'available', 'last_checked_at' => now(),
            'next_check_at' => $nextCheck ?: now()->addDays(7), 'next_attempt_at' => null, 'failure_count' => 0, 'last_result' => 'ok'])->save();
    }

    private function failure(ThumbnailMirror $mirror, string $result): void
    {
        $failures = $mirror->failure_count + 1;
        $next = NightWindow::next(min(7, 2 ** min(3, $failures - 1)));
        $mirror->forceFill(['status' => 'failed', 'failure_count' => $failures, 'last_checked_at' => now(),
            'next_check_at' => $mirror->thumbnail_url ? $next : null, 'next_attempt_at' => $next, 'last_result' => mb_substr($result, 0, 40)])->save();
    }
}
