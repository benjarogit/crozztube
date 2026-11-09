<?php

namespace App\Support\Clips;

use RuntimeException;

/** Fixed diagnostics only: upstream errors may contain tokens and signed URLs. */
final class OdyseeImportFailure extends RuntimeException
{
    public function __construct(public readonly string $stage, public readonly ?int $status = null)
    {
        $label = match ($stage) {
            'authorization' => 'upload authorization',
            'registration' => 'remote upload registration',
            default => 'remote import',
        };
        $http = $status !== null && $status >= 100 && $status <= 599 ? ' (HTTP '.$status.')' : '';
        parent::__construct('Odysee '.$label.' failed'.$http.'.');
    }

    /** A lost registration response may already have created an upstream upload. */
    public function outcomeUncertain(): bool
    {
        return $this->stage === 'registration' && ($this->status === null || $this->status >= 500 || $this->status < 300);
    }
}
