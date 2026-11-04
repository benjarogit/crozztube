<?php

namespace App\Services\Auth;

use App\Exceptions\DeviceAuthorizationException;
use App\Models\TvDeviceAuthorization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TvDeviceAuthorizationService
{
    private const USER_CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(
        private readonly NativeDeviceSessionManager $sessions,
        private readonly NativeAccountAccess $accountAccess,
    ) {}

    public function create(Request $request): array
    {
        $expiresAt = now()->addSeconds($this->authorizationTtlSeconds());
        $interval = $this->pollIntervalSeconds();
        $device = $this->sessions->tvDeviceMetadata($request);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $deviceCode = $this->randomDeviceCode();
            $normalizedUserCode = $this->randomUserCode();
            $deviceLookup = $this->lookupHash($deviceCode);
            $userLookup = $this->lookupHash($normalizedUserCode);

            if (TvDeviceAuthorization::query()
                ->where('device_code_lookup', $deviceLookup)
                ->orWhere('user_code_lookup', $userLookup)
                ->exists()) {
                continue;
            }

            try {
                $authorization = TvDeviceAuthorization::query()->create([
                    'device_code_lookup' => $deviceLookup,
                    'device_code_hash' => Hash::make($deviceCode),
                    'user_code_lookup' => $userLookup,
                    'user_code_hash' => Hash::make($normalizedUserCode),
                    'status' => TvDeviceAuthorization::STATUS_PENDING,
                    'platform' => $device['platform'],
                    'device_name' => $device['device_name'],
                    'device_identifier_hash' => $device['device_identifier_hash'],
                    'app_version' => $device['app_version'],
                    'os_version' => $device['os_version'],
                    'request_ip_address' => $device['ip_address'],
                    'user_agent' => $device['user_agent'],
                    'poll_interval_seconds' => $interval,
                    'expires_at' => $expiresAt,
                ]);

                $userCode = substr($normalizedUserCode, 0, 4).'-'.substr($normalizedUserCode, 4);
                $verificationUri = rtrim(trim((string) config('native_auth.verification_uri')) ?: url('/activate'), '/');
                $verificationUriComplete = $verificationUri.'?'.http_build_query(['user_code' => $userCode], '', '&', PHP_QUERY_RFC3986);

                return [
                    'authorization_id' => $authorization->uuid,
                    'device_code' => $deviceCode,
                    'user_code' => $userCode,
                    'verification_uri' => $verificationUri,
                    'verification_uri_complete' => $verificationUriComplete,
                    'qr_uri' => $verificationUriComplete,
                    'expires_in' => (int) max(0, now()->diffInSeconds($expiresAt, false)),
                    'interval' => $interval,
                ];
            } catch (QueryException $exception) {
                if ($attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('A TV authorization code could not be generated.');
    }

    public function approve(User $user, string $userCode, Request $request): TvDeviceAuthorization
    {
        if ($message = $this->accountAccess->denialMessage($user)) {
            throw new DeviceAuthorizationException('access_denied', $message, 403);
        }

        $normalized = $this->normalizeUserCode($userCode);
        $authorization = strlen($normalized) === 8
            ? TvDeviceAuthorization::query()->where('user_code_lookup', $this->lookupHash($normalized))->first()
            : null;

        if (! $authorization || ! Hash::check($normalized, $authorization->user_code_hash)) {
            $this->invalidUserCode();
        }

        $result = DB::transaction(function () use ($authorization, $user, $request): array {
            $authorization = TvDeviceAuthorization::query()->lockForUpdate()->findOrFail($authorization->id);

            if (! $authorization->expires_at->isFuture()) {
                $authorization->forceFill(['status' => TvDeviceAuthorization::STATUS_EXPIRED])->save();

                return ['error' => 'expired'];
            }

            if ($authorization->status !== TvDeviceAuthorization::STATUS_PENDING) {
                return ['error' => 'invalid'];
            }

            $authorization->forceFill([
                'status' => TvDeviceAuthorization::STATUS_APPROVED,
                'approved_by_user_id' => $user->id,
                'approval_ip_address' => $request->ip(),
                'approved_at' => now(),
            ])->save();

            return ['authorization' => $authorization];
        }, 3);

        if (isset($result['error'])) {
            $this->invalidUserCode();
        }

        return $result['authorization'];
    }

    public function preview(User $user, string $userCode): TvDeviceAuthorization
    {
        if ($message = $this->accountAccess->denialMessage($user)) {
            throw new DeviceAuthorizationException('access_denied', $message, 403);
        }

        $normalized = $this->normalizeUserCode($userCode);
        $authorization = strlen($normalized) === 8
            ? TvDeviceAuthorization::query()->where('user_code_lookup', $this->lookupHash($normalized))->first()
            : null;

        if (! $authorization
            || ! Hash::check($normalized, $authorization->user_code_hash)
            || $authorization->status !== TvDeviceAuthorization::STATUS_PENDING
            || ! $authorization->expires_at->isFuture()) {
            $this->invalidUserCode();
        }

        return $authorization;
    }

    public function poll(string $deviceCode): array
    {
        $lookup = $this->lookupHash(trim($deviceCode));
        $authorization = TvDeviceAuthorization::query()->where('device_code_lookup', $lookup)->first();

        if (! $authorization || ! Hash::check(trim($deviceCode), $authorization->device_code_hash)) {
            throw new DeviceAuthorizationException('invalid_grant', 'The device authorization is invalid.', 400);
        }

        $result = DB::transaction(function () use ($authorization): array {
            $authorization = TvDeviceAuthorization::query()->lockForUpdate()->findOrFail($authorization->id);

            if (! $authorization->expires_at->isFuture()) {
                $authorization->forceFill(['status' => TvDeviceAuthorization::STATUS_EXPIRED])->save();

                return ['error' => 'expired_token'];
            }

            if ($authorization->status === TvDeviceAuthorization::STATUS_PENDING) {
                $now = now();
                $retryAfter = $authorization->last_polled_at
                    ? max(0, $authorization->poll_interval_seconds - $authorization->last_polled_at->diffInSeconds($now))
                    : 0;

                if ($retryAfter > 0) {
                    $newInterval = min(30, $authorization->poll_interval_seconds + 5);
                    $authorization->forceFill([
                        'poll_interval_seconds' => $newInterval,
                        'poll_attempts' => $authorization->poll_attempts + 1,
                    ])->save();

                    return ['error' => 'slow_down', 'retry_after' => max($retryAfter, $newInterval)];
                }

                $authorization->forceFill([
                    'last_polled_at' => $now,
                    'poll_attempts' => $authorization->poll_attempts + 1,
                ])->save();

                return [
                    'error' => 'authorization_pending',
                    'retry_after' => $authorization->poll_interval_seconds,
                ];
            }

            if ($authorization->status === TvDeviceAuthorization::STATUS_APPROVED) {
                $user = User::query()->find($authorization->approved_by_user_id);
                if (! $user || ($message = $this->accountAccess->denialMessage($user))) {
                    $authorization->forceFill([
                        'status' => TvDeviceAuthorization::STATUS_DENIED,
                        'denied_at' => now(),
                    ])->save();

                    return ['error' => 'access_denied', 'message' => $message ?? 'Account access is unavailable.'];
                }

                $issued = $this->sessions->issueTvToken($user, [
                    'client_type' => 'tv',
                    'platform' => $authorization->platform,
                    'device_name' => $authorization->device_name,
                    'device_identifier_hash' => $authorization->device_identifier_hash,
                    'app_version' => $authorization->app_version,
                    'os_version' => $authorization->os_version,
                    'ip_address' => $authorization->request_ip_address,
                    'user_agent' => $authorization->user_agent,
                ]);

                $authorization->forceFill([
                    'status' => TvDeviceAuthorization::STATUS_CONSUMED,
                    'consumed_at' => now(),
                ])->save();

                return ['issued' => $issued, 'user' => $user];
            }

            if ($authorization->status === TvDeviceAuthorization::STATUS_DENIED) {
                return ['error' => 'access_denied', 'message' => 'Device authorization was denied.'];
            }

            if ($authorization->status === TvDeviceAuthorization::STATUS_EXPIRED) {
                return ['error' => 'expired_token'];
            }

            return ['error' => 'invalid_grant'];
        }, 3);

        if (isset($result['issued'])) {
            return $result;
        }

        $error = (string) ($result['error'] ?? 'invalid_grant');
        throw match ($error) {
            'authorization_pending' => new DeviceAuthorizationException(
                $error,
                'The user has not approved this device yet.',
                400,
                ['Retry-After' => (string) $result['retry_after']],
                ['interval' => (int) $result['retry_after']],
            ),
            'slow_down' => new DeviceAuthorizationException(
                $error,
                'Polling too quickly. Wait before trying again.',
                429,
                ['Retry-After' => (string) $result['retry_after']],
                ['interval' => (int) $result['retry_after']],
            ),
            'expired_token' => new DeviceAuthorizationException($error, 'The device authorization has expired.', 400),
            'access_denied' => new DeviceAuthorizationException($error, (string) ($result['message'] ?? 'Device authorization was denied.'), 403),
            default => new DeviceAuthorizationException('invalid_grant', 'The device authorization is invalid or has already been used.', 400),
        };
    }

    private function invalidUserCode(): never
    {
        throw ValidationException::withMessages([
            'user_code' => ['The TV code is invalid, expired, or has already been used.'],
        ]);
    }

    private function randomDeviceCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function randomUserCode(): string
    {
        $code = '';
        $last = strlen(self::USER_CODE_ALPHABET) - 1;

        for ($index = 0; $index < 8; $index++) {
            $code .= self::USER_CODE_ALPHABET[random_int(0, $last)];
        }

        return $code;
    }

    private function normalizeUserCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', trim($code)));
    }

    private function lookupHash(string $value): string
    {
        $key = trim((string) config('native_auth.hash_key')) ?: (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('TV device authorization requires an application key.');
        }

        return hash_hmac('sha256', $value, $key);
    }

    private function authorizationTtlSeconds(): int
    {
        return max(120, min((int) config('native_auth.device_code_ttl_seconds', 600), 1800));
    }

    private function pollIntervalSeconds(): int
    {
        return max(3, min((int) config('native_auth.device_code_poll_interval_seconds', 5), 30));
    }
}
