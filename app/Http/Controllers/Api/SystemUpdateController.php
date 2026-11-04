<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Licensing\ProductLicenseVerifier;
use App\Models\SystemUpdate;
use App\Models\User;
use App\Updates\SystemUpdater;
use App\Updates\UpdateLogger;
use App\Updates\UpdateManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class SystemUpdateController extends Controller
{
    public function __construct(
        private readonly SystemUpdater $updater,
        private readonly UpdateLogger $logger,
        private readonly ProductLicenseVerifier $licenses,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json($this->updater->dashboard())->header('Cache-Control', 'no-store');
    }

    public function check(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->execute(fn (): array => $this->updater->check());
    }

    public function install(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:48'],
            'target_version' => ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! UpdateManifest::isValidVersion($value)) {
                    $fail("The {$attribute} field must be a semantic version.");
                }
            }],
        ]);

        return $this->execute(function () use ($request, $data): array {
            $verification = $this->licenses->verify($data['license_key']);
            $update = $this->updater->start((int) $request->user()->id, $data['target_version'] ?? null);
            $this->logger->write($update, 'info', 'Product license verified before the system update was queued.', [
                'license_type' => $verification['license_type'],
                'license_id' => $verification['license']['license_id'] ?? null,
            ]);

            return [
                'message' => 'The PlayHub update was queued and will continue even if this browser closes.',
                'update' => $this->updater->updatePayload($update),
                '_status' => 202,
            ];
        });
    }

    public function show(Request $request, SystemUpdate $systemUpdate): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->updater->recoverInterrupted();

        return response()->json(['update' => $this->updater->updatePayload($systemUpdate->fresh('installer'))])->header('Cache-Control', 'no-store');
    }

    public function log(Request $request, SystemUpdate $systemUpdate): JsonResponse
    {
        $this->authorizeAdmin($request);
        $lines = max(1, min(2000, (int) $request->query('lines', 500)));

        return response()->json(['entries' => $this->logger->read($systemUpdate, $lines)])->header('Cache-Control', 'no-store');
    }

    private function execute(callable $callback): JsonResponse
    {
        try {
            $payload = $callback();
            $status = (int) ($payload['_status'] ?? 200);
            unset($payload['_status']);

            return response()->json($payload, $status)->header('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $status = str_contains(strtolower($exception->getMessage()), 'already') ? 409 : 422;

            return response()->json(['message' => $exception->getMessage()], $status)->header('Cache-Control', 'no-store');
        }
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($this->isAdmin($request->user()), 403, 'Admin access required.');
    }

    private function isAdmin(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $settings = is_array($user->settings) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;

        return $adminEnabled || (bool) ($settings['is_admin'] ?? false) || in_array($role, ['admin', 'super admin', 'owner'], true);
    }
}
