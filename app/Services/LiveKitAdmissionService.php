<?php

namespace App\Services;

use App\Models\LiveStream;
use App\Models\User;
use App\Services\Api\V1\LiveStreamAccessService;
use App\Support\LiveKitSettings;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Current PlayHub authorization for the external LiveKit signaling gateway. */
class LiveKitAdmissionService
{
    public const VERSION_METADATA = 'playhub_admission_version';

    public const VERSION_SETTINGS = '_rtc_admission_versions';

    public function __construct(private readonly LiveStreamAccessService $access) {}

    public function allows(string $token): bool
    {
        $settings = LiveKitSettings::settings();
        if (! ($settings['enabled'] ?? false) || empty($settings['api_key']) || empty($settings['api_secret'])) {
            return false;
        }
        $claims = $this->verifiedClaims($token, $settings);
        if (! $claims) {
            return false;
        }
        $grant = $claims['video'] ?? null;
        if (! is_array($grant) || ($grant['roomJoin'] ?? false) !== true
            || ! is_bool($grant['canPublish'] ?? null) || ($grant['canSubscribe'] ?? null) !== true
            || ($grant['canPublishData'] ?? null) !== false || ($grant['canUpdateOwnMetadata'] ?? null) !== false) {
            return false;
        }
        foreach (['roomAdmin', 'roomCreate', 'roomList', 'roomRecord', 'ingressAdmin', 'recorder', 'agent'] as $privilege) {
            if (! empty($grant[$privilege])) {
                return false;
            }
        }
        $identity = $claims['sub'] ?? null;
        if (! is_string($identity) || strlen($identity) > 160 || ! is_string($grant['room'] ?? null)
            || ! str_starts_with($grant['room'], 'playhub-live-')) {
            return false;
        }
        $metadata = $this->jsonObject($claims['metadata'] ?? null);
        if (! $metadata || ! is_int($metadata['live_stream_id'] ?? null)) {
            return false;
        }
        $stream = LiveStream::query()->where('uuid', substr($grant['room'], strlen('playhub-live-')))->first();
        if (! $stream || $stream->id !== $metadata['live_stream_id'] || $stream->status === 'ended') {
            return false;
        }
        $userId = $metadata['user_id'] ?? null;
        if ($userId !== null && (! is_int($userId) || $userId < 1)) {
            return false;
        }
        $version = $metadata[self::VERSION_METADATA] ?? 0;
        if (! is_int($version) || $version < 0 || $version !== self::storedVersion($stream, $identity, $userId)) {
            return false;
        }
        $user = $userId ? User::query()->find($userId) : null;
        if ($userId && (! $user || $this->accountDisabled($user))) {
            return false;
        }
        $preview = ($grant['hidden'] ?? false) === true;
        $publisher = $grant['canPublish'];
        $sources = $grant['canPublishSources'] ?? [];
        if (! is_array($sources) || ! array_is_list($sources)
            || collect($sources)->contains(fn ($source): bool => ! is_string($source))
            || count($sources) !== count(array_unique($sources))) {
            return false;
        }
        $ownerDraftPreview = $preview && ! $publisher && $user && (int) $stream->user_id === $user->id
            && $stream->source_type === 'rtmp' && in_array($stream->status, ['draft', 'scheduled'], true);
        if (! $ownerDraftPreview && ! in_array($stream->status, ['waiting-for-source', 'live'], true)) {
            return false;
        }
        try {
            // Draft encoder monitoring is owner-only; all active joins use the same
            // audience and restriction rules as the existing web/native APIs.
            $this->access->assertViewer($stream, $user);
            if ($preview) {
                return ! $publisher && $sources === [] && preg_match('/^preview-[a-z0-9]{16}$/D', $identity) === 1;
            }
            if (! $publisher) {
                return $sources === [] && $this->subscriberIdentity($identity, $user, $stream);
            }
            if (! $user || $user->isBannedFrom('live') || $sources === []) {
                return false;
            }
            if (($metadata['role'] ?? null) === 'host') {
                $this->access->assertPublisher($stream, $user);

                return $identity === 'creator-'.$stream->user_id
                    && array_diff($sources, ['camera', 'microphone', 'screen_share', 'screen_share_audio']) === [];
            }
            if (! in_array($metadata['role'] ?? null, ['guest', 'cohost'], true)
                || ! (bool) data_get($stream->stream_settings, 'accept_participants', true)) {
                return false;
            }
            $role = DB::table('live_stream_invites')->where('live_stream_id', $stream->id)
                ->where('user_id', $user->id)->value('role');
            if ($role !== $metadata['role']) {
                return false;
            }
            $request = DB::table('live_stream_participant_requests')->where('live_stream_id', $stream->id)
                ->where('user_id', $user->id)->where('status', 'accepted')->first();
            $allowedSources = $request?->media_mode === 'audio' ? ['microphone'] : ['camera', 'microphone'];
            $stageIdentity = $identity === 'participant-'.$user->id
                || ($request && $request->identity === $identity && $this->viewerIdentity($identity, $user->id));

            return $stageIdentity && array_diff($sources, $allowedSources) === [];
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    public static function versionFor(LiveStream $stream, string $identity, ?int $userId = null): int
    {
        $current = LiveStream::query()->find($stream->id, ['id', 'stream_settings']);

        return self::storedVersion($current ?? $stream, $identity, $userId);
    }

    /** A stream ban invalidates even tokens whose identity is currently offline. */
    public static function revokeUser(LiveStream $stream, int $userId): void
    {
        DB::transaction(function () use ($stream, $userId): void {
            $current = LiveStream::query()->whereKey($stream->id)->lockForUpdate()->first();
            if (! $current) {
                return;
            }
            $settings = (array) $current->stream_settings;
            $key = hash('sha256', 'user:'.$userId);
            $settings[self::VERSION_SETTINGS][$key] = (int) data_get($settings, self::VERSION_SETTINGS.'.'.$key, 0) + 1;
            $current->forceFill(['stream_settings' => $settings])->save();
        });
    }

    /** Removing/demoting a connected identity invalidates its cached/refreshed JWTs. */
    public static function revokeIdentity(string $room, string $identity): void
    {
        if (! str_starts_with($room, 'playhub-live-')) {
            return;
        }
        DB::transaction(function () use ($room, $identity): void {
            $stream = LiveStream::query()->where('uuid', substr($room, strlen('playhub-live-')))->lockForUpdate()->first();
            if (! $stream) {
                return;
            }
            $settings = (array) $stream->stream_settings;
            $settings[self::VERSION_SETTINGS][hash('sha256', $identity)] = self::storedVersion($stream, $identity) + 1;
            $stream->forceFill(['stream_settings' => $settings])->save();
        });
    }

    public static function metadataFor(string $room, string $identity, array $metadata): array
    {
        $stream = str_starts_with($room, 'playhub-live-')
            ? LiveStream::query()->where('uuid', substr($room, strlen('playhub-live-')))->first(['id', 'stream_settings'])
            : null;
        if ($stream) {
            $metadata[self::VERSION_METADATA] = self::storedVersion($stream, $identity, $metadata['user_id'] ?? null);
        }

        return $metadata;
    }

    private static function storedVersion(LiveStream $stream, string $identity, ?int $userId = null): int
    {
        return (int) data_get($stream->stream_settings, self::VERSION_SETTINGS.'.'.hash('sha256', $identity), 0)
            + ($userId ? (int) data_get($stream->stream_settings, self::VERSION_SETTINGS.'.'.hash('sha256', 'user:'.$userId), 0) : 0);
    }

    private function accountDisabled(User $user): bool
    {
        $status = strtolower(trim(str_replace(['_', '-'], ' ', (string) data_get($user->settings, 'status', 'active'))));

        return in_array($status, ['suspended', 'deleted'], true) || $user->isBannedFrom('account');
    }

    private function subscriberIdentity(string $identity, ?User $user, LiveStream $stream): bool
    {
        return $user ? ($this->viewerIdentity($identity, $user->id) || $identity === 'participant-'.$user->id
            || ((int) $stream->user_id === $user->id && $identity === 'creator-'.$user->id))
            : preg_match('/^guest-[a-z0-9]{16,20}$/D', $identity) === 1;
    }

    private function viewerIdentity(string $identity, int $userId): bool
    {
        return preg_match('/^viewer-'.$userId.'-[a-z0-9]{8,32}$/D', $identity) === 1;
    }

    private function verifiedClaims(string $token, array $settings): ?array
    {
        if (strlen($token) > 16384 || ! preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/D', $token, $parts)) {
            return null;
        }
        $header = $this->jsonObject($this->decode($parts[1]));
        $claims = $this->jsonObject($this->decode($parts[2]));
        if (($header['alg'] ?? null) !== 'HS256' || ! $claims || ($claims['iss'] ?? null) !== $settings['api_key']
            || ! hash_equals(hash_hmac('sha256', $parts[1].'.'.$parts[2], $settings['api_secret'], true), (string) $this->decode($parts[3]))) {
            return null;
        }
        $now = time();
        if (! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || (isset($claims['nbf']) && (! is_int($claims['nbf']) || $claims['nbf'] > $now + 5))
            || (isset($claims['iat']) && (! is_int($claims['iat']) || $claims['iat'] > $now + 5))) {
            return null;
        }

        // LiveKit refreshes participant tokens itself. Do not impose the initial
        // issuance TTL here: metadata versions/current permissions survive refresh.
        return $claims;
    }

    private function decode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function jsonObject(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);

            return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
        } catch (JsonException) {
            return null;
        }
    }
}
