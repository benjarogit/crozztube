<?php

namespace App\Support\Clips;

use RuntimeException;

/** Whitelisted diagnostics only. Never retain an HTTP exception or its body. */
final class OdyseeLoginFailure extends RuntimeException
{
    public function __construct(string $stage, string $reason, ?int $status = null)
    {
        $safeStage = match ($stage) {
            'session' => 'client session',
            'signin' => 'account sign-in',
            'account' => 'account verification',
            default => 'setup',
        };
        $safeReason = match ($reason) {
            'transport' => 'connection failed',
            'http' => 'request rejected',
            'confirmation' => 'additional account confirmation required',
            'identity' => 'account email is not confirmed or does not match',
            default => 'unexpected provider response',
        };
        $http = $status !== null && $status >= 100 && $status <= 599 ? ' (HTTP '.$status.')' : '';
        parent::__construct('Odysee '.$safeStage.': '.$safeReason.$http.'. No credentials were saved.');
    }
}
