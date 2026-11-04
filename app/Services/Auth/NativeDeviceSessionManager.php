<?php

namespace App\Services\Auth;

use App\Exceptions\DeviceAuthorizationException;
use App\Models\NativeDeviceSession;
use App\Models\NativeSessionRefreshToken;
use App\Models\PushDevice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Throwable;

class NativeDeviceSessionManager
{
    public function __construct(private readonly NativeAccountAccess $accountAccess) {}

    public function attachMobileLoginToken(string $plainTextToken, Request $request): array
    {
        [$token, $user] = $this->resolvePlainTextToken($plainTextToken);

        try {
            if ($message = $this->accountAccess->denialMessage($user)) {
                throw new DeviceAuthorizationException('account_unavailable', $message, 403);
            }

            $device = $this->deviceMetadata($request, 'mobile');
            $expiresAt = now()->addMinutes($this->tokenTtlMinutes('mobile'));
            $issued = DB::transaction(function () use ($token, $user, $device, $expiresAt): array {
                $lockedUser = $this->lockUser($user);
                if ($message = $this->accountAccess->denialMessage($lockedUser)) {
                    throw new DeviceAuthorizationException('account_unavailable', $message, 403);
                }
                $token = PersonalAccessToken::query()->lockForUpdate()->findOrFail($token->id);
                $token->forceFill([
                    'name' => 'playhub-native',
                    'abilities' => $this->tokenAbilities($device['client_type'], $device['platform']),
                    'expires_at' => $expiresAt,
                ])->save();

                $this->revokeMatchingDeviceSessions($user, $device['device_identifier_hash'], (int) $token->id);

                $session = NativeDeviceSession::query()->create(array_merge($device, [
                    'user_id' => $user->id,
                    'personal_access_token_id' => $token->id,
                    'last_seen_at' => now(),
                    'expires_at' => $expiresAt,
                ]));

                $this->pruneExcessSessions($user, (int) $session->id);

                return [
                    'session' => $session,
                    'refresh' => $this->issueRefreshCredential($session),
                ];
            }, 3);
        } catch (Throwable $exception) {
            PersonalAccessToken::query()->whereKey($token->id)->delete();
            throw $exception;
        }

        return [
            'user' => $user,
            'token' => $token->fresh(),
            'plain_text_token' => $plainTextToken,
            'session' => $issued['session'],
            'expires_at' => $expiresAt,
            'plain_text_refresh_token' => $issued['refresh']['plain_text_token'],
            'refresh_expires_at' => $issued['refresh']['expires_at'],
        ];
    }

    public function issueMobileToken(User $user, Request $request): array
    {
        if ($message = $this->accountAccess->denialMessage($user)) {
            throw new DeviceAuthorizationException('account_unavailable', $message, 403);
        }

        $device = $this->deviceMetadata($request, 'mobile');

        return DB::transaction(function () use ($user, $device): array {
            $lockedUser = $this->lockUser($user);
            if ($message = $this->accountAccess->denialMessage($lockedUser)) {
                throw new DeviceAuthorizationException('account_unavailable', $message, 403);
            }

            $expiresAt = now()->addMinutes($this->tokenTtlMinutes('mobile'));
            /** @var NewAccessToken $newToken */
            $newToken = $lockedUser->createToken(
                'playhub-native',
                $this->tokenAbilities('mobile', (string) $device['platform']),
                $expiresAt,
            );

            $this->revokeMatchingDeviceSessions(
                $lockedUser,
                (string) $device['device_identifier_hash'],
                (int) $newToken->accessToken->id,
            );

            $session = NativeDeviceSession::query()->create(array_merge($device, [
                'user_id' => $lockedUser->id,
                'personal_access_token_id' => $newToken->accessToken->id,
                'client_type' => 'mobile',
                'last_seen_at' => now(),
                'expires_at' => $expiresAt,
            ]));

            $this->pruneExcessSessions($lockedUser, (int) $session->id);
            $refresh = $this->issueRefreshCredential($session);

            return [
                'user' => $lockedUser,
                'plain_text_token' => $newToken->plainTextToken,
                'token' => $newToken->accessToken,
                'session' => $session,
                'expires_at' => $expiresAt,
                'plain_text_refresh_token' => $refresh['plain_text_token'],
                'refresh_expires_at' => $refresh['expires_at'],
            ];
        }, 3);
    }

    public function issueTvToken(User $user, array $device): array
    {
        if ($message = $this->accountAccess->denialMessage($user)) {
            throw new DeviceAuthorizationException('account_unavailable', $message, 403);
        }

        return DB::transaction(function () use ($user, $device): array {
            $lockedUser = $this->lockUser($user);
            if ($message = $this->accountAccess->denialMessage($lockedUser)) {
                throw new DeviceAuthorizationException('account_unavailable', $message, 403);
            }
            $expiresAt = now()->addMinutes($this->tokenTtlMinutes('tv'));
            /** @var NewAccessToken $newToken */
            $newToken = $lockedUser->createToken(
                'playhub-native',
                $this->tokenAbilities('tv', (string) $device['platform']),
                $expiresAt,
            );

            $this->revokeMatchingDeviceSessions($lockedUser, (string) $device['device_identifier_hash'], (int) $newToken->accessToken->id);

            $session = NativeDeviceSession::query()->create(array_merge($device, [
                'user_id' => $lockedUser->id,
                'personal_access_token_id' => $newToken->accessToken->id,
                'client_type' => 'tv',
                'last_seen_at' => now(),
                'expires_at' => $expiresAt,
            ]));

            $this->pruneExcessSessions($lockedUser, (int) $session->id);
            $refresh = $this->issueRefreshCredential($session);

            return [
                'plain_text_token' => $newToken->plainTextToken,
                'token' => $newToken->accessToken,
                'session' => $session,
                'expires_at' => $expiresAt,
                'plain_text_refresh_token' => $refresh['plain_text_token'],
                'refresh_expires_at' => $refresh['expires_at'],
            ];
        }, 3);
    }

    public function refresh(Request $request): array
    {
        [$selector, $secret] = $this->parseRefreshToken((string) $request->input('refresh_token'));
        $candidate = NativeSessionRefreshToken::query()
            ->where('uuid', $selector)
            ->first();

        if (! $candidate || ! hash_equals((string) $candidate->token_hash, $this->refreshHash($secret))) {
            throw new DeviceAuthorizationException(
                'invalid_refresh_token',
                'The refresh credential is invalid.',
                401,
            );
        }

        $result = DB::transaction(function () use ($candidate, $secret, $request): array {
            $candidate = NativeSessionRefreshToken::query()->find($candidate->id);
            $session = $candidate
                ? NativeDeviceSession::query()->find($candidate->native_device_session_id)
                : null;

            if (! $candidate || ! $session) {
                return ['error' => 'invalid_refresh_token'];
            }

            $user = User::query()->whereKey($session->user_id)->lockForUpdate()->first();
            $session = NativeDeviceSession::query()->whereKey($session->id)->lockForUpdate()->first();
            $candidate = NativeSessionRefreshToken::query()->whereKey($candidate->id)->lockForUpdate()->first();

            if (! $user || ! $session || ! $candidate
                || ! hash_equals((string) $candidate->token_hash, $this->refreshHash($secret))) {
                return ['error' => 'invalid_refresh_token'];
            }

            if ($candidate->consumed_at !== null) {
                $this->revokeSessionFamilyLocked($session);

                return ['error' => 'refresh_token_reused'];
            }

            if ($candidate->revoked_at !== null || $session->revoked_at !== null) {
                return ['error' => 'invalid_refresh_token'];
            }

            if (! $candidate->expires_at->isFuture()
                || ($session->refresh_expires_at !== null && ! $session->refresh_expires_at->isFuture())) {
                $this->revokeSessionFamilyLocked($session);

                return ['error' => 'refresh_token_expired'];
            }

            $deviceHash = $this->hashDeviceIdentifier((string) $request->input('device_id'));
            $platform = strtolower(trim((string) $request->input('platform')));
            if (! hash_equals((string) $session->device_identifier_hash, $deviceHash)
                || ! hash_equals((string) $session->platform, $platform)) {
                return ['error' => 'refresh_device_mismatch'];
            }

            if ($message = $this->accountAccess->denialMessage($user)) {
                $this->revokeSessionFamilyLocked($session);

                return [
                    'error' => 'account_unavailable',
                    'message' => $message,
                    'status' => 403,
                ];
            }

            $expiresAt = now()->addMinutes($this->tokenTtlMinutes((string) $session->client_type));
            /** @var NewAccessToken $newToken */
            $newToken = $user->createToken(
                'playhub-native',
                $this->tokenAbilities((string) $session->client_type, (string) $session->platform),
                $expiresAt,
            );
            $previousAccessTokenId = $session->personal_access_token_id;

            $candidate->forceFill(['consumed_at' => now()])->save();
            $refresh = $this->issueRefreshCredential($session, false, $expiresAt);
            $session->forceFill([
                'personal_access_token_id' => $newToken->accessToken->id,
                'device_name' => trim((string) $request->input('device_name')) ?: $session->device_name,
                'app_version' => trim((string) $request->input('app_version')) ?: $session->app_version,
                'os_version' => trim((string) $request->input('os_version')) ?: $session->os_version,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit(trim((string) $request->userAgent()), 500, ''),
                'last_seen_at' => now(),
                'expires_at' => $expiresAt,
                'refresh_expires_at' => $refresh['expires_at'],
            ])->save();

            if ($previousAccessTokenId) {
                PersonalAccessToken::query()
                    ->whereKey($previousAccessTokenId)
                    ->where('tokenable_type', User::class)
                    ->where('tokenable_id', $user->id)
                    ->delete();
            }

            return [
                'user' => $user,
                'plain_text_token' => $newToken->plainTextToken,
                'token' => $newToken->accessToken,
                'session' => $session,
                'expires_at' => $expiresAt,
                'plain_text_refresh_token' => $refresh['plain_text_token'],
                'refresh_expires_at' => $refresh['expires_at'],
            ];
        }, 3);

        if (isset($result['error'])) {
            $error = (string) $result['error'];
            throw new DeviceAuthorizationException(
                $error,
                (string) ($result['message'] ?? match ($error) {
                    'refresh_token_reused' => 'Refresh credential reuse was detected. Sign in again.',
                    'refresh_token_expired' => 'The refresh credential has expired. Sign in again.',
                    'refresh_device_mismatch' => 'The refresh credential does not belong to this device.',
                    default => 'The refresh credential is invalid.',
                }),
                (int) ($result['status'] ?? 401),
            );
        }

        return $result;
    }

    public function activeSessions(User $user): Collection
    {
        return NativeDeviceSession::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->get();
    }

    public function currentSession(Request $request): ?NativeDeviceSession
    {
        $tokenId = $this->currentPersonalAccessTokenId($request);
        if ($tokenId < 1) {
            return null;
        }

        $resolved = $request->attributes->get(NativeDeviceSession::REQUEST_ATTRIBUTE);
        if ($resolved instanceof NativeDeviceSession
            && (int) $resolved->user_id === (int) $request->user()->id
            && (int) $resolved->personal_access_token_id === $tokenId
            && $resolved->isActive()) {
            return $resolved;
        }

        $session = NativeDeviceSession::query()
            ->where('user_id', $request->user()->id)
            ->where('personal_access_token_id', $tokenId)
            ->active()
            ->first();

        if ($session) {
            $request->attributes->set(NativeDeviceSession::REQUEST_ATTRIBUTE, $session);
        }

        return $session;
    }

    public function touchCurrent(Request $request): ?NativeDeviceSession
    {
        $session = $this->currentSession($request);
        if (! $session) {
            return null;
        }

        if (! $session->last_seen_at || $session->last_seen_at->lte(now()->subMinutes(5))) {
            $session->forceFill([
                'last_seen_at' => now(),
                'ip_address' => $request->ip(),
            ])->save();
        }

        return $session;
    }

    public function rename(User $user, NativeDeviceSession $session, string $deviceName): NativeDeviceSession
    {
        $this->ensureOwnedBy($user, $session);
        if (! $session->isActive()) {
            abort(404, 'Device session not found.');
        }

        $session->forceFill(['device_name' => trim($deviceName)])->save();

        return $session->fresh();
    }

    public function revoke(User $user, NativeDeviceSession $session): int
    {
        $this->ensureOwnedBy($user, $session);

        return $this->revokeSessionIds($user, collect([$session->id]));
    }

    public function revokeCurrent(Request $request): int
    {
        $session = $this->currentSession($request);

        return $session ? $this->revokeSessionIds($request->user(), collect([$session->id])) : 0;
    }

    public function revokeOthers(Request $request): int
    {
        return DB::transaction(function () use ($request): int {
            $this->lockUser($request->user());
            $currentTokenId = $this->currentPersonalAccessTokenId($request);
            $ids = NativeDeviceSession::query()
                ->where('user_id', $request->user()->id)
                ->active()
                ->when($currentTokenId > 0, function ($query) use ($currentTokenId): void {
                    $query->where(function ($token) use ($currentTokenId): void {
                        $token->whereNull('personal_access_token_id')
                            ->orWhere('personal_access_token_id', '!=', $currentTokenId);
                    });
                })
                ->lockForUpdate()
                ->pluck('id');

            return $this->revokeSessionIds($request->user(), $ids);
        }, 3);
    }

    public function revokeAll(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $this->lockUser($user);
            $ids = NativeDeviceSession::query()
                ->where('user_id', $user->id)
                ->active()
                ->lockForUpdate()
                ->pluck('id');

            return $this->revokeSessionIds($user, $ids);
        }, 3);
    }

    public function hashDeviceIdentifier(string $identifier): string
    {
        return $this->keyedHash(trim($identifier));
    }

    public function maximumSessions(): int
    {
        return max(1, min((int) config('native_auth.max_sessions_per_user', 10), 100));
    }

    private function issueRefreshCredential(
        NativeDeviceSession $session,
        bool $updateSession = true,
        ?CarbonInterface $minimumExpiresAt = null,
    ): array {
        $secret = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $expiresAt = now()->addMinutes($this->refreshTokenTtlMinutes());
        $minimumExpiresAt ??= $session->expires_at;
        if ($minimumExpiresAt !== null && $minimumExpiresAt->isAfter($expiresAt)) {
            $expiresAt = $minimumExpiresAt;
        }
        $refreshToken = NativeSessionRefreshToken::query()->create([
            'native_device_session_id' => $session->id,
            'token_hash' => $this->refreshHash($secret),
            'expires_at' => $expiresAt,
        ]);

        if ($updateSession) {
            $session->forceFill(['refresh_expires_at' => $expiresAt])->save();
        }

        return [
            'plain_text_token' => $refreshToken->uuid.'.'.$secret,
            'expires_at' => $expiresAt,
        ];
    }

    private function parseRefreshToken(string $plainTextToken): array
    {
        $parts = explode('.', trim($plainTextToken), 2);
        if (count($parts) !== 2
            || ! preg_match('/^[0-9a-fA-F-]{36}$/', $parts[0])
            || ! preg_match('/^[A-Za-z0-9_-]{64}$/', $parts[1])) {
            throw new DeviceAuthorizationException(
                'invalid_refresh_token',
                'The refresh credential is invalid.',
                401,
            );
        }

        return $parts;
    }

    private function refreshTokenTtlMinutes(): int
    {
        return max(60, min((int) config('native_auth.refresh_token_ttl_minutes', 259200), 2628000));
    }

    private function refreshHash(string $secret): string
    {
        return $this->keyedHash('refresh:'.$secret);
    }

    private function resolvePlainTextToken(string $plainTextToken): array
    {
        if (! str_contains($plainTextToken, '|')) {
            throw new RuntimeException('The login token could not be converted into a native session.');
        }

        [$id, $secret] = explode('|', $plainTextToken, 2);
        if (! ctype_digit($id) || $secret === '') {
            throw new RuntimeException('The login token could not be converted into a native session.');
        }

        /** @var PersonalAccessToken|null $token */
        $token = PersonalAccessToken::query()->with('tokenable')->find((int) $id);
        $valid = $token && hash_equals((string) $token->token, hash('sha256', $secret));
        if (! $valid || ! $token->tokenable instanceof User) {
            throw new RuntimeException('The login token could not be converted into a native session.');
        }

        return [$token, $token->tokenable];
    }

    private function deviceMetadata(Request $request, string $clientType): array
    {
        $platform = strtolower(trim((string) $request->input('platform')));
        $defaultName = match ($platform) {
            'ios' => 'iPhone or iPad',
            'android' => 'Android device',
            'tvos' => 'Apple TV',
            'android-tv' => 'Android TV',
            'tizen' => 'Samsung TV',
            'webos' => 'LG TV',
            default => 'PlayHub device',
        };

        return [
            'client_type' => $clientType,
            'platform' => $platform,
            'device_name' => trim((string) $request->input('device_name')) ?: $defaultName,
            'device_identifier_hash' => $this->hashDeviceIdentifier((string) $request->input('device_id')),
            'app_version' => trim((string) $request->input('app_version')) ?: null,
            'os_version' => trim((string) $request->input('os_version')) ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit(trim((string) $request->userAgent()), 500, ''),
        ];
    }

    public function tvDeviceMetadata(Request $request): array
    {
        return $this->deviceMetadata($request, 'tv');
    }

    private function tokenAbilities(string $clientType, string $platform): array
    {
        $abilities = [
            'client:native',
            'client:'.$clientType,
            'platform:'.$platform,
            'account:read',
            'catalog:read',
            'playback:read',
            'library:write',
            'engagement:write',
            'live:view',
        ];

        if ($clientType === 'mobile') {
            $abilities[] = 'account:write';
            $abilities[] = 'creator:write';
            $abilities[] = 'uploads:write';
            $abilities[] = 'live:publish';
        }

        return $abilities;
    }

    private function tokenTtlMinutes(string $clientType): int
    {
        $configured = (int) config('native_auth.'.$clientType.'_token_ttl_minutes', 43200);
        $ttl = max(5, min($configured, 525600));
        $sanctumExpiration = config('sanctum.expiration');

        if (is_numeric($sanctumExpiration) && (int) $sanctumExpiration > 0) {
            $ttl = min($ttl, max(1, (int) $sanctumExpiration));
        }

        return $ttl;
    }

    private function revokeMatchingDeviceSessions(User $user, string $deviceHash, int $exceptTokenId): void
    {
        $ids = NativeDeviceSession::query()
            ->where('user_id', $user->id)
            ->where('device_identifier_hash', $deviceHash)
            ->where(function ($token) use ($exceptTokenId): void {
                $token->whereNull('personal_access_token_id')
                    ->orWhere('personal_access_token_id', '!=', $exceptTokenId);
            })
            ->active()
            ->lockForUpdate()
            ->pluck('id');

        // Replacing the login token on the same physical device should not
        // disable its push registration; the new session immediately owns it.
        $this->revokeSessionIds($user, $ids, false);
    }

    private function pruneExcessSessions(User $user, int $keepSessionId): void
    {
        $maximum = $this->maximumSessions();
        $ids = NativeDeviceSession::query()
            ->where('user_id', $user->id)
            ->active()
            ->where('id', '!=', $keepSessionId)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->skip(max(0, $maximum - 1))
            ->take(100)
            ->pluck('id');

        $this->revokeSessionIds($user, $ids);
    }

    private function revokeSessionIds(User $user, $ids, bool $revokePushDevices = true): int
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($user, $ids, $revokePushDevices): int {
            $this->lockUser($user);
            $sessions = NativeDeviceSession::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $ids)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();

            if ($sessions->isEmpty()) {
                return 0;
            }

            $tokenIds = $sessions->pluck('personal_access_token_id')->filter()->unique();
            $deviceHashes = $sessions->pluck('device_identifier_hash')->filter()->unique();
            NativeDeviceSession::query()->whereIn('id', $sessions->pluck('id'))->update(['revoked_at' => now()]);
            NativeSessionRefreshToken::query()
                ->whereIn('native_device_session_id', $sessions->pluck('id'))
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($revokePushDevices && $deviceHashes->isNotEmpty()) {
                PushDevice::query()
                    ->where('user_id', $user->id)
                    ->whereIn('device_identifier_hash', $deviceHashes)
                    ->active()
                    ->update([
                        'revoked_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            if ($tokenIds->isNotEmpty()) {
                PersonalAccessToken::query()
                    ->where('tokenable_type', User::class)
                    ->where('tokenable_id', $user->id)
                    ->whereIn('id', $tokenIds)
                    ->delete();
            }

            return $sessions->count();
        }, 3);
    }

    private function revokeSessionFamilyLocked(NativeDeviceSession $session): void
    {
        $now = now();
        $accessTokenId = $session->personal_access_token_id;

        $session->forceFill(['revoked_at' => $now])->save();
        NativeSessionRefreshToken::query()
            ->where('native_device_session_id', $session->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);
        PushDevice::query()
            ->where('user_id', $session->user_id)
            ->where('device_identifier_hash', $session->device_identifier_hash)
            ->active()
            ->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);

        if ($accessTokenId) {
            PersonalAccessToken::query()
                ->whereKey($accessTokenId)
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $session->user_id)
                ->delete();
        }
    }

    private function ensureOwnedBy(User $user, NativeDeviceSession $session): void
    {
        abort_unless((int) $session->user_id === (int) $user->id, 404, 'Device session not found.');
    }

    private function keyedHash(string $value): string
    {
        $key = trim((string) config('native_auth.hash_key')) ?: (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Native authentication requires an application key.');
        }

        return hash_hmac('sha256', $value, $key);
    }

    private function currentPersonalAccessTokenId(Request $request): int
    {
        $accessToken = $request->user()?->currentAccessToken();

        return $accessToken instanceof PersonalAccessToken ? (int) $accessToken->getKey() : 0;
    }

    private function lockUser(User $user): User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }
}
