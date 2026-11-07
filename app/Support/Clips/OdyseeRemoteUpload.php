<?php

namespace App\Support\Clips;

use InvalidArgumentException;
use JsonSerializable;

/** Internal upload reference, not proof of publication or playback readiness. */
final readonly class OdyseeRemoteUpload implements JsonSerializable
{
    public function __construct(private string $location)
    {
        if (! preg_match('~^https://(?:uploads-v4\.cluster-alpha\.odysee\.com|uploads-v4\.na-backend\.odysee\.com)/v1/urls/[a-zA-Z0-9+_.-]{32,256}$~D', $location)) {
            throw new InvalidArgumentException('Invalid Odysee remote upload reference.');
        }
    }

    /** Only the server-side publishing transport may send this to Odysee. */
    public function filePath(): string
    {
        return $this->location;
    }

    public function jsonSerialize(): array
    {
        return ['provider' => 'odysee', 'registered' => true, 'published' => false];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
