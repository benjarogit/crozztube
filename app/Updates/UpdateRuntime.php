<?php

namespace App\Updates;

use Symfony\Component\Process\ExecutableFinder;

final class UpdateRuntime
{
    public static function phpBinary(): ?string
    {
        $configured = (string) config('playhub-updates.php_binary', 'php');
        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            return is_file($configured) && (PHP_OS_FAMILY === 'Windows' || is_executable($configured))
                ? realpath($configured) ?: null
                : null;
        }

        // Queue workers already run under CLI PHP, which may be absent from PATH.
        $extraDirectories = PHP_SAPI === 'cli' && $configured === 'php' ? [dirname(PHP_BINARY)] : [];

        return (new ExecutableFinder)->find($configured, extraDirs: $extraDirectories);
    }
}
