<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://crozztube.local/'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    $message = get_class($error) === RuntimeException::class ? $error->getMessage() : get_class($error);
    fwrite(STDERR, 'Media provider API test failed: '.$message."\n");
    exit(1);
});

use App\Http\Controllers\Api\AdminMediaProvidersController;
use App\Models\User;
use App\Support\Clips\OdyseeAccountLogin;
use App\Support\Clips\OdyseePublishingConnection;
use App\Support\Clips\OracleStagingConnection;
use App\Support\MediaProviderSettings;
use App\Support\Thumbnails\Hosters;
use App\Support\Thumbnails\RemoteImages;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class FixtureMediaController extends AdminMediaProvidersController
{
    public int $oracleChecks = 0;
    public array $channels = [];

    protected function odyseeConnection(#[\SensitiveParameter] array $settings): OdyseePublishingConnection
    {
        $mock = new MockHandler([new Response(200, [], json_encode(['result' => ['items' => $this->channels]]))]);

        return new OdyseePublishingConnection($settings, new Client(['handler' => HandlerStack::create($mock)]));
    }

    protected function checkOracleAccess(OracleStagingConnection $connection): void
    {
        $this->oracleChecks++;
    }
}
final class FixtureAdminHosters extends Hosters
{
    public int $calls = 0;

    public function upload(string $provider, array $image, string $identity, float $deadline, bool $original = false): array
    {
        $this->calls++;
        if (! $original || $image['type'] !== 'image/png' || strlen($image['bytes']) > 1024 || $deadline > microtime(true) + 11) {
            throw new RuntimeException('Unbounded synthetic test.');
        }

        return ['url' => 'https://i.postimg.cc/fixture/test.png'];
    }
}
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$status = static function (callable $call): int {
    try {
        return $call()->getStatusCode();
    } catch (HttpExceptionInterface $error) {
        return $error->getStatusCode();
    } catch (ValidationException) {
        return 422;
    }
};
$directory = sys_get_temp_dir().'/crozztube-media-api-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
config(['media_providers.settings_path' => $directory.'/settings.enc', 'cache.default' => 'array',
    'clip_publishing.odysee' => ['auth_token' => 'fixture-env-token', 'channel_id' => str_repeat('a', 40)],
    'clip_staging.oci' => ['region' => 'eu-frankfurt-1', 'namespace' => 'fixture', 'bucket' => 'fixture-private', 'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret'],
    'thumbnails.providers.fastpic.key' => 'fixture-env-image-key']);
Log::swap(new Psr\Log\NullLogger());
$admin = new User(['settings' => ['admin' => true]]); $admin->id = 99101;
$otherAdmin = new User(['settings' => ['admin' => true]]); $otherAdmin->id = 99102;
$viewer = new User(['settings' => []]); $viewer->id = 99103;
$moderator = new User(['settings' => ['role' => 'moderator']]); $moderator->id = 99104;
$request = static function (?User $user, array $data = [], bool $secure = true): Request {
    $request = Request::create(($secure ? 'https' : 'http').'://crozztube.local/api/admin/media-providers', 'POST', $data);
    $request->headers->set('Accept', 'application/json');
    $request->setUserResolver(static fn () => $user);

    return $request;
};
$controller = new FixtureMediaController();
$hosters = new FixtureAdminHosters(new RemoteImages());
$token = 'fixture-account-token-not-real';
$channel = str_repeat('b', 40);
$controller->channels = [['claim_id' => $channel, 'name' => '@crozztube-fixture']];
$newLogin = static function () use ($token): OdyseeAccountLogin {
    $json = static fn (array $data): Response => new Response(200, [], json_encode(['success' => true, 'data' => $data]));
    $responses = [$json(['auth_token' => $token]), $json([]), $json(['has_verified_email' => true, 'primary_email' => 'admin@example.invalid'])];

    return new OdyseeAccountLogin(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
};
try {
    foreach ([null, $viewer, $moderator] as $actor) {
        $check($status(static fn () => $controller->index($request($actor))) === 403, 'Guests, viewers and moderators must not read provider settings.');
        $check($status(static fn () => $controller->update($request($actor, ['changes' => ['key' => 'injected']]), 'fastpic')) === 403, 'Unauthorized updates must fail before writing credentials.');
        $check($status(static fn () => $controller->connectOdysee($request($actor), $newLogin())) === 403, 'Unauthorized callers must not begin an external account login.');
        $check($status(static fn () => $controller->test($request($actor), 'fastpic', $hosters)) === 403, 'Unauthorized callers must not trigger a provider request.');
    }
    $read = $controller->index($request($admin));
    $check($read->getStatusCode() === 200 && str_contains($read->headers->get('Cache-Control'), 'no-store'), 'Credential configuration responses must never be cached.');
    $check(! str_contains($read->getContent(), 'fixture-env-token') && ! str_contains($read->getContent(), 'fixture-secret'), 'Admin API must expose only presence flags, never stored credentials.');
    $check($status(static fn () => $controller->update($request($admin, ['changes' => ['key' => 'fixture-updated-key']]), 'fastpic')) === 200, 'An administrator can change image credentials.');
    $check($status(static fn () => $controller->update($request($admin, ['changes' => [], 'clear_secrets' => ['key']]), 'fastpic')) === 200, 'Explicit secret removal must work even with no other changes.');
    $check(MediaProviderSettings::current()->effective('fastpic')['key'] === '', 'Explicit removal must suppress the old .env key.');
    $check($status(static fn () => $controller->update($request($admin, ['changes' => ['endpoint' => 'https://attacker.invalid']]), 'fastpic')) === 422, 'Administrator forms must not introduce arbitrary credential destinations.');
    $check($status(static fn () => $controller->connectOdysee($request($admin, ['email' => 'admin@example.invalid', 'password' => 'fixture-hidden-password'], false), $newLogin())) === 422, 'Account sign-in must require HTTPS.');
    $connected = $controller->connectOdysee($request($admin, ['email' => 'admin@example.invalid', 'password' => 'fixture-hidden-password']), $newLogin());
    $payload = $connected->getData(true);
    $check($connected->getStatusCode() === 200 && count($payload['channels']) === 1 && $payload['expires_in'] === 300, 'Successful sign-in must offer a bounded, short-lived channel selection.');
    $check(! str_contains($connected->getContent(), $token) && ! str_contains($connected->getContent(), 'fixture-hidden-password'), 'Login results must never send the account token or password back to the browser.');
    $id = $payload['connection_id'];
    $pendingKey = 'admin-media-odysee:'.$admin->id.':'.$id;
    $pending = Cache::get($pendingKey);
    $check(is_string($pending) && ! str_contains($pending, $token), 'Pending credentials must be encrypted even in cache.');
    $save = ['changes' => ['channel_id' => $channel], 'connection_id' => $id];
    $check($status(static fn () => $controller->update($request($otherAdmin, $save), 'odysee')) === 422, 'A pending login is bound to the administrator who created it.');
    $check($status(static fn () => $controller->update($request($admin, ['changes' => ['channel_id' => str_repeat('c', 40)], 'connection_id' => $id]), 'odysee')) === 422, 'A login must not be assigned to a channel outside its account.');
    $check($status(static fn () => $controller->update($request($admin, $save), 'odysee')) === 200, 'The owner can persist the authenticated token with an owned selected channel.');
    $settings = MediaProviderSettings::current()->effective('odysee');
    $check($settings['auth_token'] === $token && $settings['channel_id'] === $channel && Cache::get($pendingKey) === null, 'Save must persist the verified pair and consume the pending session.');
    $check($status(static fn () => $controller->update($request($admin, $save), 'odysee')) === 422, 'A consumed or expired pending login cannot be reused.');
    $check($status(static fn () => $controller->test($request($admin), 'odysee', $hosters)) === 200, 'Saved Odysee access tests must check owned channel selection.');
    $check($status(static fn () => $controller->test($request($admin), 'oracle', $hosters)) === 200 && $controller->oracleChecks === 1, 'Saved Oracle tests must perform exactly the bucket access check.');
    $check($status(static fn () => $controller->test($request($admin), 'fastpic', $hosters)) === 422 && $hosters->calls === 0, 'Unconfigured image providers must not send test uploads.');
    MediaProviderSettings::current()->save('postimages', ['enabled' => true, 'key' => 'fixture-image-key']);
    $check($status(static fn () => $controller->test($request($admin), 'postimages', $hosters)) === 200 && $hosters->calls === 1, 'An explicit image test uses a bounded synthetic image, never user content.');
    file_put_contents($directory.'/settings.enc', 'corrupt fixture');
    $check($controller->index($request($admin))->getStatusCode() === 503, 'Corrupt encrypted settings must return a safe failure, not old environment secrets.');
    foreach ([['GET', '/api/admin/media-providers'], ['PUT', '/api/admin/media-providers/oracle'], ['POST', '/api/admin/media-providers/odysee/connect'], ['POST', '/api/admin/media-providers/oracle/test']] as [$method, $path]) {
        $route = app('router')->getRoutes()->match(Request::create('https://crozztube.local'.$path, $method));
        $check(in_array('auth:sanctum', $route->gatherMiddleware(), true), 'All provider endpoints must remain inside the authenticated API group.');
    }
} finally {
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
echo $checks." admin media-provider API checks passed. No external requests, database writes or real credential changes.\n";
