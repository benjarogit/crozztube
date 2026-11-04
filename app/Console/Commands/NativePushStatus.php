<?php

namespace App\Console\Commands;

use App\Models\PushDelivery;
use App\Services\Push\PushCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class NativePushStatus extends Command
{
    protected $signature = 'push:status';

    protected $description = 'Check native FCM/APNs readiness without exposing credentials or device tokens';

    public function handle(PushCredentials $credentials): int
    {
        $this->table(['Component', 'Status'], [
            ['Delivery switch', config('push.enabled') ? 'Enabled' : 'Disabled'],
            ['Android FCM', $credentials->configured('fcm') ? 'Configured; live delivery requires device test' : 'Configuration required'],
            ['iOS APNs', $credentials->configured('apns') ? 'Configured; live delivery requires device test' : 'Configuration / HTTP2 required'],
            ['Queue', config('push.queue')],
        ]);
        if (Schema::hasTable('push_deliveries')) {
            $this->table(['Delivery state', 'Count'], PushDelivery::query()->selectRaw('status, count(*) as total')->groupBy('status')->get()->map(fn ($row) => [$row->status, $row->total])->all());
        } else {
            $this->warn('Run migrations before enabling delivery.');
        }

        return self::SUCCESS;
    }
}
