<?php

namespace App\Jobs;

use App\Services\Push\PushDeliveryService;
use App\Services\Push\PushGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverNativePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $deliveryId) {}

    public function handle(PushDeliveryService $deliveries, PushGateway $gateway): void
    {
        $deliveries->deliver($this->deliveryId, $gateway);
    }
}
