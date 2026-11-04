<?php

namespace App\Services\Push;

use App\Models\PushDevice;
use Illuminate\Support\Facades\Http;

class ApnsSender
{
    public function __construct(private PushCredentials $credentials) {}

    public function send(PushDevice $device, array $payload, string $id): PushResult
    {
        $topic = $device->app_identifier ?: config('push.apns.bundle_id');
        if (! $topic || ! in_array($topic, config('push.apns.bundle_ids', []), true)) {
            return new PushResult('failed', 'apns_topic_not_allowed');
        }
        $host = $device->environment === 'sandbox' ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = Http::withToken($this->credentials->apnsToken($attempt > 0), 'bearer')
                ->withOptions(['version' => 2.0])->connectTimeout(5)->timeout(15)
                ->withHeaders([
                    'apns-topic' => $topic, 'apns-push-type' => 'alert', 'apns-priority' => '10',
                    'apns-expiration' => (string) $payload['expires_at'], 'apns-id' => $id,
                    'apns-collapse-id' => 'playhub-'.$payload['data']['notification_id'],
                ])->withBody(json_encode([
                    'aps' => ['alert' => ['title' => $payload['title'], 'body' => $payload['body']], 'sound' => 'default'],
                    ...$payload['data'],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'application/json')
                ->post('https://'.$host.'/3/device/'.$device->token);
            if ($response->successful()) {
                return new PushResult('sent');
            }
            $reason = $response->json('reason');
            if ($reason === 'ExpiredProviderToken' && $attempt === 0) {
                continue;
            }
            if (in_array($reason, ['Unregistered', 'ExpiredToken', 'BadDeviceToken'], true)) {
                $since = $response->json('timestamp');

                return new PushResult('invalid', 'apns_invalid_token', invalidSince: is_numeric($since) ? (int) $since : null);
            }

            return new PushResult(
                $response->status() === 429 || $response->serverError() ? 'retry' : 'failed',
                'apns_http_'.$response->status(),
                max(60, min(3600, (int) $response->header('Retry-After', 60))),
            );
        }

        return new PushResult('failed', 'apns_auth_failed');
    }
}
