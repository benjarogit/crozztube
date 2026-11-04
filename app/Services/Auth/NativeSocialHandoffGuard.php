<?php

namespace App\Services\Auth;

use App\Exceptions\DeviceAuthorizationException;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NativeSocialHandoffGuard
{
    public function __construct(private readonly NativeAccountAccess $accountAccess) {}

    public function exchange(Request $request, Closure $exchange): mixed
    {
        $code = trim((string) $request->input('code'));
        $codeVerifier = trim((string) $request->input('code_verifier'));
        $codeChallenge = $codeVerifier !== ''
            ? rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=')
            : '';
        $key = 'playhub-social-handoff:'.$code.($codeChallenge !== ''
            ? ':'.hash('sha256', $codeChallenge)
            : '');

        try {
            return Cache::lock('playhub-native-social-exchange:'.hash('sha256', $key), 10)
                ->block(5, function () use ($key, $exchange): mixed {
                    $handoff = Cache::get($key);
                    $user = is_array($handoff)
                        ? User::query()->find($handoff['user_id'] ?? null)
                        : null;

                    if ($user && ($message = $this->accountAccess->denialMessage($user))) {
                        Cache::forget($key);
                        throw new DeviceAuthorizationException('account_unavailable', $message, 403);
                    }

                    // AuthController consumes the handoff while this lock is
                    // held. A concurrent exchange waits, then observes that
                    // the one-time handoff has already been removed.
                    return $exchange();
                });
        } catch (LockTimeoutException) {
            throw new DeviceAuthorizationException(
                'social_exchange_in_progress',
                'This social sign-in is already being completed.',
                409,
            );
        }
    }
}
