<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\Encrypter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Encrypted administrator overrides. Environment settings remain the fallback. */
final class MediaProviderSettings
{
    public const FIELDS = [
        'directupload' => ['enabled' => 'bool', 'minute_limit' => 'limit', 'day_limit' => 'limit'],
        'fastpic' => ['enabled' => 'bool', 'key' => 'secret', 'minute_limit' => 'limit', 'day_limit' => 'limit'],
        'postimages' => ['enabled' => 'bool', 'key' => 'secret', 'client_o' => 'text', 'client_m' => 'text', 'minute_limit' => 'limit', 'day_limit' => 'limit', 'month_limit' => 'limit'],
        'oracle' => ['region' => 'text', 'namespace' => 'text', 'bucket' => 'text', 'access_key_id' => 'secret', 'secret_access_key' => 'secret'],
        'odysee' => ['channel_id' => 'text', 'auth_token' => 'secret'],
    ];

    public function __construct(private string $path, #[\SensitiveParameter] private array $defaults, private Encrypter $encrypter) {}

    public static function current(): self
    {
        $defaults = [];
        foreach ((array) config('thumbnails.providers', []) as $id => $settings) {
            $defaults[$id] = [...$settings, 'enabled' => (bool) ($settings['supported'] ?? false)];
        }
        $defaults['oracle'] = (array) config('clip_staging.oci', []);
        $defaults['odysee'] = (array) config('clip_publishing.odysee', []);

        return new self((string) config('media_providers.settings_path', storage_path('app/private/media-provider-settings.enc')), $defaults, app('encrypter'));
    }

    public function effective(string $provider): array
    {
        $this->assertProvider($provider);

        return array_replace($this->defaults[$provider] ?? [], $this->read()[$provider] ?? []);
    }

    public static function imageProviders(): array
    {
        $store = self::current();
        $providers = (array) config('thumbnails.providers', []);
        foreach ($providers as $id => &$settings) {
            if (isset(self::FIELDS[$id])) {
                $settings = $store->effective($id);
                $settings['supported'] = (bool) ($settings['supported'] ?? false) && (bool) ($settings['enabled'] ?? false);
            }
        }

        return $providers;
    }

    public function adminSettings(): array
    {
        $saved = $this->read();
        $result = [];
        foreach (self::FIELDS as $id => $fields) {
            $effective = array_replace($this->defaults[$id] ?? [], $saved[$id] ?? []);
            $public = ['configured_secrets' => [], 'saved_override' => array_key_exists($id, $saved)];
            foreach ($fields as $name => $type) {
                if ($type === 'secret') {
                    $public[$name] = '';
                    $public['configured_secrets'][$name] = trim((string) ($effective[$name] ?? '')) !== '';
                } else {
                    $public[$name] = $effective[$name] ?? ($type === 'bool' ? false : '');
                }
            }
            $result[$id] = $public;
        }

        return $result;
    }

    /** Blank secrets preserve their saved value. Explicit clears override .env. */
    public function save(string $provider, #[\SensitiveParameter] array $changes, array $clearSecrets = []): void
    {
        $this->assertProvider($provider);
        $fields = self::FIELDS[$provider];
        foreach ($changes as $name => $value) {
            if (! isset($fields[$name])) {
                throw new InvalidArgumentException('Unknown media provider setting.');
            }
            $type = $fields[$name];
            if ($type === 'bool' && ! is_bool($value)
                || $type === 'limit' && (! is_int($value) || $value < 1 || $value > 1000000)
                || in_array($type, ['text', 'secret'], true) && (! is_string($value) || strlen($value) > ($type === 'secret' ? 2048 : 512) || preg_match('/[\x00-\x20\x7f]/', $value))) {
                throw new InvalidArgumentException('Invalid media provider setting format.');
            }
        }
        foreach ($clearSecrets as $name) {
            if (! is_string($name) || ($fields[$name] ?? null) !== 'secret') {
                throw new InvalidArgumentException('Only credential fields can be explicitly cleared.');
            }
        }
        $this->ensureDirectory();
        $lockPath = $this->path.'.lock';
        if (is_link($this->path) || is_link($lockPath)) {
            throw new RuntimeException('Media provider settings cannot use symbolic links.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Media provider settings lock is unavailable.');
        }
        chmod($lockPath, 0600);
        $temporary = null;
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Media provider settings are busy.');
            }
            $saved = $this->read();
            $current = $saved[$provider] ?? [];
            foreach ($changes as $name => $value) {
                if ($fields[$name] !== 'secret' || $value !== '') {
                    $current[$name] = $value;
                }
            }
            foreach ($clearSecrets as $name) {
                $current[$name] = '';
            }
            $saved[$provider] = $current;
            $ciphertext = $this->encrypter->encryptString(json_encode($saved, JSON_THROW_ON_ERROR));
            $temporary = tempnam(dirname($this->path), '.media-provider-');
            if ($temporary === false || ! chmod($temporary, 0600) || file_put_contents($temporary, $ciphertext, LOCK_EX) !== strlen($ciphertext)
                || ! rename($temporary, $this->path)) {
                throw new RuntimeException('Media provider settings could not be saved.');
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(): array
    {
        if (is_link($this->path)) {
            throw new RuntimeException('Encrypted media provider settings cannot use symbolic links.');
        }
        if (! file_exists($this->path)) {
            return [];
        }
        try {
            if (is_link($this->path) || filesize($this->path) > 65536) {
                throw new RuntimeException();
            }
            $saved = json_decode($this->encrypter->decryptString(file_get_contents($this->path)), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($saved) || array_diff(array_keys($saved), array_keys(self::FIELDS))) {
                throw new RuntimeException();
            }
            foreach ($saved as $id => $values) {
                if (! is_array($values) || array_diff(array_keys($values), array_keys(self::FIELDS[$id]))) {
                    throw new RuntimeException();
                }
            }

            return $saved;
        } catch (Throwable) {
            // Do not silently reactivate environment credentials after corruption.
            throw new RuntimeException('Encrypted media provider settings could not be read.');
        }
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Private media provider settings directory is unavailable.');
        }
    }

    private function assertProvider(string $provider): void
    {
        if (! isset(self::FIELDS[$provider])) {
            throw new InvalidArgumentException('Unknown media provider.');
        }
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'encrypted-media-provider-settings'];
    }
}
