<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

final class MediaBinaries
{
    public static function ffmpeg(): string
    {
        return self::resolve('ffmpeg', (string) config('playhub.ffmpeg_binary', 'ffmpeg'));
    }

    public static function ffprobe(): string
    {
        $ffmpeg = self::ffmpeg();
        $extension = strtolower(pathinfo($ffmpeg, PATHINFO_EXTENSION));
        $sibling = dirname($ffmpeg).DIRECTORY_SEPARATOR.'ffprobe'.($extension === 'exe' ? '.exe' : '');

        return self::resolve(
            'ffprobe',
            (string) config('playhub.ffprobe_binary', 'ffprobe'),
            [$sibling],
        );
    }

    /**
     * Resolve a configured executable file, a directory containing the
     * executable, or a command available on PATH on Windows and Unix.
     */
    public static function resolve(string $name, ?string $configured = null, array $fallbacks = []): string
    {
        $configured = self::unquote((string) $configured);
        $finder = new ExecutableFinder;

        foreach (array_filter([$configured, ...$fallbacks]) as $candidate) {
            $candidate = self::unquote((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (is_dir($candidate)) {
                $resolved = $finder->find($name, null, [$candidate]);
                if ($resolved !== null) {
                    return $resolved;
                }

                continue;
            }

            if (self::isUsableFile($candidate)) {
                return realpath($candidate) ?: $candidate;
            }

            if (PHP_OS_FAMILY === 'Windows' && pathinfo($candidate, PATHINFO_EXTENSION) === '' && self::isUsableFile($candidate.'.exe')) {
                return realpath($candidate.'.exe') ?: $candidate.'.exe';
            }

            if (! str_contains($candidate, '/') && ! str_contains($candidate, '\\')) {
                $resolved = $finder->find($candidate);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        $resolved = $finder->find($name);
        if ($resolved !== null) {
            return $resolved;
        }

        $setting = 'PLAYHUB_'.strtoupper($name).'_BINARY';
        throw new RuntimeException("{$name} could not be found. Set {$setting} to an executable path, its bin directory, or a command available on PATH.");
    }

    private static function isUsableFile(string $path): bool
    {
        return is_file($path) && (PHP_OS_FAMILY === 'Windows' || is_executable($path));
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            return trim(substr($value, 1, -1));
        }

        return $value;
    }
}
