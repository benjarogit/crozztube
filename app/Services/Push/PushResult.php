<?php

namespace App\Services\Push;

final readonly class PushResult
{
    public function __construct(
        public string $status,
        public ?string $code = null,
        public int $retryAfter = 60,
        public ?int $invalidSince = null,
    ) {}
}
