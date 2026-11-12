<?php

namespace CrzSourceResolver;

use App\Plugins\PluginContext;
use CrzSourceResolver\Models\CrozztubeImportSource;
use CrzSourceResolver\Support\AudioTracks;
use RuntimeException;
use Symfony\Component\Process\Process;

class Resolver
{
    public function __construct(private readonly PluginContext $context)
    {
    }

    public static function available(): bool
    {
        try {
            return (bool) config('playhub.plugins_enabled', true)
                && \App\Models\Plugin::query()
                    ->where('id', 'crozztube.source-resolver')
                    ->where('enabled', true)
                    ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function instance(): ?self
    {
        if (! self::available() || ! app()->bound(self::class)) {
            return null;
        }

        return app(self::class);
    }

    private function stringSetting(string $key, string $default): string
    {
        $value = $this->context->setting($key, $default);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $value = $this->context->setting($key, $default);

        return is_bool($value) ? $value : $default;
    }

    private function intSetting(string $key, int $default): int
    {
        $value = $this->context->setting($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    public function preferHls(): bool
    {
        return $this->boolSetting('prefer_hls', true);
    }

    public function downloadViaProxy(): bool
    {
        return $this->boolSetting('download_via_proxy', true);
    }

    /**
     * Betriebsstatus fuer den Health-Endpoint: verwendet genau die Binaries,
     * die auch beim Aufloesen benutzt werden.
     *
     * @return array{ytdlp:array{binary:string,version:?string,available:bool},
     *               ffprobe:array{binary:string,version:?string,available:bool}}
     */
    public function health(): array
    {
        $ytdlp = $this->stringSetting('ytdlp_command', 'yt-dlp');
        $ffprobe = $this->stringSetting('ffprobe_command', 'ffprobe');
        $ytdlpVersion = $this->binaryVersion($ytdlp, '--version');
        $ffprobeVersion = $this->binaryVersion($ffprobe, '-version');

        return [
            'ytdlp' => [
                'binary' => $ytdlp,
                'version' => $ytdlpVersion,
                'available' => $ytdlpVersion !== null,
            ],
            'ffprobe' => [
                'binary' => $ffprobe,
                'version' => $ffprobeVersion,
                'available' => $ffprobeVersion !== null,
            ],
        ];
    }

    private function binaryVersion(string $binary, string $flag): ?string
    {
        try {
            $process = new Process([$binary, $flag], null, null, null, 15.0);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $line = trim(strtok($process->getOutput(), "\n") ?: '');

            return $line !== '' ? mb_substr($line, 0, 200) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fuehrt yt-dlp aus (nur URL-/Metadaten-Extraktion, kein Download) und waehlt die
     * beste abspielbare Direkt-URL aus: HLS-Manifest bevorzugt, sonst progressive MP4.
     *
     * @return array{media_url:string, kind:string, download_url:?string, ttl_seconds:int,
     *               title:?string, duration_seconds:?int, thumbnail_url:?string, chapters:?array}
     */
    public function resolve(string $url, ?int $videoId = null): array
    {
        $provider = \App\Support\MediaSourceMetadata::providerForUrl($url);
        $callback = \App\Support\VideoImportSettings::providers()[$provider ?? '']['source_candidates'] ?? null;
        if (! is_callable($callback)) return $this->fromMetadata($this->extract($url));

        $discovery = $callback($url);
        if (! is_array($discovery) || ! is_array($discovery['playback_candidates'] ?? null)
            || ! is_array($discovery['metadata'] ?? null)) {
            throw new \App\Support\ResolverFailure('permanent', 'The provider returned invalid source candidates.');
        }
        if ($videoId !== null && ($video = \App\Models\Video::query()->find($videoId))) {
            $expectedId = \App\Support\MediaSourceMetadata::providerIdForVideo($video, $provider);
            if ($expectedId && $expectedId !== ($discovery['metadata']['provider_id'] ?? null)) {
                throw new \App\Support\ResolverFailure('permanent', 'The provider video identity has changed.');
            }
        }
        $failure = null;
        foreach (array_slice($discovery['playback_candidates'], 0, 3) as $candidate) {
            // Provider callbacks own their CDN allowlist; the shared contract still
            // rejects credentials, custom ports and non-HTTP transport before extraction.
            $parts = is_string($candidate) ? parse_url($candidate) : false;
            if (! is_array($parts) || ! filter_var($candidate, FILTER_VALIDATE_URL)
                || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])
                || (isset($parts['port']) && $parts['port'] !== (strtolower($parts['scheme']) === 'https' ? 443 : 80))) continue;
            try {
                $extracted = $this->extract($candidate, 45.0);
                // Generic extraction of a direct master reports its URL on each
                // format, but may omit top-level manifest_url. Keep the provider's
                // validated master so already muxed A/V need not use a split proxy.
                if (str_ends_with(strtolower((string) parse_url($candidate, PHP_URL_PATH)), '.m3u8')) {
                    $extracted['manifest_url'] = $candidate;
                }
                $resolved = $this->fromMetadata($extracted);
                // Generic direct-file metadata (title/ID/chapters) must not replace
                // the provider's verified record or erase its chapter information.
                $resolved['metadata'] = array_replace($resolved['metadata'], array_filter(
                    $discovery['metadata'], static fn ($value): bool => $value !== null && $value !== [] && $value !== ''
                ));
                foreach (['title', 'duration_seconds', 'thumbnail_url', 'chapters'] as $key) {
                    if (isset($resolved['metadata'][$key])) $resolved[$key] = $resolved['metadata'][$key];
                }

                return $resolved;
            } catch (\Throwable $exception) {
                $exception = $exception instanceof \App\Support\ResolverFailure ? $exception
                    : new \App\Support\ResolverFailure($exception instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException ? 'transient' : 'unknown', 'Provider source extraction failed.');
                // A transiently broken backup should still be retryable even if
                // a preceding host has permanently removed its copy.
                if ($failure?->category !== 'transient') $failure = $exception;
            }
        }

        throw $failure ?? new \App\Support\ResolverFailure('permanent', 'No compatible provider playback source.');
    }

    public static function supportsSourceCandidates(): bool
    {
        return true;
    }

    /** Raw extractor output; separated so provider fallback ordering can be verified without network calls. */
    protected function extract(string $url, float $timeout = 180.0): array
    {
        $binary = $this->stringSetting('ytdlp_command', 'yt-dlp');

        $process = new Process(
            [$binary, '-J', '--no-playlist', '--no-warnings', '--socket-timeout', '30', $url],
            null,
            null,
            null,
            $timeout
        );
        $process->run();

        if (! $process->isSuccessful()) {
            $tail = trim(implode("\n", array_slice(explode("\n", $process->getErrorOutput() ?: $process->getOutput()), -3)));

            throw \App\Support\ResolverFailure::fromOutput($tail);
        }

        $data = json_decode($process->getOutput(), true);
        if (! is_array($data)) {
            throw new \App\Support\ResolverFailure('unknown', 'yt-dlp returned invalid JSON.');
        }

        return $data;
    }

    /** Select sources without losing the extractor's audio language/role information. */
    public function fromMetadata(array $data): array
    {

        $formats = array_values(array_filter(
            is_array($data['formats'] ?? null) ? $data['formats'] : [],
            fn ($format): bool => is_array($format)
                && is_string($format['url'] ?? null)
                && filter_var($format['url'], FILTER_VALIDATE_URL) !== false
                && ! ($format['has_drm'] ?? false)
        ));

        $originalLanguage = AudioTracks::originalLanguage($formats);

        $progressive = array_filter($formats, function (array $format): bool {
            if (! str_starts_with((string) ($format['protocol'] ?? ''), 'http')) {
                return false;
            }

            $ext = strtolower((string) ($format['ext'] ?? ''));
            if (! in_array($ext, ['mp4', 'webm', 'm4v', 'mov'], true)) {
                return false;
            }

            // yt-dlp meldet bei einer direkt verlinkten Datei (Generic-Extractor)
            // oft weder vcodec noch acodec und keine Hoehe. Die Datei ist dann
            // trotzdem eine vollstaendige progressive Quelle.
            $unknownDirect = ($format['vcodec'] ?? null) === null
                && ($format['acodec'] ?? null) === null;

            if ($unknownDirect) {
                return true;
            }

            return ($format['vcodec'] ?? '') !== 'none'
                && ($format['acodec'] ?? '') !== 'none'
                && ($format['height'] ?? 0) > 0;
        });

        usort($progressive, fn (array $a, array $b): int =>
            [AudioTracks::preference($b, $originalLanguage), $b['height'] ?? 0, $b['tbr'] ?? 0]
            <=> [AudioTracks::preference($a, $originalLanguage), $a['height'] ?? 0, $a['tbr'] ?? 0]);
        $bestProgressive = $progressive[0] ?? null;
        if ($originalLanguage && $bestProgressive && AudioTracks::preference($bestProgressive, $originalLanguage) < 100) {
            $bestProgressive = null;
        }

        $manifest = is_string($data['manifest_url'] ?? null) && filter_var($data['manifest_url'], FILTER_VALIDATE_URL)
            ? $data['manifest_url']
            : null;

        // HLS-Einzel-Playlists (z. B. YouTube ohne Master-Manifest):
        // Video-Playlists (m3u8, vcodec != none) + Audio-Playlists (m3u8, vcodec == none).
        $hlsVideo = [];
        $allHlsVideoMuxed = true;
        $audioTracks = AudioTracks::hls($formats);
        if ($originalLanguage && empty($audioTracks[0]['original'])) {
            // The original can exist only as DASH while HLS exposes a dub.
            // Don't silently substitute that dub as the native default.
            $audioTracks = [];
            $manifest = null;
        }
        foreach ($formats as $format) {
            $protocol = (string) ($format['protocol'] ?? '');
            if (! str_starts_with($protocol, 'm3u8')) {
                continue;
            }

            $url = (string) ($format['url'] ?? '');
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $height = (int) ($format['height'] ?? 0);
            $vcodec = (string) ($format['vcodec'] ?? 'none');

            if ($height > 0 && $vcodec !== 'none') {
                $allHlsVideoMuxed = $allHlsVideoMuxed
                    && is_string($format['acodec'] ?? null)
                    && ! in_array($format['acodec'], ['', 'none', 'unknown'], true);
                $hlsVideo[] = [
                    'height' => $height,
                    'width' => (int) ($format['width'] ?? 0),
                    'bandwidth' => (int) round(((float) ($format['tbr'] ?? 0)) * 1000),
                    'codecs' => $vcodec,
                    'url' => $url,
                ];
            }
        }

        // Pro Hoehe die variant mit der hoechsten Bandbreite behalten (AVC z. B. vor VP9).
        usort($hlsVideo, fn (array $a, array $b): int => [$b['height'], $b['bandwidth']] <=> [$a['height'], $a['bandwidth']]);
        $variants = [];
        $seenHeights = [];
        foreach ($hlsVideo as $variant) {
            if (isset($seenHeights[$variant['height']])) {
                continue;
            }
            $seenHeights[$variant['height']] = true;
            // Audio-Schaetzung (AAC HLS, ~140 kbps) in die Stream-Bandbreite einrechnen.
            $variant['bandwidth'] += 140000;
            $variants[] = $variant;
        }
        $variants = array_slice($variants, 0, 8);

        $audioUrl = $audioTracks[0]['url'] ?? null;

        if ($manifest && $variants !== [] && $allHlsVideoMuxed && ! $originalLanguage
            && count($audioTracks) <= 1 && ! ($audioTracks[0]['descriptive'] ?? false)
            && ($this->preferHls() || ! $bestProgressive)) {
            // Every video rendition already carries audio. A separate audio-only
            // quality entry is not an alternate language: the published master
            // preserves relative init/segment URLs and byte-range delivery itself.
            [$kind, $mediaUrl] = ['hls', $manifest];
        } elseif ($variants !== [] && $audioUrl !== null && ($this->preferHls() || count($audioTracks) > 1 || ! $bestProgressive)) {
            // A local master preserves alternate languages and marks the original
            // as default instead of inheriting a regional dub from a remote master.
            [$kind, $mediaUrl] = ['hls-synth', ''];
        } elseif ($this->preferHls() && $manifest) {
            [$kind, $mediaUrl] = ['hls', $manifest];
        } elseif ($bestProgressive) {
            [$kind, $mediaUrl] = ['mp4', (string) $bestProgressive['url']];
        } elseif ($manifest) {
            [$kind, $mediaUrl] = ['hls', $manifest];
        } elseif ($variants !== [] && $audioUrl !== null) {
            // Synthetisches Master-Manifest wird vom Manifest-Endpoint erzeugt
            // (Video- + Audio-Segmente streamen direkt vom Original-CDN).
            [$kind, $mediaUrl] = ['hls-synth', ''];
        } else {
            throw new \App\Support\ResolverFailure('permanent', 'No compatible direct playback source.');
        }

        $downloadUrl = $bestProgressive ? (string) $bestProgressive['url'] : null;
        $isDirectFile = $bestProgressive !== null && (int) ($bestProgressive['height'] ?? 0) <= 0;

        $ttlCandidates = [$mediaUrl];
        if ($kind === 'hls-synth') {
            $ttlCandidates = [...array_column($variants, 'url'), ...array_column($audioTracks, 'url')];
        }

        $ttl = null;
        foreach ($ttlCandidates as $candidate) {
            $candidateTtl = $this->ttlFromUrl((string) $candidate);

            if ($candidateTtl !== null && ($ttl === null || $candidateTtl < $ttl)) {
                $ttl = $candidateTtl;
            }
        }
        if ($ttl !== null && $ttl <= 0) {
            throw new \App\Support\ResolverFailure('transient', 'The provider returned an expired playback source.');
        }
        $ttl ??= $isDirectFile
            ? 86400
            : $this->intSetting('stale_fallback_minutes', 180) * 60;

        $chapters = null;
        if (is_array($data['chapters'] ?? null)) {
            $chapters = collect($data['chapters'])
                ->map(fn ($chapter): array => [
                    'time' => (float) ($chapter['start_time'] ?? ($chapter['timestamp'] ?? 0)),
                    'title' => (string) ($chapter['title'] ?? ''),
                ])
                ->values()
                ->all();
        }

        $normalized = \App\Support\YtDlp::normalize($data);
        $duration = is_numeric($data['duration'] ?? null) ? (int) ceil($data['duration']) : null;
        // Generic extractor does not report a duration for a directly linked
        // file; probe it so feed cards/watch metadata are not blank.
        if (($duration === null || empty($normalized['width']) || empty($normalized['height'])) && $isDirectFile && $bestProgressive !== null) {
            $probe = $this->probeMedia((string) $bestProgressive['url']);
            $duration ??= $probe['duration_seconds'];
            $normalized['width'] = $probe['width'];
            $normalized['height'] = $probe['height'];
        }

        return [
            'metadata' => [...$normalized, 'duration_seconds' => $duration],
            'media_url' => $mediaUrl,
            'kind' => $kind,
            'download_url' => $downloadUrl,
            'variants' => $variants,
            'audio_url' => $audioUrl,
            'audio_tracks' => $audioTracks,
            'ttl_seconds' => $ttl,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : null,
            'duration_seconds' => $duration,
            'thumbnail_url' => is_string($data['thumbnail'] ?? null) ? $data['thumbnail'] : null,
            'chapters' => $chapters,
        ];
    }

    /**
     * Liest die Dauer einer direkt verlinkten Datei (nur Metadaten, kein Download).
     */
    private function probeMedia(string $url): array
    {
        $binary = $this->stringSetting('ffprobe_command', 'ffprobe');
        $process = new Process([$binary, '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'format=duration:stream=width,height:stream_side_data=rotation', '-of', 'json', $url], null, null, null, 30.0);
        $process->run();
        $data = $process->isSuccessful() ? json_decode($process->getOutput(), true) : [];
        $stream = $data['streams'][0] ?? [];
        $width = $stream['width'] ?? null;
        $height = $stream['height'] ?? null;
        if (abs((int) ($stream['side_data_list'][0]['rotation'] ?? 0)) % 180 === 90) [$width, $height] = [$height, $width];
        return ['duration_seconds' => ! empty($data['format']['duration']) ? (int) ceil($data['format']['duration']) : null, 'width' => $width, 'height' => $height];
    }

    /**
     * Parst eine Ablaufzeit aus der Direkt-URL (z. B. ?expire=... bei YouTube-CDN-URLs).
     */
    private function ttlFromUrl(string $url): ?int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $expire = isset($query['expire']) ? (int) $query['expire'] : 0;

        if ($expire > 0) {
            return max(0, $expire - time() - 300);
        }

        return null;
    }

    /**
     * Loest die Quelle auf und schreibt den Datensatz. Wird von den Jobs genutzt.
     */
    public function resolveForVideo(CrozztubeImportSource $row): CrozztubeImportSource
    {
        $resolved = $this->resolve((string) $row->video->import_url, (int) $row->video_id);

        $mediaUrl = $resolved['media_url'];

        if ($resolved['kind'] === 'hls-synth') {
            $mediaUrl = (string) url('api/source-resolver/manifest/'.$row->video_id);
        }

        $row->update([
            'media_url' => $mediaUrl,
            'download_url' => $resolved['download_url'],
            'audio_url' => $resolved['audio_url'],
            'audio_tracks' => $resolved['audio_tracks'],
            'variants' => $resolved['variants'],
            'kind' => $resolved['kind'],
            'state' => 'fresh',
            'expires_at' => now()->addSeconds($resolved['ttl_seconds']),
            'resolved_at' => now(),
            'refresh_started_at' => null,
            'error_message' => null,
            'error_category' => null,
        ]);

        $this->backfillMetadata($row, $resolved);

        return $row;
    }

    /** Reuse metadata from the playback extraction; no second yt-dlp process. */
    private function backfillMetadata(CrozztubeImportSource $row, array $resolved): void
    {
        if ($row->video) {
            \App\Support\ImportMetadataBackfill::apply($row->video, $resolved['metadata'] ?? []);
        }
    }

    public function rowForVideoId(int $videoId): ?CrozztubeImportSource
    {
        return CrozztubeImportSource::query()->where('video_id', $videoId)->first();
    }
}
