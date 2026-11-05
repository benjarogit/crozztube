<?php

namespace App\Jobs;

use App\Support\Thumbnails\ThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        app(\App\Support\Images\NightImageMaintenance::class)->run($service, app(\App\Support\Images\ImageUploads::class));
    }
}
