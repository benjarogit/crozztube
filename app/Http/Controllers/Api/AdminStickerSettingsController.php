<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\KlipyStickerSettings;
use App\Support\KlipyStickers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminStickerSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);

        return response()->json(['settings' => KlipyStickerSettings::adminSettings()])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:256', 'regex:/^[A-Za-z0-9_.-]*$/D'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'locale' => ['required', 'string', 'regex:/^[a-zA-Z]{2}(?:[_-][a-zA-Z]{2})?$/D'],
            'content_filter' => ['required', Rule::in(['off', 'low', 'medium', 'high'])],
            'live_price_cents' => ['required', 'integer', 'min:1', 'max:10000000'],
            'per_page' => ['required', 'integer', 'min:8', 'max:50'],
        ]);
        KlipyStickerSettings::save($data);

        return response()->json(['message' => 'KLIPY sticker configuration saved.', 'settings' => KlipyStickerSettings::adminSettings()])->header('Cache-Control', 'private, no-store');
    }

    public function test(Request $request, KlipyStickers $stickers): JsonResponse
    {
        $this->authorizeAdministrator($request);
        abort_unless(KlipyStickerSettings::isAvailable(), 422, 'Save and enable your KLIPY client API key first.');

        // The browser tests the provider connection directly, as required by KLIPY.
        return response()->json(['client' => $stickers->clientConfiguration($request)])->header('Cache-Control', 'private, no-store');
    }

    private function authorizeAdministrator(Request $request): void
    {
        $settings = (array) ($request->user()?->settings ?? []);
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $enabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;
        abort_unless($request->user() && ($enabled || ($settings['is_admin'] ?? false) || in_array($role, ['admin', 'super admin', 'owner'], true)), 403, 'Administrator access required.');
    }
}
