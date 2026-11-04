<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RenameNativeDeviceSessionRequest;
use App\Http\Resources\NativeDeviceSessionResource;
use App\Models\NativeDeviceSession;
use App\Services\Auth\NativeDeviceSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NativeDeviceSessionController extends Controller
{
    public function index(Request $request, NativeDeviceSessionManager $sessions): JsonResponse
    {
        $active = $sessions->activeSessions($request->user());

        return response()->json([
            'sessions' => NativeDeviceSessionResource::collection($active),
            'active_count' => $active->count(),
            'max_sessions' => $sessions->maximumSessions(),
        ])->header('Cache-Control', 'no-store');
    }

    public function update(
        RenameNativeDeviceSessionRequest $request,
        NativeDeviceSession $deviceSession,
        NativeDeviceSessionManager $sessions,
    ): JsonResponse {
        $session = $sessions->rename(
            $request->user(),
            $deviceSession,
            (string) $request->validated('device_name'),
        );

        return response()->json([
            'message' => 'Device renamed successfully.',
            'session' => new NativeDeviceSessionResource($session),
        ])->header('Cache-Control', 'no-store');
    }

    public function destroy(
        Request $request,
        NativeDeviceSession $deviceSession,
        NativeDeviceSessionManager $sessions,
    ): JsonResponse {
        $revoked = $sessions->revoke($request->user(), $deviceSession);

        return response()->json([
            'message' => $revoked ? 'Device signed out successfully.' : 'Device session was already inactive.',
            'revoked_count' => $revoked,
        ]);
    }

    public function destroyCurrent(Request $request, NativeDeviceSessionManager $sessions): JsonResponse
    {
        $revoked = $sessions->revokeCurrent($request);

        return response()->json([
            'message' => $revoked ? 'Current device signed out successfully.' : 'Current native session was not found.',
            'revoked_count' => $revoked,
        ], $revoked ? 200 : 404);
    }

    public function destroyOthers(Request $request, NativeDeviceSessionManager $sessions): JsonResponse
    {
        $revoked = $sessions->revokeOthers($request);

        return response()->json([
            'message' => $revoked ? 'Other devices signed out successfully.' : 'No other devices to sign out.',
            'revoked_count' => $revoked,
        ]);
    }

    public function destroyAll(Request $request, NativeDeviceSessionManager $sessions): JsonResponse
    {
        $revoked = $sessions->revokeAll($request->user());

        return response()->json([
            'message' => $revoked ? 'All devices signed out successfully.' : 'No active devices to sign out.',
            'revoked_count' => $revoked,
        ]);
    }
}
