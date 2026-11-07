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
use App\Support\Clips\OdyseeUrlImporter;
use App\Support\Clips\OracleStagingConnection;
use App\Support\Clips\OracleStagingStore;
use App\Support\MediaProviderSettings;
use Illuminate\Support\Str;

$input = json_decode(stream_get_contents(STDIN, 4096), true, 16, JSON_THROW_ON_ERROR);
$settings = MediaProviderSettings::current();
$store = new OracleStagingStore(new OracleStagingConnection($settings->effective('oracle')));
$object = new ClipStagingObject($input['object']['jobId'] ?? (string) Str::uuid(), $input['object']['bytes'], $input['object']['sha256']);
switch ($input['operation'] ?? '') {
    case 'grant':
        // Five minutes leaves room for bounded provider requests and cleanup.
        echo json_encode(['object' => $object, 'grant' => $store->uploadGrant($object, new DateTimeImmutable('+5 minutes'))], JSON_THROW_ON_ERROR);
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
        echo '{"deleted":true}';
        break;
    default:
        throw new InvalidArgumentException('Unknown probe operation.');
}
