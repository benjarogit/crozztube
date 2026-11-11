<?php

// Explicit owner-run integration test. Input/output is metadata, never video.
if (! in_array('--run', $argv, true)) {
    echo "No requests made. Use the browser clip workflow probe.\n";
    exit(0);
}
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    echo '{"failed":true,"reason":"workflow_probe_failed"}';
    exit(1);
});

use App\Models\ClipStagingAccount;
use App\Models\ClipTransfer;
use App\Models\User;
use App\Support\Clips\ClipTransferProcessor;
use App\Support\Clips\ClipTransferQueue;
use App\Support\Clips\OdyseeTransferGateway;
use App\Support\Clips\OracleStagingConnection;
use App\Support\Clips\OracleStagingStore;
use App\Support\MediaProviderSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$input = json_decode(stream_get_contents(STDIN, 4096), true, 16, JSON_THROW_ON_ERROR);
$user = User::where('username', $input['owner'] ?? 'benny')->firstOrFail();
if (data_get($user->settings, 'role') !== 'owner') throw new RuntimeException();
$queue = ClipTransferQueue::fromConfig();
if (($input['operation'] ?? '') === 'reserve') {
    if ($queue->synchronizeOracle(MediaProviderSettings::current()->effective('oracle')) === null) {
        throw new RuntimeException('Oracle staging is not configured.');
    }
    $videoId = DB::table('videos')->where('user_id', $user->id)->orderBy('id')->value('id');
    if (! $videoId) throw new RuntimeException();
    $transfer = $queue->enqueue($user->id, (int) $videoId, (string) Str::uuid(), [
        'bytes' => $input['object']['bytes'], 'sha256' => $input['object']['sha256'],
        'start' => 0, 'end' => 5, 'visibility' => 'public', 'title' => 'CrozzTube Bestätigungstest',
    ]);
} else {
    $transfer = ClipTransfer::where('user_id', $user->id)->where('uuid', $input['id'])->firstOrFail();
}
switch ($input['operation'] ?? '') {
    case 'reserve':
    case 'grant':
        $queue->promote(); $transfer->refresh();
        $grant = null;
        if ($transfer->phase === 'uploading' && $transfer->grant_until->isFuture()) {
            $account = ClipStagingAccount::findOrFail($transfer->account_id);
            $grant = (new OracleStagingStore(new OracleStagingConnection($account->connection)))
                ->uploadGrant($transfer->stagingObject(), $transfer->grant_until->toDateTimeImmutable());
        }
        echo json_encode(['id' => $transfer->uuid, 'state' => $transfer->phase, 'grant' => $grant], JSON_THROW_ON_ERROR);
        break;
    case 'uploaded':
        $queue->uploaded($user->id, $transfer->uuid);
        echo '{"recorded":true}';
        break;
    case 'observe':
        if ($transfer->phase !== 'ready' || $transfer->bytes > 1048576
            || ($transfer->metadata['title'] ?? null) !== 'CrozzTube Bestätigungstest') throw new RuntimeException();
        $request = Illuminate\Http\Request::create('/api/clip-transfers/'.$transfer->uuid.'/playback', 'GET');
        $request->setUserResolver(fn () => $user);
        $playback = (new App\Http\Controllers\Api\ClipTransferController())->playback($request, $transfer->uuid)->getData(true);
        $settings = MediaProviderSettings::current()->effective('odysee');
        $settings['channel_id'] = $transfer->publisher_channel_id;
        $claim = (new App\Support\Clips\OdyseePublisher($settings))->ownedClip($transfer->uuid, $transfer->provider_state['claim']);
        echo json_encode(['id' => $transfer->uuid, 'state' => $transfer->phase, 'bytes' => $transfer->bytes,
            'embed' => $playback['embed_url'], 'hash' => $claim['value']['source']['sd_hash'] ?? null,
            'claim' => $claim['claim_id'], 'confirmations' => $claim['confirmations'] ?? 0,
            'notifications' => App\Models\UserNotification::where('clip_transfer_id', $transfer->id)->count(),
            'cleanup_checked' => $transfer->cleanup_checked_at !== null,
        ], JSON_THROW_ON_ERROR);
        break;
    case 'advance':
        (new ClipTransferProcessor(new OdyseeTransferGateway()))->run($transfer->uuid);
        $transfer->refresh();
        echo json_encode(['id' => $transfer->uuid, 'state' => $transfer->phase, 'available_at' => $transfer->available_at?->toIso8601String(),
            'provider_accepted' => isset($transfer->provider_state['query']) || isset($transfer->provider_state['claim']),
            'notification_recorded' => $transfer->notification_handled_at !== null,
        ], JSON_THROW_ON_ERROR);
        break;
    default:
        throw new InvalidArgumentException();
}
