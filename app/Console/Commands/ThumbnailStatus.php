<?php

namespace App\Console\Commands;

use App\Models\ThumbnailMirror;
use App\Models\ThumbnailProvider;
use App\Support\Thumbnails\NightWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ThumbnailStatus extends Command
{
    protected $signature = 'crozztube:thumbnails:status';
    protected $description = 'Show thumbnail provider state, backlog and the last bounded night run (no secrets).';

    public function handle(): int
    {
        $this->line(json_encode([
            'night' => [config('thumbnails.night_start'), config('thumbnails.night_end'), config('thumbnails.timezone')],
            'in_window' => NightWindow::contains(), 'paused' => NightWindow::paused(),
            'providers' => ThumbnailProvider::orderBy('priority')->get()->map(fn ($p) => [
                'id' => $p->id, 'priority' => $p->priority,
                'configured' => (bool) config('thumbnails.providers.'.$p->id.'.supported')
                    && (! config('thumbnails.providers.'.$p->id.'.requires_key', true)
                        || trim((string) config('thumbnails.providers.'.$p->id.'.key')) !== ''),
                'supported' => (bool) config('thumbnails.providers.'.$p->id.'.supported'), 'paused_until' => $p->paused_until,
                'circuit_open' => $p->circuit_open, 'last_result' => $p->last_result,
            ]),
            'states' => ThumbnailMirror::query()->whereHas('video', fn ($q) => $q->whereColumn('videos.thumbnail_source_version', 'thumbnail_mirrors.source_version'))
                ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
            'last_run' => Cache::get('thumbnails:last-run'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
