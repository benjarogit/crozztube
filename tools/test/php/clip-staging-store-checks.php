<?php

require __DIR__.'/../../../vendor/autoload.php';

use App\Support\Clips\ClipStagingObject;
use App\Support\Clips\OracleStagingConnection;
use App\Support\Clips\OracleStagingStore;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$reject = static function (callable $call, string $expected) use ($check): void {
    try {
        $call();
        throw new LogicException('Unexpected acceptance.');
    } catch (InvalidArgumentException|RuntimeException $error) {
        $check(str_contains($error->getMessage(), $expected) && $error->getPrevious() === null, 'Unsafe operations must fail closed with a redacted error.');
    }
};
$connection = new OracleStagingConnection([
    'region' => 'eu-frankfurt-1', 'namespace' => 'fixture', 'bucket' => 'fixture-clip-bucket',
    'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret-not-real',
]);
$id = '12345678-abcd-4abc-8abc-123456789abc';
$sha = hash('sha256', 'fixture');
$object = new ClipStagingObject($id, 7, $sha);
$check($object->reservedBytes() === 14, 'Reservation must cover incoming and handoff copies, even during replay.');
foreach ([['../escape', 7, $sha], [$id, 0, $sha], [$id, 67108865, $sha], [$id, 7, 'short'], [strtoupper($id), 7, $sha]] as $invalid) {
    $reject(static fn () => new ClipStagingObject(...$invalid), 'Invalid clip staging');
}
$store = new OracleStagingStore($connection);
$expires = new DateTimeImmutable('+5 minutes');
$grant = $store->uploadGrant($object, $expires);
$policy = json_decode(base64_decode($grant['fields']['Policy']), true);
$check($grant['method'] === 'POST' && $grant['url'] === $connection->endpoint().'/'.$connection->bucket(), 'Uploads must target only the dedicated Oracle bucket.');
$check($grant['fields']['key'] === $object->incomingKey(), 'The browser must never get write access to the handoff copy.');
$check(in_array(['content-length-range', 7, 7], $policy['conditions'], true), 'Oracle must enforce the exact reserved byte count.');
$check(in_array(['bucket' => $connection->bucket()], $policy['conditions'], true), 'The bucket must be exact, not a prefix.');
foreach ($grant['fields'] as $key => $value) {
    if (in_array($key, ['Policy', 'X-Amz-Signature'], true)) {
        continue;
    }
    $check(in_array([$key => $value], $policy['conditions'], true), 'Every submitted field must be bound by an exact signed condition.');
}
$check(! str_contains(json_encode($grant), 'fixture-secret-not-real'), 'Only the derived signature, never the secret key, may reach the browser.');
$check(strtotime($policy['expiration']) === $expires->getTimestamp(), 'Policy expiration must match the queue-held grant lifetime.');
foreach (['-1 second', '+11 minutes'] as $badExpiry) {
    $reject(static fn () => $store->uploadGrant($object, new DateTimeImmutable($badExpiry)), 'expiration');
}
$head = ['ContentLength' => 7, 'ContentType' => 'video/mp4', 'ChecksumSHA256' => $object->checksumBase64(),
    'Metadata' => ['clip-job' => $id, 'clip-sha256' => $sha], 'ETag' => '"fixture-etag"'];
$commands = [];
$makeStore = static function (array $responses) use ($connection, &$commands): OracleStagingStore {
    $commands = [];
    $mock = new MockHandler($responses);
    $client = $connection->client();
    $client->getHandlerList()->setHandler(static function ($command, $request) use ($mock, &$commands) {
        $commands[] = ['operation' => $command->getName(), 'arguments' => $command->toArray()];

        return $mock($command, $request);
    });

    return new OracleStagingStore($connection, $client);
};
$store = $makeStore([new Result($head), new Result(), new Result($head)]);
$store->seal($object);
$check(array_column($commands, 'operation') === ['HeadObject', 'CopyObject', 'HeadObject'], 'Sealing must inspect, copy inside Oracle, then confirm the destination.');
$copy = $commands[1]['arguments'];
$check($copy['CopySourceIfMatch'] === $head['ETag'] && $copy['Key'] === $object->handoffKey(), 'Conditional copy must seal exactly the inspected revision into the separate handoff key.');
$check(! isset($copy['Body']) && ! isset($copy['SourceFile']), 'Video bytes must never pass through the application.');
foreach ([['ContentLength' => 8], ['ContentType' => 'text/plain'], ['ChecksumSHA256' => 'wrong'], ['Metadata' => []], ['ETag' => "bad\nheader"]] as $change) {
    $store = $makeStore([new Result([...$head, ...$change])]);
    $reject(static fn () => $store->seal($object), 'does not match');
    $check(count($commands) === 1, 'Invalid metadata must prevent the copy, not just produce a warning.');
}
$store = $makeStore([new Result($head), new Result(), new Result([...$head, 'ChecksumSHA256' => 'wrong'])]);
$reject(static fn () => $store->seal($object), 'does not match');
$store = $makeStore([new Result($head)]);
$url = $store->downloadGrant($object, new DateTimeImmutable('+15 minutes'));
$check(parse_url($url, PHP_URL_PATH) === '/fixture-clip-bucket/'.$object->handoffKey(), 'The hoster gets read access to the sealed copy only.');
parse_str(parse_url($url, PHP_URL_QUERY), $query);
$check((int) $query['X-Amz-Expires'] <= 900 && ! str_contains($url, 'fixture-secret-not-real'), 'Hoster read capability must be short-lived and secret-free.');
$reject(static fn () => $store->downloadGrant($object, new DateTimeImmutable('+2 hours')), 'expiration');
$missing = static fn (): S3Exception => new S3Exception('fixture only', new Command('HeadObject'), ['response' => new Response(404), 'code' => 'NotFound']);
$store = $makeStore([new Result(), $missing(), new Result(), $missing()]);
$store->deleteCopies($object);
$check(array_column($commands, 'operation') === ['DeleteObject', 'HeadObject', 'DeleteObject', 'HeadObject'], 'Both deletions require confirmation; one missing copy is not enough.');
$store = $makeStore([new Result(), new Result($head)]);
$reject(static fn () => $store->deleteCopies($object), 'deletion is not confirmed');
$store = $makeStore([new S3Exception('secret provider response', new Command('HeadObject'), ['response' => new Response(403)])]);
$reject(static fn () => $store->seal($object), 'HeadObject failed');
ob_start();
var_dump($store);
$check(! str_contains(ob_get_clean(), 'fixture-access'), 'Debug output must never recurse into SDK credentials.');
echo $checks." Oracle object-store checks passed. Fake provider responses only; no network or media traffic.\n";
