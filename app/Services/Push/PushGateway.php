<?php

namespace App\Services\Push;

use App\Models\PushDevice;
use Throwable;

class PushGateway
{
    public function __construct(private FcmSender $fcm, private ApnsSender $apns, private PushCredentials $credentials) {}

    public function send(PushDevice $device, array $payload, string $id): PushResult
    {
        if (! $this->credentials->configured($device->provider)) {
            return new PushResult('retry', 'push_not_configured', 300);
        }
        try {
            return match ($device->provider) {
                'fcm' => $this->fcm->send($device, $payload, $id),
                'apns' => $this->apns->send($device, $payload, $id),
                default => new PushResult('failed', 'push_provider_invalid'),
            };
        } catch (PushTransportException $exception) {
            return new PushResult('retry', $exception->getMessage(), 300);
        } catch (Throwable) {
            // A transport error can contain the token in its URL / request body.
            return new PushResult('retry', 'push_transport_failed');
        }
    }
}
