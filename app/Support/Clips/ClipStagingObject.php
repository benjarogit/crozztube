<?php

namespace App\Support\Clips;

use InvalidArgumentException;

/** Server-assigned identity and the exact browser output expected by a job. */
final readonly class ClipStagingObject
{
    public const MAX_BYTES = 67108864;

    public function __construct(public string $jobId, public int $bytes, public string $sha256)
    {
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $jobId)
            || $bytes < 1 || $bytes > self::MAX_BYTES || ! preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new InvalidArgumentException('Invalid clip staging identity, size or checksum.');
        }
    }

    public function incomingKey(): string
    {
        return 'clips/incoming/'.$this->jobId.'.mp4';
    }

    public function handoffKey(): string
    {
        return 'clips/handoff/'.$this->jobId.'.mp4';
    }

    public function checksumBase64(): string
    {
        return base64_encode(hex2bin($this->sha256));
    }

    public function reservedBytes(): int
    {
        // Both copies can exist, including replay of an unexpired incoming grant.
        return 2 * $this->bytes;
    }
}
