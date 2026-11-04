<?php

namespace App\Services\Api\V1;

use App\Models\LiveStream;
use App\Models\User;
use App\Services\LiveKitAdmissionService;
use App\Support\LiveKitSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LiveKitTokenService
{
    public function issue(
        LiveStream $stream,
        ?User $user,
        string $role,
        bool $canPublish,
        ?array $settingsOverride = null,
    ): array {
        $settings = $settingsOverride ?? LiveKitSettings::settings();
        $url = trim((string) ($settings['url'] ?? ''));
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $apiSecret = trim((string) ($settings['api_secret'] ?? ''));
        abort_unless((bool) ($settings['enabled'] ?? true) && $url !== '' && $apiKey !== '' && $apiSecret !== '', 503, 'Live streaming credentials are unavailable.');

        $role = $canPublish ? (in_array($role, ['host', 'guest', 'cohost'], true) ? $role : 'guest') : (in_array($role, ['viewer', 'moderator'], true) ? $role : 'viewer');
        $preview = (bool) $stream->getAttribute('_live_preview');
        $mediaMode = $canPublish && $role !== 'host' && $user
            ? DB::table('live_stream_participant_requests')->where('live_stream_id', $stream->id)->where('user_id', $user->id)->where('status', 'accepted')->value('media_mode')
            : null;
        $identity = $canPublish
            ? ($role === 'host' ? 'creator-'.$stream->user_id : 'participant-'.$user?->id)
            : ($user ? 'viewer-'.$user->id.'-'.Str::lower(Str::random(8)) : 'guest-'.Str::lower(Str::random(20)));
        if ($preview) {
            $identity = 'preview-'.Str::lower(Str::random(16));
        }
        $sources = ! $canPublish ? [] : ($role === 'host'
            ? ['camera', 'microphone', 'screen_share', 'screen_share_audio']
            : ($mediaMode === 'audio' ? ['microphone'] : ['camera', 'microphone']));
        $now = time();
        // The signaling gateway enforces current access and identity revocation;
        // short-lived credentials additionally limit exposure outside that gateway.
        $expiresAt = $now + ($role === 'host' ? 300 : 90);
        $room = 'playhub-live-'.$stream->uuid;
        $claims = [
            'iss' => $apiKey,
            'sub' => $identity,
            'iat' => $now,
            'nbf' => $now - 5,
            'exp' => $expiresAt,
            'jti' => (string) Str::uuid(),
            'name' => $user?->name ?: 'Guest',
            'metadata' => json_encode([
                'user_id' => $user?->id,
                'live_stream_id' => $stream->id,
                'role' => $role,
                'media_mode' => $mediaMode,
                'name' => $user?->name,
                'username' => $user?->username,
                LiveKitAdmissionService::VERSION_METADATA => LiveKitAdmissionService::versionFor($stream, $identity, $user?->id),
            ], JSON_THROW_ON_ERROR),
            'video' => [
                'roomJoin' => true,
                'room' => $room,
                'canPublish' => $canPublish,
                'canSubscribe' => true,
                'canPublishData' => false,
                'canPublishSources' => $sources,
                'canUpdateOwnMetadata' => false,
                'hidden' => $preview,
            ],
        ];
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = $this->base64Url(hash_hmac('sha256', $header.'.'.$payload, $apiSecret, true));

        return [
            'provider' => 'livekit',
            'url' => $url,
            'token' => $header.'.'.$payload.'.'.$signature,
            'room' => $room,
            'identity' => $identity,
            'role' => $role,
            'permissions' => [
                'can_publish' => $canPublish,
                'can_subscribe' => true,
                'can_publish_data' => false,
                'can_publish_sources' => $sources,
            ],
            'expires_at' => now()->setTimestamp($expiresAt)->toIso8601String(),
            'rtc_config' => LiveKitSettings::clientRtcConfig($settings),
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
