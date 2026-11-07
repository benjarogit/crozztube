<?php

require __DIR__.'/../../../vendor/autoload.php';

use App\Support\Clips\OdyseePublishingConnection;
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
$settings = ['auth_token' => 'fixture-token-not-real', 'channel_id' => str_repeat('a', 40)];
$connection = new OdyseePublishingConnection($settings);
$check($connection->problems() === [], 'Valid dedicated publishing settings must be accepted.');
$check(count((new OdyseePublishingConnection([]))->problems()) === 2, 'Both missing fields must be diagnosed.');
$check((new OdyseePublishingConnection(['auth_token' => 'fixture']))->problems(false) === [], 'Token-only channel discovery must be possible.');
foreach ([['auth_token', "bad\nheader"], ['auth_token', []], ['auth_token', str_repeat('x', 2049)], ['channel_id', '@name'], ['channel_id', str_repeat('b', 39)], ['channel_id', 'https://other.invalid']] as [$field, $value]) {
    $check((new OdyseePublishingConnection([...$settings, $field => $value]))->problems() !== [], 'Malformed settings must be rejected.');
}
ob_start();
var_dump($connection);
$debug = ob_get_clean();
$check(! str_contains($debug.json_encode($connection), $settings['auth_token']), 'Diagnostics must redact the account token.');
$mock = new MockHandler([new Response(200, [], json_encode(['result' => ['items' => [
    ['claim_id' => $settings['channel_id'], 'name' => '@test-channel'],
    ['claim_id' => str_repeat('b', 40), 'name' => '@other-channel'],
]]]))]);
$history = [];
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$channels = (new OdyseePublishingConnection($settings, new Client(['handler' => $stack])))->ownedChannels();
$check(count($channels) === 2 && $channels[0]['selected'] && ! $channels[1]['selected'], 'Only the configured owned channel is selected.');
$request = $history[0]['request'];
$options = $history[0]['options'];
$check($request->getUri()->getScheme() === 'https' && $request->getUri()->getHost() === 'api.na-backend.odysee.com', 'The account token goes only to the fixed Odysee API host.');
$check($request->getHeaderLine('X-Lbry-Auth-Token') === $settings['auth_token'], 'The official legacy token header must be used.');
$check(($options['allow_redirects'] ?? null) === false, 'Redirects must not forward the token.');
$check(($options['verify'] ?? null) === true && ($options['stream'] ?? null) === true, 'TLS verification and bounded streaming reads must remain enabled.');
$payload = json_decode((string) $request->getBody(), true);
$check($payload['method'] === 'channel_list' && $payload['params']['page_size'] === 100, 'Discovery must use a bounded account channel list, never publish.');
foreach ([
    new Response(401, [], 'secret response'),
    new Response(302, ['Location' => 'https://attacker.invalid']),
    new Response(200, [], json_encode(['error' => ['message' => $settings['auth_token']]])),
    new Response(200, [], '{broken'),
    new Response(200, [], json_encode(['result' => ['items' => ['unexpected' => 'map']]])),
    new Response(200, [], json_encode(['result' => ['items' => [['claim_id' => 'invalid', 'name' => '@x']]]])),
    new Response(200, [], str_repeat('x', 1048577)),
] as $response) {
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);
    try {
        (new OdyseePublishingConnection($settings, $client))->ownedChannels();
        throw new LogicException('An unsafe provider response was accepted.');
    } catch (RuntimeException $error) {
        $check(! $error instanceof LogicException && ! str_contains($error->getMessage(), $settings['auth_token']) && $error->getPrevious() === null, 'Provider errors must fail closed without retaining secrets.');
    }
}
$page = array_fill(0, 100, ['claim_id' => str_repeat('b', 40), 'name' => '@fixture']);
$client = new Client(['handler' => HandlerStack::create(new MockHandler([
    new Response(200, [], json_encode(['result' => ['items' => $page]])),
    new Response(200, [], json_encode(['result' => ['items' => [['claim_id' => $settings['channel_id'], 'name' => '@target']]]])),
]))]);
$channels = (new OdyseePublishingConnection($settings, $client))->ownedChannels();
$check(count($channels) === 2 && $channels[1]['selected'], 'Later pages must be checked and duplicate IDs deduplicated.');
$responses = array_map(static fn (): Response => new Response(200, [], json_encode(['result' => ['items' => $page]])), range(1, 10));
$client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
try {
    (new OdyseePublishingConnection($settings, $client))->ownedChannels();
    throw new LogicException('Unbounded pagination was accepted.');
} catch (RuntimeException $error) {
    $check(str_contains($error->getMessage(), 'page limit'), 'Provider pagination must stop after ten pages.');
}
echo $checks." Odysee publishing configuration checks passed. No network requests, media or database changes.\n";
