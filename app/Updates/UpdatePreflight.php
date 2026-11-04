<?php

namespace App\Updates;

use App\Jobs\InstallSystemUpdate;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

final class UpdatePreflight
{
    public function __construct(
        private readonly UpdateFilesystem $filesystem,
        private readonly UpdateBackupManager $backups,
        private readonly UpdateRepositoryClient $repository,
    ) {}

    public function inspect(string $currentVersion, array $manifests): array
    {
        $checks = [];
        $add = function (string $key, string $label, bool $passed, string $detail) use (&$checks): void {
            $checks[] = compact('key', 'label', 'passed', 'detail');
        };

        $configurationIssues = $this->repository->configurationIssues();
        $add('configuration', 'Updater configuration', ! $configurationIssues, $configurationIssues ? implode(' ', $configurationIssues) : 'The trusted update source and verification key are configured.');

        $cursor = $currentVersion;
        $pathValid = (bool) $manifests;
        foreach ($manifests as $manifest) {
            if (! $manifest instanceof UpdateManifest || $manifest->fromVersion() !== $cursor) {
                $pathValid = false;
                break;
            }
            $cursor = $manifest->toVersion();
        }
        $add('upgrade_path', 'Upgrade path', $pathValid, $pathValid ? "A continuous update path from {$currentVersion} to {$cursor} is available." : 'The release repository did not provide a continuous update path.');

        $phpPassed = true;
        $laravelPassed = true;
        $missingExtensions = [];
        foreach ($manifests as $manifest) {
            $requirements = $manifest->requirements();
            $phpPassed = $phpPassed && version_compare(PHP_VERSION, $requirements['php_min'], '>=');
            $laravelPassed = $laravelPassed && version_compare(Application::VERSION, $requirements['laravel_min'], '>=');
            foreach ($requirements['extensions'] as $extension) {
                if (! extension_loaded($extension)) {
                    $missingExtensions[$extension] = true;
                }
            }
        }
        $add('php', 'PHP version', $phpPassed, $phpPassed ? 'PHP '.PHP_VERSION.' satisfies every release requirement.' : 'PHP '.PHP_VERSION.' is below a required release version.');
        $add('laravel', 'Laravel compatibility', $laravelPassed, $laravelPassed ? 'Laravel '.Application::VERSION.' is compatible.' : 'This PlayHub Laravel version does not satisfy the release requirements.');
        $add('extensions', 'PHP extensions', ! $missingExtensions, $missingExtensions ? 'Missing PHP extensions: '.implode(', ', array_keys($missingExtensions)).'.' : 'All required PHP extensions are loaded.');

        $missingEnvironment = [];
        $manualEnvironment = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest->requirements()['environment'] as $setting) {
                $configured = Env::get($setting['name']);
                if ($setting['required'] && ($configured === null || $configured === '')) {
                    $missingEnvironment[$setting['name']] = true;
                }
                if (! $setting['required'] && ($configured === null || $configured === '')) {
                    $manualEnvironment[$setting['name']] = true;
                }
            }
        }
        $environmentPassed = ! $missingEnvironment;
        $environmentDetail = $missingEnvironment
            ? 'Required environment settings need manual configuration: '.implode(', ', array_keys($missingEnvironment)).'.'
            : ($manualEnvironment ? 'Optional new settings may be configured manually: '.implode(', ', array_keys($manualEnvironment)).'.' : 'No environment changes are required.');
        $add('environment', 'Environment settings', $environmentPassed, $environmentDetail);

        try {
            [$driver, $databaseVersion] = $this->databaseInfo();
            $databasePassed = true;
            foreach ($manifests as $manifest) {
                $requirement = $manifest->requirements()['database'];
                $minimum = $requirement['minimum_versions'][$driver] ?? null;
                $databasePassed = $databasePassed && in_array($driver, $requirement['drivers'], true) && (! $minimum || version_compare($databaseVersion, $minimum, '>='));
            }
            if ($driver === 'sqlite') {
                $databasePath = (string) DB::connection()->getConfig('database');
                $databasePassed = $databasePassed
                    && $databasePath !== ':memory:'
                    && is_file($databasePath)
                    && ! is_link($databasePath)
                    && is_readable($databasePath)
                    && is_writable(dirname($databasePath));
            }
            $add('database', 'Database', $databasePassed, $databasePassed ? "{$driver} {$databaseVersion} is connected, compatible, and recoverable." : "{$driver} {$databaseVersion} does not satisfy the release or recovery requirements.");
        } catch (\Throwable $exception) {
            $add('database', 'Database', false, 'Database connectivity check failed: '.$exception->getMessage());
        }

        try {
            $migrator = app('migrator');
            $migrationFiles = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
            $pendingMigrations = $migrator->repositoryExists()
                ? array_diff(array_keys($migrationFiles), $migrator->getRepository()->getRan())
                : array_keys($migrationFiles);
            $add(
                'migration_state',
                'Current migrations',
                ! $pendingMigrations,
                $pendingMigrations
                    ? 'The existing installation has pending migrations: '.implode(', ', array_slice($pendingMigrations, 0, 10)).'.'
                    : 'The current PlayHub database migration history is complete.',
            );
        } catch (\Throwable $exception) {
            $add('migration_state', 'Current migrations', false, 'Laravel migration history could not be verified: '.$exception->getMessage());
        }

        $storage = rtrim((string) config('playhub-updates.storage_path'), DIRECTORY_SEPARATOR);
        try {
            File::ensureDirectoryExists($storage, 0770, true);
            $storageWritable = is_dir($storage) && is_writable($storage);
        } catch (\Throwable) {
            $storageWritable = false;
        }
        $add('storage', 'Private update storage', $storageWritable, $storageWritable ? 'Staging and backup storage is writable.' : 'The private PlayHub update storage directory is not writable.');

        $versionFile = (string) config('playhub-updates.version_file');
        $versionDirectory = realpath(dirname($versionFile));
        $versionLocationValid = $versionDirectory !== false && $versionDirectory === $this->filesystem->root() && basename($versionFile) === 'VERSION' && ! is_link($versionFile);
        $versionWritable = $versionLocationValid
            && (! file_exists($versionFile) || is_file($versionFile))
            && is_writable($versionDirectory);
        $add('version_file', 'Version tracking', $versionWritable, $versionWritable ? 'The centralized VERSION file can be committed atomically.' : ($versionLocationValid ? 'The PlayHub VERSION file or its directory is not writable.' : 'Version tracking must use the non-symlinked VERSION file in the configured PlayHub root.'));

        $targetErrors = [];
        $virtualExists = [];
        $virtualHashes = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest->fileOperations() as $operation) {
                $target = $operation['target'];
                try {
                    $path = $this->filesystem->absoluteTarget($target);
                } catch (\Throwable $exception) {
                    $targetErrors[] = "{$target} is unsafe: {$exception->getMessage()}";

                    continue;
                }
                $known = array_key_exists($target, $virtualExists);
                $exists = $known ? $virtualExists[$target] : (file_exists($path) || is_link($path));
                $hash = $known ? ($virtualHashes[$target] ?? null) : (is_file($path) && ! is_link($path) ? hash_file('sha256', $path) : null);
                if (! $known && is_dir($path)) {
                    $targetErrors[] = "{$target} is a directory; update file operations cannot replace or delete directories";
                }
                if ($operation['action'] === 'add' && $exists) {
                    $same = is_string($hash) && hash_equals($operation['sha256'], $hash);
                    if (! $same) {
                        $targetErrors[] = "{$target} already exists";
                    }
                }
                if ($operation['action'] === 'replace') {
                    if (! $exists) {
                        $targetErrors[] = "{$target} is missing";
                    } elseif (! is_string($hash) || ! hash_equals($operation['previous_sha256'], $hash)) {
                        $targetErrors[] = "{$target} does not match the expected previous release";
                    }
                }
                if ($operation['action'] === 'delete') {
                    if (! $exists) {
                        $targetErrors[] = "{$target} is already missing";
                    } elseif (isset($operation['previous_sha256']) && (! is_string($hash) || ! hash_equals($operation['previous_sha256'], $hash))) {
                        $targetErrors[] = "{$target} does not match the expected previous release";
                    }
                }
                if (! $this->targetWritable($target)) {
                    $targetErrors[] = "{$target} is not writable";
                }
                $virtualExists[$target] = $operation['action'] !== 'delete';
                $virtualHashes[$target] = $operation['action'] === 'delete' ? null : $operation['sha256'];
            }

            if ($manifest->composer()['mode'] === 'install') {
                $lock = 'composer.lock';
                $known = array_key_exists($lock, $virtualExists);
                $path = $this->filesystem->root().'/'.$lock;
                $hash = $known ? ($virtualHashes[$lock] ?? null) : (is_file($path) && ! is_link($path) ? hash_file('sha256', $path) : null);
                if (! is_string($hash) || ! hash_equals($manifest->composer()['lock_sha256'], $hash)) {
                    $targetErrors[] = 'composer.lock does not match the deterministic dependency instruction';
                }
            }
            if ($manifest->frontend()['mode'] === 'build') {
                $lock = 'package-lock.json';
                $exists = array_key_exists($lock, $virtualExists) ? $virtualExists[$lock] : is_file($this->filesystem->root().'/'.$lock);
                if (! $exists) {
                    $targetErrors[] = 'package-lock.json is required for the deterministic frontend build';
                }
            }
        }
        $frontendBuild = collect($manifests)->reverse()->first(fn (UpdateManifest $manifest): bool => $manifest->frontend()['mode'] !== 'none');
        if ($frontendBuild?->frontend()['mode'] === 'build') {
            foreach (['package.json', 'package-lock.json', 'vite.config.js'] as $requiredFile) {
                $exists = array_key_exists($requiredFile, $virtualExists)
                    ? $virtualExists[$requiredFile]
                    : is_file($this->filesystem->root().'/'.$requiredFile);
                if (! $exists) {
                    $targetErrors[] = "{$requiredFile} is required for the isolated frontend build";
                }
            }
            $resourcesExist = is_dir($this->filesystem->root().'/resources')
                || collect(array_keys($virtualExists))->contains(fn (string $target): bool => str_starts_with($target, 'resources/'));
            if (! $resourcesExist) {
                $targetErrors[] = 'resources is required for the isolated frontend build';
            }
        }
        $targetErrors = array_values(array_unique($targetErrors));
        $add('filesystem', 'Application files', ! $targetErrors, $targetErrors ? 'File preflight failed: '.implode('; ', array_slice($targetErrors, 0, 8)).'.' : 'Every declared application target is writable and outside protected paths.');

        $finder = new ExecutableFinder;
        $missingBinaries = [];
        if (! UpdateRuntime::phpBinary()) {
            $missingBinaries['php'] = true;
        }
        if (! is_file($this->filesystem->root().'/artisan')) {
            $missingBinaries['artisan entry point'] = true;
        }
        foreach ($this->backups->requiredDatabaseBinaries() as $binary) {
            if (! $finder->find($binary)) {
                $missingBinaries[$binary] = true;
            }
        }
        foreach ($manifests as $manifest) {
            if ($manifest->composer()['mode'] === 'install' && ! $finder->find('composer')) {
                $missingBinaries['composer'] = true;
            }
            if ($manifest->frontend()['mode'] === 'build') {
                if (! $finder->find('node')) {
                    $missingBinaries['node'] = true;
                }
                if (! $finder->find('npm')) {
                    $missingBinaries['npm'] = true;
                }
            }
        }
        $add('binaries', 'Required commands', ! $missingBinaries, $missingBinaries ? 'Missing required executables: '.implode(', ', array_keys($missingBinaries)).'.' : 'All required deterministic update commands are available.');

        $artisanCommands = array_keys(Artisan::all());
        $missingArtisan = [];
        $commandMap = [
            'package_discover' => 'package:discover',
            'optimize_clear' => 'optimize:clear',
            'config_cache' => 'config:cache',
            'route_cache' => 'route:cache',
            'view_cache' => 'view:cache',
            'queue_restart' => 'queue:restart',
            'reverb_restart' => 'reverb:restart',
        ];
        foreach (['optimize:clear', 'migrate:status'] as $requiredCommand) {
            if (! in_array($requiredCommand, $artisanCommands, true)) {
                $missingArtisan[$requiredCommand] = true;
            }
        }
        if (collect($manifests)->contains(fn (UpdateManifest $manifest): bool => $manifest->requiresMaintenance())) {
            foreach (['down', 'up'] as $requiredCommand) {
                if (! in_array($requiredCommand, $artisanCommands, true)) {
                    $missingArtisan[$requiredCommand] = true;
                }
            }
        }
        foreach ($manifests as $manifest) {
            if ($manifest->migrationsRequired() && ! in_array('migrate', $artisanCommands, true)) {
                $missingArtisan['migrate'] = true;
            }
            foreach ($manifest->postUpdateOperations() as $operation) {
                $command = $commandMap[$operation] ?? null;
                if ($operation === 'reverb_restart' && ! in_array('reverb:restart', $artisanCommands, true)) {
                    // Reverb is optional; releases also support installations without it.
                    continue;
                }
                if (! $command || ! in_array($command, $artisanCommands, true)) {
                    $missingArtisan[$command ?: $operation] = true;
                }
            }
        }
        $add('artisan', 'Laravel operations', ! $missingArtisan, $missingArtisan ? 'Unavailable Laravel commands: '.implode(', ', array_keys($missingArtisan)).'.' : 'Every declared Laravel operation is available.');

        try {
            $requiredBytes = $this->backups->requiredBytes($manifests);
            $freeBytes = (int) disk_free_space($storage ?: $this->filesystem->root());
            $diskPassed = $freeBytes >= $requiredBytes;
            $add('disk_space', 'Disk space', $diskPassed, $diskPassed ? 'Enough free space is available for staging and recovery.' : 'The update requires '.number_format($requiredBytes).' free bytes; only '.number_format($freeBytes).' are available.');
        } catch (\Throwable $exception) {
            $add('disk_space', 'Disk space', false, 'Available disk space could not be verified: '.$exception->getMessage());
        }

        $maintenanceRequired = collect($manifests)->contains(fn (UpdateManifest $manifest): bool => $manifest->requiresMaintenance());
        $maintenanceReady = ! $maintenanceRequired || (! app()->isDownForMaintenance() && is_writable(storage_path('framework')));
        $add('maintenance', 'Maintenance mode', $maintenanceReady, $maintenanceReady ? ($maintenanceRequired ? 'Laravel maintenance mode is available.' : 'This update does not require maintenance mode.') : 'Laravel is already in maintenance mode or its framework storage is not writable.');

        $queueConnection = (string) config('queue.default');
        $queueDriver = (string) config("queue.connections.{$queueConnection}.driver");
        $retryAfter = config("queue.connections.{$queueConnection}.retry_after");
        $retryWindowSafe = ! in_array($queueDriver, ['database', 'redis', 'beanstalkd'], true)
            || (is_numeric($retryAfter) && (int) $retryAfter > InstallSystemUpdate::TIMEOUT);
        $queueReady = app()->environment('testing') || ($queueConnection !== 'sync' && $queueDriver !== '' && $retryWindowSafe);
        $queueDetail = $queueReady
            ? "The {$queueConnection} queue can run independently of the browser with a safe reservation window."
            : ($queueConnection === 'sync'
                ? 'Configure a persistent Laravel queue connection and worker before installing updates.'
                : 'The queue retry_after value must exceed the six-hour system update job timeout.');
        $add('queue', 'Background worker', $queueReady, $queueDetail);

        return $checks;
    }

    public function assertReady(string $currentVersion, array $manifests): array
    {
        $checks = $this->inspect($currentVersion, $manifests);
        $failed = array_values(array_filter($checks, fn (array $check): bool => ! $check['passed']));
        if ($failed) {
            throw new RuntimeException('Update preflight failed: '.implode(' ', array_column($failed, 'detail')));
        }

        return $checks;
    }

    private function databaseInfo(): array
    {
        $driver = DB::connection()->getDriverName();
        $raw = match ($driver) {
            'mysql', 'mariadb' => (string) (DB::selectOne('select version() as version')->version ?? ''),
            'pgsql' => (string) (DB::selectOne('show server_version')->server_version ?? ''),
            'sqlite' => (string) (DB::selectOne('select sqlite_version() as version')->version ?? ''),
            default => '',
        };
        if ($driver === 'mysql' && str_contains(strtolower($raw), 'mariadb')) {
            $driver = 'mariadb';
        }
        preg_match('/\d+\.\d+(?:\.\d+)?/', $raw, $matches);
        $version = $matches[0] ?? '0.0.0';
        if (substr_count($version, '.') === 1) {
            $version .= '.0';
        }

        return [$driver, $version];
    }

    private function targetWritable(string $target): bool
    {
        try {
            $path = $this->filesystem->absoluteTarget($target);
            if (file_exists($path)) {
                return ! is_link($path) && is_writable(dirname($path));
            }
            $parent = dirname($path);
            while (! file_exists($parent) && $parent !== dirname($parent)) {
                $parent = dirname($parent);
            }

            return is_dir($parent) && ! is_link($parent) && is_writable($parent);
        } catch (\Throwable) {
            return false;
        }
    }
}
