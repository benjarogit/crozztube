<?php

require __DIR__.'/../../../vendor/autoload.php';

use App\Support\Clips\ClipStagingObject;
use App\Support\Clips\OdyseeImportFailure;
use App\Support\Clips\OdyseeRemoteUpload;
use App\Support\Clips\OdyseeUrlImporter;
use App\Support\Clips\OracleStagingConnection;
use App\Support\Clips\OracleStagingStore;
use Aws\MockHandler as AwsMock;
use Aws\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$settings = ['auth_token' => 'fixture-account-token-not-real', 'channel_id' => str_repeat('a', 40)];
$object = new ClipStagingObject('12345678-abcd-4abc-8abc-123456789abc', 7, hash('sha256', 'fixture'));
$oracle = new OracleStagingConnection(['region' => 'eu-frankfurt-1', 'namespace' => 'fixture', 'bucket' => 'fixture-clips',
    'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret-not-real']);
$store = static function (bool $valid = true) use ($object, $oracle): OracleStagingStore {
    $client = $oracle->client();
    $client->getHandlerList()->setHandler(new AwsMock([new Result([
        'ContentLength' => $valid ? $object->bytes : 8, 'ContentType' => 'video/mp4',
        'ChecksumSHA256' => $object->checksumBase64(), 'ETag' => '"fixture-etag"',
        'Metadata' => ['clip-job' => $object->jobId, 'clip-sha256' => $object->sha256],
    ])]));

    return new OracleStagingStore($oracle, $client);
};
$location = 'https://uploads-v4.cluster-alpha.odysee.com/v1/urls/';
$uploadId = str_repeat('b', 32);
$auth = static fn (array $changes = []): Response => new Response(200, [], json_encode(['status' => 'upload_token_created',
    'payload' => [...['token' => 'fixture-scoped-upload-token', 'location' => $location], ...$changes]]));
$registered = static fn (array $changes = []): Response => new Response(201, [], json_encode(['status' => 'url_created',
    'payload' => [...['upload_id' => $uploadId], ...$changes]]));
$history = [];
$http = static function (array $responses) use (&$history): Client {
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new Client(['handler' => $stack]);
};
$client = new OdyseeUrlImporter($settings, $http([$auth(), $registered()]));
$upload = $client->register($store(), $object);
$check($upload->filePath() === $location.$uploadId, 'Keep the exact provider reference for the subsequent SDK publication.');
$check(count($history) === 2, 'Registration is bounded to two requests without implicit retries.');
$check($history[0]['request']->getUri() == 'https://api.na-backend.odysee.com/api/v1/asynqueries/urls/', 'Authorize only at the fixed account API.');
$check($history[0]['request']->getHeaderLine('X-Lbry-Auth-Token') === $settings['auth_token'], 'The account token is used only for authorization.');
$check((string) $history[0]['request']->getBody() === '', 'No video or account password is submitted.');
$check($history[1]['request']->getUri() == $location, 'Use the exact allowlisted upload endpoint.');
$check(! $history[1]['request']->hasHeader('X-Lbry-Auth-Token')
    && $history[1]['request']->getHeaderLine('Authorization') === 'Bearer fixture-scoped-upload-token', 'Never forward the account token to the upload service.');
$payload = json_decode((string) $history[1]['request']->getBody(), true);
$check(array_keys($payload) === ['url'], 'Only a remote URL, no media bytes, is sent to Odysee.');
$check(parse_url($payload['url'], PHP_URL_HOST) === 'fixture.compat.objectstorage.eu-frankfurt-1.oci.customer-oci.com'
    && parse_url($payload['url'], PHP_URL_PATH) === '/fixture-clips/'.$object->handoffKey(), 'URL must be minted for the exact sealed handoff object, never supplied by a user.');
parse_str(parse_url($payload['url'], PHP_URL_QUERY), $query);
$check((int) $query['X-Amz-Expires'] === 1800, 'The import gets a bounded 30-minute read capability.');
foreach ($history as $entry) {
    $options = $entry['options'];
    $check($options['verify'] === true && $options['allow_redirects'] === false && $options['stream'] === true, 'TLS, no token-bearing redirects and bounded streamed response reads are mandatory.');
    $check($options['connect_timeout'] === 5 && $options['timeout'] === 20, 'External calls must have short finite deadlines.');
}
ob_start();
var_dump($client, $upload);
$debug = ob_get_clean().json_encode([$client, $upload]);
foreach ([$settings['auth_token'], 'fixture-scoped-upload-token', $uploadId, $payload['url'], 'fixture-secret-not-real'] as $sensitive) {
    $check(! str_contains($debug, $sensitive), 'Debug and JSON output must redact credentials and capabilities.');
}
$check(json_decode(json_encode($upload), true)['published'] === false, 'An accepted URL is explicitly not claimed to be published/playable.');
$reject = static function (array $responses, string $stage, bool $uncertain) use ($settings, $http, $store, $object, $check, &$history): void {
    $client = new OdyseeUrlImporter($settings, $http($responses));
    try {
        $client->register($store(), $object);
        throw new LogicException('Unsafe provider response accepted.');
    } catch (OdyseeImportFailure $error) {
        $check($error->stage === $stage && $error->outcomeUncertain() === $uncertain, 'Uncertain side effects must be distinguished from a safe rejection.');
        $check($error->getPrevious() === null && ! str_contains($error->getMessage(), 'fixture'), 'Errors must not retain signed requests, tokens or provider messages.');
        $check(count($history) === ($stage === 'authorization' ? 1 : 2), 'Failure must not retry or continue to a later operation.');
    }
};
foreach (['http://uploads-v4.cluster-alpha.odysee.com/v1/urls/', 'https://attacker.invalid/v1/urls/',
    $location.'../', 'https://uploads-v4.cluster-alpha.odysee.com.attacker.invalid/v1/urls/',
    'https://uploads-v4.cluster-alpha.odysee.com:443/v1/urls/', 'https://user@uploads-v4.cluster-alpha.odysee.com/v1/urls/'] as $invalid) {
    $reject([$auth(['location' => $invalid])], 'authorization', false);
}
foreach (['', "bad\ntoken", str_repeat('x', 4097), null] as $token) {
    $reject([$auth(['token' => $token])], 'authorization', false);
}
foreach ([new Response(302, ['Location' => 'https://attacker.invalid']), new Response(401, [], 'fixture-account-token-not-real'),
    new Response(200, [], '{bad'), new Response(200, [], str_repeat('x', 65537)),
    new Response(200, [], json_encode(['status' => 'upload_token_created', 'error' => 'fixture-secret']))] as $bad) {
    $reject([$bad], 'authorization', false);
}
foreach (['../escape', str_repeat('b', 31), str_repeat('b', 257), null, 'https://attacker.invalid/'.str_repeat('b', 32)] as $id) {
    $reject([$auth(), $registered(['upload_id' => $id])], 'registration', true);
}
foreach ([[new Response(503, [], 'fixture-secret'), true], [new Response(429), false],
    [new Response(302, ['Location' => 'https://attacker.invalid']), false],
    [new ConnectException('fixture-secret', new Request('POST', $location)), true],
    [new Response(200, [], '{bad'), true]] as [$response, $uncertain]) {
    $reject([$auth(), $response], 'registration', $uncertain);
}
foreach (['https://attacker.invalid/v1/urls/'.$uploadId, $location.'../'.$uploadId, $location.$uploadId.'?key=fixture', $location.$uploadId.'#fragment'] as $reference) {
    try {
        new OdyseeRemoteUpload($reference);
        throw new LogicException('Invalid reference accepted.');
    } catch (InvalidArgumentException) {
        $check(true, 'Persisted references require validation before reuse.');
    }
}
$client = new OdyseeUrlImporter($settings, $http([]));
try {
    $client->register($store(false), $object);
    throw new LogicException('Unsealed input accepted.');
} catch (RuntimeException $error) {
    $check(count($history) === 0 && str_contains($error->getMessage(), 'does not match'), 'Invalid Oracle metadata must prevent every Odysee request.');
}
echo $checks." Odysee URL-import checks passed. Mock responses only; no publication or media transfer.\n";
