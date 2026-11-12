<?php

namespace App\Support\Clips;

use RuntimeException;

/** No provider bodies, previous HTTP exceptions or signed requests retained. */
final class OdyseePublishingFailure extends RuntimeException
{
    public function __construct(public readonly string $operation, public readonly string $reason, public readonly ?int $status = null)
    {
        parent::__construct('Odysee publishing operation failed.');
    }

    public function outcomeUncertain(): bool
    {
        return in_array($this->operation, ['create', 'visibility', 'abandon'], true)
            && $this->reason !== 'request'
            && ! ($this->reason === 'http' && $this->status !== null && $this->status >= 300 && $this->status < 500);
    }
}
