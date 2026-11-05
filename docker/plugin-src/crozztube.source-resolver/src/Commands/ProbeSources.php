<?php

namespace CrzSourceResolver\Commands;

use CrzSourceResolver\Jobs\RefreshSource;
use CrzSourceResolver\Models\CrozztubeImportSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Prueft gespeicherte Direkt-URLs mit einem HEAD-Request (Fallback: 1-Byte-
 * Range-GET). Kein Download, keine Segmente - nur ein Gueltigkeitscheck.
 *
 * Laeuft nachts (Scheduler: 02:00-05:00), damit die Last nicht in die
 * Hauptzeit faellt, und ist selbst limitiert + gestaffelt.
 */
final class ProbeSources extends Command
{
    protected $signature = 'crozztube:sources:probe
        {--limit=20 : Maximum sources per run}
        {--min-age=60 : Skip sources checked within this many minutes}
        {--stagger=10 : Seconds between two dispatched refreshes}
        {--scheduled : Set by the scheduler (honours the pause flag)}
        {--dry-run : Only check and report, never refresh}';

    protected $description = 'Probe stored direct URLs for imported videos and refresh the broken ones.';

    public function handle(): int
    {
        if ($this->option('scheduled') && RefreshExpiringSources::maintenancePaused()) {
            $this->line('Maintenance paused (storage/framework/crozztube-maintenance.pause).');
            return self::SUCCESS;
        }

        $lock = Cache::lock('source-resolver:probe', 900);
        if (! $lock->get()) {
            $this->warn('Another probe run is still active.');
            return self::SUCCESS;
        }

        try {
            return $this->probe();
        } finally {
            $lock->release();
        }
    }

    private function probe(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $minAge = max(0, (int) $this->option('min-age'));
        $stagger = max(0, (int) $this->option('stagger'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subMinutes($minAge)->format('Y-m-d H:i:s');

        $rows = CrozztubeImportSource::query()->whereHas('video')
            ->whereIn('kind', ['hls', 'mp4', 'hls-synth'])
            ->where(fn ($q) => $q->whereNull('error_category')->orWhereNotIn('error_category', ['permanent', 'unknown_exhausted']))
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<', $cutoff);
            })
            ->orderByRaw('last_checked_at IS NOT NULL, last_checked_at ASC')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->line('Nothing to probe.');
            return self::SUCCESS;
        }

        $ok = 0;
        $broken = 0;
        $dispatched = 0;

        foreach ($rows as $row) {
            $url = $this->probeUrl($row);
            if ($url === null) {
                $row->forceFill([
                    'last_checked_at' => now(),
                    'last_check_status' => 'no-url',
                ])->save();
                $broken++;
                continue;
            }

            [$status, $detail] = $this->check($url);

            $row->forceFill([
                'last_checked_at' => now(),
                'last_check_status' => $status,
            ])->save();

            $this->line(sprintf(
                'video=%d status=%s%s%s',
                $row->video_id,
                $status,
                $detail !== '' ? ' ' : '',
                $detail
            ));

            if ($status === 'ok') {
                $ok++;
                continue;
            }

            $broken++;

            if ($dryRun) {
                continue;
            }

            // Broken URL: mark it stale immediately so waiting viewers see the
            // banner, then renew in the background (staggered).
            if ($row->hasDirectSource() && $row->state !== 'refreshing') {
                $row->forceFill(['state' => 'stale'])->save();
            }

            $queued = RefreshSource::request(
                (int) $row->video_id,
                force: true,
                delaySeconds: $stagger * $dispatched,
                queue: 'maintenance'
            );

            if ($queued) {
                $dispatched++;
            }
        }

        $this->info(sprintf(
            'Probed %d source(s): %d ok, %d broken, %d refresh job(s) queued.',
            $rows->count(),
            $ok,
            $broken,
            $dispatched
        ));

        return self::SUCCESS;
    }

    /**
     * Bei synthetischen HLS-Quellen zeigt media_url auf den eigenen Proxy;
     * geprueft werden muss die CDN-URL der ersten Variante bzw. des Audios.
     */
    private function probeUrl(CrozztubeImportSource $row): ?string
    {
        if ($row->kind === 'hls-synth') {
            $variants = is_array($row->variants) ? $row->variants : [];
            $candidate = $variants[0]['url'] ?? $row->audio_url;

            return is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : null;
        }

        $url = (string) $row->media_url;

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /**
     * @return array{0:string,1:string} [status, detail]
     */
    private function check(string $url): array
    {
        $status = $this->request($url, 'HEAD');
        $code = $status['code'];

        // Some CDNs answer HEAD with 405/403 while GET works.
        if ($code === 0 || in_array($code, [403, 405, 501], true)) {
            $fallback = $this->request($url, 'GET');
            if ($fallback['code'] >= 200 && $fallback['code'] < 400) {
                $status = $fallback;
                $code = $fallback['code'];
            } elseif ($code === 0) {
                $status = $fallback;
                $code = $fallback['code'];
            }
        }

        if ($code >= 200 && $code < 400) {
            return ['ok', 'http:'.$code];
        }

        return [$code === 0 ? 'error' : 'http:'.$code, $status['error']];
    }

    /**
     * @return array{code:int,error:string}
     */
    private function request(string $url, string $method): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HTTPGET => $method === 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'CrozzTube-SourceProbe/1.0',
            CURLOPT_RANGE => $method === 'GET' ? '0-0' : null,
        ]);

        curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = (string) curl_error($handle);
        curl_close($handle);

        return ['code' => $code, 'error' => mb_substr($error, 0, 120)];
    }
}
