<?php

namespace App\Updates;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class UpdateLock
{
    public function acquire(string $owner): ?UpdateLockHandle
    {
        $directory = rtrim((string) config('playhub-updates.storage_path'), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($directory, 0770, true);
        $path = $directory.'/update.lock';
        $stream = @fopen($path, 'c+');
        if (! is_resource($stream)) {
            throw new RuntimeException('The PlayHub update lock file could not be opened.');
        }
        @chmod($path, 0660);
        if (! @flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);

            return null;
        }

        ftruncate($stream, 0);
        rewind($stream);
        fwrite($stream, json_encode(['owner' => $owner, 'pid' => getmypid(), 'acquired_at' => now()->toIso8601String()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        fflush($stream);

        return new UpdateLockHandle($stream);
    }
}

final class UpdateLockHandle
{
    private bool $released = false;

    public function __construct(private $stream) {}

    public function release(): void
    {
        if ($this->released || ! is_resource($this->stream)) {
            return;
        }
        @flock($this->stream, LOCK_UN);
        fclose($this->stream);
        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}
