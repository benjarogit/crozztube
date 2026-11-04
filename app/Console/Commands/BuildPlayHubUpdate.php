<?php

namespace App\Console\Commands;

use App\Updates\UpdatePackageBuilder;
use Illuminate\Console\Command;
use Throwable;

class BuildPlayHubUpdate extends Command
{
    protected $signature = 'playhub:update-package
        {from : Previous PlayHub semantic version}
        {to : New PlayHub semantic version}
        {--previous= : Absolute path to the immutable previous release snapshot}
        {--current= : Absolute path to the immutable new release snapshot}
        {--output= : Update repository output root}
        {--base-url=https://playhub.kontackt.de/updates : Public update repository base URL}
        {--private-key= : RSA private PEM used only on the release builder}
        {--changelog= : Structured changelog JSON file}
        {--requirements= : Optional release requirements JSON file}
        {--composer=none : none or install}
        {--frontend=prebuilt : none, prebuilt, or build}
        {--post=optimize_clear,config_cache,view_cache,queue_restart : Comma-separated allowlisted post-update operations}
        {--health=application,database,filesystem,files,migrations : Comma-separated health checks}
        {--maintenance=1 : Whether Laravel maintenance mode is required}
        {--released-at= : ISO-8601 release timestamp}
        {--unsigned : Build an unsigned package for local testing only}
        {--force : Replace an existing generated release directory}';

    protected $description = 'Build a signed, delta-only PlayHub update package from two immutable release snapshots';

    public function handle(UpdatePackageBuilder $builder): int
    {
        if ($this->option('unsigned') && ! app()->environment(['local', 'testing'])) {
            $this->error('Unsigned update packages may only be built in local or testing environments.');

            return self::FAILURE;
        }

        try {
            $result = $builder->build((string) $this->argument('from'), (string) $this->argument('to'), [
                'previous' => $this->option('previous'),
                'current' => $this->option('current'),
                'output' => $this->option('output'),
                'base_url' => $this->option('base-url'),
                'private_key' => $this->option('private-key'),
                'changelog' => $this->option('changelog'),
                'requirements' => $this->option('requirements'),
                'composer' => $this->option('composer'),
                'frontend' => $this->option('frontend'),
                'post' => $this->option('post'),
                'health' => $this->option('health'),
                'maintenance' => $this->option('maintenance'),
                'released_at' => $this->option('released-at') ?: now()->utc()->toIso8601String(),
                'unsigned' => (bool) $this->option('unsigned'),
                'force' => (bool) $this->option('force'),
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("PlayHub {$result['from_version']} -> {$result['to_version']} package created.");
        $this->table(['Artifact', 'Value'], [
            ['Release directory', $result['release_directory']],
            ['Manifest', $result['manifest']],
            ['Package', $result['package']],
            ['File operations', $result['file_operations']],
            ['Package bytes', $result['package_size_bytes']],
            ['SHA-256', $result['package_sha256']],
        ]);

        return self::SUCCESS;
    }
}
