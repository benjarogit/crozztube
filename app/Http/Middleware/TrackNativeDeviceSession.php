<?php

namespace App\Http\Middleware;

use App\Exceptions\DeviceAuthorizationException;
use App\Services\Auth\NativeDeviceSessionManager;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class TrackNativeDeviceSession
{
    public function __construct(private readonly NativeDeviceSessionManager $sessions)
    {
    }

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->name !== 'playhub-native') {
            if ($mode === 'optional') {
                return $next($request);
            }

            throw new DeviceAuthorizationException(
                'invalid_native_session',
                'A native device session is required.',
                401,
            );
        }

        $session = $this->sessions->touchCurrent($request);

        if (! $session || ! $session->isActive()) {
            if ($session && $session->revoked_at === null) {
                $this->sessions->revoke($request->user(), $session);
            } else {
                $token->delete();
            }

            throw new DeviceAuthorizationException(
                'invalid_native_session',
                'The device session is expired or has been revoked.',
                401,
            );
        }

        return $next($request);
    }
}
