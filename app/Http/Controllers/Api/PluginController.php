<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class PluginController extends Controller
{
    public function __construct(private readonly PluginManager $plugins)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->execute(fn (): array => $this->plugins->indexPayload());
    }

    public function install(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $maxKilobytes = max(1, (int) config('playhub.plugin_max_archive_mb', 25)) * 1024;
        $data = $request->validate([
            'package' => ['required', 'file', 'max:'.$maxKilobytes],
        ]);
        $package = $data['package'];
        if (strtolower((string) $package->getClientOriginalExtension()) !== 'zip') {
            return response()->json(['message' => 'Plugin packages must be uploaded as ZIP files.'], 422);
        }

        return $this->execute(function () use ($package, $request): array {
            $result = $this->plugins->install($package, (int) $request->user()->id);
            $result['_status'] = $result['updated'] ? 200 : 201;

            return $result;
        });
    }

    public function enable(Request $request, string $plugin): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->execute(fn (): array => $this->plugins->enable($plugin, (int) $request->user()->id));
    }

    public function disable(Request $request, string $plugin): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->execute(fn (): array => $this->plugins->disable($plugin, (int) $request->user()->id));
    }

    public function updateSettings(Request $request, string $plugin): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'settings' => ['required', 'array', 'max:100'],
        ]);

        return $this->execute(fn (): array => $this->plugins->updateSettings($plugin, $data['settings'], (int) $request->user()->id));
    }

    public function destroy(Request $request, string $plugin): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->execute(fn (): array => $this->plugins->remove($plugin, (int) $request->user()->id));
    }

    private function execute(callable $callback): JsonResponse
    {
        try {
            $payload = $callback();
            $status = (int) ($payload['_status'] ?? 200);
            unset($payload['_status']);

            return response()->json($payload, $status)->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $conflict = str_contains($exception->getMessage(), 'already running')
                || str_contains($exception->getMessage(), 'Disable this plugin')
                || str_contains($exception->getMessage(), 'dependent plugins');

            return response()->json(['message' => $exception->getMessage()], $conflict ? 409 : 422)->header('Cache-Control', 'no-store');
        }
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($this->isAdmin($request->user()), 403, 'Admin access required.');
    }

    private function isAdmin(?User $user): bool
    {
        if (! $user) return false;
        $settings = is_array($user->settings) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;

        return $adminEnabled || (bool) ($settings['is_admin'] ?? false) || in_array($role, ['admin', 'super admin', 'owner'], true);
    }
}
