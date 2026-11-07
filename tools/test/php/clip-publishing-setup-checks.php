<?php

require __DIR__.'/../../../vendor/autoload.php';

use App\Support\Clips\ClipPublisherSettingsWriter;
use App\Support\Clips\OdyseeAccountLogin;
use App\Support\Clips\OdyseeLoginFailure;
use Dotenv\Dotenv;
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
$reject = static function (callable $call) use ($check): void {
    try {
        $call();
        throw new LogicException('Unsafe setup was accepted.');
    } catch (RuntimeException|InvalidArgumentException $error) {
        $check(! str_contains($error->getMessage(), 'fixture-login-password') && $error->getPrevious() === null, 'Failed setup must not retain or expose sensitive provider details.');
    }
};
$token = 'fixture-login-token-not-real';
$email = 'fixture@example.invalid';
$password = 'fixture-login-password';
$channel = str_repeat('a', 40);
$json = static fn (array $data): Response => new Response(200, [], json_encode(['success' => true, 'data' => $data]));
$history = [];
$stack = HandlerStack::create(new MockHandler([
    $json(['auth_token' => $token])->withStatus(201), $json([]), $json(['has_verified_email' => true, 'primary_email' => $email]),
]));
$stack->push(Middleware::history($history));
$login = new OdyseeAccountLogin(new Client(['handler' => $stack]));
$check($login->authenticate($email, $password) === $token, 'A verified login must return the original newly authenticated session token.');
$check(count($history) === 3, 'Setup must use one bounded three-request login flow without retries.');
foreach ($history as $entry) {
    $request = $entry['request'];
    $check($request->getUri()->getHost() === 'api.odysee.com' && $request->getUri()->getScheme() === 'https' && $request->getUri()->getQuery() === '', 'Credentials must go only in HTTPS request bodies to the fixed official host, never URLs.');
    $check($entry['options']['verify'] === true && $entry['options']['allow_redirects'] === false, 'TLS must be verified and credential-bearing redirects forbidden.');
}
parse_str((string) $history[0]['request']->getBody(), $new);
parse_str((string) $history[1]['request']->getBody(), $signin);
$check(! isset($new['email']) && ! isset($new['password']) && str_starts_with($new['app_id'], 'crozztubeclips'), 'Client-session creation must not register an email account or pretend to be another app.');
$check((bool) preg_match('/^crozztubeclips[a-f0-9]{52}$/D', $new['app_id']), 'The live-rejected hyphenated app ID must not return: use a 66-character alphanumeric ID with random installation entropy.');
$check($signin['email'] === $email && $signin['password'] === $password && $signin['auth_token'] === $token, 'Authentication must bind the existing account to the newly created client session.');
foreach ([null, true] as $unusedSignInData) {
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([
        $json(['auth_token' => $token]),
        new Response(200, [], json_encode(['success' => true, 'data' => $unusedSignInData])),
        $json(['has_verified_email' => true, 'primary_email' => $email]),
    ]))]);
    $check((new OdyseeAccountLogin($client))->authenticate($email, $password) === $token, 'Ignore unused successful sign-in data as the web client does; verify identity through user/me.');
}
foreach ([
    [$json(['auth_token' => $token]), new Response(417, [], 'fixture-login-password')],
    [$json(['auth_token' => $token]), new Response(302, ['Location' => 'https://attacker.invalid'])],
    [$json(['auth_token' => "bad\ntoken"])],
    [$json(['auth_token' => $token]), $json([]), $json(['has_verified_email' => false, 'primary_email' => $email])],
    [$json(['auth_token' => $token]), $json([]), $json(['has_verified_email' => true, 'primary_email' => 'another@example.invalid'])],
    [new Response(200, [], '{broken')],
    [new Response(200, [], json_encode(['success' => false, 'data' => ['auth_token' => $token]]))],
    [new Response(200, [], str_repeat('x', 1048577))],
] as $responses) {
    $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    $reject(static fn () => (new OdyseeAccountLogin($client))->authenticate($email, $password));
}
// Invalid input must fail before any request can run.
$empty = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);
$reject(static fn () => (new OdyseeAccountLogin($empty))->authenticate('bad-address', $password));
$reject(static fn () => (new OdyseeAccountLogin($empty))->authenticate($email, ''));
$sessionHistory = [];
$stack = HandlerStack::create(new MockHandler([$json(['auth_token' => $token]), $json(['auth_token' => $token])]));
$stack->push(Middleware::history($sessionHistory));
$sessionLogin = new OdyseeAccountLogin(new Client(['handler' => $stack]));
$check($sessionLogin->checkSession() === null, 'The diagnostic must not return the new client token.');
$sessionLogin->checkSession();
$appIds = [];
foreach ($sessionHistory as $entry) {
    parse_str((string) $entry['request']->getBody(), $body);
    $appIds[] = $body['app_id'];
    $check($entry['request']->getUri()->getPath() === '/user/new' && ! isset($body['email']) && ! isset($body['password']), 'The preflight must never attempt account sign-in or ask for a password.');
}
$check($appIds[0] !== $appIds[1], 'Distinct setup sessions must get distinct random installation IDs.');
foreach ([
    ['session', 'http', 400, 'client session: request rejected (HTTP 400)'],
    ['signin', 'http', 401, 'account sign-in: request rejected (HTTP 401)'],
    ['signin', 'confirmation', 417, 'additional account confirmation required (HTTP 417)'],
    ['account', 'identity', null, 'account email is not confirmed or does not match'],
    [$token, $password, 9999, 'Odysee setup: unexpected provider response.'],
] as [$stage, $reason, $status, $expected]) {
    $error = new OdyseeLoginFailure($stage, $reason, $status);
    $check(str_contains($error->getMessage(), $expected) && ! str_contains($error->getMessage(), $token) && ! str_contains($error->getMessage(), $password) && $error->getPrevious() === null, 'Diagnostic text must identify only whitelisted stages, reasons and valid HTTP codes.');
}
$client = new Client(['handler' => HandlerStack::create(new MockHandler([
    new Response(400, [], json_encode(['success' => false, 'error' => 'app_id: invalid application ID. '.$token])),
]))]);
try {
    (new OdyseeAccountLogin($client))->checkSession();
    throw new LogicException('A rejected setup session was accepted.');
} catch (OdyseeLoginFailure $error) {
    $check(str_contains($error->getMessage(), 'client session: request rejected (HTTP 400)') && ! str_contains($error->getMessage(), $token), 'Live-observed session rejection must be distinguishable from a password failure, without printing the response.');
}
$directory = sys_get_temp_dir().'/crozztube-publisher-setup-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory.'/.env';
try {
    $original = "# keep this comment\r\nOTHER_VALUE='unchanged'\r\nCLIP_PUBLISH_ODYSEE_AUTH_TOKEN=\r\nCLIP_PUBLISH_ODYSEE_CHANNEL_ID=\r\n";
    file_put_contents($path, $original);
    $writer = new ClipPublisherSettingsWriter($path);
    $writer->save($token, $channel);
    $saved = file_get_contents($path);
    $settings = Dotenv::parse($saved);
    $check($settings['CLIP_PUBLISH_ODYSEE_AUTH_TOKEN'] === $token && $settings['CLIP_PUBLISH_ODYSEE_CHANNEL_ID'] === $channel, 'Only the account token and chosen channel must be saved correctly.');
    $check(str_starts_with($saved, "# keep this comment\r\nOTHER_VALUE='unchanged'\r\n"), 'Unrelated settings, comments and CRLF line endings must survive.');
    $check((fileperms($path) & 0777) === 0600, 'The resulting environment file must be owner-only.');
    $check(! str_contains($saved, $password) && ! str_contains($saved, $email), 'Account password and email must not be persisted.');
    $writer->save($token, $channel);
    $check(file_get_contents($path) === $saved, 'Repeating a setup with identical settings must not duplicate fields.');
    $reject(static fn () => $writer->save("bad'\nOTHER_VALUE=changed", $channel));
    $check(file_get_contents($path) === $saved, 'Environment injection must fail without changing the file.');
    foreach ([
        "CLIP_PUBLISH_ODYSEE_AUTH_TOKEN=x\n  export CLIP_PUBLISH_ODYSEE_AUTH_TOKEN=y\n",
        "CLIP_PUBLISH_ODYSEE_AUTH_TOKEN='first\nsecond'\n",
        "BROKEN='unclosed\n",
    ] as $bad) {
        file_put_contents($path, $bad);
        $reject(static fn () => $writer->save($token, $channel));
        $check(file_get_contents($path) === $bad, 'Unsafe existing environment contents must never be partially overwritten.');
    }
    unlink($path);
    $reject(static fn () => $writer->save($token, $channel));
    file_put_contents($directory.'/target', $original);
    symlink($directory.'/target', $path);
    $reject(static fn () => $writer->save($token, $channel));
    $check(file_get_contents($directory.'/target') === $original, 'A symlink must not redirect credential writes.');
    $check(count(glob($directory.'/.clip-env-*')) === 0, 'No temporary credential copies may remain.');
} finally {
    foreach ([$path, $directory.'/target'] as $file) {
        if (is_file($file) || is_link($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
}
echo $checks." Odysee interactive-setup checks passed. Fake sign-in only; no real account access or publishing.\n";
