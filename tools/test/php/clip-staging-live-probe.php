<?php

// Deliberate external protocol test, never part of the offline harness checks.
// The payload is 256 synthetic text bytes, NOT video. No user media is touched.
if (! in_array('--run', $argv, true)) {
    echo "No requests made. Pass --run to test a disposable 256-byte Oracle object.\n";
    exit(0);
}
set_exception_handler(static function (Throwable $error): void {
    // Also covers bootstrap/.env errors without disclosing their input values.
    fwrite(STDERR, "Oracle protocol probe failed before completion; details suppressed.\n");
    exit(1);
});
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Clips\ClipStagingObject;
use App\Support\Clips\OracleStagingConnection;
use App\Support\Clips\OracleStagingStore;
use Aws\Exception\AwsException;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

$connection = new OracleStagingConnection(\App\Support\MediaProviderSettings::current()->effective('oracle'));
$client = $connection->client();
$store = new OracleStagingStore($connection, $client);
$bytes = str_pad('CrozzTube synthetic protocol fixture. THIS IS NOT VIDEO.', 256, '.');
$object = new ClipStagingObject((string) Str::uuid(), strlen($bytes), hash('sha256', $bytes));
$http = new Client(['allow_redirects' => false, 'http_errors' => false, 'verify' => true,
    'connect_timeout' => 5, 'timeout' => 20]);
$grant = $store->uploadGrant($object, new DateTimeImmutable('+2 minutes'));
$post = static function (array $fields, string $payload) use ($http, $grant): int {
    $parts = [];
    foreach ($fields as $name => $value) {
        $parts[] = ['name' => $name, 'contents' => $value];
    }
    $parts[] = ['name' => 'file', 'filename' => 'synthetic-protocol-fixture.mp4', 'contents' => $payload];
    $response = $http->post($grant['url'], ['multipart' => $parts]);
    $status = $response->getStatusCode();
    $response->getBody()->close();

    return $status;
};
$stage = 'initialization';
$passed = [];
$failed = false;
try {
    foreach ([
        'changed form field' => [[...$grant['fields'], 'Content-Type' => 'text/plain'], $bytes],
        'wrong byte count' => [$grant['fields'], $bytes.'.'],
        'wrong file checksum' => [$grant['fields'], 'X'.substr($bytes, 1)],
    ] as $stage => [$fields, $payload]) {
        $status = $post($fields, $payload);
        if (! in_array($status, [400, 403, 412], true)) {
            throw new RuntimeException();
        }
        $passed[] = $stage.' rejected';
    }
    $stage = 'valid signed POST';
    if ($post($grant['fields'], $bytes) !== 204) {
        throw new RuntimeException();
    }
    $passed[] = $stage;
    $stage = 'conditional copy protection';
    try {
        $client->copyObject(['Bucket' => $connection->bucket(), 'Key' => $object->handoffKey(),
            'CopySource' => $connection->bucket().'/'.$object->incomingKey(),
            'CopySourceIfMatch' => '"definitely-wrong-etag"']);
        throw new LogicException('Unsafe conditional copy accepted.');
    } catch (AwsException $error) {
        if ($error->getStatusCode() !== 412) {
            throw new RuntimeException();
        }
    }
    $passed[] = $stage;
    $stage = 'validated provider-internal handoff copy';
    $store->seal($object);
    $passed[] = $stage;
    $stage = 'private handoff';
    $anonymous = $http->get($connection->endpoint().'/'.$connection->bucket().'/'.$object->handoffKey(), ['stream' => true]);
    $anonymousStatus = $anonymous->getStatusCode();
    $anonymous->getBody()->close();
    if (! in_array($anonymousStatus, [403, 404], true)) {
        throw new RuntimeException();
    }
    $passed[] = $stage;
    $stage = 'signed handoff read';
    $read = $http->get($store->downloadGrant($object, new DateTimeImmutable('+1 minute')), ['stream' => true]);
    if ($read->getStatusCode() !== 200 || $read->getBody()->read(257) !== $bytes) {
        throw new RuntimeException();
    }
    $read->getBody()->close();
    $passed[] = $stage;
} catch (Throwable) {
    $failed = true;
    // Never print the provider exception, its response or a signed URL.
    echo 'FAIL: '.$stage.". Provider details suppressed.\n";
} finally {
    try {
        $store->deleteCopies($object);
        $passed[] = 'both disposable copies deleted and absence confirmed';
    } catch (Throwable) {
        $failed = true;
        echo 'FAIL: cleanup not confirmed for disposable job '.$object->jobId.".\n";
    }
}
foreach ($passed as $label) {
    echo 'PASS: '.$label."\n";
}
echo "Protocol test only: no browser CORS, video upload, Odysee import or queue acceptance. Grants can be replayed until expiry; later queue cleanup must sweep expired jobs again.\n";
exit($failed ? 1 : 0);
