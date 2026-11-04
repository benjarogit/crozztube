<?php

namespace App\Support;

use RuntimeException;

/**
 * A private, bounded disk cache. Authorization belongs to the image endpoint;
 * knowing a cache key must never grant access to an otherwise protected image.
 */
class MediaImageCache
{
    private const MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/avif', 'image/bmp', 'image/x-ms-bmp',
    ];

    /** @return array{path: string, mime: string, etag: string, modified: int, size: int} */
    public static function get(string $path): array
    {
        $path = self::objectPath($path);

        try {
            $root = self::root();
            self::pruneIfDue($root);
            [$directory, $key] = self::location($root, $path);
            self::directory($directory);
            $lock = self::lock($root, $key);
            try {
                if ($cached = self::cached($directory, $key)) {
                    // Keep Last-Modified stable while tracking recency separately.
                    if ((int) @filemtime($directory.'/'.$key.'.json') < time() - 60) {
                        @touch($directory.'/'.$key.'.json');
                    }

                    return $cached;
                }

                return self::fetch($directory, $key, $path);
            } finally {
                self::unlock($lock);
            }
        } catch (\Throwable) {
            // Adapter exceptions can contain credentials or signed query strings.
            throw new RuntimeException('The media image is temporarily unavailable.');
        }
    }

    /** Invalidate only after the corresponding storage write/delete succeeds. */
    public static function forget(string|array $paths): void
    {
        try {
            $root = self::root();
            foreach (array_unique((array) $paths) as $path) {
                $path = self::objectPath($path);
                [$directory, $key] = self::location($root, $path);
                $lock = self::lock($root, $key);
                try {
                    if (! self::remove($directory, $key)) throw new RuntimeException('Cannot invalidate cached media image.');
                } finally {
                    self::unlock($lock);
                }
            }
        } catch (\Throwable) {
            // A completed remote write/delete must not be reported as failed.
            try {
                report(new RuntimeException('Unable to invalidate the local media image cache.'));
            } catch (\Throwable) {
                // Even a broken log destination must not undo a completed write.
            }
        }
    }

    /** @return array{removed: int, bytes_removed: int, bytes_remaining: int} */
    public static function prune(): array
    {
        $root = self::root();
        $pruneLock = self::openLock($root.'/.prune.lock');
        if (! flock($pruneLock, LOCK_EX | LOCK_NB)) {
            fclose($pruneLock);

            return ['removed' => 0, 'bytes_removed' => 0, 'bytes_remaining' => 0];
        }

        try {
            $entries = [];
            $total = 0;
            $result = ['removed' => 0, 'bytes_removed' => 0, 'bytes_remaining' => 0];
            $oldest = time() - self::maxAge();
            foreach (new \DirectoryIterator($root) as $namespace) {
                if ($namespace->isDot() || $namespace->isLink() || ! $namespace->isDir()
                    || ! preg_match('/^[a-f0-9]{64}$/D', $namespace->getFilename())) continue;

                foreach (new \DirectoryIterator($namespace->getPathname()) as $file) {
                    if ($file->isDot() || $file->isLink() || ! $file->isFile()) continue;
                    if (! preg_match('/^([a-f0-9]{64})\.img$/D', $file->getFilename(), $match)) {
                        // Recover incomplete writes and metadata left by a crash.
                        $orphan = preg_match('/^([a-f0-9]{64})\.json$/D', $file->getFilename(), $metadataMatch)
                            && ! is_file($namespace->getPathname().'/'.$metadataMatch[1].'.img');
                        if (($orphan || str_starts_with($file->getFilename(), '.pending-'))
                            && $file->getMTime() < time() - 3600) @unlink($file->getPathname());
                        continue;
                    }
                    $metadata = $namespace->getPathname().'/'.$match[1].'.json';
                    $size = $file->getSize();
                    $total += $size;
                    $entries[] = [
                        'directory' => $namespace->getPathname(), 'key' => $match[1], 'size' => $size,
                        'accessed' => (int) (@filemtime($metadata) ?: $file->getMTime()),
                        'expired' => $file->getMTime() < $oldest || ! is_file($metadata) || $size > self::maxImageBytes(),
                    ];
                }
            }

            usort($entries, static fn (array $left, array $right): int => ($right['expired'] <=> $left['expired'])
                ?: ($left['accessed'] <=> $right['accessed']));
            foreach ($entries as $entry) {
                if (! $entry['expired'] && $total <= self::maxBytes()) break;
                $lock = self::lock($root, $entry['key'], false);
                if ($lock === null) continue;
                try {
                    // Recheck after acquiring the same shard lock as get/forget.
                    $image = $entry['directory'].'/'.$entry['key'].'.img';
                    clearstatcache(true, $image);
                    if (! is_file($image) || is_link($image)) continue;
                    $size = (int) filesize($image);
                    $expired = (int) filemtime($image) < $oldest
                        || ! is_file($entry['directory'].'/'.$entry['key'].'.json') || $size > self::maxImageBytes();
                    // A concurrent cache fill may have replaced an expired entry.
                    if (! $expired && $total <= self::maxBytes()) continue;
                    if (self::remove($entry['directory'], $entry['key'])) {
                        $total = max(0, $total - $size);
                        $result['removed']++;
                        $result['bytes_removed'] += $size;
                    }
                } finally {
                    self::unlock($lock);
                }
            }
            $result['bytes_remaining'] = $total;
            @touch($root.'/.last-prune');
            @chmod($root.'/.last-prune', 0600);

            return $result;
        } finally {
            self::unlock($pruneLock);
        }
    }

    private static function fetch(string $directory, string $key, string $path): array
    {
        $temporary = tempnam($directory, '.pending-');
        if ($temporary === false) throw new RuntimeException('Cannot create image cache file.');
        @chmod($temporary, 0600);
        $source = $output = null;

        try {
            // readStream is one authenticated GET; no exists/size/MIME HEAD probe.
            $source = MediaStorage::disk()->readStream($path);
            if (! is_resource($source)) throw new RuntimeException('Cannot read media image.');
            $output = fopen($temporary, 'wb');
            if ($output === false) throw new RuntimeException('Cannot write image cache file.');
            $size = 0;
            $hash = hash_init('sha256');
            while (! feof($source)) {
                $chunk = fread($source, min(65536, self::maxImageBytes() + 1 - $size));
                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    throw new RuntimeException('Incomplete image download.');
                }
                $size += strlen($chunk);
                if ($size > self::maxImageBytes()) throw new RuntimeException('Image exceeds the cache limit.');
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) throw new RuntimeException('Incomplete image cache write.');
                    $offset += $written;
                }
            }
            if (! fflush($output)) throw new RuntimeException('Incomplete image cache write.');
            fclose($output);
            $output = null;

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
            $dimensions = @getimagesize($temporary);
            if ($size === 0 || ! in_array($mime, self::MIMES, true) || ! is_array($dimensions)
                || ($dimensions[0] ?? 0) < 1 || ($dimensions[1] ?? 0) < 1
                || ! in_array($dimensions['mime'] ?? '', self::MIMES, true)) {
                throw new RuntimeException('Media object is not a supported raster image.');
            }
            $metadata = ['mime' => $mime, 'etag' => '"'.hash_final($hash).'"', 'modified' => time(), 'size' => $size];
            $metadataTemporary = tempnam($directory, '.pending-');
            if ($metadataTemporary === false) throw new RuntimeException('Cannot create image cache metadata.');
            try {
                @chmod($metadataTemporary, 0600);
                $json = json_encode($metadata, JSON_THROW_ON_ERROR);
                if (file_put_contents($metadataTemporary, $json, LOCK_EX) !== strlen($json)) {
                    throw new RuntimeException('Cannot write image cache metadata.');
                }
                self::remove($directory, $key);
                if (! rename($temporary, $directory.'/'.$key.'.img')
                    || ! rename($metadataTemporary, $directory.'/'.$key.'.json')) {
                    self::remove($directory, $key);
                    throw new RuntimeException('Cannot publish image cache entry.');
                }
            } finally {
                if (is_file($metadataTemporary)) @unlink($metadataTemporary);
            }

            return ['path' => $directory.'/'.$key.'.img'] + $metadata;
        } finally {
            if (is_resource($source)) fclose($source);
            if (is_resource($output)) fclose($output);
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private static function cached(string $directory, string $key): ?array
    {
        $image = $directory.'/'.$key.'.img';
        $metadata = $directory.'/'.$key.'.json';
        clearstatcache(true, $image);
        if (! is_file($image) || is_link($image) || ! is_readable($image)
            || ! is_file($metadata) || is_link($metadata)
            || (int) filemtime($image) < time() - self::maxAge()) return null;
        $json = @file_get_contents($metadata, false, null, 0, 4097);
        if (! is_string($json) || strlen($json) > 4096) return null;
        $entry = json_decode($json, true);
        if (! is_array($entry) || ! in_array($entry['mime'] ?? '', self::MIMES, true)
            || ! is_string($entry['etag'] ?? null) || ! preg_match('/^"[a-f0-9]{64}"$/D', $entry['etag'])
            || ! is_int($entry['modified'] ?? null) || $entry['modified'] < 1
            || ! is_int($entry['size'] ?? null) || $entry['size'] < 1 || $entry['size'] > self::maxImageBytes()
            || (int) filesize($image) !== $entry['size']) return null;

        return ['path' => $image] + $entry;
    }

    private static function location(string $root, string $path): array
    {
        $settings = MediaStorage::settings();
        $configuration = MediaStorage::configuration($settings);
        $identity = ['provider' => $settings['mediaStorage'] ?? 'default'];
        foreach (['driver', 'bucket', 'container', 'account_name', 'endpoint', 'root', 'region'] as $field) {
            $identity[$field] = $configuration[$field] ?? null;
        }
        // Do not hash credentials: rotating a key should not discard useful warm images.
        if (! empty($configuration['connection_string'])) {
            foreach (explode(';', $configuration['connection_string']) as $segment) {
                [$field, $value] = array_pad(explode('=', $segment, 2), 2, '');
                if (in_array(strtolower(trim($field)), ['accountname', 'blobendpoint', 'endpointsuffix', 'defaultendpointsprotocol'], true)) {
                    $identity['azure_'.strtolower(trim($field))] = trim($value);
                }
            }
        }
        ksort($identity);

        return [$root.'/'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)), hash('sha256', $path)];
    }

    private static function root(): string
    {
        $root = rtrim((string) (config('playhub.media_image_cache.path') ?: storage_path('app/media-image-cache')), '/\\');
        self::directory($root);
        self::directory($root.'/.locks');

        return $root;
    }

    private static function directory(string $directory): void
    {
        if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))) {
            throw new RuntimeException('Cannot create private image cache directory.');
        }
        @chmod($directory, 0700);
    }

    /** Fixed shards bound lock-file growth while allowing unrelated downloads in parallel. */
    private static function lock(string $root, string $key, bool $wait = true): mixed
    {
        $lock = self::openLock($root.'/.locks/'.substr($key, 0, 2).'.lock');
        if (! flock($lock, LOCK_EX | ($wait ? 0 : LOCK_NB))) {
            fclose($lock);
            if ($wait) throw new RuntimeException('Cannot lock image cache.');

            return null;
        }

        return $lock;
    }

    private static function openLock(string $path): mixed
    {
        if (is_link($path)) throw new RuntimeException('Invalid image cache lock.');
        $lock = @fopen($path, 'c+b');
        if ($lock === false) throw new RuntimeException('Cannot open image cache lock.');
        @chmod($path, 0600);

        return $lock;
    }

    private static function unlock(mixed $lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private static function remove(string $directory, string $key): bool
    {
        $image = $directory.'/'.$key.'.img';
        $removed = ! is_file($image) || @unlink($image);
        $metadata = $directory.'/'.$key.'.json';
        if (is_file($metadata) && ! @unlink($metadata)) $removed = false;

        return $removed;
    }

    private static function pruneIfDue(string $root): void
    {
        if ((int) @filemtime($root.'/.last-prune') >= time() - 3600) return;
        try {
            self::prune();
        } catch (\Throwable) {
            // Cache housekeeping must not make an otherwise readable image fail.
        }
    }

    private static function objectPath(string $path): string
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) || preg_match('/^(https?:)?\/\//i', $path)) {
            throw new \InvalidArgumentException('Invalid media image path.');
        }
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || str_contains($path, ':') || preg_match('#(^|/)\.{1,2}(/|$)#', $path)) {
            throw new \InvalidArgumentException('Invalid media image path.');
        }

        return $path;
    }

    private static function maxImageBytes(): int
    {
        return max(1, (int) config('playhub.media_image_cache.max_image_bytes', 20 * 1024 * 1024));
    }

    private static function maxBytes(): int
    {
        return max(0, (int) config('playhub.media_image_cache.max_bytes', 1024 * 1024 * 1024));
    }

    private static function maxAge(): int
    {
        return max(1, (int) config('playhub.media_image_cache.max_age_seconds', 30 * 86400));
    }
}
