<?php

namespace App\Jobs;

use App\Models\SystemUpdate;
use App\Updates\SystemUpdater;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MonitorSystemUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $updateUuid) {}

    public function handle(SystemUpdater $updater): void
    {
        $updater->recoverInterrupted();

        $update = SystemUpdate::query()->where('uuid', $this->updateUuid)->first();
        if (! $update || ! in_array($update->status, SystemUpdate::ACTIVE_STATUSES, true)) {
            return;
        }

        if (config('queue.default') !== 'sync') {
            self::dispatch($this->updateUuid)->delay(now()->addMinutes(5));
        }
    }
}
