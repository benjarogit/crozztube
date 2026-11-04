<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePlatformAppVersionRequest;
use App\Models\PlatformAppVersion;
use App\Services\ClientVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlatformAppVersionController extends Controller
{
    public function index(Request $request, ClientVersionService $versions): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['platforms' => $versions->all()]);
    }

    public function update(
        UpdatePlatformAppVersionRequest $request,
        PlatformAppVersion $platformAppVersion,
        ClientVersionService $versions,
    ): JsonResponse {
        $this->authorizeAdmin($request);

        return response()->json([
            'platform' => $versions->update($platformAppVersion, $request->validated()),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $settings = is_array($request->user()?->settings) ? $request->user()->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;

        abort_unless(
            $adminEnabled || (bool) ($settings['is_admin'] ?? false) || in_array($role, ['admin', 'super admin', 'owner'], true),
            403,
            'Admin access required.',
        );
    }
}
