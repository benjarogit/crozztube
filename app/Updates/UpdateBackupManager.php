<?php

namespace App\Updates;

use App\Models\SystemUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class UpdateBackupManager
{
    public function __construct(private readonly UpdateFilesystem $filesystem) {}

    public function create(SystemUpdate $update, array $manifests): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $update->from_version.'_to_'.$update->to_version.'_'.now()->format('Y-m-d_His').'_'.$update->uuid);
        $relative = 'backups/'.$name;
        $directory = $this->storageRoot().'/'.$relative;
        File::ensureDirectoryExists($directory.'/files', 0770, true);

        try {
            $entries = [];
            foreach ($this->backupTargets($manifests) as $target) {
                $absolute = $this->absoluteOwnedPath($target);
                $entry = [
                    'target' => $target,
                    'existed' => file_exists($absolute),
                    'type' => is_dir($absolute) ? 'directory' : 'file',
                    'mode' => file_exists($absolute) ? (fileperms($absolute) & 0777) : null,
                    'owner' => file_exists($absolute) ? @fileowner($absolute) : null,
                    'group' => file_exists($absolute) ? @filegroup($absolute) : null,
                ];
                if ($entry['existed']) {
                    if (is_link($absolute)) {
                        throw new RuntimeException("Cannot back up symbolic link {$target}.");
                    }
                    $sourceFingerprint = $this->pathFingerprint($absolute);
                    $backupTarget = $directory.'/files/'.$target;
                    $this->copyOwnedPath($absolute, $backupTarget);
                    $entry['backup_sha256'] = $this->pathFingerprint($backupTarget);
                    if (! hash_equals($sourceFingerprint, $entry['backup_sha256'])) {
                        throw new RuntimeException("Backup content for {$target} does not match its source.");
                    }
                }
                $entries[] = $entry;
            }

            $database = $this->dumpDatabase($directory);
            $metadata = [
                'schema_version' => 1,
                'update_uuid' => $update->uuid,
                'from_version' => $update->from_version,
                'to_version' => $update->to_version,
                'created_at' => now()->toIso8601String(),
                'files' => $entries,
                'database' => $database,
            ];
            $this->writeJson($directory.'/backup.json', $metadata);
        } catch (\Throwable $exception) {
            File::deleteDirectory($directory);

            throw $exception;
        }

        return $relative;
    }

    public function restore(SystemUpdate $update, string $relative): void
    {
        $directory = $this->resolveBackup($relative);
        $metadataPath = $directory.'/backup.json';
        if (! is_file($metadataPath)) {
            throw new RuntimeException('The update backup metadata is missing.');
        }
        $expectedManifestHash = data_get($update->release_metadata, 'backup_manifest_sha256');
        $actualManifestHash = hash_file('sha256', $metadataPath);
        if (! is_string($expectedManifestHash)
            || preg_match('/^[a-f0-9]{64}$/', $expectedManifestHash) !== 1
            || ! is_string($actualManifestHash)
            || ! hash_equals($expectedManifestHash, $actualManifestHash)) {
            throw new RuntimeException('The update backup metadata failed integrity verification.');
        }
        $metadata = json_decode((string) file_get_contents($metadataPath), true);
        if (! is_array($metadata)
            || ($metadata['schema_version'] ?? null) !== 1
            || ($metadata['update_uuid'] ?? null) !== $update->uuid
            || ($metadata['from_version'] ?? null) !== $update->from_version
            || ($metadata['to_version'] ?? null) !== $update->to_version
            || ! is_array($metadata['files'] ?? null)
            || ! is_array($metadata['database'] ?? null)) {
            throw new RuntimeException('The update backup metadata is invalid.');
        }

        $targets = [];
        foreach ($metadata['files'] as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('The update backup file inventory is invalid.');
            }
            $target = UpdateManifest::normalizePath((string) ($entry['target'] ?? ''));
            if (isset($targets[$target])) {
                throw new RuntimeException("The update backup contains duplicate target {$target}.");
            }
            $targets[$target] = true;
            if (! ($entry['existed'] ?? false)) {
                continue;
            }
            $source = $directory.'/files/'.$target;
            $type = $entry['type'] ?? null;
            $typeMatches = ($type === 'file' && is_file($source)) || ($type === 'directory' && is_dir($source));
            if (! $typeMatches || is_link($source) || ! is_string($entry['backup_sha256'] ?? null) || ! hash_equals($entry['backup_sha256'], $this->pathFingerprint($source))) {
                throw new RuntimeException("Backup content for {$target} failed integrity verification.");
            }
        }
        $expectedTargets = array_fill_keys($this->backupTargets($this->manifestsFor($update)), true);
        ksort($targets, SORT_STRING);
        ksort($expectedTargets, SORT_STRING);
        if (array_keys($targets) !== array_keys($expectedTargets)) {
            throw new RuntimeException('The update backup file inventory does not match the signed release operations.');
        }
        $this->validateDatabaseBackup($directory, $metadata['database']);
        if (($metadata['database']['driver'] ?? null) !== $this->databaseDriver()) {
            throw new RuntimeException('The configured database driver no longer matches the update recovery point.');
        }

        foreach (array_reverse($metadata['files']) as $entry) {
            $target = UpdateManifest::normalizePath((string) ($entry['target'] ?? ''));
            $absolute = $this->absoluteOwnedPath($target);
            $this->remove($absolute);
            if (! ($entry['existed'] ?? false)) {
                continue;
            }
            $source = $directory.'/files/'.$target;
            if (! file_exists($source) || is_link($source)) {
                throw new RuntimeException("Backup content for {$target} is missing.");
            }
            $this->copyOwnedPath($source, $absolute);
            if (is_int($entry['mode'] ?? null)) {
                @chmod($absolute, $entry['mode']);
            }
            if (is_int($entry['owner'] ?? null) && function_exists('chown')) {
                @chown($absolute, $entry['owner']);
            }
            if (is_int($entry['group'] ?? null) && function_exists('chgrp')) {
                @chgrp($absolute, $entry['group']);
            }
        }

        $this->restoreDatabase($directory, $metadata['database']);
    }

    public function manifestHash(string $relative): string
    {
        $path = $this->resolveBackup($relative).'/backup.json';
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('The update backup metadata is missing.');
        }

        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw new RuntimeException('The update backup metadata could not be verified.');
        }

        return $hash;
    }

    public function requiredBytes(array $manifests): int
    {
        $bytes = 0;
        $minimumFreeBytes = 0;
        $composerInstall = false;
        $frontendBuild = false;
        $seen = [];
        foreach ($this->backupTargets($manifests) as $target) {
            if (isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;
            $path = $this->absoluteOwnedPath($target);
            $bytes += $this->pathBytes($path);
        }
        foreach ($manifests as $manifest) {
            $bytes += (int) $manifest->package()['size_bytes'] * 2;
            foreach ($manifest->fileOperations() as $operation) {
                $bytes += (int) ($operation['size_bytes'] ?? 0);
            }
            $minimumFreeBytes = max($minimumFreeBytes, (int) $manifest->requirements()['disk_free_bytes']);
            $composerInstall = $composerInstall || $manifest->composer()['mode'] === 'install';
            $frontendBuild = $frontendBuild || $manifest->frontend()['mode'] === 'build';
        }
        if ($composerInstall) {
            $bytes += $this->estimatedWorkspaceBytes($this->filesystem->root().'/vendor');
        }
        if ($frontendBuild) {
            $bytes += $this->estimatedWorkspaceBytes($this->filesystem->root().'/node_modules');
        }

        return max($minimumFreeBytes, $bytes + ($this->databaseBytes() * 2) + 100 * 1024 * 1024);
    }

    public function requiredDatabaseBinaries(): array
    {
        $driver = $this->databaseDriver();

        return match ($driver) {
            'mysql', 'mariadb' => ['mysqldump', 'mysql'],
            'pgsql' => ['pg_dump', 'psql'],
            default => [],
        };
    }

    public function prune(): void
    {
        $keep = max(1, (int) config('playhub-updates.backup_retention', 5));
        $completed = SystemUpdate::query()
            ->whereIn('status', ['completed', 'failed', 'rolled_back'])
            ->whereNotNull('backup_path')
            ->latest('id')
            ->get();
        foreach ($completed->slice($keep) as $update) {
            try {
                if (! File::deleteDirectory($this->resolveBackup($update->backup_path))) {
                    throw new RuntimeException('The expired update backup could not be removed.');
                }
                $update->forceFill(['backup_path' => null])->save();
            } catch (\Throwable) {
                // A failed retention cleanup must never affect an installed release.
            }
        }
    }

    private function backupTargets(array $manifests): array
    {
        $targets = ['VERSION' => true];
        foreach ($manifests as $manifest) {
            foreach ($manifest->fileOperations() as $operation) {
                $targets[$operation['target']] = true;
            }
            if ($manifest->composer()['mode'] === 'install') {
                $targets['vendor'] = true;
            }
            if ($manifest->frontend()['mode'] === 'build') {
                $targets['build'] = true;
            }
        }

        $paths = array_keys($targets);
        usort($paths, fn (string $left, string $right): int => substr_count($left, '/') <=> substr_count($right, '/'));
        $result = [];
        foreach ($paths as $path) {
            if (collect($result)->contains(fn (string $parent): bool => str_starts_with($path, $parent.'/'))) {
                continue;
            }
            $result[] = $path;
        }

        return $result;
    }

    private function manifestsFor(SystemUpdate $update): array
    {
        $rows = data_get($update->release_metadata, 'manifests');
        if (! is_array($rows) || ! $rows) {
            throw new RuntimeException('The update recovery point has no signed release inventory.');
        }

        return array_map(
            fn (mixed $manifest): UpdateManifest => UpdateManifest::fromArray(is_array($manifest) ? $manifest : []),
            $rows,
        );
    }

    private function dumpDatabase(string $directory): array
    {
        $driver = $this->databaseDriver();
        $connection = DB::connection()->getConfig();

        if ($driver === 'sqlite') {
            $destination = $directory.'/database.sqlite';
            $quoted = str_replace("'", "''", $destination);
            DB::statement("VACUUM INTO '{$quoted}'");
            if (! is_file($destination)) {
                throw new RuntimeException('The SQLite database could not be backed up consistently.');
            }
            @chmod($destination, 0600);

            return $this->databaseMetadata('sqlite', 'database.sqlite', $destination);
        }

        $dump = $directory.'/database.sql';
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $binary = $this->findExecutable('mysqldump');
            $process = new Process([
                $binary,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--no-tablespaces',
                '--add-drop-table',
                '--routines',
                '--events',
                '--triggers',
                '--hex-blob',
                ...$this->mysqlConnectionArguments($connection),
                '--user='.(string) ($connection['username'] ?? ''),
                '--result-file='.$dump,
                (string) ($connection['database'] ?? ''),
            ], $this->filesystem->root(), ['MYSQL_PWD' => (string) ($connection['password'] ?? '')]);
        } elseif ($driver === 'pgsql') {
            $process = new Process([
                $this->findExecutable('pg_dump'),
                '--format=plain',
                '--clean',
                '--if-exists',
                '--no-owner',
                '--no-privileges',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? 5432),
                '--username='.(string) ($connection['username'] ?? ''),
                '--file='.$dump,
                (string) ($connection['database'] ?? ''),
            ], $this->filesystem->root(), ['PGPASSWORD' => (string) ($connection['password'] ?? '')]);
        } else {
            throw new RuntimeException("Database backups are not supported for driver {$driver}.");
        }

        $this->run($process, 'The database backup command failed.');
        if (! is_file($dump) || filesize($dump) === 0) {
            throw new RuntimeException('The database backup command did not create a valid dump.');
        }
        @chmod($dump, 0600);

        return $this->databaseMetadata($driver, 'database.sql', $dump);
    }

    private function restoreDatabase(string $directory, array $database): void
    {
        $driver = (string) ($database['driver'] ?? '');
        $file = basename((string) ($database['file'] ?? ''));
        $dump = $directory.'/'.$file;
        $this->validateDatabaseBackup($directory, $database);
        $connection = DB::connection()->getConfig();

        if ($driver === 'sqlite') {
            $target = (string) ($connection['database'] ?? '');
            if ($target === ':memory:') {
                throw new RuntimeException('Automatic restoration of an in-memory SQLite database is not supported.');
            }
            DB::disconnect();
            $temporary = $target.'.playhub-restore-'.bin2hex(random_bytes(6));
            $mode = is_file($target) ? fileperms($target) & 0777 : 0600;
            $owner = is_file($target) ? @fileowner($target) : false;
            $group = is_file($target) ? @filegroup($target) : false;
            try {
                if (! @copy($dump, $temporary)) {
                    throw new RuntimeException('The SQLite database recovery point could not be staged.');
                }
                @chmod($temporary, $mode);
                if ($owner !== false && function_exists('chown')) {
                    @chown($temporary, $owner);
                }
                if ($group !== false && function_exists('chgrp')) {
                    @chgrp($temporary, $group);
                }
                if (! @rename($temporary, $target)) {
                    throw new RuntimeException('The SQLite database recovery point could not be restored atomically.');
                }
            } finally {
                @unlink($temporary);
                DB::reconnect();
            }

            return;
        }

        DB::disconnect();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $process = new Process([
                $this->findExecutable('mysql'),
                ...$this->mysqlConnectionArguments($connection),
                '--user='.(string) ($connection['username'] ?? ''),
                (string) ($connection['database'] ?? ''),
            ], $this->filesystem->root(), ['MYSQL_PWD' => (string) ($connection['password'] ?? '')]);
            $input = fopen($dump, 'rb');
            $process->setInput($input);
        } else {
            $process = new Process([
                $this->findExecutable('psql'),
                '--set=ON_ERROR_STOP=1',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? 5432),
                '--username='.(string) ($connection['username'] ?? ''),
                '--file='.$dump,
                (string) ($connection['database'] ?? ''),
            ], $this->filesystem->root(), ['PGPASSWORD' => (string) ($connection['password'] ?? '')]);
        }
        try {
            $this->run($process, 'The database recovery command failed.');
        } finally {
            if (isset($input) && is_resource($input)) {
                fclose($input);
            }
            DB::reconnect();
        }
    }

    private function run(Process $process, string $fallback): void
    {
        $process->setTimeout(7200);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: $fallback);
        }
    }

    private function findExecutable(string $name): string
    {
        $path = (new ExecutableFinder)->find($name);
        if (! $path) {
            throw new RuntimeException("Required database utility {$name} is not installed or not executable.");
        }

        return $path;
    }

    private function mysqlConnectionArguments(array $connection): array
    {
        $socket = trim((string) ($connection['unix_socket'] ?? ''));
        if ($socket !== '') {
            return ['--socket='.$socket];
        }

        return [
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? 3306),
        ];
    }

    private function databaseDriver(): string
    {
        $driver = (string) DB::connection()->getDriverName();
        if ($driver === 'mysql' && str_contains(strtolower((string) DB::selectOne('select version() as version')->version), 'mariadb')) {
            return 'mariadb';
        }

        return $driver;
    }

    private function databaseBytes(): int
    {
        try {
            $driver = $this->databaseDriver();
            $connection = DB::connection()->getConfig();
            if ($driver === 'sqlite') {
                $path = (string) ($connection['database'] ?? '');

                return is_file($path) ? (int) filesize($path) : 10 * 1024 * 1024;
            }
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne('select coalesce(sum(data_length + index_length), 0) as bytes from information_schema.tables where table_schema = ?', [(string) ($connection['database'] ?? '')]);

                return (int) ($row->bytes ?? 0);
            }
            if ($driver === 'pgsql') {
                return (int) (DB::selectOne('select pg_database_size(current_database()) as bytes')->bytes ?? 0);
            }
        } catch (\Throwable) {
            // Preflight still applies a conservative database allowance below.
        }

        return 100 * 1024 * 1024;
    }

    private function databaseMetadata(string $driver, string $file, string $path): array
    {
        return [
            'driver' => $driver,
            'file' => $file,
            'size_bytes' => (int) filesize($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }

    private function validateDatabaseBackup(string $directory, array $database): void
    {
        $driver = (string) ($database['driver'] ?? '');
        $file = (string) ($database['file'] ?? '');
        $expectedFile = $driver === 'sqlite' ? 'database.sqlite' : 'database.sql';
        $path = $directory.'/'.$file;
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)
            || $file !== $expectedFile
            || ! is_int($database['size_bytes'] ?? null)
            || $database['size_bytes'] < 1
            || ! is_string($database['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $database['sha256']) !== 1
            || ! is_file($path)
            || is_link($path)
            || (int) filesize($path) !== $database['size_bytes']
            || ! hash_equals($database['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('The database recovery point failed integrity verification.');
        }
    }

    private function pathFingerprint(string $path): string
    {
        if (is_link($path) || (! is_file($path) && ! is_dir($path))) {
            throw new RuntimeException("Backup path {$path} has an unsupported type.");
        }
        if (is_file($path)) {
            return hash_file('sha256', $path);
        }

        $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException("Backup path {$item->getPathname()} contains a symbolic link.");
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($path) + 1));
            $entries[$relative] = $item->isDir() ? 'directory' : hash_file('sha256', $item->getPathname());
        }
        ksort($entries, SORT_STRING);

        return hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function pathBytes(string $path): int
    {
        if (is_link($path)) {
            throw new RuntimeException("Backup target {$path} is a symbolic link.");
        }
        if (! file_exists($path)) {
            return 0;
        }
        if (is_file($path)) {
            return (int) filesize($path);
        }
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException("Backup target {$item->getPathname()} contains a symbolic link.");
            }
            if ($item->isFile()) {
                $bytes += (int) $item->getSize();
            }
        }

        return $bytes;
    }

    private function estimatedWorkspaceBytes(string $path): int
    {
        try {
            return max(1024 * 1024 * 1024, $this->pathBytes($path));
        } catch (\Throwable) {
            return 1024 * 1024 * 1024;
        }
    }

    private function copyOwnedPath(string $source, string $destination): void
    {
        if (is_link($source)) {
            throw new RuntimeException("Refusing to copy symbolic link {$source} into an update backup.");
        }
        if (is_file($source)) {
            File::ensureDirectoryExists(dirname($destination), 0770, true);
            if (! @copy($source, $destination)) {
                throw new RuntimeException("Could not back up {$source}.");
            }
            @chmod($destination, fileperms($source) & 0777);

            return;
        }
        if (! is_dir($source)) {
            return;
        }
        File::ensureDirectoryExists($destination, 0770, true);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException("Refusing to copy symbolic link {$item->getPathname()} into an update backup.");
            }
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination.DIRECTORY_SEPARATOR.$relative;
            if ($item->isDir()) {
                File::ensureDirectoryExists($target, 0770, true);
                @chmod($target, $item->getPerms() & 0777);
            } elseif (! @copy($item->getPathname(), $target)) {
                throw new RuntimeException("Could not back up {$item->getPathname()}.");
            } else {
                @chmod($target, $item->getPerms() & 0777);
            }
        }
    }

    private function absoluteOwnedPath(string $relative): string
    {
        $relative = UpdateManifest::normalizePath($relative);
        $root = $this->filesystem->root();
        $cursor = $root;
        foreach (explode('/', $relative) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($cursor)) {
                throw new RuntimeException("Owned application path {$relative} crosses a symbolic link.");
            }
        }

        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function remove(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('A backup restore target became a symbolic link.');
        }
        if (is_dir($path)) {
            if (! File::deleteDirectory($path)) {
                throw new RuntimeException("Could not remove {$path} during rollback.");
            }
        } elseif (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException("Could not remove {$path} during rollback.");
        }
    }

    private function resolveBackup(string $relative): string
    {
        if (! preg_match('#^backups/[A-Za-z0-9._-]+$#', $relative)) {
            throw new RuntimeException('The update backup path is invalid.');
        }
        $path = $this->storageRoot().'/'.$relative;
        $real = realpath($path);
        if ($real === false || ! str_starts_with($real.DIRECTORY_SEPARATOR, realpath($this->storageRoot()).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The update backup could not be located safely.');
        }

        return $real;
    }

    private function storageRoot(): string
    {
        $path = rtrim((string) config('playhub-updates.storage_path', storage_path('app/playhub-updates')), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($path, 0770, true);

        return $path;
    }

    private function writeJson(string $path, array $data): void
    {
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The update backup metadata could not be written.');
        }
        @chmod($path, 0600);
    }
}
