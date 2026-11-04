<?php

namespace App\Services\Push;

use App\Models\PushDevice;
use Illuminate\Support\Facades\Http;

class FcmSender
{
    public function __construct(private PushCredentials $credentials) {}

    public function send(PushDevice $device, array $payload, string $id): PushResult
    {
        $project = $this->credentials->fcm()['project_id'];
        $message = [
            'token' => $device->token,
            'notification' => ['title' => $payload['title'], 'body' => $payload['body']],
            // Expo's Android serializer decodes this JSON into content.data,
            // including on background/cold-start taps.
            'data' => ['body' => json_encode($payload['data'], JSON_THROW_ON_ERROR)],
            'android' => [
                'priority' => 'HIGH',
                'ttl' => max(0, $payload['expires_at'] - time()).'s',
                'notification' => ['channel_id' => 'playhub', 'tag' => 'playhub-'.$payload['data']['notification_id'], 'sound' => 'default'],
            ],
        ];
        if ($device->app_identifier) {
            if (! in_array($device->app_identifier, config('push.fcm.packages', []), true)) {
                return new PushResult('failed', 'fcm_package_not_allowed');
            }
            $message['android']['restricted_package_name'] = $device->app_identifier;
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = Http::withToken($this->credentials->fcmAccessToken($attempt > 0))
                ->acceptJson()->connectTimeout(5)->timeout(15)
                ->post('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send', ['message' => $message]);
            if ($response->successful()) {
                return new PushResult('sent');
            }
            if ($response->status() === 401 && $attempt === 0) {
                continue;
            }
            $details = (array) $response->json('error.details', []);
            $fcmError = collect($details)->first(fn ($item) => ($item['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError');
            $code = $fcmError['errorCode'] ?? $response->json('error.status', 'UNKNOWN');
            if ($code === 'UNREGISTERED') {
                return new PushResult('invalid', 'fcm_unregistered');
            }

            return new PushResult(
                $response->status() === 429 || $response->serverError() ? 'retry' : 'failed',
                'fcm_http_'.$response->status(),
                max(60, min(3600, (int) $response->header('Retry-After', 60))),
            );
        }

        return new PushResult('failed', 'fcm_auth_failed');
    }
}
