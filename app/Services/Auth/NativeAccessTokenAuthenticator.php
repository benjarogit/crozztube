<?php

namespace App\Services\Auth;

use App\Models\NativeDeviceSession;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class NativeAccessTokenAuthenticator
{
    public function __construct(
        private readonly NativeAccountAccess $accountAccess,
        private readonly mixed $previousCallback = null,
    ) {}

    public function previousCallback(): mixed
    {
        return $this->previousCallback;
    }

    public function __invoke(PersonalAccessToken $accessToken, bool $isValid): bool
    {
        if (is_callable($this->previousCallback)) {
            $isValid = (bool) ($this->previousCallback)($accessToken, $isValid);
        }

        if (! $isValid || $accessToken->name !== 'playhub-native') {
            return $isValid;
        }

        if (! $accessToken->tokenable instanceof User || ! $this->accountAccess->allows($accessToken->tokenable)) {
            return false;
        }

        if (! request()->is('api/v1', 'api/v1/*')) {
            return false;
        }

        $session = NativeDeviceSession::query()
            ->where('personal_access_token_id', $accessToken->getKey())
            ->where('user_id', $accessToken->tokenable_id)
            ->active()
            ->first();

        if ($session) {
            request()->attributes->set(NativeDeviceSession::REQUEST_ATTRIBUTE, $session);
        }

        return $session !== null;
    }
}
