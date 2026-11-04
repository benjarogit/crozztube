<?php

namespace App\Services\Push;

use App\Http\Resources\Api\V1\NotificationResource;
use App\Jobs\DeliverNativePush;
use App\Models\PushDelivery;
use App\Models\PushDevice;
use App\Models\UserNotification;
use App\Services\NotificationPreferencePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PushDeliveryService
{
    public function __construct(private PushCredentials $credentials) {}

    public function enqueue(UserNotification $notification): void
    {
        if (! config('push.enabled') || $notification->read_at !== null || ! Schema::hasTable('push_deliveries')) {
            return;
        }
        foreach (PushDevice::query()->active()->where('user_id', $notification->user_id)->cursor() as $device) {
            if (! $this->credentials->configured($device->provider)) {
                continue;
            }
            $delivery = PushDelivery::query()->firstOrCreate([
                'notification_id' => $notification->id, 'push_device_id' => $device->id,
            ], [
                'user_id' => $notification->user_id, 'token_hash' => $device->token_hash,
                'available_at' => now(), 'expires_at' => now()->addSeconds(config('push.ttl_seconds', 3600)),
            ]);
            if ($delivery->wasRecentlyCreated) {
                DB::afterCommit(fn () => $this->dispatch($delivery->id));
            }
        }
    }

    public function dispatch(int $id, int $delay = 0): void
    {
        try {
            DeliverNativePush::dispatch($id)->onQueue(config('push.queue', 'push'))->delay($delay);
        } catch (Throwable) {
            // The durable row survives a broker outage; the scheduler recovers it.
            Log::warning('Native push queue unavailable.', ['delivery_id' => $id]);
        }
    }

    public function deliver(int $id, PushGateway $gateway): void
    {
        // Claim with an atomic UPDATE. Duplicate jobs / scheduler recovery cannot
        // send concurrently. The lease also recovers workers killed mid-request.
        $claimed = PushDelivery::query()->whereKey($id)
            ->whereIn('status', ['pending', 'retry', 'sending'])
            ->where('available_at', '<=', now())
            ->update(['status' => 'sending', 'available_at' => now()->addSeconds(90), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $delivery = PushDelivery::query()->find($id);
        if (! $delivery) {
            return;
        }
        $device = PushDevice::query()->find($delivery->push_device_id);
        $notification = UserNotification::query()->find($delivery->notification_id);
        if ($delivery->expires_at->isPast() || $delivery->attempts > config('push.max_attempts', 6)
            || ! $notification || $notification->read_at !== null
            || (int) $notification->user_id !== (int) $delivery->user_id
            || ! $device?->isActive() || (int) $device->user_id !== (int) $delivery->user_id
            || ! hash_equals($delivery->token_hash, $device->token_hash)
            || ! app(NotificationPreferencePolicy::class)->allows($notification)) {
            $delivery->update(['status' => 'skipped', 'error_code' => 'push_no_longer_eligible']);

            return;
        }
        $notification->load(['actor.channel', 'video.liveStream', 'comment', 'communityPost.channel']);
        $resource = (new NotificationResource($notification))->resolve(Request::create('/'));
        $result = $gateway->send($device, [
            'title' => mb_substr(strip_tags($resource['title']), 0, 160),
            'body' => mb_substr(strip_tags($resource['body']), 0, 400),
            'expires_at' => $delivery->expires_at->getTimestamp(),
            'data' => [
                'notification_id' => (int) $notification->id,
                'recipient_user_id' => (int) $delivery->user_id,
                'action' => $resource['action'],
            ],
        ], $delivery->uuid);
        if ($result->status === 'invalid') {
            // Never revoke a token refreshed / reassigned during this request.
            PushDevice::query()->whereKey($device->id)->where('user_id', $delivery->user_id)
                ->where('token_hash', $delivery->token_hash)->where('last_seen_at', $device->last_seen_at)
                ->when($result->invalidSince !== null, fn ($query) => $query->where('last_seen_at', '<=', date('Y-m-d H:i:s', (int) floor($result->invalidSince / 1000))))
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
        }
        $retry = $result->status === 'retry' && $delivery->attempts < config('push.max_attempts', 6) && ! $delivery->expires_at->isPast();
        $delay = max($result->retryAfter, min(1800, 60 * (2 ** ($delivery->attempts - 1)))) + random_int(0, 15);
        $delivery->update([
            'status' => $retry ? 'retry' : ($result->status === 'retry' ? 'failed' : $result->status),
            'error_code' => $result->code,
            'sent_at' => $result->status === 'sent' ? now() : null,
            'available_at' => now()->addSeconds($delay),
        ]);
        if ($retry) {
            $this->dispatch($id, $delay);
        }
    }
}
