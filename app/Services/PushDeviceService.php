<?php

namespace App\Services;

use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PushDeviceService
{
    public function activeFor(User $user): Collection
    {
        return PushDevice::query()
            ->where('user_id', $user->getKey())
            ->active()
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get();
    }

    public function register(User $user, array $attributes): PushDevice
    {
        $provider = (string) $attributes['provider'];
        $token = (string) $attributes['token'];
        $tokenHash = PushDevice::hashToken($provider, $token);
        $deviceIdentifierHash = $this->hashDeviceIdentifier((string) $attributes['device_id']);
        $now = now();

        return DB::transaction(function () use ($user, $attributes, $provider, $token, $tokenHash, $deviceIdentifierHash, $now): PushDevice {
            $tokenDevice = PushDevice::query()
                ->where('provider', $provider)
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            // A single installation may switch accounts or rotate providers. Retire
            // every older token for it before the new account can receive pushes.
            PushDevice::query()
                ->where('device_identifier_hash', $deviceIdentifierHash)
                ->when($tokenDevice, fn ($query) => $query->whereKeyNot($tokenDevice->getKey()))
                ->active()
                ->update([
                    'revoked_at' => $now,
                    'updated_at' => $now,
                ]);

            return PushDevice::query()->updateOrCreate(
                [
                    'provider' => $provider,
                    'token_hash' => $tokenHash,
                ],
                [
                    'user_id' => $user->getKey(),
                    'platform' => $attributes['platform'],
                    'environment' => $attributes['environment'],
                    'token' => $token,
                    'device_identifier_hash' => $deviceIdentifierHash,
                    'device_name' => $attributes['device_name'] ?? null,
                    'app_version' => $attributes['app_version'] ?? null,
                    'app_identifier' => $attributes['app_identifier'] ?? null,
                    'locale' => $attributes['locale'] ?? null,
                    'last_seen_at' => $now,
                    'revoked_at' => null,
                ],
            );
        }, 3);
    }

    public function revoke(User $user, PushDevice $device): PushDevice
    {
        if ((int) $device->user_id !== (int) $user->getKey()) {
            throw (new ModelNotFoundException)->setModel(PushDevice::class, [$device->getRouteKey()]);
        }

        if ($device->isActive()) {
            $device->forceFill(['revoked_at' => now()])->save();
        }

        return $device->refresh();
    }

    public function revokeAll(User $user): int
    {
        $now = now();

        return PushDevice::query()
            ->where('user_id', $user->getKey())
            ->active()
            ->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function revokeForDevice(User $user, string $deviceIdentifier): int
    {
        $now = now();

        return PushDevice::query()
            ->where('user_id', $user->getKey())
            ->where('device_identifier_hash', $this->hashDeviceIdentifier($deviceIdentifier))
            ->active()
            ->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function hashDeviceIdentifier(string $deviceIdentifier): string
    {
        $key = trim((string) config('native_auth.hash_key')) ?: (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Push device registration requires an application key.');
        }

        return hash_hmac('sha256', trim($deviceIdentifier), $key);
    }
}
