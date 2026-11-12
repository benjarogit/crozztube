<?php

namespace App\Support\Clips;

use Dotenv\Dotenv;
use RuntimeException;
use Throwable;

/** Replace only the two publishing fields; preserve the rest of the local .env. */
final class ClipPublisherSettingsWriter
{
    public function __construct(private string $path) {}

    public function save(#[\SensitiveParameter] string $token, string $channelId): void
    {
        if (! preg_match('/^[a-zA-Z0-9_+\/=.-]{8,2048}$/D', $token)
            || ! preg_match('/^[a-f0-9]{40}$/D', $channelId)
            || is_link($this->path) || ! is_file($this->path)) {
            throw new RuntimeException('Publishing settings cannot be saved to this environment file.');
        }
        $before = file_get_contents($this->path);
        if ($before === false) {
            throw new RuntimeException('The environment file could not be read.');
        }
        try {
            $parsed = Dotenv::parse($before);
        } catch (Throwable) {
            throw new RuntimeException('Correct the existing environment file locally before setup.');
        }
        $after = $before;
        foreach (['CLIP_PUBLISH_ODYSEE_AUTH_TOKEN' => $token, 'CLIP_PUBLISH_ODYSEE_CHANNEL_ID' => $channelId] as $key => $value) {
            if (strpbrk((string) ($parsed[$key] ?? ''), "\r\n") !== false) {
                throw new RuntimeException('Multiline publishing fields must be corrected locally before saving.');
            }
            $pattern = '/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=[^\r\n]*/m';
            if (preg_match_all($pattern, $after) > 1) {
                throw new RuntimeException('Duplicate publishing fields must be corrected locally before saving.');
            }
            $line = $key."='".$value."'";
            if (preg_match($pattern, $after)) {
                $after = preg_replace_callback($pattern, static fn (): string => $line, $after);
            } else {
                $after .= (str_ends_with($after, "\n") ? '' : "\n").$line."\n";
            }
        }
        $temporary = tempnam(dirname($this->path), '.clip-env-');
        if ($temporary === false) {
            throw new RuntimeException('A protected temporary environment file could not be created.');
        }
        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $after, LOCK_EX) !== strlen($after)) {
                throw new RuntimeException('Publishing settings could not be written safely.');
            }
            // Refuse detected concurrent editor changes; do not overwrite a newer file.
            if (is_link($this->path) || file_get_contents($this->path) !== $before) {
                throw new RuntimeException('The environment file changed during setup. Please retry after saving your edits.');
            }
            if (! rename($temporary, $this->path)) {
                throw new RuntimeException('Publishing settings could not replace the environment file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
