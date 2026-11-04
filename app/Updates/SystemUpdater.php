<?php

namespace App\Updates;

use App\Jobs\InstallSystemUpdate;
use App\Jobs\MonitorSystemUpdate;
use App\Models\SystemUpdate;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class SystemUpdater
{
    public function __construct(
        private readonly VersionRepository $versions,
        private readonly UpdateRepositoryClient $repository,
        private readonly UpdatePreflight $preflight,
        private readonly UpdateFilesystem $filesystem,
        private readonly UpdateBackupManager $backups,
        private readonly UpdateLogger $logger,
        private readonly UpdateLock $updateLock,
    ) {}

    public function dashboard(): array
    {
        $this->recoverInterrupted();
        $current = $this->versions->current();
        $active = SystemUpdate::query()->whereIn('status', SystemUpdate::ACTIVE_STATUSES)->latest('id')->first();

        return [
            'current_version' => $current,
            'active_update' => $active ? $this->updatePayload($active) : null,
            'available_update' => Cache::get($this->availabilityCacheKey($current)),
            'history' => SystemUpdate::query()->with('installer:id,name')->latest('id')->limit(25)->get()->map(fn (SystemUpdate $update): array => $this->updatePayload($update))->all(),
            'configuration' => [
                'enabled' => (bool) config('playhub-updates.enabled', true),
                'endpoint' => (string) config('playhub-updates.endpoint'),
                'signature_required' => $this->repository->signatureRequired(),
                'backup_retention' => (int) config('playhub-updates.backup_retention', 5),
                'issues' => $this->repository->configurationIssues(),
            ],
        ];
    }

    public function check(): array
    {
        $this->recoverInterrupted();
        $current = $this->versions->current();
        $this->logger->system('info', 'Checking the trusted PlayHub update repository.', ['current_version' => $current]);
        try {
            $path = $this->repository->releasesFor($current);
        } catch (Throwable $exception) {
            $this->logger->system('error', 'PlayHub update check failed.', ['current_version' => $current, 'error' => $exception->getMessage()]);
            throw $exception;
        }
        $manifests = $path['updates'];
        $payload = [
            'checked_at' => now()->toIso8601String(),
            'current_version' => $current,
            'latest_version' => $path['latest_version'],
            'up_to_date' => $path['up_to_date'],
            'update_count' => count($manifests),
            'total_size_bytes' => array_sum(array_map(fn (UpdateManifest $manifest): int => (int) $manifest->package()['size_bytes'], $manifests)),
            'releases' => array_map(fn (UpdateManifest $manifest): array => $manifest->summary(), $manifests),
            'preflight' => $manifests ? $this->preflight->inspect($current, $manifests) : [],
        ];
        Cache::put($this->availabilityCacheKey($current), $payload, now()->addMinutes(15));
        $this->logger->system('info', $path['up_to_date'] ? 'PlayHub is up to date.' : 'A compatible PlayHub update path is available.', ['current_version' => $current, 'latest_version' => $path['latest_version'], 'release_count' => count($manifests)]);

        return $payload;
    }

    public function start(int $adminId, ?string $targetVersion = null): SystemUpdate
    {
        $lock = Cache::lock('playhub-system-update-start', 30);
        try {
            $lock->block(3);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Another administrator is already starting an update.');
        }

        try {
            $this->recoverInterrupted();
            if (SystemUpdate::query()->whereIn('status', SystemUpdate::ACTIVE_STATUSES)->exists()) {
                throw new RuntimeException('Another PlayHub update is already queued or running.');
            }

            $current = $this->versions->current();
            $path = $this->repository->releasesFor($current);
            if ($path['up_to_date']) {
                throw new RuntimeException('PlayHub is already up to date.');
            }
            if ($targetVersion !== null && $targetVersion !== $path['latest_version']) {
                throw new RuntimeException('The requested target version is no longer the latest compatible release. Check for updates again.');
            }

            $checks = $this->preflight->assertReady($current, $path['updates']);
            $manifestData = array_map(fn (UpdateManifest $manifest): array => $manifest->toArray(), $path['updates']);
            $checksum = hash('sha256', implode('', array_map(fn (UpdateManifest $manifest): string => $manifest->package()['sha256'], $path['updates'])));
            $update = SystemUpdate::query()->create([
                'uuid' => (string) Str::uuid(),
                'from_version' => $current,
                'to_version' => $path['latest_version'],
                'status' => 'queued',
                'progress' => 0,
                'current_step' => 'Queued for background installation',
                'package_checksum' => $checksum,
                'installed_by' => $adminId,
                'heartbeat_at' => now(),
                'release_metadata' => [
                    'manifests' => $manifestData,
                    'preflight' => $checks,
                    'queued_at' => now()->toIso8601String(),
                ],
            ]);
            $this->logger->write($update, 'info', 'System update queued by an administrator.', ['admin_id' => $adminId]);
            try {
                InstallSystemUpdate::dispatch($update->uuid);
            } catch (Throwable $exception) {
                $fresh = $update->fresh();
                if ($fresh && in_array($fresh->status, ['completed', 'failed', 'rolled_back'], true)) {
                    throw $exception;
                }
                $message = $this->logger->sanitize($exception->getMessage());
                $update->forceFill([
                    'status' => 'failed',
                    'current_step' => 'Could not queue update',
                    'failed_at' => now(),
                    'heartbeat_at' => now(),
                    'error_details' => $message,
                ])->save();
                $this->logger->write($update, 'error', 'The system update job could not be queued.', ['error' => $message]);
                throw new RuntimeException('The PlayHub update could not be queued: '.$message, previous: $exception);
            }

            try {
                MonitorSystemUpdate::dispatch($update->uuid)->delay(now()->addMinutes(5));
            } catch (Throwable $exception) {
                $this->logger->write($update, 'warning', 'The update recovery watchdog could not be queued; scheduled recovery remains available.', [
                    'error' => $this->logger->sanitize($exception->getMessage()),
                ]);
            }

            return $update->fresh('installer');
        } finally {
            $lock->release();
        }
    }

    public function install(string $uuid): void
    {
        $update = SystemUpdate::query()->where('uuid', $uuid)->firstOrFail();
        if (! in_array($update->status, ['queued', 'preflight'], true)) {
            return;
        }

        $lock = $this->updateLock->acquire($update->uuid);
        if (! $lock) {
            $this->logger->write($update, 'warning', 'A duplicate installer process could not acquire the update lock and exited.');

            return;
        }

        $maintenanceEnabled = false;
        $backupPath = null;
        $staging = $this->storageRoot().'/staging/'.$update->uuid;
        try {
            $manifests = $this->manifests($update);
            $this->transition($update, 'preflight', 'Running pre-update checks', 2);
            if ($this->versions->current() !== $update->from_version) {
                throw new RuntimeException('The installed PlayHub version changed after this update was queued.');
            }
            foreach ($manifests as $manifest) {
                $manifest->verifySignature($this->repository->publicKey(), $this->repository->signatureRequired());
            }
            $this->preflight->assertReady($update->from_version, $manifests);

            File::deleteDirectory($staging);
            File::ensureDirectoryExists($staging.'/packages', 0770, true);
            File::ensureDirectoryExists($staging.'/releases', 0770, true);
            $releaseDirectories = [];
            $count = max(1, count($manifests));
            $totalDownloadBytes = max(1, array_sum(array_map(fn (UpdateManifest $manifest): int => (int) $manifest->package()['size_bytes'], $manifests)));
            $downloadedBefore = 0;
            $reportedDownloadProgress = 2;
            foreach ($manifests as $index => $manifest) {
                $this->transition($update, 'downloading', 'Downloading PlayHub '.$manifest->toVersion(), 3 + (int) floor(($downloadedBefore / $totalDownloadBytes) * 15));
                $archive = $staging.'/packages/'.$manifest->toVersion().'.zip';
                $this->repository->download($manifest, $archive, function (int $downloaded) use ($update, $downloadedBefore, $totalDownloadBytes, &$reportedDownloadProgress): void {
                    $progress = min(18, 3 + (int) floor((($downloadedBefore + $downloaded) / $totalDownloadBytes) * 15));
                    if ($progress > $reportedDownloadProgress) {
                        $reportedDownloadProgress = $progress;
                        $update->forceFill(['progress' => $progress, 'heartbeat_at' => now()])->save();
                    }
                });
                $downloadedBefore += (int) $manifest->package()['size_bytes'];
                $this->logger->write($update, 'info', 'Update package downloaded and checksum verified.', ['version' => $manifest->toVersion(), 'bytes' => filesize($archive)]);
                $this->transition($update, 'verifying', 'Verifying PlayHub '.$manifest->toVersion(), 18 + (int) floor(($index / $count) * 12));
                $directory = $staging.'/releases/'.$manifest->toVersion();
                $this->filesystem->extract($manifest, $archive, $directory);
                $releaseDirectories[$manifest->id()] = $directory;
            }

            $this->transition($update, 'preparing', 'Validating staged update', 31);
            $checks = $this->preflight->assertReady($update->from_version, $manifests);
            $this->mergeMetadata($update, ['installation_preflight' => $checks, 'staged_at' => now()->toIso8601String()]);

            if (collect($manifests)->contains(fn (UpdateManifest $manifest): bool => $manifest->requiresMaintenance())) {
                $this->mergeMetadata($update, ['maintenance_owned' => true, 'maintenance_requested_at' => now()->toIso8601String()]);
                $this->runArtisan($update, 'down', ['--retry' => 60]);
                $maintenanceEnabled = true;
                $this->logger->write($update, 'info', 'Laravel maintenance mode enabled.');
            }

            $this->transition($update, 'backing_up', 'Creating file and database recovery point', 35);
            $backupPath = $this->backups->create($update, $manifests);
            $metadata = is_array($update->release_metadata) ? $update->release_metadata : [];
            $metadata['backup_manifest_sha256'] = $this->backups->manifestHash($backupPath);
            $update->forceFill(['backup_path' => $backupPath, 'release_metadata' => $metadata, 'heartbeat_at' => now()])->save();
            $this->logger->write($update, 'info', 'Update recovery point created.', ['backup' => $backupPath]);

            $totalOperations = max(1, array_sum(array_map(fn (UpdateManifest $manifest): int => count($manifest->fileOperations()), $manifests)));
            $completedOperations = 0;
            foreach ($manifests as $manifest) {
                $this->transition($update, 'installing_files', 'Installing PlayHub '.$manifest->toVersion().' files', 45 + (int) floor(($completedOperations / $totalOperations) * 20));
                $this->filesystem->deploy($manifest, $releaseDirectories[$manifest->id()], function (int $done, int $total, string $action, string $target) use ($update, &$completedOperations, $totalOperations): void {
                    $completedOperations++;
                    $progress = 45 + (int) floor(($completedOperations / $totalOperations) * 20);
                    $update->forceFill(['progress' => min(65, $progress), 'heartbeat_at' => now()])->save();
                    $this->logger->write($update, 'info', strtoupper($action).' application path.', ['target' => $target]);
                });
                $this->filesystem->verify($manifest);
            }

            $composerManifest = collect($manifests)->reverse()->first(fn (UpdateManifest $manifest): bool => $manifest->composer()['mode'] === 'install');
            if ($composerManifest) {
                $this->transition($update, 'installing_dependencies', 'Installing locked PHP dependencies', 68);
                $this->installComposerDependencies($update, $composerManifest);
            }

            $this->transition($update, 'migrating_database', 'Updating the PlayHub database', 74);
            $this->runArtisan($update, 'optimize:clear', freshApplication: true);
            if (collect($manifests)->contains(fn (UpdateManifest $manifest): bool => $manifest->migrationsRequired())) {
                $this->runArtisan($update, 'migrate', ['--force' => true], freshApplication: true);
            }

            $frontendManifest = collect($manifests)->reverse()->first(fn (UpdateManifest $manifest): bool => $manifest->frontend()['mode'] !== 'none');
            if ($frontendManifest?->frontend()['mode'] === 'build') {
                $this->transition($update, 'rebuilding_frontend', 'Building production frontend assets', 80);
                $this->buildFrontend($update);
            }

            $this->transition($update, 'refreshing_caches', 'Refreshing Laravel services and caches', 85);
            $operations = collect($manifests)->flatMap(fn (UpdateManifest $manifest): array => $manifest->postUpdateOperations())->unique()->values()->all();
            foreach ($operations as $operation) {
                if ($operation === 'optimize_clear') {
                    continue;
                }
                [$command, $arguments] = $this->artisanOperation($operation);
                if ($operation === 'reverb_restart' && ! array_key_exists($command, Artisan::all())) {
                    $this->logger->write($update, 'info', 'Reverb is not installed; its optional restart was skipped.');

                    continue;
                }
                $this->runArtisan($update, $command, $arguments, freshApplication: true);
            }

            $this->transition($update, 'health_check', 'Running post-update health checks', 93);
            $this->healthCheck($update, $manifests);

            $this->transition($update, 'finalizing', 'Committing PlayHub '.$update->to_version, 98);
            $this->versions->commit($update);
            if ($maintenanceEnabled) {
                $this->runArtisan($update, 'up');
                $maintenanceEnabled = false;
                $this->mergeMetadata($update, ['maintenance_owned' => false]);
            }
            $this->versions->complete($update);
            $this->logger->write($update->fresh(), 'info', 'System update completed successfully.');
            $cleanup = [
                fn (): bool => Cache::forget($this->availabilityCacheKey($update->from_version)),
                fn (): bool => File::deleteDirectory($staging),
                function (): bool {
                    $this->backups->prune();

                    return true;
                },
            ];
            foreach ($cleanup as $operation) {
                try {
                    $operation();
                } catch (Throwable $cleanupException) {
                    $this->logger->write($update->fresh(), 'warning', 'The update completed, but non-critical cleanup needs a later retry.', [
                        'error' => $this->logger->sanitize($cleanupException->getMessage()),
                    ]);
                }
            }
        } catch (Throwable $exception) {
            $this->rollbackFailure($update, $exception, $backupPath, $maintenanceEnabled);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function recoverInterrupted(): void
    {
        $cutoff = now()->subMinutes(max(15, (int) config('playhub-updates.stale_after_minutes', 120)));
        $stale = SystemUpdate::query()
            ->whereIn('status', SystemUpdate::ACTIVE_STATUSES)
            ->where(fn ($query) => $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', $cutoff))
            ->oldest('id')
            ->get();
        foreach ($stale as $update) {
            $lock = $this->updateLock->acquire('recovery:'.$update->uuid);
            if (! $lock) {
                continue;
            }
            try {
                $this->logger->write($update, 'warning', 'A stale update transaction was detected; automatic recovery started.');
                $this->rollbackFailure($update, new RuntimeException('The update process was interrupted before completion.'), $update->backup_path, false);
            } finally {
                $lock->release();
            }
        }
    }

    public function updatePayload(SystemUpdate $update): array
    {
        $metadata = is_array($update->release_metadata) ? $update->release_metadata : [];

        return [
            'uuid' => $update->uuid,
            'from_version' => $update->from_version,
            'to_version' => $update->to_version,
            'status' => $update->status,
            'progress' => (int) $update->progress,
            'current_step' => $update->current_step,
            'started_at' => $update->started_at?->toIso8601String(),
            'completed_at' => $update->completed_at?->toIso8601String(),
            'failed_at' => $update->failed_at?->toIso8601String(),
            'created_at' => $update->created_at?->toIso8601String(),
            'error_details' => $update->error_details,
            'rollback_error' => $update->rollback_error,
            'backup_available' => is_string($update->backup_path) && $update->backup_path !== '',
            'installer' => $update->relationLoaded('installer') && $update->installer ? ['id' => $update->installer->id, 'name' => $update->installer->name] : null,
            'releases' => array_map(function (array $manifest): array {
                try {
                    return UpdateManifest::fromArray($manifest)->summary();
                } catch (Throwable) {
                    return [];
                }
            }, is_array($metadata['manifests'] ?? null) ? $metadata['manifests'] : []),
            'log_url' => '/api/admin/system-updates/'.$update->uuid.'/log',
        ];
    }

    public function markJobFailure(string $uuid, ?Throwable $exception): void
    {
        $update = SystemUpdate::query()->where('uuid', $uuid)->first();
        if (! $update || in_array($update->status, ['completed', 'failed', 'rolled_back'], true)) {
            return;
        }
        $message = $this->logger->sanitize($exception?->getMessage() ?: 'The background update job failed unexpectedly.');
        $needsRecovery = is_string($update->backup_path) && $update->backup_path !== ''
            || data_get($update->release_metadata, 'maintenance_owned') === true;
        if ($needsRecovery) {
            $lock = $this->updateLock->acquire('job-failure:'.$update->uuid);
            if (! $lock) {
                $this->logger->write($update, 'warning', 'The failed job still has an active installer lock; scheduled recovery will retry.');

                return;
            }
            try {
                $update = $update->fresh();
                if ($update && ! in_array($update->status, ['completed', 'failed', 'rolled_back'], true)) {
                    $this->rollbackFailure($update, new RuntimeException($message), $update->backup_path, false);
                }
            } finally {
                $lock->release();
            }

            return;
        }
        $update->forceFill(['status' => 'failed', 'current_step' => 'Update failed', 'failed_at' => now(), 'heartbeat_at' => now(), 'error_details' => $message])->save();
        $this->logger->write($update, 'error', $message);
    }

    private function rollbackFailure(SystemUpdate $update, Throwable $exception, ?string $backupPath, bool $maintenanceEnabled): void
    {
        $error = $this->logger->sanitize($exception->getMessage() ?: 'The update failed.');
        $this->logger->write($update, 'error', 'Update failed; transactional recovery started.', ['error' => $error]);
        $rollbackError = null;
        $rolledBack = false;
        $ownsMaintenance = $maintenanceEnabled || data_get($update->release_metadata, 'maintenance_owned') === true;
        $enteredMaintenanceForRollback = false;
        try {
            if ($backupPath) {
                if (! app()->isDownForMaintenance()) {
                    if (Artisan::call('down', ['--retry' => 60]) !== 0 || ! app()->isDownForMaintenance()) {
                        throw new RuntimeException('Laravel maintenance mode could not be enabled for rollback.');
                    }
                    $enteredMaintenanceForRollback = true;
                }
                $update->forceFill(['status' => 'rolling_back', 'current_step' => 'Restoring the previous release', 'heartbeat_at' => now()])->save();
                $this->backups->restore($update, $backupPath);
                $this->versions->write($update->from_version);
                try {
                    Artisan::call('optimize:clear');
                } catch (Throwable) {
                }
                $rolledBack = true;
            }
            if (($ownsMaintenance || $enteredMaintenanceForRollback) && app()->isDownForMaintenance()) {
                if (Artisan::call('up') !== 0 || app()->isDownForMaintenance()) {
                    throw new RuntimeException('Laravel maintenance mode could not be disabled after recovery.');
                }
            }
        } catch (Throwable $rollbackException) {
            $rollbackError = $this->logger->sanitize($rollbackException->getMessage());
            $this->logger->write($update, 'critical', 'Automatic rollback failed. Laravel remains in maintenance mode when it was enabled by the updater.', ['error' => $rollbackError]);
        }

        $record = SystemUpdate::query()->where('uuid', $update->uuid)->first() ?: $update;
        $recovered = $rolledBack && $rollbackError === null;
        $metadata = is_array($record->release_metadata) ? $record->release_metadata : [];
        $backupManifestHash = data_get($update->release_metadata, 'backup_manifest_sha256');
        if (is_string($backupManifestHash)) {
            $metadata['backup_manifest_sha256'] = $backupManifestHash;
        }
        $metadata['maintenance_owned'] = $rollbackError !== null && $ownsMaintenance && app()->isDownForMaintenance();
        $record->forceFill([
            'status' => $recovered ? 'rolled_back' : 'failed',
            'progress' => min(99, (int) $record->progress),
            'current_step' => $recovered ? 'Update failed and was rolled back' : 'Update failed',
            'backup_path' => $backupPath,
            'release_metadata' => $metadata,
            'failed_at' => now(),
            'heartbeat_at' => now(),
            'error_details' => $error,
            'rollback_error' => $rollbackError,
        ])->save();
        if ($recovered) {
            $this->logger->write($record, 'warning', 'The previous PlayHub release and database were restored successfully.');
            $this->backups->prune();
        }
        try {
            File::deleteDirectory($this->storageRoot().'/staging/'.$update->uuid);
        } catch (Throwable) {
            // Recovery status and backups are more important than staging cleanup.
        }
    }

    private function healthCheck(SystemUpdate $update, array $manifests): void
    {
        DB::select('select 1');
        $root = $this->filesystem->root();
        foreach ([$root.'/storage', $root.'/storage/framework', $root.'/storage/logs', $root.'/bootstrap/cache'] as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                throw new RuntimeException("Required PlayHub directory {$path} is not writable.");
            }
        }
        $this->filesystem->verifyFinal($manifests);
        $this->runArtisan($update, 'migrate:status', freshApplication: true);
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            throw new RuntimeException('Laravel migration history is unavailable after the update.');
        }
        $migrationFiles = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
        $pendingMigrations = array_diff(array_keys($migrationFiles), $migrator->getRepository()->getRan());
        if ($pendingMigrations) {
            throw new RuntimeException('Laravel still has pending migrations after the update: '.implode(', ', array_slice($pendingMigrations, 0, 10)).'.');
        }
        if (! app()->bound('router') || count(app('router')->getRoutes()) === 0) {
            throw new RuntimeException('Laravel route bootstrap failed after the update.');
        }
        $artisan = $root.'/artisan';
        if (is_file($artisan)) {
            $binary = UpdateRuntime::phpBinary();
            if (! $binary) {
                throw new RuntimeException('The PHP CLI binary is unavailable for the clean application bootstrap check.');
            }
            $this->runProcess($update, new Process([$binary, $artisan, 'about', '--only=environment'], $root), 'Clean Laravel application bootstrap failed.');
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('The PlayHub artisan entry point is missing after the update.');
        }
        $this->logger->write($update, 'info', 'Post-update application, database, migration, filesystem, and release-file checks passed.');
    }

    private function installComposerDependencies(SystemUpdate $update, UpdateManifest $manifest): void
    {
        $lock = $this->filesystem->root().'/composer.lock';
        if (! is_file($lock) || ! hash_equals($manifest->composer()['lock_sha256'], hash_file('sha256', $lock))) {
            throw new RuntimeException('composer.lock does not match the signed update manifest.');
        }
        $composer = (new ExecutableFinder)->find('composer');
        if (! $composer) {
            throw new RuntimeException('Composer is required by this update but is not installed.');
        }
        $this->runProcess($update, new Process([$composer, 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--optimize-autoloader', '--no-scripts'], $this->filesystem->root(), ['COMPOSER_ALLOW_SUPERUSER' => '1']), 'Composer dependency installation failed.');
    }

    private function buildFrontend(SystemUpdate $update): void
    {
        $finder = new ExecutableFinder;
        $npm = $finder->find('npm');
        $root = $this->filesystem->root();
        if (! $npm || ! $finder->find('node') || ! is_file($root.'/package-lock.json')) {
            throw new RuntimeException('The optional frontend build requires Node.js, npm, and package-lock.json.');
        }
        $workspace = $this->storageRoot().'/staging/'.$update->uuid.'/frontend-build';
        File::deleteDirectory($workspace);
        File::ensureDirectoryExists($workspace, 0770, true);
        foreach (['package.json', 'package-lock.json', 'vite.config.js', 'resources'] as $input) {
            $this->copyFrontendInput($root.'/'.$input, $workspace.'/'.$input);
        }

        $this->runProcess($update, new Process([$npm, 'ci', '--ignore-scripts', '--no-audit', '--no-fund'], $workspace), 'Deterministic npm installation failed.');
        $this->runProcess($update, new Process([$npm, 'run', 'build'], $workspace), 'The production frontend build failed.');
        $this->validateFrontendBuild($workspace.'/build');
        $this->filesystem->deployGeneratedTree($workspace.'/build', 'build', function (string $target) use ($update): void {
            $update->forceFill(['heartbeat_at' => now()])->save();
            $this->logger->write($update, 'info', 'Installed generated frontend asset.', ['target' => $target]);
        });
    }

    private function copyFrontendInput(string $source, string $destination): void
    {
        if (is_link($source) || (! is_file($source) && ! is_dir($source))) {
            throw new RuntimeException("Frontend build input {$source} is missing or unsafe.");
        }
        if (is_file($source)) {
            File::ensureDirectoryExists(dirname($destination), 0770, true);
            if (! @copy($source, $destination)) {
                throw new RuntimeException("Frontend build input {$source} could not be staged.");
            }

            return;
        }

        File::ensureDirectoryExists($destination, 0770, true);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('Frontend build inputs cannot contain symbolic links.');
            }
            $target = $destination.DIRECTORY_SEPARATOR.substr($item->getPathname(), strlen($source) + 1);
            if ($item->isDir()) {
                File::ensureDirectoryExists($target, 0770, true);
            } elseif (! $item->isFile() || ! @copy($item->getPathname(), $target)) {
                throw new RuntimeException("Frontend build input {$item->getPathname()} could not be staged.");
            }
        }
    }

    private function validateFrontendBuild(string $buildDirectory): void
    {
        $manifestPath = $buildDirectory.'/manifest.json';
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        if (! is_array($manifest) || ! $manifest) {
            throw new RuntimeException('The frontend build did not produce a valid Vite manifest.');
        }
        foreach ($manifest as $entry) {
            if (! is_array($entry) || ! is_string($entry['file'] ?? null) || (isset($entry['css']) && (! is_array($entry['css']) || ! array_is_list($entry['css'])))) {
                throw new RuntimeException('The generated Vite manifest contains an invalid entry.');
            }
            $assets = [$entry['file'], ...($entry['css'] ?? [])];
            foreach ($assets as $asset) {
                if (! is_string($asset)) {
                    throw new RuntimeException('The generated Vite manifest contains an invalid asset path.');
                }
                $asset = UpdateManifest::normalizePath((string) $asset);
                $path = $buildDirectory.'/'.$asset;
                if (! is_file($path) || is_link($path)) {
                    throw new RuntimeException("The generated frontend asset {$asset} is missing.");
                }
            }
        }
    }

    private function runProcess(SystemUpdate $update, Process $process, string $fallback): void
    {
        $process->setTimeout(7200);
        $process->run(function () use ($update): void {
            $update->forceFill(['heartbeat_at' => now()])->save();
        });
        if (! $process->isSuccessful()) {
            throw new RuntimeException($this->logger->sanitize(trim($process->getErrorOutput()) ?: $fallback));
        }
        $this->logger->write($update, 'info', $fallback === '' ? 'Controlled process completed.' : str_replace(' failed.', ' completed.', $fallback));
    }

    private function runArtisan(SystemUpdate $update, string $command, array $arguments = [], bool $freshApplication = false): void
    {
        if ($freshApplication) {
            $root = $this->filesystem->root();
            $binary = UpdateRuntime::phpBinary();
            if (! $binary || ! is_file($root.'/artisan')) {
                throw new RuntimeException('A PHP CLI binary and artisan entry point are required to bootstrap the updated application.');
            }
            $parameters = [$binary, $root.'/artisan', $command, '--no-interaction'];
            foreach ($arguments as $name => $value) {
                if ($value === true) {
                    $parameters[] = $name;
                } elseif ($value !== false && $value !== null) {
                    $parameters[] = $name.'='.$value;
                }
            }
            // The worker has already loaded the old release's PHP classes. A new
            // process must discover packages, migrate, and cache the new release.
            $this->runProcess($update, new Process($parameters, $root), "Laravel command {$command} failed.");

            return;
        }

        $exit = Artisan::call($command, $arguments);
        $output = trim(Artisan::output());
        $this->logger->write($update, $exit === 0 ? 'info' : 'error', "Laravel command {$command} finished with exit code {$exit}.", $output !== '' ? ['output' => mb_substr($output, 0, 4000)] : []);
        $update->forceFill(['heartbeat_at' => now()])->save();
        if ($exit !== 0) {
            throw new RuntimeException("Laravel command {$command} failed.");
        }
    }

    private function artisanOperation(string $operation): array
    {
        return match ($operation) {
            'package_discover' => ['package:discover', []],
            'config_cache' => ['config:cache', []],
            'route_cache' => ['route:cache', []],
            'view_cache' => ['view:cache', []],
            'queue_restart' => ['queue:restart', []],
            'reverb_restart' => ['reverb:restart', []],
            default => throw new RuntimeException("Unsupported post-update operation {$operation}."),
        };
    }

    private function transition(SystemUpdate $update, string $status, string $step, int $progress): void
    {
        $update->forceFill([
            'status' => $status,
            'current_step' => $step,
            'progress' => max((int) $update->progress, min(99, $progress)),
            'heartbeat_at' => now(),
            'started_at' => $update->started_at ?: now(),
        ])->save();
        $this->logger->write($update, 'info', $step.'.', ['progress' => (int) $update->progress]);
    }

    private function manifests(SystemUpdate $update): array
    {
        $rows = data_get($update->release_metadata, 'manifests');
        if (! is_array($rows) || ! $rows) {
            throw new RuntimeException('The queued update no longer has valid release metadata.');
        }

        return array_map(fn (array $manifest): UpdateManifest => UpdateManifest::fromArray($manifest), $rows);
    }

    private function mergeMetadata(SystemUpdate $update, array $values): void
    {
        $update->forceFill(['release_metadata' => array_replace(is_array($update->release_metadata) ? $update->release_metadata : [], $values), 'heartbeat_at' => now()])->save();
    }

    private function storageRoot(): string
    {
        $path = rtrim((string) config('playhub-updates.storage_path'), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($path, 0770, true);

        return $path;
    }

    private function availabilityCacheKey(string $version): string
    {
        return 'playhub-system-updates.available.'.hash('sha256', $version);
    }
}
