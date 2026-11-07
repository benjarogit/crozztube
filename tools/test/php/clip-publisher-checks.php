<?php

require __DIR__.'/../../../vendor/autoload.php';

use App\Support\Clips\ClipStagingObject;
use App\Support\Clips\OdyseePublisher;
use App\Support\Clips\OdyseePublishingFailure;
use App\Support\Clips\OdyseeRemoteUpload;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$settings = ['auth_token' => 'fixture-account-token-not-real', 'channel_id' => str_repeat('a', 40)];
$object = new ClipStagingObject('12345678-abcd-4abc-8abc-123456789abc', 12051, str_repeat('f', 64));
$claimId = str_repeat('b', 40);
$queryId = str_repeat('a', 32).'1794074400123';
$upload = new OdyseeRemoteUpload('https://uploads-v4.cluster-alpha.odysee.com/v1/urls/'.str_repeat('z', 32));
$claim = ['claim_id' => $claimId, 'name' => OdyseePublisher::name($object->jobId), 'is_my_output' => true,
    'signing_channel' => ['claim_id' => $settings['channel_id']],
    'value' => ['tags' => ['c:unlisted', 'author-tag'], 'source' => ['size' => $object->bytes, 'media_type' => 'video/mp4'], 'video' => ['duration' => 5]]];
$rpc = static fn (array $data): Response => new Response(200, [], json_encode(['result' => $data]));
$history = [];
$make = static function (array $responses) use (&$history, $settings): OdyseePublisher {
    $history = [];
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));

    return new OdyseePublisher($settings, new Client(['handler' => $handler]));
};
$publisher = $make([new Response(201, [], json_encode(['status' => 'query_created', 'payload' => ['query_id' => $queryId]]))]);
$check($publisher->create($object, $upload, 'A personal clip') === $queryId, 'Keep the query ID for polling, never recreate a pending publication.');
$body = json_decode((string) $history[0]['request']->getBody(), true);
$check(is_int($body['id']), 'The live Odysee Go decoder rejects string JSON-RPC IDs; send a numeric ID.');
$check($body['method'] === 'stream_create' && $body['params']['file_path'] === $upload->filePath(), 'Publish only the registered remote handle.');
$check($body['params']['name'] === 'crozztube-clip-12345678abcd4abc8abc123456789abc' && $body['params']['channel_id'] === $settings['channel_id'], 'Name and channel must bind the publication to this job.');
$check($body['params']['tags'] === ['c:unlisted', 'crozztube-clip'] && $body['params']['release_time'] === 2147483647, 'New uploads stay unlisted during processing without notification.');
$check($body['params']['bid'] === '0.001' && ! isset($body['params']['fee_amount']), 'Use the webclient minimum deposit, without charging viewers.');
$options = $history[0]['options'];
$check($options['verify'] && ! $options['allow_redirects'] && $options['stream'] && $options['timeout'] === 20, 'Verify TLS and bound every request without redirects.');
foreach ([204 => 'pending', 404 => 'missing'] as $http => $state) {
    $publisher = $make([new Response($http)]);
    $check($publisher->status($queryId, $object) === ['state' => $state], 'A not-ready response is never playback readiness.');
    $check(count($history) === 1 && $history[0]['request']->getMethod() === 'GET', 'Poll without another publishing request.');
}
$transactionClaim = $claim;
unset($transactionClaim['is_my_output']);
$publisher = $make([$rpc(['outputs' => [$transactionClaim, ['type' => 'payment', 'amount' => '1.0']]]), $rpc(['items' => [$claim]])]);
$check($publisher->status($queryId, $object)['claim_id'] === $claimId, 'A successful query must match channel, job, owner and media metadata.');
$check(count($history) === 2, 'Real SDK change outputs and missing transaction owner flags require a separate account ownership check.');
$publisher = $make([$rpc(['items' => [$claim]])]);
$check($publisher->ownedClip($object->jobId, $claimId) === $claim, 'Use the authenticated account list for owner verification.');
$publicClaim = $claim;
$publicClaim['value']['tags'] = ['author-tag'];
$publisher = $make([$rpc(['items' => [$claim]]), $rpc(['txid' => 'fixture']), $rpc(['items' => [$publicClaim]])]);
$publisher->visibility($object->jobId, $claimId, true);
$body = json_decode((string) $history[1]['request']->getBody(), true);
$check($body['method'] === 'stream_update' && $body['params']['tags'] === ['author-tag'] && $body['params']['clear_tags'], 'Visibility changes preserve content tags while replacing only visibility markers.');
$check(! isset($body['params']['replace']) && ! isset($body['params']['title']) && ! isset($body['params']['file_path']), 'Visibility cannot overwrite author metadata or media.');
$check(count($history) === 3, 'Read ownership, update, then confirm visibility.');
$publisher = $make([$rpc(['items' => [$publicClaim]]), $rpc(['txid' => 'fixture']), $rpc(['items' => [$claim]])]);
$publisher->visibility($object->jobId, $claimId, false);
$body = json_decode((string) $history[1]['request']->getBody(), true);
$check(in_array('c:unlisted', $body['params']['tags'], true) && $body['params']['release_time'] === 2147483647, 'Private CrozzTube clips return to unlisted, never claimed encrypted/private at the host.');
$publisher = $make([$rpc(['items' => [$claim]]), $rpc(['txid' => 'fixture']), $rpc(['items' => []])]);
$publisher->abandon($object->jobId, $claimId);
$check(count($history) === 3 && json_decode((string) $history[1]['request']->getBody(), true)['method'] === 'stream_abandon', 'Deletion requires ownership and confirmed disappearance.');
$publisher = $make([$rpc(['items' => []]), $rpc(['items' => []])]);
$publisher->abandon($object->jobId, $claimId);
$check(count($history) === 2, 'Repeated deletion never creates a new transaction.');
$streamUrl = 'https://secure.odycdn.com/fixture-playback/clip.mp4?fixture=temporary';
$signed = ['signature' => str_repeat('d', 128), 'signing_ts' => '1794074400'];
$publisher = $make([$rpc(['items' => [$claim]]), $rpc($signed), $rpc(['streaming_url' => $streamUrl])]);
$check($publisher->playback($object->jobId, $claimId) === $streamUrl, 'Playback returns only a validated provider URL, never reads video bytes.');
$signBody = json_decode((string) $history[1]['request']->getBody(), true);
$getBody = json_decode((string) $history[2]['request']->getBody(), true);
$check($signBody['method'] === 'channel_sign' && $signBody['params']['channel_id'] === $settings['channel_id']
    && $signBody['params']['hexdata'] === bin2hex($claimId), 'Unlisted access signs exactly this verified clip ID using its own channel.');
$check($getBody['params']['signature'] === $signed['signature'] && $getBody['params']['signature_ts'] === $signed['signing_ts']
    && $getBody['params']['save_file'] === false, 'Match the official unlisted access flow without downloading into the app.');
$publisher = $make([$rpc(['items' => [$publicClaim]]), $rpc(['streaming_url' => $streamUrl])]);
$check($publisher->playback($object->jobId, $claimId) === $streamUrl && count($history) === 2, 'Public playback does not need a channel-signing request.');
$reject = static function (callable $call, string $reason) use ($check): void {
    try {
        $call();
        throw new LogicException('Unsafe publishing accepted.');
    } catch (OdyseePublishingFailure $error) {
        $check($error->reason === $reason && $error->getPrevious() === null
            && ! str_contains($error->getMessage(), 'fixture'), 'Failures retain only fixed, non-sensitive diagnostics.');
    }
};
foreach ([['name' => 'another-creator-video'], ['signing_channel' => ['claim_id' => str_repeat('c', 40)]], ['is_my_output' => false], ['claim_id' => 'invalid']] as $patch) {
    $publisher = $make([$rpc(['items' => [[...$claim, ...$patch]]])]);
    $reject(static fn () => $publisher->visibility($object->jobId, $claimId, true), 'identity');
    $check(count($history) === 1, 'Wrong ownership must prevent all changes and deletions.');
}
foreach ([['items' => [$claim, $claim]], ['items' => ['map' => $claim]], ['unexpected' => true]] as $data) {
    $publisher = $make([$rpc($data)]);
    $reject(static fn () => $publisher->ownedClip($object->jobId), 'response');
}
$wrongMedia = $claim;
$wrongMedia['value']['source']['size']++;
$publisher = $make([$rpc(['outputs' => [$wrongMedia]])]);
$reject(static fn () => $publisher->status($queryId, $object), 'media');
$publisher = $make([$rpc(['items' => [$claim]]), $rpc(['txid' => 'fixture']), $rpc(['items' => [$claim]])]);
$reject(static fn () => $publisher->visibility($object->jobId, $claimId, true), 'unconfirmed');
foreach ([[new Response(401), 'http'], [new Response(302, ['Location' => 'https://attacker.invalid']), 'http'],
    [new Response(200, [], json_encode(['error' => ['message' => 'fixture-account-token-not-real']])), 'provider'],
    [new Response(200, [], str_repeat('a', 262145)), 'response'], [new Response(200, [], 'not-json'), 'transport']] as [$response, $reason]) {
    $publisher = $make([$response]);
    $reject(static fn () => $publisher->create($object, $upload, 'Clip'), $reason);
    $check(count($history) === 1, 'Never automatically retry an ambiguous write.');
}
foreach ([-32700, -32600, -32601, -32602] as $code) {
    $publisher = $make([new Response(200, [], json_encode(['error' => ['code' => $code, 'message' => 'fixture-secret']]))]);
    $reject(static fn () => $publisher->create($object, $upload, 'Clip'), 'request');
}
foreach (['http://secure.odycdn.com/video', 'https://secure.odycdn.com.attacker.invalid/video',
    'https://attacker.invalid/video', 'https://user@secure.odycdn.com/video',
    'https://secure.odycdn.com:443/video', 'https://secure.odycdn.com/video#fragment'] as $badUrl) {
    $publisher = $make([$rpc(['items' => [$publicClaim]]), $rpc(['streaming_url' => $badUrl])]);
    $reject(static fn () => $publisher->playback($object->jobId, $claimId), 'response');
}
foreach ([['signature' => 'short'], ['signing_ts' => 'not-a-timestamp']] as $patch) {
    $publisher = $make([$rpc(['items' => [$claim]]), $rpc([...$signed, ...$patch])]);
    $reject(static fn () => $publisher->playback($object->jobId, $claimId), 'response');
    $check(count($history) === 2, 'A malformed access signature cannot be forwarded to the get endpoint.');
}
$publisher = $make([$rpc(['outputs' => [$transactionClaim, $transactionClaim]])]);
$reject(static fn () => $publisher->status($queryId, $object), 'response');
$publisher = $make([$rpc(['outputs' => [$transactionClaim]]), $rpc(['items' => []])]);
$reject(static fn () => $publisher->status($queryId, $object), 'identity');
foreach ([static fn () => OdyseePublisher::name('../another'),
    static fn () => $make([])->status('../escape', $object),
    static fn () => $make([])->ownedClip($object->jobId, 'bad'),
    static fn () => $make([])->create($object, $upload, ''),
    static fn () => $make([])->create($object, $upload, str_repeat('a', 201))] as $badInput) {
    try {
        $badInput();
        throw new LogicException('Invalid input accepted.');
    } catch (InvalidArgumentException) {
        $check(true, 'Validate every persisted reference and user input before requests.');
    }
}
ob_start(); var_dump($publisher); $debug = ob_get_clean().json_encode($publisher);
$check(! str_contains($debug, $settings['auth_token']), 'Debug and JSON must not expose account credentials.');
foreach ([['create', 'transport', null, true], ['create', 'response', 200, true], ['create', 'http', 503, true],
    ['create', 'http', 401, false], ['create', 'request', 200, false], ['status', 'transport', null, false],
    ['visibility', 'unconfirmed', null, true], ['abandon', 'transport', null, true]] as [$operation, $reason, $status, $uncertain]) {
    $check((new OdyseePublishingFailure($operation, $reason, $status))->outcomeUncertain() === $uncertain,
        'A queue must reconcile uncertain writes and must not confuse read failures with publication side effects.');
}
echo $checks." Odysee publisher checks passed. Mock responses only; no real publication or media.\n";
