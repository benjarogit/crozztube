<?php

namespace App\Console\Commands;

use App\Support\Clips\OracleStagingConnection;
use Illuminate\Console\Command;
use Throwable;

class CheckClipStaging extends Command
{
    protected $signature = 'crozztube:clips:storage-check {--connect : Perform one read-only bucket HEAD request}';

    protected $description = 'Validate Oracle clip-staging settings without exposing credentials.';

    public function handle(): int
    {
        $connection = new OracleStagingConnection((array) config('clip_staging.oci', []));
        $this->line(json_encode($connection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($connection->problems()) {
            return self::FAILURE;
        }
        if (! $this->option('connect')) {
            $this->info('Configuration valid. No network request was made; provider access is not yet verified.');

            return self::SUCCESS;
        }
        try {
            $connection->client()->headBucket(['Bucket' => $connection->bucket()]);
        } catch (Throwable) {
            // SDK exception messages can include signed URLs or request details.
            $this->error('Oracle bucket access failed. Check bucket, region, credentials and IAM permissions locally.');

            return self::FAILURE;
        }
        $this->info('Bucket HEAD succeeded. Privacy, browser uploads, hoster import and cleanup still require their integration tests.');

        return self::SUCCESS;
    }
}
