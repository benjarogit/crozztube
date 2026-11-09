<?php

namespace App\Console\Commands;

use App\Models\ThumbnailMirror;
use App\Models\ThumbnailProvider;
use App\Support\Thumbnails\NightWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ThumbnailStatus extends Command
{
    protected $signature = 'crozztube:thumbnails:status';
    protected $description = 'Show thumbnail provider state, backlog and the last bounded night run (no secrets).';

    public function handle(): int
    {
        $providers = \App\Support\MediaProviderSettings::imageProviders();
        $this->line(json_encode([
            'night' => [config('thumbnails.night_start'), config('thumbnails.night_end'), config('thumbnails.timezone')],
            'in_window' => NightWindow::contains(), 'paused' => NightWindow::paused(),
            'providers' => ThumbnailProvider::orderBy('priority')->get()->map(fn ($p) => [
                'id' => $p->id, 'priority' => $p->priority,
                'configured' => (bool) ($providers[$p->id]['supported'] ?? false)
                    && (! ($providers[$p->id]['requires_key'] ?? true)
                        || trim((string) ($providers[$p->id]['key'] ?? '')) !== ''),
                'supported' => (bool) ($providers[$p->id]['supported'] ?? false), 'paused_until' => $p->paused_until,
                'circuit_open' => $p->circuit_open, 'last_result' => $p->last_result,
            ]),
            'states' => ThumbnailMirror::query()->whereHas('video', fn ($q) => $q->whereColumn('videos.thumbnail_source_version', 'thumbnail_mirrors.source_version'))
                ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
            'image_upload_states' => Schema::hasTable('image_uploads')
                ? DB::table('image_uploads')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status') : [],
            'attachment_mirror_states' => Schema::hasTable('image_upload_mirrors')
                ? DB::table('image_upload_mirrors')->join('image_uploads', 'image_uploads.id', '=', 'image_upload_mirrors.image_upload_id')
                    ->where('image_uploads.status', 'attached')->selectRaw('image_upload_mirrors.status, COUNT(*) AS total')
                    ->groupBy('image_upload_mirrors.status')->pluck('total', 'image_upload_mirrors.status') : [],
            'shared_budget' => ['checks' => config('thumbnails.check_limit'), 'upload_attempts' => config('thumbnails.upload_limit'),
                'seconds' => config('thumbnails.run_budget'), 'scope' => 'thumbnails-and-attachments'],
            'last_run' => Cache::get('thumbnails:last-run'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
