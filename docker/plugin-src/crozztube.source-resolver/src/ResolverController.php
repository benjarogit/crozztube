<?php

namespace CrzSourceResolver;

use App\Models\Video;
use CrzSourceResolver\Jobs\BaseSourceJob;
use CrzSourceResolver\Jobs\RefreshSource;
use CrzSourceResolver\Models\CrozztubeImportSource;
use CrzSourceResolver\Support\Mirror;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResolverController extends Controller
{
    public function status(Request $request, int $videoId): JsonResponse
    {
        $video = $this->findImport($videoId);
        if ($video instanceof JsonResponse) {
            return $video;
        }

        return response()->json(['ok' => true, 'source' => $this->statePayload($video)]);
    }

    /**
     * Betriebsstatus: Plugin-/yt-dlp-Version, Quellenzahlen und letzter
     * erfolgreicher Resolve. Read-only und ohne Secrets.
     */
    public function health(): JsonResponse
    {
        $resolver = Resolver::instance();
        $binaries = $resolver?->health() ?? [];
        $plugin = \App\Models\Plugin::query()->where('id', 'crozztube.source-resolver')->first();

        $byState = CrozztubeImportSource::query()
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $lastResolved = CrozztubeImportSource::query()->max('resolved_at');

        return response()->json([
            'ok' => true,
            'health' => [
                'plugin' => [
                    'id' => 'crozztube.source-resolver',
                    'version' => $plugin?->version,
                    'enabled' => (bool) $plugin?->enabled,
                ],
                'ytdlp' => $binaries['ytdlp'] ?? [
                    'binary' => 'yt-dlp',
                    'version' => null,
                    'available' => false,
                ],
                'ffprobe' => $binaries['ffprobe'] ?? [
                    'binary' => 'ffprobe',
                    'version' => null,
                    'available' => false,
                ],
                'sources' => [
                    'total' => array_sum($byState),
                    'by_state' => $byState,
                    'fresh' => $byState['fresh'] ?? 0,
                    'stale' => $byState['stale'] ?? 0,
                    'error' => $byState['error'] ?? 0,
                    'last_resolved_at' => $lastResolved,
                ],
            ],
        ]);
    }

    /**
     * Status per slug: the watch page knows the slug, so the frontend never has
     * to load the full video payload just to map slug -> id.
     */
    public function statusBySlug(Request $request, string $slug): JsonResponse
    {
        $video = Video::query()->where('slug', $slug)->first();
        if (! $video || $video->source_type !== 'import') {
            return response()->json(['ok' => false, 'message' => 'Not an imported video.'], 404);
        }

        return response()->json(['ok' => true, 'source' => $this->statePayload($video)]);
    }

    /**
     * Loest einen Refresh der Direkt-URL aus (idempotent, Single-Flight im Job).
     */
    public function refresh(Request $request, int $videoId): JsonResponse
    {
        $video = $this->findImport($videoId);
        if ($video instanceof JsonResponse) {
            return $video;
        }

        RefreshSource::request($videoId, force: true, allowFailed: $request->boolean('manual'));

        return response()->json(['ok' => true, 'source' => $this->statePayload($video)]);
    }

    /**
     * Download-Passthrough: streamt die best progressive MP4 direkt vom Original
     * (kein Speichern, kein Re-Hosting). Fallback: 302 zur Original-URL.
     */
    public function download(Request $request, int $videoId): StreamedResponse|JsonResponse
    {
        if (($check = $this->findImport($videoId)) instanceof JsonResponse) {
            return $check;
        }

        $row = CrozztubeImportSource::query()->where('video_id', $videoId)->first();
        if (! $row || ! $row->hasDirectSource()) {
            return response()->json(['ok' => false, 'message' => 'Direct source not resolved yet.'], 409);
        }

        if (! is_string($row->download_url) || $row->download_url === '') {
            return response()->json(['ok' => false, 'message' => 'No progressive MP4 available (HLS-only source).'], 409);
        }

        $resolver = Resolver::instance();
        if ($resolver && $resolver->downloadViaProxy()) {
            $filename = $this->downloadFilename($video);

            return response()->streamDownload(function () use ($row): void {
                $this->streamRemote((string) $row->download_url, 120);
            }, $filename, [
                'Content-Type' => 'video/mp4',
                'Cache-Control' => 'no-store',
            ]);
        }

        return response()->redirectTo($row->download_url);
    }

    /**
     * Synthetisches HLS-Master-Manifest: verbindet gespeicherte Video-Playlists
     * mit der Audio-Playlist. Alle CDNs werden via /playlist-Proxy geladen
     * (Passthrough, nichts gespeichert).
     */
    public function manifest(Request $request, int $videoId)
    {
        $row = CrozztubeImportSource::query()->where('video_id', $videoId)->first();

        // Safety net for clients without the enhancer: an expired source is
        // re-resolved in the background. The current manifest is still served
        // so a URL that merely looks expired does not break playback.
        if ($row && ! $row->isFresh()) {
            RefreshSource::request($videoId, force: true);
        }

        if (! $row
            || $row->kind !== 'hls-synth'
            || ! is_array($row->variants)
            || $row->variants === []
            || ! is_string($row->audio_url)
            || $row->audio_url === '') {
            return response()->json(['ok' => false, 'message' => 'No composite HLS source.'], 404);
        }

        $base = rtrim(url('api/source-resolver'), '/').'/playlist?url=';
        // Pass the video id along so a failing CDN URL can trigger a refresh.
        $suffix = '&video_id='.$videoId;

        $tracks = $row->audio_tracks ?: [['language' => 'und', 'label' => 'Audio', 'codecs' => 'mp4a.40.2', 'url' => $row->audio_url]];
        $master = \CrzSourceResolver\Support\AudioTracks::master($row->variants, $tracks, $base, $suffix);

        return response($master, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Passthrough einer HLS-Playlist vom allowlisteten CDN. Alle Segment-URLs
     * in der Playlist werden auf /seg umgeschrieben (weiterhin Original-CDN).
     */
    public function playlist(Request $request)
    {
        $src = (string) $request->query('url', '');

        if (! $this->allowedCdnUrl($src)) {
            return response()->json(['ok' => false, 'message' => 'Blocked host.'], 403);
        }

        return response()->streamDownload(function () use ($src, $request): void {
            $playlist = $this->fetchRemote($src, 25);

            if ($playlist === false || $playlist === '') {
                // CDN rejected the stored URL (expired signature): renew it.
                $videoId = (int) $request->integer('video_id');
                if ($videoId > 0) {
                    RefreshSource::request($videoId, force: true);
                }

                echo "#EXTM3U\n";

                return;
            }

            $prefix = rtrim(url('api/source-resolver'), '/');

            foreach (preg_split('/\R/', $playlist) as $line) {
                $line = rtrim((string) $line);

                if ($line === '') {
                    continue;
                }

                if ($line[0] === '#') {
                    echo preg_replace_callback(
                        '/URI="([^"]+)"/',
                        fn (array $matches): string => str_starts_with($matches[1], 'http')
                            ? 'URI="'.$prefix.'/seg?url='.urlencode($matches[1]).'"'
                            : $matches[0],
                        $line
                    )."\n";

                    continue;
                }

                echo str_starts_with($line, 'http')
                    ? $prefix.'/seg?url='.urlencode($line)."\n"
                    : $line."\n";
            }
        }, 'playlist.m3u8', [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Passthrough einzelner Mediensegmente (HLS.js xhr). Kein Speichern.
     */
    public function segment(Request $request)
    {
        $src = (string) $request->query('url', '');

        if (! $this->allowedCdnUrl($src)) {
            return response()->json(['ok' => false, 'message' => 'Blocked host.'], 403);
        }

        return response()->streamDownload(function () use ($src): void {
            $this->streamRemote($src, 60);
        }, 'segment.mp4', [
            'Content-Type' => 'video/mp4',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Laedt eine Remote-Ressource komplett (kleine Playlists).
     */
    private function fetchRemote(string $src, int $timeoutSec): string|false
    {
        $handle = $this->curlFor($src, $timeoutSec, true);

        $body = curl_exec($handle);

        curl_close($handle);

        return is_string($body) ? $body : false;
    }

    /**
     * Streamt eine Remote-Ressource direkt in den Response (kein Speichern).
     */
    private function streamRemote(string $src, int $timeoutSec): void
    {
        $handle = $this->curlFor($src, $timeoutSec, false);

        curl_exec($handle);
        curl_close($handle);
    }

    private function curlFor(string $src, int $timeoutSec, bool $returnTransfer): \CurlHandle
    {
        $handle = curl_init($src);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => $returnTransfer,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => ['Accept: */*', 'User-Agent: Mozilla/5.0 (X11; Linux x86_64)'],
        ]);

        if (! $returnTransfer) {
            curl_setopt_array($handle, [
                CURLOPT_WRITEFUNCTION => function ($handle, string $data): int {
                    echo $data;

                    if (connection_aborted()) {
                        return -1;
                    }

                    return strlen($data);
                },
            ]);
        }

        return $handle;
    }

    private function allowedCdnUrl(string $src): bool
    {
        $parts = parse_url($src);

        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = (string) ($parts['host'] ?? '');

        return str_ends_with($host, '.googlevideo.com');
    }

    private function findImport(int $videoId): Video|JsonResponse
    {
        $video = Video::query()->find($videoId);
        if (! $video || $video->source_type !== 'import') {
            return response()->json(['ok' => false, 'message' => 'Not an imported video.'], 404);
        }

        return $video;
    }

    private function statePayload(Video $video): array
    {
        $videoId = (int) $video->id;
        $row = CrozztubeImportSource::query()->where('video_id', $videoId)->first();
        $host = (string) (request()->getHost() ?: parse_url((string) config('app.url'), PHP_URL_HOST));
        $mirror = Mirror::forVideo($video, $host);

        $base = [
            'video_id' => $videoId,
            'slug' => $video->slug,
            'mirror' => $mirror,
        ];

        if (! $row) {
            return $base + [
                'state' => 'unknown',
                'kind' => null,
                'has_direct' => false,
                'fresh' => false,
                'refreshing' => false,
                'downloadable' => false,
                'error_message' => null,
                'expires_at' => null,
                'expires_in' => null,
                'can_refresh' => true,
                'retry_after' => 3,
                'refresh_started_at' => null,
            ];
        }

        // Ein haengender Refresh (Worker abgestuerzt) darf die Seite nicht
        // dauerhaft im "wird erneuert"-Zustand lassen.
        $refreshing = $row->state === 'refreshing'
            && ($row->refresh_started_at === null
                || $row->refresh_started_at->gt(now()->subSeconds(BaseSourceJob::LOCK_TTL - 60)));
        // Match VideoSources: pre-audio-metadata records must be renewed before
        // native playback can use them, even while their old URL has time left.
        $fresh = $row->isFresh() && $row->audio_tracks !== null;

        return $base + [
            'state' => $row->state,
            'kind' => $row->kind,
            'has_direct' => $row->hasDirectSource(),
            'fresh' => $fresh,
            'refreshing' => $refreshing,
            'error_category' => $row->error_category,
            'auto_refresh_allowed' => ! in_array($row->error_category, ['permanent', 'unknown_exhausted'], true),
            'error_message' => in_array($row->state, ['error', 'stale'], true) ? $row->error_message : null,
            'downloadable' => $row->hasDirectSource() && is_string($row->download_url) && $row->download_url !== '',
            'expires_at' => optional($row->expires_at)->toIso8601String(),
            'expires_in' => $row->expires_at ? (int) max(0, now()->diffInSeconds($row->expires_at, false)) : null,
            'can_refresh' => ! $fresh,
            'retry_after' => $refreshing ? 2 : 5,
            'refresh_started_at' => optional($row->refresh_started_at)->toIso8601String(),
        ];
    }

    private function downloadFilename(Video $video): string
    {
        $base = trim((string) $video->title) ?: 'crozztube-video';

        return preg_replace('/[^\w\-. ]+/u', '', $base).'.mp4';
    }
}
