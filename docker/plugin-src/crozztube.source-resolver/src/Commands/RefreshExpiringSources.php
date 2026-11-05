<?php

namespace CrzSourceResolver\Commands;

use CrzSourceResolver\Jobs\RefreshSource;
use CrzSourceResolver\Models\CrozztubeImportSource;
use Illuminate\Console\Command;

/**
 * Erneuert Direkt-URLs kurz vor dem Ablauf - gestaffelt, damit yt-dlp nicht
 * in Wellen laeuft. Wird vom Scheduler alle fuenf Minuten aufgerufen.
 */
final class RefreshExpiringSources extends Command
{
    protected $signature = 'crozztube:sources:refresh-expiring
        {--window=15 : Refresh sources expiring within this many minutes}
        {--limit=10 : Maximum sources per run}
        {--stagger=15 : Seconds between two dispatched refreshes}
        {--scheduled : Set by the scheduler (honours the pause flag)}
        {--dry-run : Only list what would be refreshed}';

    protected $description = 'Refresh imported-video direct URLs that are about to expire (staggered).';

    public function handle(): int
    {
        if ($this->option('scheduled') && self::maintenancePaused()) {
            $this->line('Maintenance paused (storage/framework/crozztube-maintenance.pause).');
            return self::SUCCESS;
        }

        $window = max(1, (int) $this->option('window'));
        $limit = max(1, (int) $this->option('limit'));
        $stagger = max(0, (int) $this->option('stagger'));
        $dryRun = (bool) $this->option('dry-run');

        $threshold = now()->addMinutes($window)->format('Y-m-d H:i:s');

        // `stale` rows are picked up as well: their URL is already rejected.
        $rows = CrozztubeImportSource::query()->whereHas('video')
            ->whereIn('kind', ['hls', 'mp4', 'hls-synth'])
            ->where(fn ($q) => $q->whereNull('error_category')->orWhereNotIn('error_category', ['permanent', 'unknown_exhausted']))
            ->where(function ($query) use ($threshold): void {
                $query->where('state', 'stale')
                    ->orWhere(function ($query) use ($threshold): void {
                        $query->whereIn('state', ['fresh', 'error'])
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<=', $threshold);
                    });
            })
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->line('Nothing to refresh.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($rows as $index => $row) {
            // Never stack on top of a running refresh.
            if ($row->state === 'refreshing'
                && $row->refresh_started_at?->gt(now()->subSeconds(RefreshSource::LOCK_TTL - 60))) {
                continue;
            }

            $this->line(sprintf(
                '#%d video=%d expires=%s state=%s',
                $row->id,
                $row->video_id,
                $row->expires_at?->toDateTimeString() ?? '-',
                $row->state
            ));

            if ($dryRun) {
                continue;
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

        $this->info($dryRun
            ? sprintf('%d source(s) would be refreshed.', $rows->count())
            : sprintf('%d refresh job(s) queued.', $dispatched));

        return self::SUCCESS;
    }

    public static function maintenancePaused(): bool
    {
        return file_exists(storage_path('framework/crozztube-maintenance.pause'));
    }
}
