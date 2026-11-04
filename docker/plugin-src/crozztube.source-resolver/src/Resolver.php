<?php

namespace CrzSourceResolver;

use App\Plugins\PluginContext;
use CrzSourceResolver\Models\CrozztubeImportSource;
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
     * Fuehrt yt-dlp aus (nur URL-/Metadaten-Extraktion, kein Download) und waehlt die
     * beste abspielbare Direkt-URL aus: HLS-Manifest bevorzugt, sonst progressive MP4.
     *
     * @return array{media_url:string, kind:string, download_url:?string, ttl_seconds:int,
     *               title:?string, duration_seconds:?int, thumbnail_url:?string, chapters:?array}
     */
    public function resolve(string $url, ?int $videoId = null): array
    {
        $binary = $this->stringSetting('ytdlp_command', 'yt-dlp');

        $process = new Process(
            [$binary, '-J', '--no-playlist', '--no-warnings', '--socket-timeout', '30', $url],
            null,
            null,
            null,
            180.0
        );
        $process->run();

        if (! $process->isSuccessful()) {
            $tail = trim(implode("\n", array_slice(explode("\n", $process->getErrorOutput() ?: $process->getOutput()), -3)));

            throw new RuntimeException('yt-dlp failed: '.mb_substr($tail, 0, 500));
        }

        $data = json_decode($process->getOutput(), true);
        if (! is_array($data)) {
            throw new RuntimeException('yt-dlp returned invalid JSON.');
        }

        $formats = array_values(array_filter(
            is_array($data['formats'] ?? null) ? $data['formats'] : [],
            fn ($format): bool => is_array($format)
                && is_string($format['url'] ?? null)
                && filter_var($format['url'], FILTER_VALIDATE_URL) !== false
        ));

        $progressive = array_filter($formats, fn (array $format): bool => str_starts_with((string) $format['protocol'], 'http')
            && ($format['ext'] ?? '') === 'mp4'
            && ($format['vcodec'] ?? '') !== 'none'
            && ($format['acodec'] ?? '') !== 'none'
            && ($format['height'] ?? 0) > 0);

        usort($progressive, fn (array $a, array $b): int => [$b['height'] ?? 0, $b['tbr'] ?? 0] <=> [$a['height'] ?? 0, $a['tbr'] ?? 0]);
        $bestProgressive = $progressive[0] ?? null;

        $manifest = is_string($data['manifest_url'] ?? null) && filter_var($data['manifest_url'], FILTER_VALIDATE_URL)
            ? $data['manifest_url']
            : null;

        // HLS-Einzel-Playlists (z. B. YouTube ohne Master-Manifest):
        // Video-Playlists (m3u8, vcodec != none) + Audio-Playlists (m3u8, vcodec == none).
        $hlsVideo = [];
        $hlsAudio = [];
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
                $hlsVideo[] = [
                    'height' => $height,
                    'width' => (int) ($format['width'] ?? 0),
                    'bandwidth' => (int) round(((float) ($format['tbr'] ?? 0)) * 1000),
                    'codecs' => $vcodec,
                    'url' => $url,
                ];
            } elseif ($height === 0 && $vcodec === 'none') {
                $hlsAudio[] = $url;
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

        if (count($hlsAudio) > 1) {
            usort($hlsAudio, fn (string $a, string $b): int => (int) basename(parse_url($b, PHP_URL_PATH) ?: '') <=> (int) basename(parse_url($a, PHP_URL_PATH) ?: ''));
        }
        $audioUrl = $hlsAudio[0] ?? null;

        if ($this->preferHls() && $manifest) {
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
            throw new RuntimeException('No direct playback source (no HLS manifest, no progressive MP4, no HLS video+audio pair).');
        }

        $downloadUrl = $bestProgressive ? (string) $bestProgressive['url'] : null;

        $ttlCandidates = [$mediaUrl];
        if ($kind === 'hls-synth') {
            $ttlCandidates = [...array_column($variants, 'url'), (string) $audioUrl];
        }

        $ttl = null;
        foreach ($ttlCandidates as $candidate) {
            $candidateTtl = $this->ttlFromUrl((string) $candidate);

            if ($candidateTtl !== null && ($ttl === null || $candidateTtl < $ttl)) {
                $ttl = $candidateTtl;
            }
        }
        $ttl ??= $this->intSetting('stale_fallback_minutes', 180) * 60;

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

        return [
            'media_url' => $mediaUrl,
            'kind' => $kind,
            'download_url' => $downloadUrl,
            'variants' => $variants,
            'audio_url' => $audioUrl,
            'ttl_seconds' => $ttl,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : null,
            'duration_seconds' => is_numeric($data['duration'] ?? null) ? (int) $data['duration'] : null,
            'thumbnail_url' => is_string($data['thumbnail'] ?? null) ? $data['thumbnail'] : null,
            'chapters' => $chapters,
        ];
    }

    /**
     * Parst eine Ablaufzeit aus der Direkt-URL (z. B. ?expire=... bei YouTube-CDN-URLs).
     */
    private function ttlFromUrl(string $url): ?int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $expire = isset($query['expire']) ? (int) $query['expire'] : 0;

        if ($expire > 3 * time()) {
            return max(120, $expire - time() - 300);
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
            'variants' => $resolved['variants'],
            'kind' => $resolved['kind'],
            'state' => 'fresh',
            'expires_at' => now()->addSeconds($resolved['ttl_seconds']),
            'resolved_at' => now(),
            'refresh_started_at' => null,
            'error_message' => null,
        ]);

        return $row;
    }

    public function rowForVideoId(int $videoId): ?CrozztubeImportSource
    {
        return CrozztubeImportSource::query()->where('video_id', $videoId)->first();
    }
}
