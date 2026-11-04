<?php

namespace App\Console\Commands;

use App\Models\PushDelivery;
use App\Services\Push\PushDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverNativePush extends Command
{
    protected $signature = 'push:recover';

    protected $description = 'Recover due native push outbox rows after broker / worker interruption';

    public function handle(PushDeliveryService $deliveries): int
    {
        if (! config('push.enabled') || ! Schema::hasTable('push_deliveries')) {
            return self::SUCCESS;
        }
        PushDelivery::query()->whereIn('status', ['pending', 'retry', 'sending'])->where('available_at', '<=', now())
            ->orderBy('id')->limit(500)->pluck('id')->each(fn ($id) => $deliveries->dispatch($id));
        // Retain only operational metadata for 30 days; no notification content or
        // plaintext device tokens are stored in this outbox.
        PushDelivery::query()->where('updated_at', '<', now()->subDays(30))->delete();

        return self::SUCCESS;
    }
}
