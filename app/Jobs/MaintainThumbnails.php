<?php

namespace App\Jobs;

use App\Models\ThumbnailMirror;
use App\Models\Video;
use App\Support\Thumbnails\NightWindow;
use App\Support\Thumbnails\ThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class MaintainThumbnails implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 70;
    public int $tries = 1;
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('thumbnails');
    }

    public function handle(ThumbnailService $service): void
    {
        // Enforce here as well as in the scheduler: no daytime backlog processing.
        if (! NightWindow::contains() || NightWindow::paused()) return;
        $lock = Cache::lock('thumbnails:transfer', 90);
        if (! $lock->get()) return;
        $start = microtime(true);
        $endOfWindow = now()->timezone(config('thumbnails.timezone'))->setTimeFromTimeString(config('thumbnails.night_end'));
        if ($endOfWindow->lte(now())) $endOfWindow->addDay();
        $deadline = min($start + (float) config('thumbnails.run_budget'), $start + now()->diffInSeconds($endOfWindow, false));
        $stats = ['checks' => 0, 'copies' => 0, 'attempts' => 0, 'started_at' => now()->toIso8601String()];
        $allowed = fn () => microtime(true) < $deadline - 0.1 && NightWindow::contains() && ! NightWindow::paused();
        try {
            // Seed only a bounded number of old records; subsequent runs continue.
            Video::query()->whereNotNull('thumbnail_source_version')
                ->whereDoesntHave('thumbnailMirrors', fn ($q) => $q->whereColumn('source_version', 'videos.thumbnail_source_version'))
                ->limit(20)->get()->each(fn ($video) => $service->ensureSource($video));
            // Old local files get an identity only. The service removes them after verified hosting.
            Video::query()->whereNull('thumbnail_source_version')->whereNotNull('thumbnail_path')->limit(5)->get()->each(function ($video) use ($service): void {
                $video->forceFill(['thumbnail_source_version' => hash('sha256', 'legacy:'.$video->id.':'.$video->thumbnail_path)])->save();
                $service->ensureSource($video);
            });
            $ids = array_keys(ThumbnailService::providers());
            $base = fn () => ThumbnailMirror::query()->with(['video', 'provider'])
                ->whereIn('provider_id', [...$ids, 'original'])
                ->whereHas('provider', fn ($q) => $q->whereNull('paused_until')->orWhere('paused_until', '<=', now()))
                ->whereHas('video', fn ($q) => $q->whereColumn('videos.thumbnail_source_version', 'thumbnail_mirrors.source_version'));
            $checks = $base()->whereNotNull('thumbnail_url')->where('next_check_at', '<=', now())
                ->orderBy('next_check_at')->orderBy('id')->limit((int) config('thumbnails.check_limit'))->get();
            foreach ($checks as $mirror) {
                if (! $allowed()) break;
                $mirror->load('provider');
                if ($mirror->provider->paused_until?->isFuture()) continue;
                $service->check($mirror, $deadline);
                $stats['checks']++;
            }
            $copies = $base()->whereIn('provider_id', $ids)->whereIn('status', ['pending', 'failed'])->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')->orderBy('priority')->orderBy('id')->limit((int) config('thumbnails.upload_limit'))->get();
            foreach ($copies as $mirror) {
                if (! $allowed()) break;
                $mirror->load('provider');
                if ($mirror->provider->paused_until?->isFuture()) continue;
                $stats['attempts']++;
                if ($service->copy($mirror, $deadline)) $stats['copies']++;
            }
        } finally {
            $stats['seconds'] = round(microtime(true) - $start, 3);
            $stats['finished_at'] = now()->toIso8601String();
            Cache::put('thumbnails:last-run', $stats, now()->addDays(30));
            $lock->release();
        }
    }
}
