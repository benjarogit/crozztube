<?php

namespace App\Updates;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

final class UpdateFilesystem
{
    public function extract(UpdateManifest $release, string $archive, string $destination): UpdateManifest
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP ZIP extension is required to install PlayHub updates.');
        }
        File::deleteDirectory($destination);
        File::ensureDirectoryExists($destination, 0770, true);

        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The update package is not a readable ZIP archive.');
        }

        $maxFiles = max(1, (int) config('playhub-updates.max_files', 20000)) + 1000;
        $maxBytes = max(1, (int) config('playhub-updates.max_uncompressed_mb', 1536)) * 1024 * 1024;
        if ($zip->numFiles > $maxFiles) {
            $zip->close();
            throw new RuntimeException('The update package contains too many files.');
        }

        $written = [];
        $entries = [];
        $totalBytes = 0;
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $rawName = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                $directory = str_ends_with($rawName, '/');
                $name = $directory ? rtrim($rawName, '/') : $rawName;
                $name = UpdateManifest::normalizePath($name);
                if (isset($entries[$name])) {
                    throw new RuntimeException("The update package contains duplicate entry {$name}.");
                }
                $entries[$name] = true;
                if ($name !== 'manifest.json' && $name !== 'files' && ! str_starts_with($name, 'files/')) {
                    throw new RuntimeException("The update package contains unexpected entry {$name}.");
                }
                if ($name === 'files' && ! $directory) {
                    throw new RuntimeException('The reserved update package path files must be a directory.');
                }
                if ($this->zipEntryIsLink($zip, $index)) {
                    throw new RuntimeException("The update package contains forbidden symbolic link {$name}.");
                }

                $size = (int) ($stat['size'] ?? 0);
                if ($size < 0 || ($name === 'manifest.json' && $size > max(1, (int) config('playhub-updates.max_manifest_mb', 16)) * 1024 * 1024)) {
                    throw new RuntimeException("The update entry {$name} exceeds its allowed size.");
                }
                $totalBytes += max(0, $size);
                if ($size > $maxBytes || $totalBytes > $maxBytes) {
                    throw new RuntimeException('The update package exceeds the allowed uncompressed size.');
                }

                $target = $destination.'/'.$name;
                if ($directory) {
                    File::ensureDirectoryExists($target, 0770, true);

                    continue;
                }
                File::ensureDirectoryExists(dirname($target), 0770, true);
                $input = $zip->getStream($rawName);
                $output = @fopen($target, 'wb');
                if (! is_resource($input) || ! is_resource($output)) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new RuntimeException("The update entry {$name} could not be staged.");
                }
                $copied = stream_copy_to_stream($input, $output, min($size, $maxBytes) + 1);
                fclose($input);
                fclose($output);
                if ($copied === false || $copied !== $size) {
                    throw new RuntimeException("The update entry {$name} could not be extracted completely.");
                }
                @chmod($target, 0600);
                $written[$name] = true;
            }
        } finally {
            $zip->close();
        }

        $manifestPath = $destination.'/manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('The update package does not contain manifest.json.');
        }
        $packageManifest = UpdateManifest::fromJson((string) file_get_contents($manifestPath), false);
        $release->verifyPackageManifest($packageManifest);

        $expected = ['manifest.json' => true];
        foreach ($release->fileOperations() as $operation) {
            if ($operation['action'] === 'delete') {
                continue;
            }
            $source = $operation['source'];
            $expected[$source] = true;
            $path = $destination.'/'.$source;
            if (! is_file($path) || is_link($path) || (int) filesize($path) !== $operation['size_bytes'] || ! hash_equals($operation['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException("The staged file {$source} failed integrity verification.");
            }
        }
        foreach (array_keys($written) as $name) {
            if (! isset($expected[$name])) {
                throw new RuntimeException("The update package contains unreferenced file {$name}.");
            }
        }

        return $packageManifest;
    }

    public function deploy(UpdateManifest $manifest, string $stagedDirectory, callable $progress): void
    {
        $operations = $manifest->fileOperations();
        $total = max(1, count($operations));
        foreach ($operations as $index => $operation) {
            $target = $operation['target'];
            $absolute = $this->absoluteTarget($target);
            $action = $operation['action'];
            if (is_dir($absolute)) {
                throw new RuntimeException("Update file operation {$target} cannot replace or delete a directory.");
            }

            if ($action === 'delete') {
                if (! file_exists($absolute) && ! is_link($absolute)) {
                    throw new RuntimeException("Cannot delete missing application path {$target}.");
                }
                if (isset($operation['previous_sha256']) && (! is_file($absolute) || is_link($absolute) || ! hash_equals($operation['previous_sha256'], hash_file('sha256', $absolute)))) {
                    throw new RuntimeException("Application path {$target} changed after preflight and cannot be deleted safely.");
                }
                $this->remove($absolute);
                $progress($index + 1, $total, $action, $target);

                continue;
            }

            $source = $stagedDirectory.'/'.$operation['source'];
            if (! is_file($source) || is_link($source) || ! hash_equals($operation['sha256'], hash_file('sha256', $source))) {
                throw new RuntimeException("Staged update file {$operation['source']} is missing or corrupt.");
            }
            if ($action === 'add' && file_exists($absolute)) {
                if (is_file($absolute) && ! is_link($absolute) && hash_equals($operation['sha256'], hash_file('sha256', $absolute))) {
                    $progress($index + 1, $total, $action, $target);

                    continue;
                }
                throw new RuntimeException("Cannot add {$target} because an unowned file already exists there.");
            }
            if ($action === 'replace' && ! file_exists($absolute)) {
                throw new RuntimeException("Cannot replace missing application file {$target}.");
            }
            if ($action === 'replace' && (! is_file($absolute) || is_link($absolute) || ! hash_equals($operation['previous_sha256'], hash_file('sha256', $absolute)))) {
                throw new RuntimeException("Application file {$target} changed after preflight and cannot be replaced safely.");
            }
            if (is_dir($absolute) || is_link($absolute)) {
                throw new RuntimeException("Cannot deploy update file over unsafe target {$target}.");
            }

            $parent = dirname($absolute);
            $this->ensureDirectory($parent);
            $mode = isset($operation['mode']) ? (int) $operation['mode'] : (file_exists($absolute) ? (fileperms($absolute) & 0777) : 0644);
            $owner = file_exists($absolute) ? @fileowner($absolute) : false;
            $group = file_exists($absolute) ? @filegroup($absolute) : false;
            $temporary = $parent.'/.playhub-update-'.bin2hex(random_bytes(8));
            if (! @copy($source, $temporary)) {
                throw new RuntimeException("Update file {$target} could not be copied into its deployment directory.");
            }
            @chmod($temporary, $mode);
            if ($owner !== false && function_exists('chown')) {
                @chown($temporary, $owner);
            }
            if ($group !== false && function_exists('chgrp')) {
                @chgrp($temporary, $group);
            }
            if (! @rename($temporary, $absolute)) {
                @unlink($temporary);
                throw new RuntimeException("Update file {$target} could not be replaced atomically.");
            }
            clearstatcache(true, $absolute);
            if (! is_file($absolute) || ! hash_equals($operation['sha256'], hash_file('sha256', $absolute))) {
                throw new RuntimeException("Deployed update file {$target} failed verification.");
            }
            $progress($index + 1, $total, $action, $target);
        }
    }

    public function verify(UpdateManifest $manifest): void
    {
        $this->verifyOperations($manifest->fileOperations());
    }

    public function verifyFinal(array $manifests): void
    {
        $operations = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest->fileOperations() as $operation) {
                $operations[$operation['target']] = $operation;
            }
        }
        $this->verifyOperations(array_values($operations));
    }

    public function deployGeneratedTree(string $sourceDirectory, string $targetDirectory, callable $progress): void
    {
        $sourceRoot = realpath($sourceDirectory);
        $targetDirectory = UpdateManifest::normalizePath($targetDirectory);
        if ($sourceRoot === false || ! is_dir($sourceRoot) || UpdateManifest::isProtectedPath($targetDirectory)) {
            throw new RuntimeException('The generated frontend asset directory is invalid.');
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $item) {
            if ($item->isLink() || ! $item->isFile() || ! $item->isReadable()) {
                throw new RuntimeException('Generated frontend output contains an unsupported path type.');
            }
            $relative = UpdateManifest::normalizePath(str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1)));
            $files[$relative] = $item->getPathname();
        }
        if (! $files) {
            throw new RuntimeException('The frontend build produced no deployable assets.');
        }

        foreach ($files as $index => $source) {
            $target = $targetDirectory.'/'.$index;
            $absolute = $this->absoluteTarget($target);
            if (is_dir($absolute) || is_link($absolute)) {
                throw new RuntimeException("Cannot deploy generated frontend asset over unsafe target {$target}.");
            }
            $parent = dirname($absolute);
            $this->ensureDirectory($parent);
            $mode = file_exists($absolute) ? (fileperms($absolute) & 0777) : (fileperms($source) & 0775);
            $owner = file_exists($absolute) ? @fileowner($absolute) : false;
            $group = file_exists($absolute) ? @filegroup($absolute) : false;
            $temporary = $parent.'/.playhub-update-'.bin2hex(random_bytes(8));
            if (! @copy($source, $temporary)) {
                throw new RuntimeException("Generated frontend asset {$target} could not be staged for deployment.");
            }
            @chmod($temporary, $mode);
            if ($owner !== false && function_exists('chown')) {
                @chown($temporary, $owner);
            }
            if ($group !== false && function_exists('chgrp')) {
                @chgrp($temporary, $group);
            }
            if (! @rename($temporary, $absolute)) {
                @unlink($temporary);
                throw new RuntimeException("Generated frontend asset {$target} could not be replaced atomically.");
            }
            clearstatcache(true, $absolute);
            if (! is_file($absolute) || ! hash_equals(hash_file('sha256', $source), hash_file('sha256', $absolute))) {
                throw new RuntimeException("Generated frontend asset {$target} failed deployment verification.");
            }
            $progress($target);
        }
    }

    private function verifyOperations(array $operations): void
    {
        foreach ($operations as $operation) {
            $absolute = $this->absoluteTarget($operation['target']);
            if ($operation['action'] === 'delete') {
                if (file_exists($absolute) || is_link($absolute)) {
                    throw new RuntimeException("Obsolete path {$operation['target']} was not removed.");
                }

                continue;
            }
            if (! is_file($absolute) || is_link($absolute) || ! hash_equals($operation['sha256'], hash_file('sha256', $absolute))) {
                throw new RuntimeException("Installed file {$operation['target']} failed its health check.");
            }
        }
    }

    public function absoluteTarget(string $relative): string
    {
        $relative = UpdateManifest::normalizePath($relative);
        if (UpdateManifest::isProtectedPath($relative)) {
            throw new RuntimeException("Update target {$relative} is protected.");
        }
        $root = $this->root();
        $cursor = $root;
        foreach (explode('/', $relative) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($cursor)) {
                throw new RuntimeException("Update target {$relative} crosses a symbolic link.");
            }
        }

        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function root(): string
    {
        $configured = rtrim((string) config('playhub-updates.root_path', base_path()), DIRECTORY_SEPARATOR);
        $root = realpath($configured);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('The configured PlayHub application root does not exist.');
        }

        return $root;
    }

    private function ensureDirectory(string $directory): void
    {
        $root = $this->root();
        if (! str_starts_with($directory.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('An update attempted to create a directory outside PlayHub.');
        }
        $relative = trim(substr($directory, strlen($root)), DIRECTORY_SEPARATOR);
        $cursor = $root;
        foreach ($relative === '' ? [] : explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($cursor)) {
                throw new RuntimeException('An update target directory crosses a symbolic link.');
            }
            if (! file_exists($cursor) && ! @mkdir($cursor, 0755)) {
                throw new RuntimeException("Update target directory {$cursor} could not be created.");
            }
            if (! is_dir($cursor)) {
                throw new RuntimeException("Update target parent {$cursor} is not a directory.");
            }
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('The updater will not delete symbolic links.');
        }
        if (is_dir($path)) {
            if (! File::deleteDirectory($path)) {
                throw new RuntimeException("Obsolete directory {$path} could not be removed.");
            }
        } elseif (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException("Obsolete file {$path} could not be removed.");
        }
    }

    private function zipEntryIsLink(ZipArchive $zip, int $index): bool
    {
        $operations = 0;
        $attributes = 0;
        if (! $zip->getExternalAttributesIndex($index, $operations, $attributes)) {
            return false;
        }

        return (($attributes >> 16) & 0170000) === 0120000;
    }
}
