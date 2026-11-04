<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePushDeviceRequest;
use App\Http\Resources\Api\V1\PushDeviceResource;
use App\Models\PushDevice;
use App\Models\User;
use App\Services\PushDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushDeviceController extends Controller
{
    public function __construct(private readonly PushDeviceService $pushDevices) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $devices = $this->pushDevices->activeFor($user);

        return response()->json([
            'push_devices' => PushDeviceResource::collection($devices)->resolve($request),
            'active_count' => $devices->count(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(StorePushDeviceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $device = $this->pushDevices->register($user, $request->validated());
        $created = $device->wasRecentlyCreated;

        return response()->json([
            'push_device' => (new PushDeviceResource($device))->resolve($request),
            'message' => $created ? 'Push device registered.' : 'Push device registration refreshed.',
        ], $created ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, PushDevice $pushDevice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $device = $this->pushDevices->revoke($user, $pushDevice);

        return response()->json([
            'push_device' => (new PushDeviceResource($device))->resolve($request),
            'message' => 'Push device revoked.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function destroyAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $revoked = $this->pushDevices->revokeAll($user);

        return response()->json([
            'message' => 'Push devices revoked.',
            'revoked_count' => $revoked,
        ])->header('Cache-Control', 'private, no-store');
    }
}
