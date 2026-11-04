<?php

namespace App\Plugins;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

final class PluginPackageInstaller
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    public function stage(string $archivePath): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP ZIP extension is required to install plugins.');
        }
        if (! is_file($archivePath) || ! is_readable($archivePath)) {
            throw new RuntimeException('The uploaded plugin package is unavailable.');
        }

        $maxArchiveBytes = max(1, (int) config('playhub.plugin_max_archive_mb', 25)) * 1024 * 1024;
        if ((int) filesize($archivePath) > $maxArchiveBytes) {
            throw new RuntimeException('The plugin ZIP exceeds the configured upload size limit.');
        }

        $workspace = $this->createWorkspace();
        $packagePath = $workspace.'/package';
        $zip = new ZipArchive;
        $opened = false;

        try {
            $this->files->ensureDirectoryExists($packagePath, 0755, true);
            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException('The uploaded file is not a readable ZIP package.');
            }
            $opened = true;

            $entries = $this->validatedEntries($zip);
            $prefix = $this->packagePrefix($entries);
            $seen = [];

            foreach ($entries as $entry) {
                if ($this->ignoredMetadataPath($entry['name'])) {
                    continue;
                }
                if ($prefix !== '' && ! str_starts_with($entry['name'], $prefix)) {
                    throw new RuntimeException('Every plugin ZIP entry must be inside the same package root directory.');
                }
                $relative = $prefix !== '' ? substr($entry['name'], strlen($prefix)) : $entry['name'];
                if ($relative === '') {
                    continue;
                }
                if (! PluginManifest::safeRelativePath(rtrim($relative, '/'))) {
                    throw new RuntimeException("Plugin ZIP contains an unsafe path: {$entry['name']}");
                }

                $key = strtolower(rtrim($relative, '/'));
                if (isset($seen[$key])) {
                    throw new RuntimeException("Plugin ZIP contains a duplicate path: {$relative}");
                }
                $seen[$key] = true;
                $this->rejectServerControlFile($relative);
                $destination = $packagePath.'/'.rtrim($relative, '/');

                if ($entry['directory']) {
                    $this->files->ensureDirectoryExists($destination, 0755, true);
                    continue;
                }

                $this->files->ensureDirectoryExists(dirname($destination), 0755, true);
                $source = $zip->getStream($entry['name']);
                $target = @fopen($destination, 'xb');
                if (! is_resource($source) || ! is_resource($target)) {
                    if (is_resource($source)) fclose($source);
                    if (is_resource($target)) fclose($target);
                    throw new RuntimeException("Unable to extract plugin file: {$relative}");
                }

                try {
                    $written = stream_copy_to_stream($source, $target, $entry['size'] + 1);
                } finally {
                    fclose($source);
                    fclose($target);
                }
                if ($written !== $entry['size']) {
                    throw new RuntimeException("Plugin file size changed during extraction: {$relative}");
                }
                @chmod($destination, 0644);
            }

            $manifest = PluginManifest::fromFile($packagePath.'/plugin.json');
            $this->validatePackageStructure($manifest, $packagePath);

            return [
                'workspace' => $workspace,
                'path' => $packagePath,
                'manifest' => $manifest,
                'checksum' => (string) hash_file('sha256', $archivePath),
            ];
        } catch (\Throwable $exception) {
            $this->files->deleteDirectory($workspace);
            if ($exception instanceof RuntimeException || $exception instanceof \InvalidArgumentException) throw $exception;
            throw new RuntimeException('Unable to stage the plugin package: '.$exception->getMessage(), 0, $exception);
        } finally {
            if ($opened) $zip->close();
        }
    }

    public function promote(array $staged): array
    {
        /** @var PluginManifest $manifest */
        $manifest = $staged['manifest'];
        $root = $this->pluginsPath();
        try {
            $this->files->ensureDirectoryExists($root, 0755, true);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Unable to create the PlayHub plugins directory.', 0, $exception);
        }
        if (! is_writable($root)) {
            throw new RuntimeException('The PlayHub plugins directory is not writable by the web server.');
        }

        $target = $root.'/'.$manifest->id();
        $temporary = $root.'/.install-'.$manifest->id().'-'.Str::lower(Str::random(10));
        $backup = null;
        if (! $this->files->copyDirectory($staged['path'], $temporary)) {
            throw new RuntimeException('Unable to copy the plugin package into the plugins directory.');
        }

        try {
            if (is_dir($target)) {
                $backup = $root.'/.backup-'.$manifest->id().'-'.Str::lower(Str::random(10));
                if (! @rename($target, $backup)) {
                    throw new RuntimeException('Unable to create a rollback copy of the existing plugin.');
                }
            }
            if (! @rename($temporary, $target)) {
                throw new RuntimeException('Unable to activate the installed plugin directory.');
            }
        } catch (\Throwable $exception) {
            $this->files->deleteDirectory($temporary);
            if ($backup && is_dir($backup) && ! is_dir($target)) @rename($backup, $target);
            throw $exception;
        }

        return ['target' => $target, 'backup' => $backup, 'workspace' => $staged['workspace']];
    }

    public function rollbackPromotion(array $promotion): void
    {
        if (is_dir($promotion['target'] ?? '')) {
            $this->files->deleteDirectory($promotion['target']);
        }
        if (is_dir($promotion['backup'] ?? '')) {
            @rename($promotion['backup'], $promotion['target']);
        }
        $this->cleanupWorkspace($promotion['workspace'] ?? null);
    }

    public function finishPromotion(array $promotion): void
    {
        if (is_dir($promotion['backup'] ?? '')) {
            $this->files->deleteDirectory($promotion['backup']);
        }
        $this->cleanupWorkspace($promotion['workspace'] ?? null);
    }

    public function discard(array $staged): void
    {
        $this->cleanupWorkspace($staged['workspace'] ?? null);
    }

    public function moveToTrash(string $pluginId): array
    {
        $target = $this->pluginPath($pluginId);
        if (! is_dir($target)) {
            return ['target' => $target, 'trash' => null];
        }

        $trash = $this->pluginsPath().'/.trash-'.$pluginId.'-'.Str::uuid();
        if (! @rename($target, $trash)) {
            throw new RuntimeException('Unable to move the plugin files out of the active plugins directory.');
        }

        return ['target' => $target, 'trash' => $trash];
    }

    public function restoreTrash(array $removal): void
    {
        if (is_dir($removal['trash'] ?? '') && ! is_dir($removal['target'] ?? '')) {
            @rename($removal['trash'], $removal['target']);
        }
    }

    public function finishRemoval(array $removal): void
    {
        if (is_dir($removal['trash'] ?? '')) {
            $this->files->deleteDirectory($removal['trash']);
        }
    }

    public function pluginsPath(): string
    {
        $path = rtrim((string) config('playhub.plugins_path', storage_path('app/plugins')), DIRECTORY_SEPARATOR);
        if ($path === '' || (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path))) {
            throw new RuntimeException('The PlayHub plugins directory must be an absolute path.');
        }

        return $path;
    }

    public function pluginPath(string $pluginId): string
    {
        if (! preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $pluginId)) {
            throw new RuntimeException('Invalid plugin id.');
        }

        return $this->pluginsPath().DIRECTORY_SEPARATOR.$pluginId;
    }

    private function validatedEntries(ZipArchive $zip): array
    {
        $maxFiles = max(10, (int) config('playhub.plugin_max_files', 2000));
        $maxTotalBytes = max(1, (int) config('playhub.plugin_max_uncompressed_mb', 100)) * 1024 * 1024;
        $maxFileBytes = max(1, (int) config('playhub.plugin_max_file_mb', 20)) * 1024 * 1024;
        if ($zip->numFiles < 1 || $zip->numFiles > $maxFiles) {
            throw new RuntimeException("Plugin ZIP must contain between 1 and {$maxFiles} entries.");
        }

        $entries = [];
        $totalBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (! is_array($stat) || ! is_string($stat['name'] ?? null)) {
                throw new RuntimeException('Plugin ZIP contains an unreadable entry.');
            }
            $name = $stat['name'];
            if ($name === '' || mb_strlen($name) > 500 || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
                throw new RuntimeException('Plugin ZIP contains an invalid filename.');
            }
            $trimmed = rtrim($name, '/');
            if ($trimmed !== '' && ! PluginManifest::safeRelativePath($trimmed)) {
                throw new RuntimeException("Plugin ZIP contains an unsafe path: {$name}");
            }
            if ($this->isSymlink($zip, $index)) {
                throw new RuntimeException("Plugin ZIP cannot contain symbolic links: {$name}");
            }

            $directory = str_ends_with($name, '/');
            $size = $directory ? 0 : max(0, (int) ($stat['size'] ?? 0));
            if ($size > $maxFileBytes) {
                throw new RuntimeException("Plugin file exceeds the per-file size limit: {$name}");
            }
            $totalBytes += $size;
            if ($totalBytes > $maxTotalBytes) {
                throw new RuntimeException('Plugin ZIP exceeds the uncompressed size limit.');
            }
            $entries[] = ['name' => $name, 'directory' => $directory, 'size' => $size];
        }

        return $entries;
    }

    private function packagePrefix(array $entries): string
    {
        $files = collect($entries)
            ->reject(fn (array $entry): bool => $entry['directory'] || $this->ignoredMetadataPath($entry['name']))
            ->pluck('name')
            ->values();
        if ($files->contains('plugin.json')) {
            return '';
        }

        $candidates = $files->filter(fn (string $path): bool => preg_match('#^[^/]+/plugin\.json$#', $path) === 1)->values();
        if ($candidates->count() !== 1) {
            throw new RuntimeException('Plugin ZIP must contain one plugin.json at its root or inside one wrapper directory.');
        }
        $prefix = substr($candidates->first(), 0, strrpos($candidates->first(), '/') + 1);
        if ($files->contains(fn (string $path): bool => ! str_starts_with($path, $prefix))) {
            throw new RuntimeException('Every plugin file must be inside the same package root directory.');
        }

        return $prefix;
    }

    private function validatePackageStructure(PluginManifest $manifest, string $packagePath): void
    {
        foreach ($manifest->autoload() as $path) {
            if (! is_dir($packagePath.'/'.rtrim($path, '/'))) {
                throw new RuntimeException("Plugin autoload directory does not exist: {$path}");
            }
        }
        if (($manifest->providers() !== [] || $manifest->lifecycle()) && $manifest->autoload() === []) {
            throw new RuntimeException('Plugins with providers or lifecycle classes must define autoload.psr-4.');
        }
        if ($manifest->migrations() && ! is_dir($packagePath.'/'.$manifest->migrations())) {
            throw new RuntimeException('The declared plugin migrations directory does not exist.');
        }
        foreach (array_merge($manifest->assets()['styles'], $manifest->assets()['scripts'], $manifest->assets()['files']) as $asset) {
            if (! is_file($packagePath.'/'.$asset)) {
                throw new RuntimeException("Declared plugin asset does not exist: {$asset}");
            }
        }
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $operations = 0;
        $attributes = 0;
        if (! $zip->getExternalAttributesIndex($index, $operations, $attributes)) {
            return false;
        }

        return $operations === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
    }

    private function rejectServerControlFile(string $path): void
    {
        if (in_array(strtolower(basename(rtrim($path, '/'))), ['.env', '.htaccess', '.user.ini', 'web.config'], true)) {
            throw new RuntimeException("Plugin ZIP contains a blocked server control file: {$path}");
        }
    }

    private function ignoredMetadataPath(string $path): bool
    {
        $path = ltrim($path, '/');

        return str_starts_with($path, '__MACOSX/') || basename(rtrim($path, '/')) === '.DS_Store';
    }

    private function cleanupWorkspace(mixed $workspace): void
    {
        if (! is_string($workspace) || is_link($workspace) || ! is_dir($workspace)) {
            return;
        }

        $workspace = rtrim(str_replace('\\', '/', $workspace), '/');
        $storageRoot = rtrim(str_replace('\\', '/', storage_path('app/plugin-installs')), '/');
        $temporaryRoot = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/');
        $name = basename($workspace);
        $managed = (dirname($workspace) === $storageRoot && Str::isUuid($name))
            || (dirname($workspace) === $temporaryRoot
                && str_starts_with($name, 'playhub-plugin-install-')
                && Str::isUuid(substr($name, 23)));

        if ($managed) {
            $this->files->deleteDirectory($workspace);
        }
    }

    private function createWorkspace(): string
    {
        $candidates = [
            storage_path('app/plugin-installs/'.Str::uuid()),
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'playhub-plugin-install-'.Str::uuid(),
        ];

        foreach ($candidates as $workspace) {
            if (@mkdir($workspace, 0700, true) && is_dir($workspace) && ! is_link($workspace)) {
                @chmod($workspace, 0700);

                return $workspace;
            }
        }

        throw new RuntimeException('Unable to create a secure temporary directory for the plugin package.');
    }
}
