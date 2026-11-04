<?php

namespace App\Updates;

use App\Models\SystemUpdate;
use Illuminate\Support\Facades\File;
use Throwable;

final class UpdateLogger
{
    public function system(string $level, string $message, array $context = []): void
    {
        try {
            $entry = [
                'timestamp' => now()->toIso8601String(),
                'level' => strtolower($level),
                'message' => $this->sanitize($message),
                'context' => $this->sanitizeContext($context),
            ];
            $this->append($this->directory().'/checks.log', $entry);
        } catch (Throwable) {
            // Logging must never prevent an update rollback or recovery.
        }
    }

    public function write(SystemUpdate $update, string $level, string $message, array $context = []): void
    {
        try {
            $entry = [
                'timestamp' => now()->toIso8601String(),
                'level' => strtolower($level),
                'update' => $update->uuid,
                'from_version' => $update->from_version,
                'to_version' => $update->to_version,
                'message' => $this->sanitize($message),
                'context' => $this->sanitizeContext($context),
            ];
            $this->append($this->path($update), $entry);
        } catch (Throwable) {
            // The transactional recovery path must remain available if the disk fills.
        }
    }

    public function read(SystemUpdate $update, int $lines = 500): array
    {
        $path = $this->path($update);
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $rows = array_slice(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -max(1, min($lines, 2000)));

        return array_values(array_filter(array_map(function (string $row): ?array {
            $entry = json_decode($row, true);

            return is_array($entry) ? $entry : null;
        }, $rows)));
    }

    public function path(SystemUpdate $update): string
    {
        return $this->directory().'/'.$update->uuid.'.log';
    }

    public function sanitize(string $message): string
    {
        $message = preg_replace('/\b(password|passwd|secret|token|api[_ -]?key|private[_ -]?key)\b\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message) ?? $message;
        $message = preg_replace('#([a-z][a-z0-9+.-]*://)[^\s/@]+(?::[^\s/@]*)?@#i', '$1[redacted]@', $message) ?? $message;
        $message = preg_replace('/\bAuthorization\s*:\s*(?:Bearer|Basic)\s+\S+/i', 'Authorization: [redacted]', $message) ?? $message;

        return mb_substr($message, 0, 10000);
    }

    private function sanitizeContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match('/(?:password|passwd|secret|token|api.?key|private.?key|authorization)/i', (string) $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitizeContext($value);
            } elseif (is_string($value)) {
                $context[$key] = $this->sanitize($value);
            }
        }

        return $context;
    }

    private function append(string $path, array $entry): void
    {
        File::ensureDirectoryExists(dirname($path), 0770, true);
        if (file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('The protected update log could not be written.');
        }
        @chmod($path, 0600);
    }

    private function directory(): string
    {
        return rtrim((string) config('playhub-updates.storage_path', storage_path('app/playhub-updates')), DIRECTORY_SEPARATOR).'/logs';
    }
}
