<?php

namespace App\Console\Commands;

use App\Updates\SystemUpdater;
use Illuminate\Console\Command;

class RecoverPlayHubUpdates extends Command
{
    protected $signature = 'playhub:updates:recover';

    protected $description = 'Detect and safely recover interrupted PlayHub update transactions';

    public function handle(SystemUpdater $updater): int
    {
        $updater->recoverInterrupted();
        $this->info('Interrupted update recovery check completed.');

        return self::SUCCESS;
    }
}
