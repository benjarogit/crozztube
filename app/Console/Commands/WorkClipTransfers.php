<?php

namespace App\Console\Commands;

use App\Support\Clips\ClipTransferProcessor;
use App\Support\Clips\ClipTransferQueue;
use App\Support\Clips\OdyseeTransferGateway;
use App\Support\MediaProviderSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class WorkClipTransfers extends Command
{
    protected $signature = 'crozztube:clips:work {--limit=3 : Maximum metadata phases per run}';

    protected $description = 'Resume durable clip transfers and wait for Odysee availability without transporting video.';

    public function handle(): int
    {
        if (! Schema::hasTable('clip_transfers') || ! Schema::hasColumn('clip_transfers', 'next_check_at')) {
            $this->line('Clip transfer lifecycle is not installed.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10) {
            $this->error('Use a clip phase limit between 1 and 10.');

            return self::INVALID;
        }
        $queue = null;
        try {
            $queue = ClipTransferQueue::fromConfig();
            $queue->synchronizeOracle(MediaProviderSettings::current()->effective('oracle'));
            $queue->promote();
        } catch (Throwable) {
            // Existing leased work must still run when new admissions are paused.
            $queue = null;
            $this->warn('New clip admissions are paused until the Oracle staging configuration is valid.');
        }
        $processor = new ClipTransferProcessor(new OdyseeTransferGateway());
        $processed = 0;
        while ($processed < $limit && $processor->run()) {
            $processed++;
        }
        if ($queue !== null) {
            try {
                $queue->promote();
            } catch (Throwable) {
                $this->warn('Queued clips remain paused until Oracle staging is available.');
            }
        }
        $this->line('Clip metadata phases processed: '.$processed.'.');

        return self::SUCCESS;
    }
}
