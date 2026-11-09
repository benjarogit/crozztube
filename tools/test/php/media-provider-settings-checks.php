<?php

require __DIR__.'/../../../vendor/autoload.php';

use App\Support\MediaProviderSettings;
use Illuminate\Encryption\Encrypter;

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
        throw new LogicException('Unsafe settings were accepted.');
    } catch (InvalidArgumentException|RuntimeException $error) {
        $check($error->getPrevious() === null && ! str_contains($error->getMessage(), 'fixture-account-secret'), 'Errors must not expose credential input or retained exceptions.');
    }
};
$directory = sys_get_temp_dir().'/crozztube-media-settings-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory.'/private/providers.enc';
$defaults = [
    'fastpic' => ['key' => 'fixture-account-secret', 'enabled' => true, 'supported' => true, 'priority' => 2],
    'postimages' => ['key' => 'fixture-postimages-secret', 'enabled' => true, 'supported' => true],
    'oracle' => ['region' => 'eu-frankfurt-1', 'namespace' => 'fixture', 'bucket' => 'fixture-bucket', 'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-account-secret'],
    'odysee' => ['auth_token' => 'fixture-account-secret', 'channel_id' => str_repeat('a', 40)],
];
$key = random_bytes(32);
$store = new MediaProviderSettings($path, $defaults, new Encrypter($key, 'AES-256-CBC'));
try {
    $check($store->effective('fastpic')['key'] === 'fixture-account-secret', 'Existing environment credentials must remain the fallback.');
    $public = $store->adminSettings();
    $check($public['fastpic']['key'] === '' && $public['fastpic']['configured_secrets']['key'], 'Only credential presence, never its value, may reach an administrator response.');
    $check($public['oracle']['access_key_id'] === '' && $public['oracle']['secret_access_key'] === '', 'Both Oracle signing identifiers must stay write-only.');
    $check(! str_contains(json_encode($public), 'fixture-account-secret'), 'No credential may leak through the full settings response.');
    ob_start(); var_dump($store); $debug = ob_get_clean();
    $check(! str_contains($debug, 'fixture-account-secret'), 'Debugging must not recurse into configuration defaults.');
    $store->save('fastpic', ['key' => '', 'enabled' => false]);
    $check($store->effective('fastpic')['key'] === 'fixture-account-secret' && ! $store->effective('fastpic')['enabled'], 'An empty password field preserves its environment fallback while other fields change.');
    $store->save('fastpic', ['key' => 'fixture-rotated-secret']);
    $check($store->effective('fastpic')['key'] === 'fixture-rotated-secret', 'A newly supplied key replaces the environment fallback.');
    $store->save('fastpic', ['key' => '', 'day_limit' => 17]);
    $check($store->effective('fastpic')['key'] === 'fixture-rotated-secret', 'An empty password field preserves an encrypted saved key.');
    $check($store->effective('fastpic')['priority'] === 2, 'Provider priority and unrelated configuration must remain unchanged.');
    $store->save('oracle', ['bucket' => 'new-private-bucket', 'secret_access_key' => '']);
    $check($store->effective('oracle')['secret_access_key'] === 'fixture-account-secret' && $store->effective('oracle')['bucket'] === 'new-private-bucket', 'Changing public bucket metadata must not blank Oracle credentials.');
    $check($store->effective('fastpic')['day_limit'] === 17, 'Saving one provider must preserve changes to other providers.');
    $store->save('fastpic', [], ['key']);
    $check($store->effective('fastpic')['key'] === '' && ! $store->adminSettings()['fastpic']['configured_secrets']['key'], 'Explicit clear must override, not reactivate, the old environment key.');
    $store->save('fastpic', ['key' => '']);
    $check($store->effective('fastpic')['key'] === '', 'A later empty form must not undo an explicit credential clear.');
    $store->save('odysee', ['auth_token' => 'fixture-odysee-new', 'channel_id' => str_repeat('b', 40)]);
    $fresh = new MediaProviderSettings($path, $defaults, new Encrypter($key, 'AES-256-CBC'));
    $check($fresh->effective('odysee')['auth_token'] === 'fixture-odysee-new', 'New requests and workers must read updated credentials without rewriting .env or restarting.');
    if (function_exists('pcntl_fork')) {
        $children = [];
        foreach (['minute_limit' => 13, 'day_limit' => 19] as $name => $value) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Test worker could not be started.');
            }
            if ($pid === 0) {
                try {
                    $store->save('fastpic', [$name => $value]);
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Concurrent settings writers must finish successfully.');
        }
        $effective = $fresh->effective('fastpic');
        $check($effective['minute_limit'] === 13 && $effective['day_limit'] === 19, 'Concurrent updates to distinct fields must not overwrite one another.');
    }
    $ciphertext = file_get_contents($path);
    foreach (['fixture-rotated-secret', 'fixture-account-secret', 'fixture-odysee-new', 'new-private-bucket'] as $value) {
        $check(! str_contains($ciphertext, $value), 'Saved settings must be encrypted at rest.');
    }
    $check((fileperms($path) & 0777) === 0600 && (fileperms(dirname($path)) & 0777) === 0700, 'New files and directories must have private permissions.');
    foreach ([
        ['unknown', [], []], ['fastpic', ['endpoint' => 'https://attacker.invalid'], []],
        ['fastpic', ['enabled' => 'yes'], []], ['fastpic', ['key' => "fixture-account-secret\nheader"], []],
        ['fastpic', ['day_limit' => 0], []], ['fastpic', ['day_limit' => 1000001], []],
        ['oracle', [], ['bucket']], ['fastpic', [], [['key']]],
    ] as [$id, $changes, $clear]) {
        $reject(static fn () => $store->save($id, $changes, $clear));
        $check(file_get_contents($path) === $ciphertext, 'Invalid updates must leave the saved configuration untouched.');
    }
    $reject(static fn () => (new MediaProviderSettings($path, $defaults, new Encrypter(random_bytes(32), 'AES-256-CBC')))->effective('fastpic'));
    file_put_contents($path, 'corrupt encrypted fixture');
    $reject(static fn () => $store->effective('fastpic'));
    $reject(static fn () => $store->save('fastpic', ['key' => 'new-key']));
    $check(file_get_contents($path) === 'corrupt encrypted fixture', 'Corruption must fail closed and never fall back to an old key or overwrite the evidence.');
    unlink($path);
    symlink($directory.'/outside', $path);
    $reject(static fn () => $store->effective('fastpic'));
    $reject(static fn () => $store->save('fastpic', ['key' => 'new-key']));
    $check(! file_exists($directory.'/outside'), 'A credential update must not follow a symbolic link.');
    $check(glob(dirname($path).'/.media-provider-*') === [], 'No temporary plaintext or encrypted credential files may be abandoned.');
} finally {
    foreach (glob(dirname($path).'/*') as $file) {
        unlink($file);
    }
    rmdir(dirname($path)); rmdir($directory);
}
echo $checks." media-provider settings checks passed. Isolated encrypted fixtures only.\n";
