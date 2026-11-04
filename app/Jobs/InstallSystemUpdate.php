<?php

namespace App\Jobs;

use App\Updates\SystemUpdater;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class InstallSystemUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT = 21600;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    public bool $failOnTimeout = true;

    public function __construct(public string $updateUuid) {}

    public function handle(SystemUpdater $updater): void
    {
        $updater->install($this->updateUuid);
    }

    public function failed(?Throwable $exception): void
    {
        app(SystemUpdater::class)->markJobFailure($this->updateUuid, $exception);
    }
}
