<?php

// Testworker bridge: metadata only. This process never receives video bytes.
if (! in_array('--run', $argv, true)) {
    echo "No requests made. Called only by the explicit browser import probe.\n";
    exit(0);
}
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, "Clip import probe bridge failed; sensitive details suppressed.\n");
    exit(1);
});
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Laravel installs its own exception renderer at bootstrap; replace it only in
// this CLI test process so failures cannot print provider requests or settings.
set_exception_handler(static function (Throwable $error): void {
    echo json_encode(['probeFailed' => true, 'errorType' => get_class($error),
        'file' => basename($error->getFile()), 'line' => $error->getLine()]);
    exit(1);
});

use App\Support\Clips\ClipStagingObject;
use App\Support\Clips\OdyseeImportFailure;
use App\Support\Clips\OdyseePublisher;
use App\Support\Clips\OdyseePublishingFailure;
use App\Support\Clips\OdyseeRemoteUpload;
use App\Support\Clips\OdyseeUrlImporter;
use App\Support\Clips\OracleStagingConnection;
use App\Support\Clips\OracleStagingStore;
use App\Support\MediaProviderSettings;
use Illuminate\Support\Str;

$input = json_decode(stream_get_contents(STDIN, 4096), true, 16, JSON_THROW_ON_ERROR);
$settings = MediaProviderSettings::current();
$store = new OracleStagingStore(new OracleStagingConnection($settings->effective('oracle')));
$object = new ClipStagingObject($input['object']['jobId'] ?? (string) Str::uuid(), $input['object']['bytes'], $input['object']['sha256']);
$directory = storage_path('app/private/clip-probes');
if (! is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$path = $directory.'/'.$object->jobId.'.json';
$receipt = is_file($path) ? json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR) : ['object' => $object];
$save = static function () use ($path, &$receipt): void {
    $temp = $path.'.tmp';
    file_put_contents($temp, json_encode($receipt, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($temp, 0600);
    rename($temp, $path);
};
$publisher = new OdyseePublisher($settings->effective('odysee'));
try {
switch ($input['operation'] ?? '') {
    case 'grant':
        // Five minutes leaves room for bounded provider requests and cleanup.
        $receipt['created_at'] = date(DATE_ATOM);
        $save();
        echo json_encode(['object' => $object, 'grant' => $store->uploadGrant($object, new DateTimeImmutable('+5 minutes'))], JSON_THROW_ON_ERROR);
        break;
    case 'publish':
        if (! isset($receipt['upload'])) {
            $store->seal($object);
            $receipt['upload'] = (new OdyseeUrlImporter($settings->effective('odysee')))->register($store, $object)->filePath();
            $save();
        }
        if (! isset($receipt['query'])) {
            $receipt['query'] = $publisher->create($object, new OdyseeRemoteUpload($receipt['upload']), 'CrozzTube synthetic integration test');
            $save();
        }
        echo '{"submitted":true}';
        break;
    case 'status':
        $result = $publisher->status($receipt['query'], $object);
        if ($result['state'] === 'published') {
            $receipt['claim'] = $result['claim_id'];
            $save();
        }
        echo json_encode(['state' => $result['state']], JSON_THROW_ON_ERROR);
        break;
    case 'playback':
        $receipt['playback_url'] = $publisher->playback($object->jobId, $receipt['claim']);
        $save();
        echo json_encode(['url' => $receipt['playback_url']], JSON_THROW_ON_ERROR);
        break;
    case 'public':
    case 'private':
        $publisher->visibility($object->jobId, $receipt['claim'], $input['operation'] === 'public');
        echo '{"confirmed":true}';
        break;
    case 'abandon':
        $claim = $receipt['claim'] ?? $publisher->ownedClip($object->jobId)['claim_id'] ?? null;
        if ($claim) {
            $publisher->abandon($object->jobId, $claim);
            $receipt['abandoned_at'] = date(DATE_ATOM);
            $save();
        }
        echo json_encode(['abandoned' => $claim !== null], JSON_THROW_ON_ERROR);
        break;
    case 'register':
        $store->seal($object);
        try {
            $upload = (new OdyseeUrlImporter($settings->effective('odysee')))->register($store, $object);
            // No token, signed URL or provider upload handle in test output.
            echo json_encode($upload, JSON_THROW_ON_ERROR);
        } catch (OdyseeImportFailure $error) {
            echo json_encode(['provider' => 'odysee', 'registered' => false, 'stage' => $error->stage,
                'httpStatus' => $error->status, 'uncertain' => $error->outcomeUncertain()], JSON_THROW_ON_ERROR);
        }
        break;
    case 'cleanup':
        $store->deleteCopies($object);
        $receipt['staging_deleted_at'] = date(DATE_ATOM);
        $save();
        echo '{"deleted":true}';
        break;
    default:
        throw new InvalidArgumentException('Unknown probe operation.');
}
} catch (OdyseePublishingFailure $error) {
    echo json_encode(['failed' => true, 'operation' => $error->operation, 'reason' => $error->reason, 'httpStatus' => $error->status], JSON_THROW_ON_ERROR);
}
