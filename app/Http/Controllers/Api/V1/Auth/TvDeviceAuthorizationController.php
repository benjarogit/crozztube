<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveTvDeviceAuthorizationRequest;
use App\Http\Requests\CreateTvDeviceAuthorizationRequest;
use App\Http\Requests\PollTvDeviceAuthorizationRequest;
use App\Http\Resources\Api\V1\AuthenticatedUserResource;
use App\Http\Resources\NativeDeviceSessionResource;
use App\Services\Auth\TvDeviceAuthorizationService;
use Illuminate\Http\JsonResponse;

class TvDeviceAuthorizationController extends Controller
{
    public function store(
        CreateTvDeviceAuthorizationRequest $request,
        TvDeviceAuthorizationService $authorizations,
    ): JsonResponse {
        return response()->json($authorizations->create($request), 201)
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    public function approve(
        ApproveTvDeviceAuthorizationRequest $request,
        TvDeviceAuthorizationService $authorizations,
    ): JsonResponse {
        $authorization = $authorizations->approve(
            $request->user(),
            (string) $request->validated('user_code'),
            $request,
        );

        return response()->json([
            'message' => 'TV device approved.',
            'device_name' => $authorization->device_name,
            'platform' => $authorization->platform,
            'expires_at' => $authorization->expires_at->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function preview(
        ApproveTvDeviceAuthorizationRequest $request,
        TvDeviceAuthorizationService $authorizations,
    ): JsonResponse {
        $authorization = $authorizations->preview(
            $request->user(),
            (string) $request->validated('user_code'),
        );

        return response()->json([
            'device_name' => $authorization->device_name,
            'platform' => $authorization->platform,
            'expires_at' => $authorization->expires_at->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function token(
        PollTvDeviceAuthorizationRequest $request,
        TvDeviceAuthorizationService $authorizations,
    ): JsonResponse {
        $result = $authorizations->poll((string) $request->validated('device_code'));
        $issued = $result['issued'];
        $user = $result['user']->withAccessToken($issued['token']);
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $issued['plain_text_token'],
            'refresh_token' => $issued['plain_text_refresh_token'],
            'expires_in' => (int) max(0, now()->diffInSeconds($issued['expires_at'], false)),
            'refresh_expires_in' => (int) max(0, now()->diffInSeconds($issued['refresh_expires_at'], false)),
            'session' => new NativeDeviceSessionResource($issued['session']),
            'user' => new AuthenticatedUserResource($user->load('channel')),
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }
}
