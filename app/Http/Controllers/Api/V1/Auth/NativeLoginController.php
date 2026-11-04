<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\DeviceAuthorizationException;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Controller;
use App\Http\Requests\NativeConfirmRegistrationRequest;
use App\Http\Requests\NativeLoginRequest;
use App\Http\Requests\NativeRegisterRequest;
use App\Http\Requests\NativeSessionRefreshRequest;
use App\Http\Requests\NativeSocialExchangeRequest;
use App\Http\Resources\Api\V1\AuthenticatedUserResource;
use App\Http\Resources\NativeDeviceSessionResource;
use App\Models\User;
use App\Services\Auth\NativeDeviceSessionManager;
use App\Services\Auth\NativeSocialHandoffGuard;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class NativeLoginController extends Controller
{
    public function store(
        NativeLoginRequest $request,
        AuthController $webAuthentication,
        NativeDeviceSessionManager $sessions,
    ): JsonResponse {
        $request->attributes->set(AuthController::DEFER_FIRST_PARTY_TOKEN_ATTRIBUTE, true);

        return $this->nativeTokenResponse(
            $webAuthentication->login($request),
            $request,
            $sessions,
        );
    }

    public function register(
        NativeRegisterRequest $request,
        AuthController $webAuthentication,
        NativeDeviceSessionManager $sessions,
    ): JsonResponse {
        $request->attributes->set(AuthController::DEFER_FIRST_PARTY_TOKEN_ATTRIBUTE, true);

        return $this->nativeTokenResponse(
            $webAuthentication->register($request),
            $request,
            $sessions,
        );
    }

    public function confirmRegistration(
        NativeConfirmRegistrationRequest $request,
        AuthController $webAuthentication,
        NativeDeviceSessionManager $sessions,
    ): JsonResponse {
        $request->attributes->set(AuthController::DEFER_FIRST_PARTY_TOKEN_ATTRIBUTE, true);

        return $this->nativeTokenResponse(
            $webAuthentication->confirmRegistration($request),
            $request,
            $sessions,
        );
    }

    public function socialExchange(
        NativeSocialExchangeRequest $request,
        AuthController $webAuthentication,
        NativeDeviceSessionManager $sessions,
        NativeSocialHandoffGuard $handoffGuard,
    ): JsonResponse {
        $request->attributes->set(AuthController::DEFER_FIRST_PARTY_TOKEN_ATTRIBUTE, true);

        $authenticationResponse = $handoffGuard->exchange(
            $request,
            fn (): JsonResponse => $webAuthentication->socialExchange($request),
        );

        return $this->nativeTokenResponse($authenticationResponse, $request, $sessions);
    }

    public function refresh(
        NativeSessionRefreshRequest $request,
        NativeDeviceSessionManager $sessions,
    ): JsonResponse {
        $issued = $sessions->refresh($request);
        $user = $issued['user']->withAccessToken($issued['token']);
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'message' => 'Session renewed successfully.',
            'token_type' => 'Bearer',
            'access_token' => $issued['plain_text_token'],
            'refresh_token' => $issued['plain_text_refresh_token'],
            'expires_in' => (int) max(0, now()->diffInSeconds($issued['expires_at'], false)),
            'refresh_expires_in' => (int) max(0, now()->diffInSeconds($issued['refresh_expires_at'], false)),
            'session' => new NativeDeviceSessionResource($issued['session']),
            'user' => new AuthenticatedUserResource($user->load('channel')),
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    private function nativeTokenResponse(
        JsonResponse $authenticationResponse,
        NativeLoginRequest|NativeRegisterRequest|NativeConfirmRegistrationRequest|NativeSocialExchangeRequest $request,
        NativeDeviceSessionManager $sessions,
    ): JsonResponse {
        if ($authenticationResponse->getStatusCode() < 200 || $authenticationResponse->getStatusCode() >= 300) {
            $body = $authenticationResponse->getData(true);
            if ((bool) ($body['requires_captcha'] ?? false)) {
                throw new DeviceAuthorizationException(
                    'captcha_required',
                    (string) ($body['message'] ?? 'Complete the captcha to continue.'),
                    $authenticationResponse->getStatusCode(),
                    [],
                    [
                        'requires_captcha' => true,
                        'validation' => is_array($body['errors'] ?? null) ? $body['errors'] : [],
                    ],
                );
            }

            return $authenticationResponse;
        }

        $authenticationPayload = $authenticationResponse->getData(true);
        $authenticatedUser = $request->attributes->get(AuthController::AUTHENTICATED_USER_ATTRIBUTE);
        if (! $authenticatedUser instanceof User) {
            throw new RuntimeException('Authentication succeeded without resolving an account.');
        }

        $issued = $sessions->issueMobileToken($authenticatedUser, $request);
        $user = $issued['user']->withAccessToken($issued['token']);
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'message' => (string) ($authenticationPayload['message'] ?? 'Authentication completed successfully.'),
            'token_type' => 'Bearer',
            'access_token' => $issued['plain_text_token'],
            'refresh_token' => $issued['plain_text_refresh_token'],
            'expires_in' => (int) max(0, now()->diffInSeconds($issued['expires_at'], false)),
            'refresh_expires_in' => (int) max(0, now()->diffInSeconds($issued['refresh_expires_at'], false)),
            'session' => new NativeDeviceSessionResource($issued['session']),
            'user' => new AuthenticatedUserResource($user->load('channel')),
        ], $authenticationResponse->getStatusCode())
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }
}
