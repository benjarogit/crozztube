<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class CreateSystemBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 21600;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $backupId,
        public array $selections,
        public bool $includeDatabase,
        public string $largeFileAction,
        public int $largeFileThresholdMb,
        public int $compressionLevel,
        public int $retentionCount,
    ) {}

    public function handle(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('The PHP ZIP extension is required to create backups.');
        }

        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory, 0770, true);
        $metadata = $this->metadata();
        $metadata['status'] = 'processing';
        $metadata['started_at'] = now()->toIso8601String();
        $metadata['progress'] = 1;
        $this->writeMetadata($metadata);

        $filename = 'playhub-backup-'.now()->format('Y-m-d-His').'-'.substr($this->backupId, 0, 8).'.zip';
        $archivePath = $directory.'/'.$filename;
        $workDirectory = $directory.'/.'.$this->backupId;
        File::ensureDirectoryExists($workDirectory, 0770, true);

        try {
            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('The backup archive could not be created.');
            }

            $totalBytes = max(1, (int) ($metadata['selected_bytes'] ?? 0));
            $processedBytes = 0;
            $includedFiles = 0;
            $skippedFiles = [];
            $thresholdBytes = $this->largeFileThresholdMb * 1024 * 1024;
            $backupRoot = realpath($directory) ?: $directory;

            foreach ($this->selections as $selection) {
                $path = base_path($selection);
                if (! file_exists($path) || is_link($path)) {
                    $skippedFiles[] = $selection;
                    continue;
                }

                if (is_file($path)) {
                    $this->addFile($zip, $path, $selection, $thresholdBytes, $processedBytes, $includedFiles, $skippedFiles);
                    $this->updateProgress($metadata, $processedBytes, $totalBytes);
                    continue;
                }

                $zip->addEmptyDir(str_replace('\\', '/', $selection));
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ($iterator as $item) {
                    $itemPath = $item->getPathname();
                    if ($item->isLink() || str_starts_with($itemPath, $backupRoot.DIRECTORY_SEPARATOR)) {
                        continue;
                    }
                    $relativePath = str_replace('\\', '/', substr($itemPath, strlen(base_path()) + 1));
                    if ($item->isDir()) {
                        $zip->addEmptyDir($relativePath);
                        continue;
                    }
                    if (! $item->isFile() || ! $item->isReadable()) {
                        $skippedFiles[] = $relativePath;
                        continue;
                    }
                    $this->addFile($zip, $itemPath, $relativePath, $thresholdBytes, $processedBytes, $includedFiles, $skippedFiles);
                    if ($includedFiles % 100 === 0) {
                        $this->updateProgress($metadata, $processedBytes, $totalBytes);
                    }
                }
            }

            if ($this->includeDatabase) {
                $databasePath = $this->createDatabaseDump($workDirectory);
                $databaseName = 'database/'.basename($databasePath);
                if (! $zip->addFile($databasePath, $databaseName)) {
                    throw new \RuntimeException('The database dump could not be added to the backup.');
                }
                $zip->setCompressionName($databaseName, ZipArchive::CM_DEFLATE, $this->compressionLevel);
                $includedFiles++;
            }

            $manifest = [
                'created_at' => now()->toIso8601String(),
                'selected_paths' => $this->selections,
                'database_included' => $this->includeDatabase,
                'large_file_action' => $this->largeFileAction,
                'large_file_threshold_mb' => $this->largeFileThresholdMb,
                'included_files' => $includedFiles,
                'skipped_files' => $skippedFiles,
            ];
            $zip->addFromString('backup-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->setCompressionName('backup-manifest.json', ZipArchive::CM_DEFLATE, $this->compressionLevel);

            if (! $zip->close()) {
                throw new \RuntimeException('The backup archive could not be finalized.');
            }

            $metadata['status'] = 'completed';
            $metadata['progress'] = 100;
            $metadata['completed_at'] = now()->toIso8601String();
            $metadata['archive'] = $filename;
            $metadata['size_bytes'] = (int) (filesize($archivePath) ?: 0);
            $metadata['included_files'] = $includedFiles;
            $metadata['skipped_files'] = array_slice($skippedFiles, 0, 100);
            $metadata['skipped_count'] = count($skippedFiles);
            $this->writeMetadata($metadata);
            $this->pruneOldBackups();
        } catch (Throwable $exception) {
            if (isset($zip) && $zip instanceof ZipArchive) {
                @$zip->close();
            }
            File::delete($archivePath);
            throw $exception;
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $metadata = $this->metadata();
        $metadata['status'] = 'failed';
        $metadata['failed_at'] = now()->toIso8601String();
        $metadata['error'] = $exception?->getMessage() ?: 'The backup failed.';
        $this->writeMetadata($metadata);
    }

    private function addFile(ZipArchive $zip, string $path, string $archiveName, int $thresholdBytes, int &$processedBytes, int &$includedFiles, array &$skippedFiles): void
    {
        $size = (int) (filesize($path) ?: 0);
        $processedBytes += $size;
        if ($size > $thresholdBytes && $this->largeFileAction === 'skip') {
            $skippedFiles[] = $archiveName;
            return;
        }
        if (! $zip->addFile($path, $archiveName)) {
            $skippedFiles[] = $archiveName;
            return;
        }
        $method = $size > $thresholdBytes && $this->largeFileAction === 'store'
            ? ZipArchive::CM_STORE
            : ZipArchive::CM_DEFLATE;
        $zip->setCompressionName($archiveName, $method, $this->compressionLevel);
        $includedFiles++;
    }

    private function createDatabaseDump(string $workDirectory): string
    {
        $connectionName = config('database.default');
        $connection = config('database.connections.'.$connectionName, []);
        $driver = $connection['driver'] ?? $connectionName;

        if ($driver === 'sqlite') {
            $source = $connection['database'] ?? database_path('database.sqlite');
            if (! is_file($source) || ! copy($source, $workDirectory.'/database.sqlite')) {
                throw new \RuntimeException('The SQLite database could not be copied.');
            }
            return $workDirectory.'/database.sqlite';
        }

        $dumpPath = $workDirectory.'/database.sql';
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $command = [
                'mysqldump', '--single-transaction', '--quick', '--skip-lock-tables',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? 3306),
                '--user='.(string) ($connection['username'] ?? ''),
                '--result-file='.$dumpPath,
                (string) ($connection['database'] ?? ''),
            ];
            $process = new Process($command, base_path(), ['MYSQL_PWD' => (string) ($connection['password'] ?? '')]);
        } elseif ($driver === 'pgsql') {
            $command = [
                'pg_dump', '--format=plain', '--no-owner', '--no-privileges',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? 5432),
                '--username='.(string) ($connection['username'] ?? ''),
                '--file='.$dumpPath,
                (string) ($connection['database'] ?? ''),
            ];
            $process = new Process($command, base_path(), ['PGPASSWORD' => (string) ($connection['password'] ?? '')]);
        } else {
            throw new \RuntimeException('Database backups are not supported for the configured database driver.');
        }

        $process->setTimeout(7200);
        $process->run();
        if (! $process->isSuccessful() || ! is_file($dumpPath)) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'The database dump command failed.');
        }

        return $dumpPath;
    }

    private function updateProgress(array &$metadata, int $processedBytes, int $totalBytes): void
    {
        $progress = min(95, max(1, (int) floor(($processedBytes / max(1, $totalBytes)) * 95)));
        if ($progress <= (int) ($metadata['progress'] ?? 0)) {
            return;
        }
        $metadata['progress'] = $progress;
        $metadata['processed_bytes'] = $processedBytes;
        $this->writeMetadata($metadata);
    }

    private function metadata(): array
    {
        $path = storage_path('app/backups/'.$this->backupId.'.json');
        if (! is_file($path)) {
            return ['id' => $this->backupId];
        }
        $metadata = json_decode((string) file_get_contents($path), true);
        return is_array($metadata) ? $metadata : ['id' => $this->backupId];
    }

    private function writeMetadata(array $metadata): void
    {
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory, 0770, true);
        $path = $directory.'/'.$this->backupId.'.json';
        $temporary = $path.'.tmp';
        $contents = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $path)) {
            throw new \RuntimeException('Backup status metadata could not be written.');
        }
    }

    private function pruneOldBackups(): void
    {
        $files = glob(storage_path('app/backups/*.json')) ?: [];
        usort($files, fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
        foreach (array_slice($files, $this->retentionCount) as $metadataPath) {
            $metadata = json_decode((string) file_get_contents($metadataPath), true);
            if (! is_array($metadata) || in_array($metadata['status'] ?? '', ['queued', 'processing'], true)) {
                continue;
            }
            $archive = basename((string) ($metadata['archive'] ?? ''));
            if ($archive !== '') {
                File::delete(storage_path('app/backups/'.$archive));
            }
            File::delete($metadataPath);
        }
    }
}
