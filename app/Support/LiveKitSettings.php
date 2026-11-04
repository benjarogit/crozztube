<?php

namespace App\Support;

use App\Services\LiveKitAdmissionService;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class LiveKitSettings
{
    public const DOCKER_IMAGE = 'livekit/livekit-server:v1.13.3';

    public const INGRESS_DOCKER_IMAGE = 'livekit/ingress:v1.5.0';

    public const EGRESS_DOCKER_IMAGE = 'livekit/egress:v1.13.0';

    public const CONTAINER_NAME = 'playhub-livekit';

    public const INGRESS_CONTAINER_NAME = 'playhub-livekit-ingress';

    public const EGRESS_CONTAINER_NAME = 'playhub-livekit-egress';

    private const TEXT_FILE_KEYS = ['docker_compose_yaml', 'livekit_config_yaml', 'entrypoint_script'];

    private const REDACTED_SECRET = '[REDACTED]';

    public static function settings(): array
    {
        $stored = [];
        $path = self::settingsPath();
        if (is_file($path) && is_readable($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }

        return self::normalize(array_replace(self::defaults(), $stored));
    }

    public static function adminSettings(): array
    {
        $settings = self::settings();
        $redisPassword = $settings['redis_password'];
        $docker = LiveKitDockerStatus::read($settings['status_path']);
        $settings['docker_compose_yaml'] = self::readProjectFile($settings['docker_compose_path'], self::generatedDockerCompose($settings));
        $settings['livekit_config_yaml'] = self::readProjectFile($settings['livekit_config_path'], self::generatedLiveKitConfig($settings));
        $settings['entrypoint_script'] = self::readProjectFile($settings['entrypoint_path'], self::defaultEntrypointScript());
        $settings['api_secret_configured'] = filled($settings['api_secret']);
        $settings['api_secret'] = '';
        $settings['status'] = (string) data_get($docker, 'container.status', self::isRunning() ? 'running' : 'stopped');
        $settings['ready'] = self::isRunning();
        $settings['docker'] = $docker;
        $settings['config_path'] = $settings['livekit_config_path'];
        $settings['settings_path'] = 'livekit/runtime/settings.json';
        $settings = self::redactSecret($settings, $redisPassword);
        foreach (array_keys($settings) as $key) {
            if (str_starts_with($key, 'redis_')) {
                unset($settings[$key]);
            }
        }

        return $settings;
    }

    public static function editableKeys(): array
    {
        return array_merge(self::persistentKeys(), self::TEXT_FILE_KEYS);
    }

    public static function configured(): bool
    {
        $settings = self::settings();

        return $settings['enabled'] && filled($settings['url']) && filled($settings['api_key']) && filled($settings['api_secret']);
    }

    public static function isRunning(): bool
    {
        if (app()->environment('testing')) {
            return false;
        }
        $connection = @fsockopen('127.0.0.1', (int) self::settings()['signal_port'], $errorCode, $errorMessage, 0.2);
        if (! is_resource($connection)) {
            return false;
        }
        fclose($connection);

        return true;
    }

    public static function save(array $values, bool $requestRestart = true): array
    {
        $values = array_intersect_key($values, array_flip(self::editableKeys()));
        $current = self::settings();
        if (blank($values['api_secret'] ?? null)) {
            unset($values['api_secret']);
        }
        if (blank($values['redis_password'] ?? null)) {
            unset($values['redis_password']);
        }
        foreach (array_merge(self::TEXT_FILE_KEYS, ['advanced_config_json']) as $key) {
            if (isset($values[$key]) && is_string($values[$key])) {
                $values[$key] = self::restoreRedactedSecret($values[$key], $current['redis_password']);
            }
        }
        $textFiles = array_intersect_key($values, array_flip(self::TEXT_FILE_KEYS));
        foreach (self::TEXT_FILE_KEYS as $key) {
            unset($values[$key]);
        }
        $settings = self::normalize(array_replace($current, $values));
        if (! app()->environment('testing')) {
            if (blank($settings['api_key'])) {
                $settings['api_key'] = 'API'.Str::random(13);
            }
            if (blank($settings['api_secret'])) {
                $settings['api_secret'] = Str::random(48);
            }
            if (blank($settings['redis_password'])) {
                throw new RuntimeException('Configure the host Redis password before saving LiveKit settings.');
            }
        }

        self::ensureCertificateDirectory($settings);
        self::writeRuntimeFiles($settings, $textFiles);
        self::writeFile(self::settingsPath(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", 0600);
        if ($requestRestart) {
            self::requestRestart($settings);
            self::requestDockerApply($settings);
        }

        return $settings;
    }

    public static function requestRestart(?array $settings = null): void
    {
        $settings ??= self::settings();
        self::writeFile(self::projectPath($settings['restart_trigger_path']), now()->toIso8601String().'-'.Str::random(16)."\n", 0640);
    }

    public static function requestDockerAction(string $action): void
    {
        if (! in_array($action, ['start', 'stop', 'restart', 'recreate', 'pull'], true)) {
            throw new RuntimeException('Unsupported LiveKit Docker action.');
        }
        $settings = self::settings();
        self::writeFile(self::projectPath($settings['docker_command_path']), $action."\n".now()->toIso8601String().'-'.Str::random(16)."\n", 0640);
    }

    public static function logs(): array
    {
        $settings = self::settings();

        return self::redactSecret([
            'livekit' => self::tailFile(self::projectPath($settings['log_path'])),
            'docker' => self::tailFile(dirname(self::projectPath($settings['status_path'])).'/docker-action.log'),
        ], $settings['redis_password']);
    }

    public static function testConnection(): array
    {
        $settings = self::settings();
        if (! $settings['enabled']) {
            throw new RuntimeException('LiveKit server is disabled in Admin settings.');
        }
        if (blank($settings['api_key']) || blank($settings['api_secret'])) {
            throw new RuntimeException('Configure the LiveKit API key and secret before testing the connection.');
        }

        $port = (int) $settings['signal_port'];
        $startedAt = microtime(true);
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 2);
        if (! is_resource($socket)) {
            throw new RuntimeException('Unable to reach LiveKit signaling on 127.0.0.1:'.$port.'.');
        }
        fclose($socket);

        try {
            $response = Http::acceptJson()
                ->withToken(self::serviceToken($settings))
                ->connectTimeout(2)
                ->timeout(5)
                ->post('http://127.0.0.1:'.$port.'/twirp/livekit.RoomService/ListRooms', new \stdClass);
        } catch (\Throwable) {
            throw new RuntimeException('LiveKit signaling is reachable, but its authenticated API did not respond.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('LiveKit signaling is reachable, but its API credentials were rejected (HTTP '.$response->status().').');
        }

        return [
            'success' => true,
            'message' => 'LiveKit signaling and authenticated API connection succeeded.',
            'endpoint' => '127.0.0.1:'.$port,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'rooms' => count((array) $response->json('rooms', [])),
            'tested_at' => now()->toIso8601String(),
        ];
    }

    public static function activeRoomViewerCounts(): array
    {
        $settings = self::settings();
        if (! $settings['enabled'] || blank($settings['api_key']) || blank($settings['api_secret'])) {
            return [];
        }

        return Cache::remember('playhub:livekit:active-room-viewers', 5, function () use ($settings): array {
            try {
                $response = Http::acceptJson()
                    ->withToken(self::serviceToken($settings, ['roomList' => true], 'playhub-live-directory'))
                    ->connectTimeout(2)
                    ->timeout(4)
                    ->post('http://127.0.0.1:'.(int) $settings['signal_port'].'/twirp/livekit.RoomService/ListRooms', new \stdClass);
            } catch (\Throwable) {
                return [];
            }
            if (! $response->successful()) {
                return [];
            }

            $names = collect((array) $response->json('rooms', []))->map(fn ($room): string => trim((string) data_get($room, 'name', '')))->filter()->unique();
            if ($names->isEmpty()) {
                return [];
            }
            try {
                $participants = Http::pool(function (Pool $pool) use ($names, $settings): array {
                    return $names->map(fn (string $name) => $pool->as($name)->acceptJson()
                        ->withToken(self::serviceToken($settings, ['roomAdmin' => true, 'room' => $name], 'playhub-live-audience-count'))
                        ->connectTimeout(2)->timeout(4)
                        ->post('http://127.0.0.1:'.(int) $settings['signal_port'].'/twirp/livekit.RoomService/ListParticipants', ['room' => $name]))->all();
                }, 10);
            } catch (\Throwable) {
                return [];
            }

            return $names->mapWithKeys(function (string $name) use ($participants): array {
                $response = $participants[$name] ?? null;
                $viewers = 0;
                if ($response instanceof Response && $response->successful()) {
                    // Hidden preview participants can still be included in LiveKit room totals.
                    $viewers = collect((array) $response->json('participants', []))->filter(function ($participant): bool {
                        $participant = (array) $participant;
                        $identity = (string) ($participant['identity'] ?? '');
                        $metadata = json_decode((string) ($participant['metadata'] ?? ''), true) ?: [];
                        $permission = (array) ($participant['permission'] ?? []);

                        return ! str_starts_with($identity, 'preview-')
                            && ! (bool) ($permission['hidden'] ?? false)
                            && ! (bool) ($permission['recorder'] ?? false)
                            && ! (bool) ($participant['isPublisher'] ?? $participant['is_publisher'] ?? false)
                            && ! (bool) ($permission['canPublish'] ?? $permission['can_publish'] ?? false)
                            && ! in_array($metadata['role'] ?? null, ['host', 'guest', 'cohost', 'preview'], true);
                    })->count();
                }

                return [$name => $viewers];
            })->all();
        });
    }

    public static function createRoom(string $name, array $metadata = [], array $options = []): array
    {
        $settings = self::settings();
        if (! $settings['enabled'] || blank($settings['api_key']) || blank($settings['api_secret'])) {
            throw new RuntimeException('LiveKit credentials are unavailable.');
        }

        $request = [
            'name' => $name,
            'emptyTimeout' => (int) $settings['room_empty_timeout'],
            'departureTimeout' => max(5, min(300, (int) ($options['disconnect_grace_seconds'] ?? $settings['room_departure_timeout']))),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ];
        $configuredDelay = ((int) ($options['stream_delay_seconds'] ?? 0)) * 1000;
        $playoutDelay = $configuredDelay > 0 ? $configuredDelay : match ((string) ($options['latency_mode'] ?? 'low')) {
            'normal' => 500,
            'low' => 100,
            default => 0,
        };
        $playoutDelay = max(0, min(40000, $playoutDelay));
        if ($playoutDelay > 0) {
            $request['minPlayoutDelay'] = $playoutDelay;
            $request['maxPlayoutDelay'] = $playoutDelay;
            if ($playoutDelay > 200) {
                $request['syncStreams'] = true;
            }
        }
        if ((int) $settings['max_participants'] > 0) {
            $request['maxParticipants'] = (int) $settings['max_participants'];
        }

        try {
            $response = Http::acceptJson()
                ->withToken(self::serviceToken($settings, ['roomCreate' => true], 'playhub-live-room-create'))
                ->connectTimeout(2)
                ->timeout(5)
                ->post('http://127.0.0.1:'.(int) $settings['signal_port'].'/twirp/livekit.RoomService/CreateRoom', $request);
        } catch (\Throwable) {
            throw new RuntimeException('LiveKit did not respond while creating the live room.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('LiveKit rejected the live room request (HTTP '.$response->status().').');
        }

        return (array) $response->json();
    }

    public static function recreateRoom(string $name, array $metadata = [], array $options = []): array
    {
        $settings = self::settings();
        if (! $settings['enabled'] || blank($settings['api_key']) || blank($settings['api_secret'])) {
            throw new RuntimeException('LiveKit credentials are unavailable.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken(self::serviceToken($settings, ['roomList' => true], 'playhub-live-room-inspect'))
                ->connectTimeout(2)
                ->timeout(5)
                ->post('http://127.0.0.1:'.(int) $settings['signal_port'].'/twirp/livekit.RoomService/ListRooms', ['names' => [$name]]);
        } catch (\Throwable) {
            throw new RuntimeException('LiveKit did not respond while inspecting the live room.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('LiveKit rejected the live room inspection request (HTTP '.$response->status().').');
        }

        $room = collect((array) $response->json('rooms', []))->first(fn ($item): bool => (string) data_get($item, 'name') === $name);
        if ($room) {
            $publishers = (int) ($room['numPublishers'] ?? $room['num_publishers'] ?? 0);
            if ($publishers > 0) {
                throw new RuntimeException('LiveKit cannot reconfigure a room while a media source is publishing.');
            }
            self::deleteRoom($name);
        }

        return self::createRoom($name, $metadata, $options);
    }

    public static function deleteRoom(string $name): void
    {
        if ($name === '') {
            return;
        }
        $settings = self::settings();
        if (! $settings['enabled'] || blank($settings['api_key']) || blank($settings['api_secret'])) {
            throw new RuntimeException('LiveKit credentials are unavailable.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken(self::serviceToken($settings, ['roomCreate' => true], 'playhub-live-room-delete'))
                ->connectTimeout(2)
                ->timeout(5)
                ->post('http://127.0.0.1:'.(int) $settings['signal_port'].'/twirp/livekit.RoomService/DeleteRoom', ['room' => $name]);
        } catch (\Throwable) {
            throw new RuntimeException('LiveKit did not respond while deleting the live room.');
        }
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('LiveKit rejected the live room deletion request (HTTP '.$response->status().').');
        }
    }

    public static function participantIsPublishing(string $room, string $identity, array $requiredSources = ['CAMERA', 'MICROPHONE']): bool
    {
        if ($room === '' || $identity === '') {
            return false;
        }
        $participant = self::roomServiceRequest('GetParticipant', ['room' => $room, 'identity' => $identity], 'inspect-publisher', true);
        if ($participant === null) {
            return false;
        }

        $sourceNames = [1 => 'CAMERA', 2 => 'MICROPHONE', 3 => 'SCREEN_SHARE', 4 => 'SCREEN_SHARE_AUDIO'];
        $publishedSources = collect((array) ($participant['tracks'] ?? []))
            ->reject(fn ($track): bool => (bool) data_get($track, 'muted', false))
            ->map(function ($track) use ($sourceNames): string {
                $source = data_get($track, 'source', '');

                return is_numeric($source) ? ($sourceNames[(int) $source] ?? '') : strtoupper((string) $source);
            })
            ->filter()
            ->unique();

        return collect($requiredSources)
            ->map(fn ($source): string => strtoupper((string) $source))
            ->every(fn (string $source): bool => $publishedSources->contains($source));
    }

    public static function ingressAvailable(): bool
    {
        if (! self::configured() || ! self::isRunning() || blank(self::settings()['rtmp_url'])) {
            return false;
        }

        try {
            self::ingressServiceRequest('ListIngress', [], 'ingress-health');

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function egressAvailable(): bool
    {
        if (! self::configured() || ! self::isRunning()) {
            return false;
        }
        $connection = @fsockopen('127.0.0.1', 7981, $errorCode, $errorMessage, 0.3);
        if (! is_resource($connection)) {
            return false;
        }
        fclose($connection);

        return true;
    }

    public static function createRtmpIngress(string $room, string $identity, string $name, array $metadata = [], array $streamSettings = []): array
    {
        return self::ingressServiceRequest('CreateIngress', [
            'inputType' => 'RTMP_INPUT',
            'name' => $name,
            'roomName' => $room,
            'participantIdentity' => $identity,
            'participantName' => $name,
            'participantMetadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'enableTranscoding' => true,
            ...self::ingressEncodingOptions($streamSettings),
        ], 'ingress-create');
    }

    public static function updateRtmpIngress(string $ingressId, string $room, string $identity, string $name, array $metadata = [], array $streamSettings = []): array
    {
        return self::ingressServiceRequest('UpdateIngress', [
            'ingressId' => $ingressId,
            'name' => $name,
            'roomName' => $room,
            'participantIdentity' => $identity,
            'participantName' => $name,
            'participantMetadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ...self::ingressEncodingOptions($streamSettings),
        ], 'ingress-update');
    }

    public static function findIngress(string $ingressId): ?array
    {
        if ($ingressId === '') {
            return null;
        }
        $response = self::ingressServiceRequest('ListIngress', ['ingressId' => $ingressId], 'ingress-list');

        return collect((array) ($response['items'] ?? []))
            ->first(fn ($item): bool => (string) ($item['ingress_id'] ?? $item['ingressId'] ?? '') === $ingressId);
    }

    public static function deleteIngress(string $ingressId): void
    {
        if ($ingressId === '') {
            return;
        }
        self::ingressServiceRequest('DeleteIngress', ['ingressId' => $ingressId], 'ingress-delete');
    }

    public static function ingressIsPublishing(array $ingress): bool
    {
        $status = data_get($ingress, 'state.status');

        return $status === 'ENDPOINT_PUBLISHING' || (int) $status === 2;
    }

    public static function startRoomRecording(string $room, string $filepath, array $streamSettings): array
    {
        if (! str_starts_with($filepath, '/out/') || str_contains($filepath, '..')) {
            throw new RuntimeException('Invalid LiveKit recording path.');
        }
        $quality = (string) ($streamSettings['quality'] ?? 'auto');
        [$width, $height] = self::videoDimensions($quality);
        $advanced = [
            'framerate' => max(1, min(60, (int) ($streamSettings['frame_rate'] ?? 30))),
            'audioCodec' => 'AAC',
            'audioBitrate' => max(96, min(320, (int) ($streamSettings['audio_bitrate_kbps'] ?? 128))),
            'audioFrequency' => 48000,
            'videoCodec' => 'H264_MAIN',
            'keyFrameInterval' => max(1, min(4, (float) ($streamSettings['keyframe_interval_seconds'] ?? 2))),
        ];
        if ($quality !== '' && $quality !== 'auto' && $width && $height) {
            $advanced['width'] = $width;
            $advanced['height'] = $height;
            $advanced['videoBitrate'] = max(250, min(50000, (int) ($streamSettings['target_bitrate_kbps'] ?? 3000)));
        }

        return self::egressServiceRequest('StartRoomCompositeEgress', [
            'roomName' => $room,
            'layout' => 'grid',
            'customBaseUrl' => url('/livekit-recording-template'),
            'fileOutputs' => [[
                'fileType' => 'MP4',
                'filepath' => $filepath,
                'disableManifest' => true,
            ]],
            'advanced' => $advanced,
        ], $room, 'egress-start');
    }

    public static function startRoomThumbnailCapture(string $room, string $filenamePrefix, array $streamSettings): array
    {
        if (! str_starts_with($filenamePrefix, '/out/') || str_contains($filenamePrefix, '..')) {
            throw new RuntimeException('Invalid LiveKit thumbnail path.');
        }
        [$width, $height] = self::videoDimensions((string) ($streamSettings['quality'] ?? 'auto'));
        if (! $width || ! $height) {
            $width = 1280;
            $height = 720;
        } elseif ($width > 1280) {
            $height = self::evenDimension($height * (1280 / $width));
            $width = 1280;
        }

        return self::egressServiceRequest('StartRoomCompositeEgress', [
            'roomName' => $room,
            'layout' => 'speaker',
            'imageOutputs' => [[
                'captureInterval' => 1,
                'width' => $width,
                'height' => $height,
                'filenamePrefix' => $filenamePrefix,
                'filenameSuffix' => 'IMAGE_SUFFIX_NONE_OVERWRITE',
                'imageCodec' => 'IC_JPEG',
                'disableManifest' => true,
            ]],
            'advanced' => [
                'width' => $width,
                'height' => $height,
                'framerate' => max(1, min(30, (int) ($streamSettings['frame_rate'] ?? 30))),
                'audioCodec' => 'AAC',
                'audioBitrate' => 96,
                'audioFrequency' => 48000,
                'videoCodec' => 'H264_MAIN',
                'videoBitrate' => max(250, min(50000, (int) ($streamSettings['target_bitrate_kbps'] ?? 3000))),
                'keyFrameInterval' => max(1, min(4, (float) ($streamSettings['keyframe_interval_seconds'] ?? 2))),
            ],
        ], $room, 'thumbnail-start');
    }

    public static function stopEgress(string $egressId, string $room): array
    {
        if ($egressId === '') {
            return [];
        }

        return self::egressServiceRequest('StopEgress', ['egressId' => $egressId], $room, 'egress-stop');
    }

    public static function findEgress(string $egressId, string $room): ?array
    {
        if ($egressId === '') {
            return null;
        }
        $response = self::egressServiceRequest('ListEgress', ['egressId' => $egressId], $room, 'egress-list');

        return collect((array) ($response['items'] ?? []))
            ->first(fn ($item): bool => (string) ($item['egress_id'] ?? $item['egressId'] ?? '') === $egressId);
    }

    public static function egressIsComplete(array $egress): bool
    {
        $status = $egress['status'] ?? null;

        return $status === 'EGRESS_COMPLETE' || (int) $status === 3;
    }

    public static function egressHasFailed(array $egress): bool
    {
        $status = $egress['status'] ?? null;

        return in_array($status, ['EGRESS_FAILED', 'EGRESS_ABORTED', 'EGRESS_LIMIT_REACHED'], true) || in_array((int) $status, [4, 5, 6], true);
    }

    public static function removeParticipant(string $room, string $identity): void
    {
        LiveKitAdmissionService::revokeIdentity($room, $identity);
        self::roomServiceRequest('RemoveParticipant', ['room' => $room, 'identity' => $identity], 'remove-participant');
    }

    public static function participants(string $room): array
    {
        $response = self::roomServiceRequest('ListParticipants', ['room' => $room], 'list-participants', true);

        return (array) ($response['participants'] ?? []);
    }

    public static function mutePublishedTrack(string $room, string $identity, string $trackSid): void
    {
        self::roomServiceRequest('MutePublishedTrack', ['room' => $room, 'identity' => $identity, 'trackSid' => $trackSid, 'muted' => true], 'mute-track');
    }

    public static function updateParticipantPublishing(string $room, string $identity, bool $canPublish, string $mediaMode, array $metadata): bool
    {
        if (! $canPublish) {
            LiveKitAdmissionService::revokeIdentity($room, $identity);
        }
        $metadata = LiveKitAdmissionService::metadataFor($room, $identity, $metadata);
        $sources = ! $canPublish ? [] : ($mediaMode === 'audio' ? ['MICROPHONE'] : ['CAMERA', 'MICROPHONE']);
        $response = self::roomServiceRequest('UpdateParticipant', [
            'room' => $room,
            'identity' => $identity,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'permission' => [
                'canSubscribe' => true,
                'canPublish' => $canPublish,
                'canPublishData' => false,
                'canPublishSources' => $sources,
                'hidden' => false,
                'recorder' => false,
                'canUpdateMetadata' => false,
            ],
        ], $canPublish ? 'promote-participant' : 'demote-participant', true);

        return $response !== null;
    }

    public static function sendParticipantEvent(string $room, array $payload, array $destinationIdentities): void
    {
        $destinations = collect($destinationIdentities)->map(fn ($identity): string => trim((string) $identity))->filter()->unique()->values()->all();
        $request = [
            'room' => $room,
            'data' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'kind' => 'RELIABLE',
            'topic' => 'playhub.participant-requests',
            'nonce' => base64_encode(random_bytes(16)),
        ];
        if ($destinations !== []) {
            $request['destinationIdentities'] = $destinations;
        }
        self::roomServiceRequest('SendData', $request, 'participant-event');
    }

    private static function roomServiceRequest(string $method, array $payload, string $subject, bool $allowNotFound = false): ?array
    {
        $settings = self::settings();
        if (! $settings['enabled'] || blank($settings['api_key']) || blank($settings['api_secret'])) {
            throw new RuntimeException('LiveKit credentials are unavailable.');
        }
        try {
            $response = Http::acceptJson()
                ->withToken(self::serviceToken($settings, ['roomAdmin' => true, 'room' => $payload['room']], 'playhub-'.$subject))
                ->connectTimeout(2)
                ->timeout(5)
                ->post('http://127.0.0.1:'.(int) $settings['signal_port'].'/twirp/livekit.RoomService/'.$method, $payload);
        } catch (\Throwable) {
            throw new RuntimeException('LiveKit did not respond to the participant control request.');
        }
        if ($allowNotFound && $response->status() === 404) {
            return null;
        }
        // An automatically closed room can leave ListParticipants routed to a
        // departed worker. A healthy, uncached ListRooms response must confirm
        // absence before treating that 503 as an empty room.
        if ($method === 'ListParticipants' && $allowNotFound && $response->status() === 503
            && self::roomIsConfirmedAbsent($settings, $payload['room'])) {
            return null;
        }
        if (! $response->successful()) {
            throw new RuntimeException('LiveKit rejected the participant control request (HTTP '.$response->status().').');
        }

        return (array) $response->json();
    }

    private static function roomIsConfirmedAbsent(array $settings, string $name): bool
    {
        try {
            $response = Http::acceptJson()
                ->withToken(self::serviceToken($settings, ['roomList' => true], 'playhub-live-room-existence'))
                ->connectTimeout(2)
                ->timeout(5)
                ->post('http://127.0.0.1:'.(int) $settings['signal_port'].'/twirp/livekit.RoomService/ListRooms', ['names' => [$name]]);
            $body = json_decode($response->body());
            if (! $response->successful() || ! is_object($body)) {
                return false;
            }
            // Protobuf JSON omits the rooms field for an empty list.
            if (! property_exists($body, 'rooms')) {
                return get_object_vars($body) === [];
            }
            $rooms = $body->rooms;
            if (! is_array($rooms)) {
                return false;
            }
            foreach ($rooms as $room) {
                if (! is_object($room) || ! is_string($room->name ?? null) || $room->name === '' || $room->name === $name) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            // An infrastructure outage is never evidence that a host left.
            return false;
        }
    }

    private static function ingressServiceRequest(string $method, array $payload, string $subject): array
    {
        $settings = self::settings();
        if (! $settings['enabled'] || blank($settings['api_key']) || blank($settings['api_secret'])) {
            throw new RuntimeException('LiveKit credentials are unavailable.');
        }
        try {
            $response = Http::acceptJson()
                ->withToken(self::serviceToken($settings, ['ingressAdmin' => true], 'playhub-'.$subject))
                ->connectTimeout(2)
                ->timeout(8)
                ->post('http://127.0.0.1:'.(int) $settings['signal_port'].'/twirp/livekit.Ingress/'.$method, $payload ?: new \stdClass);
        } catch (\Throwable) {
            throw new RuntimeException('LiveKit did not respond to the RTMP ingress request.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('LiveKit rejected the RTMP ingress request (HTTP '.$response->status().').');
        }

        return (array) $response->json();
    }

    private static function egressServiceRequest(string $method, array $payload, string $room, string $subject): array
    {
        $settings = self::settings();
        if (! $settings['enabled'] || blank($settings['api_key']) || blank($settings['api_secret'])) {
            throw new RuntimeException('LiveKit credentials are unavailable.');
        }
        try {
            $response = Http::acceptJson()
                ->withToken(self::serviceToken($settings, ['roomRecord' => true, 'room' => $room], 'playhub-'.$subject))
                ->connectTimeout(3)
                ->timeout(20)
                ->post('http://127.0.0.1:'.(int) $settings['signal_port'].'/twirp/livekit.Egress/'.$method, $payload ?: new \stdClass);
        } catch (\Throwable) {
            throw new RuntimeException('LiveKit did not respond to the recording request.');
        }
        if (! $response->successful()) {
            $message = trim((string) $response->json('msg', ''));
            throw new RuntimeException($message !== '' ? 'LiveKit recording failed: '.$message : 'LiveKit rejected the recording request (HTTP '.$response->status().').');
        }

        return (array) $response->json();
    }

    private static function ingressEncodingOptions(array $streamSettings): array
    {
        $quality = (string) ($streamSettings['quality'] ?? 'auto');
        $audioBitrate = max(96000, min(320000, ((int) ($streamSettings['audio_bitrate_kbps'] ?? 128)) * 1000));
        $audio = ['name' => 'audio', 'source' => 'MICROPHONE', 'options' => ['audioCodec' => 'OPUS', 'bitrate' => $audioBitrate, 'disableDtx' => false, 'channels' => 2]];
        if ($quality === '' || $quality === 'auto') {
            return ['audio' => $audio];
        }

        [$width, $height] = self::videoDimensions($quality);
        $frameRate = max(1, min(60, (int) ($streamSettings['frame_rate'] ?? 30)));
        $targetBitrate = max(250000, min(50000000, ((int) ($streamSettings['target_bitrate_kbps'] ?? 3000)) * 1000));
        $layers = [
            ['quality' => 'LOW', 'width' => self::evenDimension($width / 4), 'height' => self::evenDimension($height / 4), 'bitrate' => min($targetBitrate, max(150000, (int) round($targetBitrate * .18)))],
            ['quality' => 'MEDIUM', 'width' => self::evenDimension($width / 2), 'height' => self::evenDimension($height / 2), 'bitrate' => min($targetBitrate, max(400000, (int) round($targetBitrate * .45)))],
            ['quality' => 'HIGH', 'width' => $width, 'height' => $height, 'bitrate' => $targetBitrate],
        ];

        return [
            'video' => ['name' => 'video', 'source' => 'CAMERA', 'options' => ['videoCodec' => 'H264_BASELINE', 'frameRate' => $frameRate, 'layers' => $layers]],
            'audio' => $audio,
        ];
    }

    private static function evenDimension(float|int $value): int
    {
        $dimension = max(2, (int) round($value));

        return $dimension % 2 === 0 ? $dimension : $dimension - 1;
    }

    private static function videoDimensions(string $quality): array
    {
        return match ($quality) {
            '480p' => [854, 480],
            '1080p' => [1920, 1080],
            '1440p' => [2560, 1440],
            '2160p' => [3840, 2160],
            default => [1280, 720],
        };
    }

    private static function requestDockerApply(array $settings): void
    {
        self::writeFile(self::projectPath($settings['docker_apply_trigger_path']), now()->toIso8601String().'-'.Str::random(16)."\n", 0640);
    }

    private static function serviceToken(array $settings, array $videoGrant = ['roomList' => true], string $subject = 'playhub-admin-connection-test'): string
    {
        $now = time();
        $header = self::base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode([
            'iss' => $settings['api_key'],
            'sub' => $subject,
            'iat' => $now,
            'nbf' => $now - 5,
            'exp' => $now + 60,
            'jti' => (string) Str::uuid(),
            'video' => $videoGrant,
        ], JSON_THROW_ON_ERROR));

        return $header.'.'.$claims.'.'.self::base64Url(hash_hmac('sha256', $header.'.'.$claims, $settings['api_secret'], true));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function writeRuntimeFiles(array $settings, array $textFiles): void
    {
        self::ensureRuntimeDirectory();
        self::ensureRecordingDirectory();
        $liveKitConfig = $settings['livekit_config_mode'] === 'custom'
            ? self::normalizeTextFile($textFiles['livekit_config_yaml'] ?? self::readProjectFile($settings['livekit_config_path'], ''))
            : self::generatedLiveKitConfig($settings);
        $dockerCompose = $settings['docker_compose_mode'] === 'custom'
            ? self::normalizeTextFile($textFiles['docker_compose_yaml'] ?? self::readProjectFile($settings['docker_compose_path'], ''))
            : self::generatedDockerCompose($settings);
        $entrypoint = self::normalizeTextFile($textFiles['entrypoint_script'] ?? self::readProjectFile($settings['entrypoint_path'], self::defaultEntrypointScript()));

        if (! self::liveKitConfigYamlIsValid($liveKitConfig)) {
            throw new RuntimeException('The custom LiveKit YAML configuration is invalid.');
        }
        if (! self::dockerComposeYamlIsValid($dockerCompose)) {
            throw new RuntimeException('The Docker Compose YAML configuration is invalid.');
        }
        if (! self::entrypointScriptIsValid($entrypoint)) {
            throw new RuntimeException('The LiveKit Docker entrypoint script is invalid.');
        }

        self::writeFile(self::projectPath($settings['livekit_config_path']), $liveKitConfig, 0640);
        self::writeFile(self::projectPath($settings['ingress_config_path']), self::generatedIngressConfig($settings), 0640);
        self::writeFile(self::projectPath($settings['egress_config_path']), self::generatedEgressConfig($settings), 0640);
        self::writeFile(self::projectPath($settings['keys_path']), $settings['api_key'].': '.$settings['api_secret']."\n", 0640);
        self::writeFile(self::projectPath($settings['docker_compose_path']), $dockerCompose, 0640);
        self::writeFile(self::projectPath($settings['entrypoint_path']), $entrypoint, 0750);
        self::ensureProjectFile($settings['restart_trigger_path'], "initial\n", 0640);
        self::ensureProjectFile($settings['docker_apply_trigger_path'], "initial\n", 0640);
        self::ensureProjectFile($settings['status_path'], json_encode([
            'updated_at' => '',
            'container' => ['exists' => false, 'name' => $settings['container_name'], 'status' => 'missing', 'running' => false],
            'image' => [],
            'ingress_container' => ['exists' => false, 'name' => $settings['ingress_container_name'], 'status' => 'missing', 'running' => false],
            'ingress_image' => [],
            'egress_container' => ['exists' => false, 'name' => $settings['egress_container_name'], 'status' => 'missing', 'running' => false],
            'egress_image' => [],
        ], JSON_UNESCAPED_SLASHES)."\n", 0644);
        self::ensureProjectFile($settings['log_path'], '', 0640);
    }

    private static function generatedLiveKitConfig(array $settings): string
    {
        return self::dumpYaml(self::generatedLiveKitConfigValues($settings));
    }

    private static function generatedLiveKitConfigValues(array $settings): array
    {
        $turnTlsDomain = $settings['turn_tls_domain'] !== '' ? $settings['turn_tls_domain'] : $settings['ssl_domain'];
        $turnTlsCertPath = $settings['turn_tls_cert_path'] !== '' ? $settings['turn_tls_cert_path'] : $settings['ssl_cert_path'];
        $turnTlsKeyPath = $settings['turn_tls_key_path'] !== '' ? $settings['turn_tls_key_path'] : $settings['ssl_key_path'];
        $core = [
            'port' => $settings['signal_port'],
            'logging' => ['level' => $settings['log_level']],
            'rtc' => [
                'tcp_port' => $settings['rtc_tcp_port'],
                'port_range_start' => $settings['rtc_udp_port_start'],
                'port_range_end' => $settings['rtc_udp_port_end'],
                'use_external_ip' => $settings['use_external_ip'],
            ],
            'room' => [
                'auto_create' => true,
                'empty_timeout' => $settings['room_empty_timeout'],
                'departure_timeout' => $settings['room_departure_timeout'],
                'max_participants' => $settings['max_participants'],
            ],
            'turn' => [
                'enabled' => true,
            ],
            'redis' => self::redisConfig($settings),
        ];
        if ($settings['rtmp_url'] !== '') {
            $core['ingress'] = ['rtmp_base_url' => $settings['rtmp_url']];
        }
        if ($settings['region'] !== '') {
            $core['region'] = $settings['region'];
        }
        if ($settings['node_ip'] !== '') {
            $core['rtc']['node_ip'] = $settings['node_ip'];
        }
        if ($settings['turn_tls_enabled']) {
            $core['turn'] += [
                'tls_port' => $settings['turn_tls_port'],
                'domain' => $turnTlsDomain,
                'cert_file' => self::containerCertificatePath($settings, $turnTlsCertPath),
                'key_file' => self::containerCertificatePath($settings, $turnTlsKeyPath),
            ];
        } else {
            $core['turn']['udp_port'] = $settings['turn_udp_port'];
        }

        return array_replace_recursive(self::advancedConfig($settings), $core);
    }

    private static function generatedIngressConfig(array $settings): string
    {
        return self::dumpYaml([
            'api_key' => $settings['api_key'],
            'api_secret' => $settings['api_secret'],
            'ws_url' => 'ws://livekit:'.(int) $settings['signal_port'],
            'redis' => self::redisConfig($settings),
            'rtmp_port' => (int) $settings['rtmp_port'],
            'log_level' => $settings['log_level'],
        ]);
    }

    private static function generatedEgressConfig(array $settings): string
    {
        return self::dumpYaml([
            'api_key' => $settings['api_key'],
            'api_secret' => $settings['api_secret'],
            'ws_url' => 'ws://livekit:'.(int) $settings['signal_port'],
            'insecure' => true,
            'redis' => self::redisConfig($settings),
            'health_port' => 7981,
            'logging' => ['level' => $settings['log_level']],
            'backup_storage' => '/out',
        ]);
    }

    private static function redisConfig(array $settings): array
    {
        // These files run inside bridge-network containers; loopback refers to
        // the container, while the existing Redis service runs on the host.
        $host = $settings['redis_host'];
        $loopbackIpv4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($host, '127.');
        if (in_array(strtolower($host), ['localhost', '::1'], true) || $loopbackIpv4) {
            $host = 'host.docker.internal';
        } elseif (str_contains($host, ':')) {
            $host = '['.$host.']';
        }

        return array_filter([
            'address' => $host.':'.$settings['redis_port'],
            // Password-only AUTH works with Redis 5 and still selects the default
            // user on Redis 6+. Named ACL users retain their configured username.
            'username' => $settings['redis_username'] === 'default' ? '' : $settings['redis_username'],
            'password' => $settings['redis_password'],
            'db' => (int) $settings['redis_database'],
        ], fn ($value): bool => $value !== '' && $value !== null);
    }

    private static function normalize(array $settings): array
    {
        $settings['enabled'] = (bool) ($settings['enabled'] ?? true);
        $settings['url'] = trim((string) ($settings['url'] ?? ''));
        if (str_starts_with($settings['url'], 'https://')) {
            $settings['url'] = 'wss://'.substr($settings['url'], 8);
        }
        if (str_starts_with($settings['url'], 'http://')) {
            $settings['url'] = 'ws://'.substr($settings['url'], 7);
        }
        $settings['api_key'] = trim((string) ($settings['api_key'] ?? ''));
        $settings['api_secret'] = trim((string) ($settings['api_secret'] ?? ''));
        $settings['ingress_image'] = trim((string) ($settings['ingress_image'] ?? self::INGRESS_DOCKER_IMAGE));
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/:@-]{0,254}$#', $settings['ingress_image'])) {
            $settings['ingress_image'] = self::INGRESS_DOCKER_IMAGE;
        }
        $settings['ingress_container_name'] = trim((string) ($settings['ingress_container_name'] ?? self::INGRESS_CONTAINER_NAME));
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $settings['ingress_container_name'])) {
            $settings['ingress_container_name'] = self::INGRESS_CONTAINER_NAME;
        }
        $settings['egress_image'] = trim((string) ($settings['egress_image'] ?? self::EGRESS_DOCKER_IMAGE));
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/:@-]{0,254}$#', $settings['egress_image'])) {
            $settings['egress_image'] = self::EGRESS_DOCKER_IMAGE;
        }
        $settings['egress_container_name'] = trim((string) ($settings['egress_container_name'] ?? self::EGRESS_CONTAINER_NAME));
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $settings['egress_container_name'])) {
            $settings['egress_container_name'] = self::EGRESS_CONTAINER_NAME;
        }
        $settings['rtmp_url'] = rtrim(trim((string) ($settings['rtmp_url'] ?? '')), '/');
        $rtmpParts = $settings['rtmp_url'] !== '' ? parse_url($settings['rtmp_url']) : [];
        if (! is_array($rtmpParts) || ! in_array($rtmpParts['scheme'] ?? '', ['rtmp', 'rtmps'], true) || blank($rtmpParts['host'] ?? null)) {
            $settings['rtmp_url'] = '';
        }
        $settings['rtmp_port'] = max(1024, min(65535, (int) ($settings['rtmp_port'] ?? 1936)));
        $settings['redis_host'] = trim((string) ($settings['redis_host'] ?? config('playhub.livekit.redis.host', '127.0.0.1')));
        if (! preg_match('/^[A-Za-z0-9._:\-]+$/', $settings['redis_host'])) {
            $settings['redis_host'] = '127.0.0.1';
        }
        $settings['redis_port'] = max(1, min(65535, (int) ($settings['redis_port'] ?? config('playhub.livekit.redis.port', 6379))));
        $settings['redis_username'] = trim((string) ($settings['redis_username'] ?? config('playhub.livekit.redis.username', 'default')));
        if (strlen($settings['redis_username']) > 128 || preg_match('/[\x00-\x1F\x7F]/', $settings['redis_username'])) {
            $settings['redis_username'] = 'default';
        }
        $settings['redis_password'] = (string) ($settings['redis_password'] ?? '');
        $settings['redis_database'] = max(0, min(255, (int) ($settings['redis_database'] ?? config('playhub.livekit.redis.database', 2))));
        $settings['docker_image'] = trim((string) ($settings['docker_image'] ?? self::DOCKER_IMAGE));
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/:@-]{0,254}$#', $settings['docker_image'])) {
            $settings['docker_image'] = self::DOCKER_IMAGE;
        }
        $settings['container_name'] = trim((string) ($settings['container_name'] ?? self::CONTAINER_NAME));
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $settings['container_name'])) {
            $settings['container_name'] = self::CONTAINER_NAME;
        }
        $settings['docker_project_name'] = strtolower(trim((string) ($settings['docker_project_name'] ?? 'playhub-livekit')));
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $settings['docker_project_name'])) {
            $settings['docker_project_name'] = 'playhub-livekit';
        }
        $settings['docker_compose_mode'] = ($settings['docker_compose_mode'] ?? 'generated') === 'custom' ? 'custom' : 'generated';
        $settings['livekit_config_mode'] = ($settings['livekit_config_mode'] ?? 'generated') === 'custom' ? 'custom' : 'generated';
        $settings['docker_compose_path'] = self::normalizeProjectPath($settings['docker_compose_path'] ?? 'livekit/runtime/docker-compose.yml', 'livekit/runtime/docker-compose.yml');
        $settings['livekit_config_path'] = self::normalizeProjectPath($settings['livekit_config_path'] ?? 'livekit/runtime/livekit.yaml', 'livekit/runtime/livekit.yaml');
        $settings['ingress_config_path'] = self::normalizeProjectPath($settings['ingress_config_path'] ?? 'livekit/runtime/ingress.yaml', 'livekit/runtime/ingress.yaml');
        $settings['egress_config_path'] = self::normalizeProjectPath($settings['egress_config_path'] ?? 'livekit/runtime/egress.yaml', 'livekit/runtime/egress.yaml');
        $settings['keys_path'] = self::normalizeProjectPath($settings['keys_path'] ?? 'livekit/runtime/keys.yaml', 'livekit/runtime/keys.yaml');
        $settings['restart_trigger_path'] = self::normalizeProjectPath($settings['restart_trigger_path'] ?? 'livekit/runtime/restart.trigger', 'livekit/runtime/restart.trigger');
        $settings['docker_apply_trigger_path'] = self::normalizeProjectPath($settings['docker_apply_trigger_path'] ?? 'livekit/runtime/docker-apply.trigger', 'livekit/runtime/docker-apply.trigger');
        $settings['docker_command_path'] = self::normalizeProjectPath($settings['docker_command_path'] ?? 'livekit/runtime/docker-command.trigger', 'livekit/runtime/docker-command.trigger');
        $settings['entrypoint_path'] = self::normalizeProjectPath($settings['entrypoint_path'] ?? 'livekit/runtime/entrypoint.sh', 'livekit/runtime/entrypoint.sh');
        $settings['status_path'] = self::normalizeProjectPath($settings['status_path'] ?? 'livekit/runtime/status/docker-status.json', 'livekit/runtime/status/docker-status.json');
        $settings['log_path'] = self::normalizeProjectPath($settings['log_path'] ?? 'livekit/runtime/logs/livekit.log', 'livekit/runtime/logs/livekit.log');
        $settings['container_livekit_config_path'] = self::normalizeContainerPath($settings['container_livekit_config_path'] ?? '/playhub/livekit/runtime/livekit.yaml', '/playhub/livekit/runtime/livekit.yaml');
        $settings['container_ingress_config_path'] = self::normalizeContainerPath($settings['container_ingress_config_path'] ?? '/playhub/livekit/runtime/ingress.yaml', '/playhub/livekit/runtime/ingress.yaml');
        $settings['container_egress_config_path'] = self::normalizeContainerPath($settings['container_egress_config_path'] ?? '/playhub/livekit/runtime/egress.yaml', '/playhub/livekit/runtime/egress.yaml');
        $settings['container_keys_path'] = self::normalizeContainerPath($settings['container_keys_path'] ?? '/playhub/livekit/runtime/keys.yaml', '/playhub/livekit/runtime/keys.yaml');
        $settings['container_restart_trigger_path'] = self::normalizeContainerPath($settings['container_restart_trigger_path'] ?? '/playhub/livekit/runtime/restart.trigger', '/playhub/livekit/runtime/restart.trigger');
        $settings['container_entrypoint_path'] = self::normalizeContainerPath($settings['container_entrypoint_path'] ?? '/playhub/livekit/entrypoint.sh', '/playhub/livekit/entrypoint.sh');
        $settings['container_status_path'] = self::normalizeContainerPath($settings['container_status_path'] ?? '/playhub/livekit/status/docker-status.json', '/playhub/livekit/status/docker-status.json');
        $settings['container_log_path'] = self::normalizeContainerPath($settings['container_log_path'] ?? '/playhub/livekit/logs/livekit.log', '/playhub/livekit/logs/livekit.log');
        $settings['container_certificate_directory'] = self::normalizeContainerPath($settings['container_certificate_directory'] ?? '/playhub/livekit/certificates', '/playhub/livekit/certificates');
        $settings['signal_port'] = max(1024, min(65535, (int) ($settings['signal_port'] ?? 7880)));
        $settings['rtc_tcp_port'] = max(1024, min(65535, (int) ($settings['rtc_tcp_port'] ?? 7881)));
        $settings['rtc_udp_port_start'] = max(1024, min(65535, (int) ($settings['rtc_udp_port_start'] ?? 50000)));
        $settings['rtc_udp_port_end'] = max($settings['rtc_udp_port_start'], min(65535, (int) ($settings['rtc_udp_port_end'] ?? 60000)));
        $settings['use_external_ip'] = (bool) ($settings['use_external_ip'] ?? true);
        $settings['node_ip'] = trim((string) ($settings['node_ip'] ?? ''));
        $settings['region'] = trim((string) ($settings['region'] ?? ''));
        $settings['log_level'] = in_array($settings['log_level'] ?? 'info', ['debug', 'info', 'warn', 'error'], true) ? $settings['log_level'] : 'info';
        $settings['room_empty_timeout'] = max(10, min(86400, (int) ($settings['room_empty_timeout'] ?? 300)));
        $settings['room_departure_timeout'] = max(5, min(3600, (int) ($settings['room_departure_timeout'] ?? 20)));
        $settings['max_participants'] = max(0, min(10000, (int) ($settings['max_participants'] ?? 0)));
        $settings['certificate_directory'] = self::normalizeCertificateDirectory($settings['certificate_directory'] ?? 'livekit/runtime/certs');
        $settings['ssl_enabled'] = (bool) ($settings['ssl_enabled'] ?? false);
        $settings['ssl_domain'] = trim((string) ($settings['ssl_domain'] ?? ($settings['turn_tls_domain'] ?? '')));
        $settings['ssl_cert_path'] = self::normalizeCertificatePath($settings['ssl_cert_path'] ?? ($settings['turn_tls_cert_path'] ?? ''));
        $settings['ssl_key_path'] = self::normalizeCertificatePath($settings['ssl_key_path'] ?? ($settings['turn_tls_key_path'] ?? ''));
        $settings['turn_udp_port'] = max(1024, min(65535, (int) ($settings['turn_udp_port'] ?? 3478)));
        $settings['turn_tls_enabled'] = (bool) ($settings['turn_tls_enabled'] ?? false);
        $settings['turn_tls_port'] = max(1024, min(65535, (int) ($settings['turn_tls_port'] ?? 5349)));
        $settings['turn_tls_domain'] = trim((string) ($settings['turn_tls_domain'] ?? ''));
        $settings['turn_tls_cert_path'] = self::normalizeCertificatePath($settings['turn_tls_cert_path'] ?? '');
        $settings['turn_tls_key_path'] = self::normalizeCertificatePath($settings['turn_tls_key_path'] ?? '');
        $settings['ice_server_config_json'] = self::normalizeIceServerConfigJson($settings['ice_server_config_json'] ?? self::defaultIceServerConfigJson());
        $settings['advanced_config_json'] = self::normalizeAdvancedConfigJson($settings['advanced_config_json'] ?? self::defaultAdvancedConfigJson());

        return array_intersect_key($settings, array_flip(self::persistentKeys()));
    }

    private static function defaults(): array
    {
        return [
            'enabled' => true,
            'url' => (string) config('playhub.livekit.url', rtrim((string) config('app.url'), '/').'/livekit'),
            'api_key' => app()->environment('testing') ? '' : (string) config('playhub.livekit.api_key', ''),
            'api_secret' => app()->environment('testing') ? '' : (string) config('playhub.livekit.api_secret', ''),
            'ingress_image' => (string) config('playhub.livekit.ingress_image', self::INGRESS_DOCKER_IMAGE),
            'ingress_container_name' => self::INGRESS_CONTAINER_NAME,
            'egress_image' => self::EGRESS_DOCKER_IMAGE,
            'egress_container_name' => self::EGRESS_CONTAINER_NAME,
            'rtmp_url' => (string) config('playhub.livekit.rtmp_url', ''),
            'rtmp_port' => (int) config('playhub.livekit.rtmp_port', 1936),
            'redis_host' => (string) config('playhub.livekit.redis.host', '127.0.0.1'),
            'redis_port' => (int) config('playhub.livekit.redis.port', 6379),
            'redis_username' => (string) config('playhub.livekit.redis.username', 'default'),
            'redis_password' => (string) config('playhub.livekit.redis.password', ''),
            'redis_database' => (int) config('playhub.livekit.redis.database', 2),
            'docker_image' => self::DOCKER_IMAGE,
            'container_name' => self::CONTAINER_NAME,
            'docker_project_name' => 'playhub-livekit',
            'docker_compose_mode' => 'generated',
            'livekit_config_mode' => 'generated',
            'docker_compose_path' => 'livekit/runtime/docker-compose.yml',
            'livekit_config_path' => 'livekit/runtime/livekit.yaml',
            'ingress_config_path' => 'livekit/runtime/ingress.yaml',
            'egress_config_path' => 'livekit/runtime/egress.yaml',
            'keys_path' => 'livekit/runtime/keys.yaml',
            'restart_trigger_path' => 'livekit/runtime/restart.trigger',
            'docker_apply_trigger_path' => 'livekit/runtime/docker-apply.trigger',
            'docker_command_path' => 'livekit/runtime/docker-command.trigger',
            'entrypoint_path' => 'livekit/runtime/entrypoint.sh',
            'status_path' => 'livekit/runtime/status/docker-status.json',
            'log_path' => 'livekit/runtime/logs/livekit.log',
            'container_livekit_config_path' => '/playhub/livekit/runtime/livekit.yaml',
            'container_ingress_config_path' => '/playhub/livekit/runtime/ingress.yaml',
            'container_egress_config_path' => '/playhub/livekit/runtime/egress.yaml',
            'container_keys_path' => '/playhub/livekit/runtime/keys.yaml',
            'container_restart_trigger_path' => '/playhub/livekit/runtime/restart.trigger',
            'container_entrypoint_path' => '/playhub/livekit/entrypoint.sh',
            'container_status_path' => '/playhub/livekit/status/docker-status.json',
            'container_log_path' => '/playhub/livekit/logs/livekit.log',
            'container_certificate_directory' => '/playhub/livekit/certificates',
            'signal_port' => 7880,
            'rtc_tcp_port' => 7881,
            'rtc_udp_port_start' => 50000,
            'rtc_udp_port_end' => 60000,
            'use_external_ip' => true,
            'node_ip' => '',
            'region' => '',
            'log_level' => 'info',
            'room_empty_timeout' => 300,
            'room_departure_timeout' => 20,
            'max_participants' => 0,
            'certificate_directory' => 'livekit/runtime/certs',
            'ssl_enabled' => false,
            'ssl_domain' => '',
            'ssl_cert_path' => '',
            'ssl_key_path' => '',
            'turn_udp_port' => 3478,
            'turn_tls_enabled' => false,
            'turn_tls_port' => 5349,
            'turn_tls_domain' => '',
            'turn_tls_cert_path' => '',
            'turn_tls_key_path' => '',
            'ice_server_config_json' => self::defaultIceServerConfigJson(),
            'advanced_config_json' => self::defaultAdvancedConfigJson(),
        ];
    }

    private static function persistentKeys(): array
    {
        return array_keys(self::defaults());
    }

    public static function projectPathIsValid(string $path): bool
    {
        try {
            self::normalizeProjectPath($path, '');

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function containerPathIsValid(string $path): bool
    {
        try {
            self::normalizeContainerPath($path, '');

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function liveKitConfigYamlIsValid(string $yaml): bool
    {
        return self::textFileIsValid($yaml, 200000) && (bool) preg_match('/^\s*port\s*:/m', $yaml);
    }

    public static function dockerComposeYamlIsValid(string $yaml): bool
    {
        return self::textFileIsValid($yaml, 200000)
            && (bool) preg_match('/^\s*services\s*:/m', $yaml)
            && (bool) preg_match('/^[ \t]+livekit\s*:/m', $yaml)
            && (bool) preg_match('/^[ \t]+ingress\s*:/m', $yaml)
            && (bool) preg_match('/^[ \t]+egress\s*:/m', $yaml);
    }

    public static function entrypointScriptIsValid(string $script): bool
    {
        return self::textFileIsValid($script, 100000)
            && str_starts_with(ltrim($script), '#!')
            && str_contains($script, 'livekit-server');
    }

    public static function certificatePathMatchesDirectory(string $path, array $settings): bool
    {
        if (! self::certificatePathIsValid($path)) {
            return false;
        }
        $path = self::normalizeCertificatePath($path);
        if ($path === '' || ! self::isAbsolutePath($path)) {
            return true;
        }
        $settings = self::normalize(array_replace(self::defaults(), $settings));

        return self::pathIsInside($path, self::hostCertificateDirectory($settings));
    }

    private static function generatedDockerCompose(array $settings): string
    {
        $runtimeGroupId = @filegroup(dirname(self::settingsPath()));
        if ($runtimeGroupId === false) {
            $runtimeGroupId = @filegroup(storage_path()) ?: 0;
        }
        $mounts = [
            [self::projectPath($settings['livekit_config_path']), $settings['container_livekit_config_path'], true],
            [self::projectPath($settings['keys_path']), $settings['container_keys_path'], true],
            [self::projectPath($settings['restart_trigger_path']), $settings['container_restart_trigger_path'], true],
            [self::projectPath($settings['entrypoint_path']), $settings['container_entrypoint_path'], true],
            [self::hostCertificateDirectory($settings), $settings['container_certificate_directory'], true],
            [dirname(self::projectPath($settings['status_path'])), dirname($settings['container_status_path']), false],
            [dirname(self::projectPath($settings['log_path'])), dirname($settings['container_log_path']), false],
        ];
        $yaml = 'name: '.self::yamlScalar($settings['docker_project_name'])."\n\nservices:\n  livekit:\n";
        $yaml .= '    image: '.self::yamlScalar($settings['docker_image'])."\n";
        $yaml .= '    container_name: '.self::yamlScalar($settings['container_name'])."\n";
        $yaml .= "    user: \"0:0\"\n";
        $yaml .= '    entrypoint: ["/bin/sh", '.self::yamlScalar($settings['container_entrypoint_path'])."]\n";
        $yaml .= self::generatedBridgeNetworking();
        $yaml .= "    ports:\n";
        $yaml .= '      - '.self::yamlScalar('127.0.0.1:'.$settings['signal_port'].':'.$settings['signal_port'].'/tcp')."\n";
        $yaml .= '      - '.self::yamlScalar($settings['rtc_tcp_port'].':'.$settings['rtc_tcp_port'].'/tcp')."\n";
        $config = self::generatedLiveKitConfigValues($settings);
        $udpRanges = [];
        $rtcMuxPort = (int) data_get($config, 'rtc.udp_port', 0);
        $rtcRange = $rtcMuxPort > 0 ? [$rtcMuxPort, $rtcMuxPort]
            : [(int) $settings['rtc_udp_port_start'], (int) $settings['rtc_udp_port_end']];
        $yaml .= self::publishedUdpRange($rtcRange, $udpRanges);
        if ((bool) data_get($config, 'turn.enabled', false)) {
            $turnTlsPort = (int) data_get($config, 'turn.tls_port', 0);
            $turnUdpPort = (int) data_get($config, 'turn.udp_port', 0);
            if ($turnTlsPort > 0) {
                $yaml .= '      - '.self::yamlScalar($turnTlsPort.':'.$turnTlsPort.'/tcp')."\n";
            }
            if ($turnUdpPort > 0) {
                $yaml .= self::publishedUdpRange([$turnUdpPort, $turnUdpPort], $udpRanges);
            }
            if ($turnTlsPort > 0 || $turnUdpPort > 0) {
                $relayStart = (int) data_get($config, 'turn.relay_range_start', 0);
                $relayEnd = (int) data_get($config, 'turn.relay_range_end', 0);
                if ($relayStart === 0 || $relayEnd === 0) {
                    // Match the pinned LiveKit server's unset/zero range defaults.
                    $relayStart = 30000;
                    $relayEnd = (bool) ($config['development'] ?? false) ? 30002 : 40000;
                }
                $yaml .= self::publishedUdpRange([$relayStart, $relayEnd], $udpRanges);
            }
        }
        $yaml .= "    restart: unless-stopped\n    read_only: true\n";
        $yaml .= "    security_opt:\n      - no-new-privileges:true\n    cap_drop:\n      - ALL\n    cap_add:\n      - DAC_OVERRIDE\n      - DAC_READ_SEARCH\n";
        $yaml .= "    tmpfs:\n      - /tmp:size=64m,mode=1777\n";
        $yaml .= "    environment:\n";
        foreach ([
            'LIVEKIT_CONFIG_FILE' => $settings['container_livekit_config_path'],
            'LIVEKIT_KEYS_FILE' => $settings['container_keys_path'],
            'LIVEKIT_RESTART_TRIGGER' => $settings['container_restart_trigger_path'],
            'LIVEKIT_STATUS_FILE' => $settings['container_status_path'],
            'LIVEKIT_LOG_FILE' => $settings['container_log_path'],
            'LIVEKIT_CONTAINER_NAME' => $settings['container_name'],
            'LIVEKIT_DOCKER_IMAGE' => $settings['docker_image'],
        ] as $name => $value) {
            $yaml .= '      '.$name.': '.self::yamlScalar($value)."\n";
        }
        $yaml .= "    volumes:\n";
        foreach ($mounts as [$source, $target, $readOnly]) {
            $yaml .= "      - type: bind\n        source: ".self::yamlScalar($source)."\n        target: ".self::yamlScalar($target)."\n";
            if ($readOnly) {
                $yaml .= "        read_only: true\n";
            }
        }
        $yaml .= "    ulimits:\n      nofile:\n        soft: 65535\n        hard: 65535\n";
        $yaml .= "\n  ingress:\n";
        $yaml .= '    image: '.self::yamlScalar($settings['ingress_image'])."\n";
        $yaml .= '    container_name: '.self::yamlScalar($settings['ingress_container_name'])."\n";
        $yaml .= "    depends_on:\n      - livekit\n";
        $yaml .= self::generatedBridgeNetworking();
        $yaml .= '    ports:'."\n      - ".self::yamlScalar($settings['rtmp_port'].':'.$settings['rtmp_port'].'/tcp')."\n";
        $yaml .= "    restart: unless-stopped\n";
        $yaml .= "    security_opt:\n      - no-new-privileges:true\n";
        $yaml .= "    tmpfs:\n      - /tmp:size=512m,mode=1777\n";
        $yaml .= "    environment:\n";
        $yaml .= '      INGRESS_CONFIG_FILE: '.self::yamlScalar($settings['container_ingress_config_path'])."\n";
        $yaml .= "      XDG_CACHE_HOME: /tmp/.cache\n";
        $yaml .= '    command: ["--config", '.self::yamlScalar($settings['container_ingress_config_path'])."]\n";
        $yaml .= "    volumes:\n      - type: bind\n        source: ".self::yamlScalar(self::projectPath($settings['ingress_config_path']))."\n        target: ".self::yamlScalar($settings['container_ingress_config_path'])."\n        read_only: true\n";
        $yaml .= "    ulimits:\n      nofile:\n        soft: 65535\n        hard: 65535\n";
        $yaml .= "\n  egress:\n";
        $yaml .= '    image: '.self::yamlScalar($settings['egress_image'])."\n";
        $yaml .= '    container_name: '.self::yamlScalar($settings['egress_container_name'])."\n";
        $yaml .= '    group_add:'."\n      - ".self::yamlScalar((string) $runtimeGroupId)."\n";
        $yaml .= "    depends_on:\n      - livekit\n";
        $yaml .= self::generatedBridgeNetworking();
        $yaml .= "    ports:\n      - \"127.0.0.1:7981:7981/tcp\"\n";
        $yaml .= "    restart: unless-stopped\n    shm_size: 1gb\n";
        $yaml .= "    cap_add:\n      - SYS_ADMIN\n";
        $yaml .= "    environment:\n";
        $yaml .= '      EGRESS_CONFIG_FILE: '.self::yamlScalar($settings['container_egress_config_path'])."\n";
        $yaml .= "      XDG_CACHE_HOME: /tmp/.cache\n";
        $yaml .= "    volumes:\n      - type: bind\n        source: ".self::yamlScalar(self::projectPath($settings['egress_config_path']))."\n        target: ".self::yamlScalar($settings['container_egress_config_path'])."\n        read_only: true\n";
        $yaml .= "      - type: bind\n        source: ".self::yamlScalar(self::recordingDirectory())."\n        target: /out\n";
        $yaml .= "    ulimits:\n      nofile:\n        soft: 65535\n        hard: 65535\n";

        $yaml .= "\nnetworks:\n  livekit:\n    driver: bridge\n";

        return $yaml;
    }

    private static function generatedBridgeNetworking(): string
    {
        return "    networks:\n      - livekit\n    extra_hosts:\n      - \"host.docker.internal:host-gateway\"\n";
    }

    /** Publish each configured UDP socket once, including TURN relay allocations. */
    private static function publishedUdpRange(array $range, array &$published): string
    {
        [$start, $end] = $range;
        if ($start < 1 || $end > 65535 || $start > $end) {
            throw new RuntimeException('The configured LiveKit UDP port range is invalid.');
        }
        $segments = [$range];
        foreach ($published as [$existingStart, $existingEnd]) {
            $remaining = [];
            foreach ($segments as [$segmentStart, $segmentEnd]) {
                if ($segmentEnd < $existingStart || $segmentStart > $existingEnd) {
                    $remaining[] = [$segmentStart, $segmentEnd];

                    continue;
                }
                if ($segmentStart < $existingStart) {
                    $remaining[] = [$segmentStart, $existingStart - 1];
                }
                if ($segmentEnd > $existingEnd) {
                    $remaining[] = [$existingEnd + 1, $segmentEnd];
                }
            }
            $segments = $remaining;
        }
        $yaml = '';
        foreach ($segments as [$segmentStart, $segmentEnd]) {
            $ports = $segmentStart === $segmentEnd ? (string) $segmentStart : $segmentStart.'-'.$segmentEnd;
            $yaml .= '      - '.self::yamlScalar($ports.':'.$ports.'/udp')."\n";
            $published[] = [$segmentStart, $segmentEnd];
        }

        return $yaml;
    }

    private static function recordingDirectory(): string
    {
        return base_path('uploads/live-streams');
    }

    private static function ensureRecordingDirectory(): void
    {
        MediaStorage::ensureLocalDirectory('live-streams');
    }

    private static function defaultEntrypointScript(): string
    {
        $path = base_path('livekit/entrypoint.sh');
        $contents = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

        return $contents === false ? "#!/bin/sh\nset -eu\nexec /livekit-server --config \"\${LIVEKIT_CONFIG_FILE}\" --key-file \"\${LIVEKIT_KEYS_FILE}\"\n" : self::normalizeTextFile($contents);
    }

    private static function normalizeProjectPath(mixed $path, string $default): string
    {
        $path = trim(str_replace('\\', '/', (string) $path));
        if ($path === '') {
            $path = $default;
        }
        if ($path === '' || str_ends_with($path, '/') || str_starts_with($path, '/') || ! self::relativePathIsValid($path) || ! str_starts_with($path, 'livekit/runtime/')) {
            throw new RuntimeException('LiveKit host configuration paths must be relative paths inside the writable PlayHub livekit/runtime directory.');
        }

        return $path;
    }

    private static function normalizeContainerPath(mixed $path, string $default): string
    {
        $path = rtrim(trim(str_replace('\\', '/', (string) $path)), '/');
        if ($path === '') {
            $path = $default;
        }
        if ($path === '' || ! self::absolutePathIsValid($path)) {
            throw new RuntimeException('LiveKit container paths must be safe absolute paths.');
        }

        return $path;
    }

    private static function projectPath(string $path): string
    {
        $path = self::normalizeProjectPath($path, '');
        if (app()->environment('testing')) {
            return storage_path('livekit/runtime/testing/'.getmypid().'/'.substr($path, strlen('livekit/runtime/')));
        }

        return base_path($path);
    }

    private static function readProjectFile(string $path, string $fallback): string
    {
        $absolute = self::projectPath($path);
        if (! is_file($absolute) || ! is_readable($absolute) || filesize($absolute) > 200000) {
            return $fallback;
        }
        $contents = @file_get_contents($absolute);

        return $contents === false ? $fallback : self::normalizeTextFile($contents);
    }

    private static function normalizeTextFile(mixed $contents): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", (string) $contents);

        return $contents === '' ? '' : rtrim($contents, "\n")."\n";
    }

    private static function redactSecret(mixed $value, string $secret): mixed
    {
        if ($secret === '') {
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::redactSecret($item, $secret);
            }

            return $value;
        }
        if (! is_string($value)) {
            return $value;
        }

        $json = json_encode($secret, JSON_UNESCAPED_SLASHES);
        $variants = array_filter(array_unique([
            $secret,
            is_string($json) ? substr($json, 1, -1) : '',
            rawurlencode($secret),
            urlencode($secret),
            base64_encode($secret),
        ]));
        usort($variants, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return str_replace($variants, self::REDACTED_SECRET, $value);
    }

    private static function restoreRedactedSecret(string $value, string $secret): string
    {
        if ($secret === '' || ! str_contains($value, self::REDACTED_SECRET)) {
            return $value;
        }
        $json = json_encode($secret, JSON_UNESCAPED_SLASHES);
        $replacement = is_string($json) ? substr($json, 1, -1) : $secret;

        return str_replace(self::REDACTED_SECRET, $replacement, $value);
    }

    private static function textFileIsValid(string $contents, int $maxLength): bool
    {
        return $contents !== '' && strlen($contents) <= $maxLength && ! str_contains($contents, "\0");
    }

    private static function tailFile(string $path, int $maxBytes = 131072): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }
        $size = max(0, (int) filesize($path));
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return '';
        }
        $offset = max(0, $size - $maxBytes);
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $contents = (string) stream_get_contents($handle);
        fclose($handle);
        if ($offset > 0 && ($newline = strpos($contents, "\n")) !== false) {
            $contents = substr($contents, $newline + 1);
        }

        return $contents;
    }

    public static function advancedConfigJsonIsValid(string $json): bool
    {
        if (trim($json) === '') {
            return true;
        }
        try {
            self::advancedConfigFromJson($json);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function iceServerConfigJsonIsValid(string $json): bool
    {
        if (trim($json) === '') {
            return true;
        }
        try {
            self::iceServerConfigFromJson($json);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function clientRtcConfig(?array $settings = null): array
    {
        $settings = $settings === null ? self::settings() : self::normalize(array_replace(self::defaults(), $settings));
        $rtc = self::iceServerConfigFromJson($settings['ice_server_config_json'])['icesConfig'];
        $clientConfig = [];
        $iceServers = $rtc['iceServers'];
        $hasPlaceholder = false;
        foreach ($iceServers as $iceServer) {
            $urls = is_string($iceServer['urls']) ? [$iceServer['urls']] : $iceServer['urls'];
            foreach ($urls as $url) {
                $hasPlaceholder = $hasPlaceholder || self::iceServerUrlHasPlaceholder($url);
            }
        }
        if ($iceServers !== [] && ! $hasPlaceholder) {
            $clientConfig['iceServers'] = $iceServers;
        }
        foreach (['iceTransportPolicy', 'bundlePolicy', 'rtcpMuxPolicy', 'iceCandidatePoolSize'] as $key) {
            if (array_key_exists($key, $rtc)) {
                $clientConfig[$key] = $rtc[$key];
            }
        }

        return $clientConfig;
    }

    public static function certificateFileExists(string $path, ?array $settings = null): bool
    {
        if (! self::certificatePathIsValid($path)) {
            return false;
        }
        $settings = $settings === null ? self::settings() : self::normalize(array_replace(self::defaults(), $settings));
        if (! self::certificatePathMatchesDirectory($path, $settings)) {
            return false;
        }
        $path = self::normalizeCertificatePath($path);

        if ($path === '') {
            return false;
        }
        if (self::isAbsolutePath($settings['certificate_directory'])) {
            return true;
        }

        $hostPath = self::hostCertificatePath($settings, $path);
        if (is_file($hostPath) && is_readable($hostPath)) {
            return true;
        }

        return false;
    }

    public static function certificateDirectoryIsValid(string $path): bool
    {
        try {
            self::normalizeCertificateDirectory($path);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function certificatePathIsValid(string $path): bool
    {
        $path = trim($path);

        return $path === '' || (self::isAbsolutePath($path) ? self::absolutePathIsValid($path) : self::relativePathIsValid($path));
    }

    private static function normalizeCertificatePath(mixed $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        if (! self::certificatePathIsValid($path)) {
            throw new RuntimeException('LiveKit certificate file paths must be relative to the configured certificate directory or absolute server paths.');
        }

        return self::isAbsolutePath($path) ? rtrim(str_replace('\\', '/', $path), '/') : $path;
    }

    private static function normalizeCertificateDirectory(mixed $path): string
    {
        $path = str_replace('\\', '/', trim((string) $path));
        $path = $path === '/' ? '/' : rtrim($path, '/');
        if ($path === '') {
            return 'livekit/runtime/certs';
        }
        if (self::isAbsolutePath($path)) {
            if (! self::absolutePathIsValid($path)) {
                throw new RuntimeException('LiveKit certificate directory must be a safe absolute server path.');
            }

            return $path;
        }
        $path = trim($path, '/');
        if (! str_starts_with($path, 'livekit/runtime/') || ! self::relativePathIsValid($path)) {
            throw new RuntimeException('LiveKit relative certificate directory must be inside the PlayHub livekit/runtime directory.');
        }

        return $path;
    }

    private static function hostCertificatePath(array $settings, string $path): string
    {
        $path = self::normalizeCertificatePath($path);
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        return self::hostCertificateDirectory($settings).'/'.$path;
    }

    private static function containerCertificateDirectory(array $settings): string
    {
        return $settings['container_certificate_directory'];
    }

    private static function containerCertificatePath(array $settings, string $path): string
    {
        $path = self::normalizeCertificatePath($path);
        if (self::isAbsolutePath($path)) {
            $hostDirectory = self::hostCertificateDirectory($settings);
            if (! self::pathIsInside($path, $hostDirectory)) {
                throw new RuntimeException('Absolute certificate files must be inside the configured certificate directory.');
            }

            return rtrim(self::containerCertificateDirectory($settings), '/').substr($path, strlen($hostDirectory));
        }

        return rtrim(self::containerCertificateDirectory($settings), '/').'/'.$path;
    }

    private static function hostCertificateDirectory(array $settings): string
    {
        return self::isAbsolutePath($settings['certificate_directory']) ? $settings['certificate_directory'] : self::projectPath($settings['certificate_directory']);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/');
    }

    private static function absolutePathIsValid(string $path): bool
    {
        return $path !== '/' && ! str_contains($path, "\0") && ! preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path) && (bool) preg_match('#^/[A-Za-z0-9][A-Za-z0-9._/-]*$#', $path);
    }

    private static function relativePathIsValid(string $path): bool
    {
        return ! str_starts_with($path, '/') && ! str_contains($path, "\0") && ! preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path) && (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $path);
    }

    private static function pathIsInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.'/');
    }

    private static function normalizeIceServerConfigJson(mixed $json): string
    {
        $json = trim((string) $json);
        if ($json === '') {
            return self::defaultIceServerConfigJson();
        }

        return json_encode(self::iceServerConfigFromJson($json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private static function iceServerConfigFromJson(string $json): array
    {
        try {
            $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('LiveKit ICE server configuration must be valid JSON.');
        }
        if (! is_array($config) || array_is_list($config) || ! isset($config['icesConfig']) || ! is_array($config['icesConfig']) || array_is_list($config['icesConfig'])) {
            throw new RuntimeException('LiveKit ICE server configuration must contain an icesConfig object.');
        }

        $rtc = $config['icesConfig'];
        if (! isset($rtc['iceServers']) || ! is_array($rtc['iceServers']) || ! array_is_list($rtc['iceServers']) || count($rtc['iceServers']) > 32) {
            throw new RuntimeException('LiveKit icesConfig.iceServers must be a JSON array with at most 32 entries.');
        }
        foreach ($rtc['iceServers'] as $iceServer) {
            if (! is_array($iceServer) || array_is_list($iceServer) || ! array_key_exists('urls', $iceServer)) {
                throw new RuntimeException('Every LiveKit ICE server must be a JSON object containing urls.');
            }
            $urls = is_string($iceServer['urls']) ? [$iceServer['urls']] : $iceServer['urls'];
            if (! is_array($urls) || ! array_is_list($urls) || $urls === [] || count($urls) > 16) {
                throw new RuntimeException('Every LiveKit ICE server urls value must be a URL or a non-empty URL array.');
            }
            foreach ($urls as $url) {
                if (! is_string($url) || strlen($url) > 2048) {
                    throw new RuntimeException('Every LiveKit ICE server URL must be a valid STUN or TURN URL.');
                }
                if (self::iceServerUrlHasPlaceholder($url)) {
                    if (! preg_match('#^turns?:TURN_IP_ADDRESS:(?:TURN_)?PORT(?:\?transport=(?:udp|tcp))?$#i', $url)) {
                        throw new RuntimeException('The LiveKit TURN placeholder must use turn:TURN_IP_ADDRESS:PORT.');
                    }
                } elseif (self::parseStunUrl($url) === null && self::parseTurnUrl($url) === null) {
                    throw new RuntimeException('Every LiveKit ICE server URL must be a valid STUN or TURN URL.');
                }
            }
            foreach (['username', 'credential'] as $credential) {
                if (array_key_exists($credential, $iceServer) && (! is_string($iceServer[$credential]) || strlen($iceServer[$credential]) > 2048)) {
                    throw new RuntimeException('LiveKit ICE usernames and credentials must be strings of at most 2048 characters.');
                }
            }
        }

        foreach ([
            'iceTransportPolicy' => ['all', 'relay'],
            'bundlePolicy' => ['balanced', 'max-compat', 'max-bundle'],
            'rtcpMuxPolicy' => ['require', 'negotiate'],
        ] as $key => $allowed) {
            if (array_key_exists($key, $rtc) && (! is_string($rtc[$key]) || ! in_array($rtc[$key], $allowed, true))) {
                throw new RuntimeException('LiveKit '.$key.' contains an unsupported value.');
            }
        }
        if (array_key_exists('iceCandidatePoolSize', $rtc) && (! is_int($rtc['iceCandidatePoolSize']) || $rtc['iceCandidatePoolSize'] < 0 || $rtc['iceCandidatePoolSize'] > 255)) {
            throw new RuntimeException('LiveKit iceCandidatePoolSize must be an integer from 0 to 255.');
        }

        return $config;
    }

    private static function parseStunUrl(string $url): ?string
    {
        if (! preg_match('#^stun:(\[[0-9a-f:.]+\]|[^:/?\s]+)(?::([0-9]{1,5}))?$#i', $url, $matches)) {
            return null;
        }
        if (isset($matches[2]) && (int) $matches[2] > 65535) {
            return null;
        }

        return $matches[1].(isset($matches[2]) && $matches[2] !== '' ? ':'.$matches[2] : '');
    }

    private static function parseTurnUrl(string $url): ?array
    {
        if (! preg_match('#^(turns?):(?:/{2})?(\[[0-9a-f:.]+\]|[^:/?\s]+)(?::([0-9]{1,5}))?(?:\?transport=(udp|tcp))?$#i', $url, $matches)) {
            return null;
        }
        $port = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : (strtolower($matches[1]) === 'turns' ? 5349 : 3478);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return [
            'host' => trim($matches[2], '[]'),
            'port' => $port,
            'protocol' => strtolower($matches[1]) === 'turns' ? 'tls' : (isset($matches[4]) && $matches[4] !== '' ? strtolower($matches[4]) : 'udp'),
        ];
    }

    private static function iceServerUrlHasPlaceholder(string $url): bool
    {
        return (bool) preg_match('/(?:TURN_IP_ADDRESS|:(?:TURN_)?PORT(?:[?\/]|$))/i', $url);
    }

    private static function defaultIceServerConfigJson(): string
    {
        return json_encode([
            'icesConfig' => [
                'iceServers' => [
                    ['urls' => 'turn:TURN_IP_ADDRESS:PORT', 'username' => '', 'credential' => ''],
                    ['urls' => 'stun:stun.l.google.com:19302'],
                    ['urls' => 'stun:stun1.l.google.com:19302'],
                    ['urls' => 'stun:stun2.l.google.com:19302'],
                    ['urls' => 'stun:stun3.l.google.com:19302'],
                    ['urls' => 'stun:stun4.l.google.com:19302'],
                ],
                'iceTransportPolicy' => 'all',
                'bundlePolicy' => 'balanced',
                'rtcpMuxPolicy' => 'require',
                'iceCandidatePoolSize' => 0,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private static function normalizeAdvancedConfigJson(mixed $json): string
    {
        $json = trim((string) $json);
        if ($json === '') {
            return self::defaultAdvancedConfigJson();
        }
        $config = self::advancedConfigFromJson($json);
        if ((int) data_get($config, 'signal_relay.connect_attempts', 0) < 1) {
            $config['signal_relay']['connect_attempts'] = 3;
        }
        if (($config['audio']['update_interval'] ?? null) === '500ms') {
            $config['audio']['update_interval'] = 500;
        }

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private static function advancedConfig(array $settings): array
    {
        $config = self::pruneEmptyConfig(self::advancedConfigFromJson($settings['advanced_config_json'] ?? self::defaultAdvancedConfigJson()));
        if (blank(data_get($config, 'redis.address')) && blank(data_get($config, 'redis.sentinel_addresses')) && blank(data_get($config, 'redis.cluster_addresses'))) {
            unset($config['redis']);
        }
        if ((int) ($config['prometheus_port'] ?? 0) <= 0) {
            unset($config['prometheus_port']);
        }
        if ((int) data_get($config, 'prometheus.port', 0) <= 0 && blank(data_get($config, 'prometheus.username')) && blank(data_get($config, 'prometheus.password'))) {
            unset($config['prometheus']);
        }
        if ((int) data_get($config, 'debug_handler.port', 0) <= 0) {
            unset($config['debug_handler']);
        }
        if (blank(data_get($config, 'webhook.urls')) && blank(data_get($config, 'webhook.api_key'))) {
            unset($config['webhook']);
        }
        if (blank(data_get($config, 'ingress.rtmp_base_url')) && blank(data_get($config, 'ingress.whip_base_url'))) {
            unset($config['ingress']);
        }
        if (data_get($config, 'rtc.forward_stats.summary_interval') === '0s' && data_get($config, 'rtc.forward_stats.report_interval') === '0s' && data_get($config, 'rtc.forward_stats.report_window') === '0s') {
            unset($config['rtc']['forward_stats']);
        }

        return self::pruneEmptyConfig($config);
    }

    private static function advancedConfigFromJson(string $json): array
    {
        try {
            $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('LiveKit advanced configuration must be valid JSON.');
        }
        if (! is_array($config) || array_is_list($config)) {
            throw new RuntimeException('LiveKit advanced configuration must be a JSON object.');
        }

        return $config;
    }

    private static function defaultAdvancedConfigJson(): string
    {
        return json_encode(self::defaultAdvancedConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private static function defaultAdvancedConfig(): array
    {
        return [
            'bind_addresses' => [],
            'redis' => [
                'address' => '',
                'db' => 0,
                'username' => '',
                'password' => '',
                'sentinel_master_name' => '',
                'sentinel_addresses' => [],
                'sentinel_username' => '',
                'sentinel_password' => '',
                'cluster_addresses' => [],
                'tls' => ['enabled' => false, 'insecure' => false, 'server_name' => '', 'ca_cert_file' => '', 'client_cert_file' => '', 'client_key_file' => ''],
            ],
            'prometheus_port' => 0,
            'prometheus' => ['port' => 0, 'username' => '', 'password' => ''],
            'debug_handler' => ['port' => 0],
            'rtc' => [
                'advertise_internal_ip' => false,
                'skip_external_ip_validation' => false,
                'udp_port' => '',
                'use_ice_lite' => false,
                'stun_servers' => [],
                'turn_servers' => [],
                'congestion_control' => ['enabled' => true, 'allow_pause' => true],
                'allow_tcp_fallback' => true,
                'tcp_fallback_rtt_threshold' => 0,
                'allow_udp_unstable_fallback' => false,
                'packet_buffer_size_video' => 500,
                'packet_buffer_size_audio' => 200,
                'pli_throttle' => ['low_quality' => '500ms', 'mid_quality' => '1s', 'high_quality' => '1s'],
                'enable_loopback_candidate' => false,
                'interfaces' => ['includes' => [], 'excludes' => []],
                'ips' => ['includes' => [], 'excludes' => []],
                'use_mdns' => false,
                'strict_acks' => true,
                'batch_io' => ['batch_size' => 128, 'max_flush_interval' => '2ms'],
                'data_channel_max_buffered_amount' => 0,
                'datachannel_slow_threshold' => 0,
                'datachannel_lossy_target_latency' => '0s',
                'forward_stats' => ['summary_interval' => '0s', 'report_interval' => '0s', 'report_window' => '0s'],
                'enable_rtp_stream_restart_detection' => false,
            ],
            'audio' => ['active_level' => 30, 'min_percentile' => 40, 'update_interval' => 500, 'smooth_intervals' => 4, 'active_red_encoding' => false],
            'video' => ['dynacast_pause_delay' => '0s', 'codec_regression_threshold' => 0],
            'logging' => ['level' => 'info', 'pion_level' => 'error', 'json' => false, 'sample' => false],
            'room' => [
                'enabled_codecs' => [],
                'enable_remote_unmute' => false,
                'playout_delay' => ['enabled' => false, 'min' => 0, 'max' => 0],
                'sync_streams' => false,
                'create_room_timeout' => '0s',
                'create_room_attempts' => 0,
                'update_batch_target_size' => 0,
            ],
            'webhook' => ['api_key' => '', 'urls' => []],
            'signal_relay' => ['retry_timeout' => '30s', 'min_retry_interval' => '500ms', 'max_retry_interval' => '5s', 'stream_buffer_size' => 1000, 'connect_attempts' => 3],
            'psrpc' => ['max_attempts' => 3, 'timeout' => '500ms', 'backoff' => '500ms', 'buffer_size' => 1000],
            'turn' => ['relay_range_start' => 1024, 'relay_range_end' => 30000, 'external_tls' => false, 'bind_addresses' => [], 'ttl_seconds' => 300, 'allow_restricted_peer_cidrs' => [], 'deny_peer_cidrs' => []],
            'ingress' => ['rtmp_base_url' => '', 'whip_base_url' => ''],
            'node_selector' => ['kind' => 'any', 'sort_by' => 'random', 'algorithm' => 'lowest', 'cpu_load_limit' => 0, 'sysload_limit' => 0.7, 'regions' => []],
            'limit' => [
                'num_tracks' => 0,
                'bytes_per_sec' => 0,
                'subscription_limit_video' => 0,
                'subscription_limit_audio' => 0,
                'max_metadata_size' => 0,
                'max_attributes_size' => 0,
                'max_room_name_length' => 0,
                'max_participant_identity_length' => 0,
                'max_participant_name_length' => 0,
                'max_data_blob_key_length' => 0,
                'max_data_blobs_size' => 0,
                'max_data_track_custom_encoding_length' => 0,
            ],
            'development' => false,
            'enable_data_tracks' => true,
            'enable_participant_data_blob' => false,
            'api' => ['execution_timeout' => '2s', 'check_interval' => '100ms', 'max_check_interval' => '1s', 'enable_psrpc_for_get_list_participants' => true],
        ];
    }

    private static function pruneEmptyConfig(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $value = self::pruneEmptyConfig($value);
                if ($value === []) {
                    unset($config[$key]);
                } else {
                    $config[$key] = $value;
                }
            } elseif ($value === null || $value === '') {
                unset($config[$key]);
            }
        }

        return $config;
    }

    private static function dumpYaml(array $config, int $indent = 0): string
    {
        $lines = [];
        foreach ($config as $key => $value) {
            $prefix = str_repeat(' ', $indent).$key.':';
            if (is_array($value)) {
                if ($value === []) {
                    $lines[] = $prefix.' []';
                } elseif (array_is_list($value)) {
                    $lines[] = $prefix;
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $lines[] = str_repeat(' ', $indent + 2).'-';
                            $lines[] = rtrim(self::dumpYaml($item, $indent + 4), "\n");
                        } else {
                            $lines[] = str_repeat(' ', $indent + 2).'- '.self::yamlScalar($item);
                        }
                    }
                } else {
                    $lines[] = $prefix;
                    $lines[] = rtrim(self::dumpYaml($value, $indent + 2), "\n");
                }
            } else {
                $lines[] = $prefix.' '.self::yamlScalar($value);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function yamlScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return json_encode((string) $value, JSON_UNESCAPED_SLASHES);
    }

    private static function ensureRuntimeDirectory(): void
    {
        $path = dirname(self::settingsPath());
        if (! is_dir($path) && ! mkdir($path, 0750, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create the LiveKit runtime directory.');
        }
        @chmod($path, 0750);
        if (! is_writable($path)) {
            throw new RuntimeException('The LiveKit runtime directory is not writable.');
        }
    }

    private static function ensureCertificateDirectory(array $settings): void
    {
        if (self::isAbsolutePath($settings['certificate_directory'])) {
            return;
        }
        $path = self::hostCertificateDirectory($settings);
        if (! is_dir($path) && ! mkdir($path, 0750, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create the LiveKit certificate directory.');
        }
    }

    private static function ensureProjectFile(string $path, string $contents, int $mode): void
    {
        $absolute = self::projectPath($path);
        if (! is_file($absolute)) {
            self::writeFile($absolute, $contents, $mode);
        }
    }

    private static function writeFile(string $path, string $contents, int $mode): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the LiveKit configuration directory.');
        }
        $owner = is_file($path) ? @fileowner($path) : @fileowner($directory);
        $group = is_file($path) ? @filegroup($path) : @filegroup($directory);
        $temporary = $path.'.tmp.'.Str::random(10);
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the LiveKit configuration.');
        }
        self::matchFileOwner($temporary, $owner, $group);
        @chmod($temporary, $mode);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to activate the LiveKit configuration.');
        }
        self::matchFileOwner($path, $owner, $group);
        @chmod($path, $mode);
    }

    private static function matchFileOwner(string $path, mixed $owner, mixed $group): void
    {
        if ($owner !== false && function_exists('chown')) {
            @chown($path, $owner);
        }
        if ($group !== false && function_exists('chgrp')) {
            @chgrp($path, $group);
        }
    }

    private static function settingsPath(): string
    {
        return self::projectPath('livekit/runtime/settings.json');
    }
}
