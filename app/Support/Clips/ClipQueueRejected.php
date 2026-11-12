<?php

namespace App\Support\Clips;

use RuntimeException;

/** Stable reason codes for API/UI translation; never include provider details. */
final class ClipQueueRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Clip transfer request rejected.');
    }
}
