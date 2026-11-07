<?php

require __DIR__.'/../../../vendor/autoload.php';

use App\Support\Clips\ClipPublisherSettingsWriter;
use App\Support\Clips\OdyseeAccountLogin;
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
$check(! isset($new['email']) && ! isset($new['password']) && str_starts_with($new['app_id'], 'crozztube-clips-'), 'Client-session creation must not register an email account or pretend to be another app.');
$check($signin['email'] === $email && $signin['password'] === $password && $signin['auth_token'] === $token, 'Authentication must bind the existing account to the newly created client session.');
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
