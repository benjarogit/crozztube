<?php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
use App\Models\ClipTransfer;
use App\Support\Clips\OdyseePublisher;
use App\Support\Clips\OdyseePublishingFailure;
use App\Support\Clips\OdyseeTransferGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
$checks = 0; $history = [];
$check = static function (bool $ok, string $message) use (&$checks): void { if (! $ok) throw new RuntimeException($message); $checks++; };
$settings = ['auth_token' => 'fixture-not-a-real-token', 'channel_id' => str_repeat('a', 40)];
$transfer = new ClipTransfer(['uuid' => '12345678-abcd-4abc-8abc-123456789abc', 'bytes' => 12051,
    'sha256' => str_repeat('f', 64), 'provider_state' => ['query' => str_repeat('a', 32).'1794074400123']]);
$claim = ['claim_id' => str_repeat('b', 40), 'name' => OdyseePublisher::name($transfer->uuid), 'is_my_output' => true,
    'signing_channel' => ['claim_id' => $settings['channel_id']],
    'value' => ['source' => ['size' => 12051, 'media_type' => 'video/mp4'], 'video' => ['duration' => 5]]];
$rpc = static fn (array $data): Response => new Response(200, [], json_encode(['result' => $data]));
$make = static function (array $responses) use (&$history, $settings): OdyseeTransferGateway {
    $history = []; $handler = HandlerStack::create(new MockHandler($responses)); $handler->push(Middleware::history($history));
    return new OdyseeTransferGateway(new OdyseePublisher($settings, new Client(['handler' => $handler])));
};
$check($make([new Response(204)])->publication($transfer) === null && count($history) === 1, 'Pending queries stay pending without another create request.');
$check($make([new Response(404), $rpc(['items' => [$claim]])])->publication($transfer) === $claim['claim_id'], 'Expired query receipts reconcile the original owned clip.');
$check(count($history) === 2 && str_ends_with($history[1]['request']->getUri()->getQuery(), 'stream_list'), 'Reconciliation is a read-only account lookup.');
$check($make([new Response(404), $rpc(['items' => []])])->publication($transfer) === null, 'Missing query and absent claim do not imply failure or readiness.');
$wrong = $claim; $wrong['value']['source']['size']++;
try { $make([new Response(404), $rpc(['items' => [$wrong]])])->publication($transfer); throw new RuntimeException('Incorrect source accepted.'); }
catch (OdyseePublishingFailure $e) { $check($e->reason === 'media', 'Reconciliation preserves the exact clip media identity.'); }
$make([new Response(404), $rpc(['items' => [$claim]]), $rpc(['items' => [$claim]]), $rpc([]), $rpc(['items' => []])])->abandon($transfer);
$check(count($history) === 5 && str_ends_with($history[3]['request']->getUri()->getQuery(), 'stream_abandon'), 'A late owned claim can be canceled after its query receipt expires.');
try { $make([new Response(404), $rpc(['items' => []])])->abandon($transfer); throw new RuntimeException('Uncertain cancellation ended prematurely.'); }
catch (OdyseePublishingFailure $e) { $check($e->reason === 'outcome_uncertain', 'Continue cancellation reconciliation when a late claim remains possible.'); }
echo $checks." clip gateway checks passed. Expired query receipts and late cancellation; mocked metadata only.\n";
