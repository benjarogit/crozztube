<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;

class KlipyStickerSettings
{
    public static function settings(): array
    {
        $defaults = [
            'enabled' => (bool) config('klipy.enabled', true),
            'api_key' => trim((string) config('klipy.app_key', '')),
            'locale' => (string) config('klipy.locale', 'en_US'),
            'content_filter' => (string) config('klipy.content_filter', 'high'),
            'live_price_cents' => (int) config('klipy.live_price_cents', 100),
            'per_page' => (int) config('klipy.per_page', 24),
        ];
        $path = (string) config('klipy.settings_path');
        $stored = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $stored = is_array($stored) ? $stored : [];
        $settings = array_replace($defaults, array_intersect_key($stored, $defaults));
        // A saved empty value deliberately overrides an environment-provided key.
        if (array_key_exists('api_key_encrypted', $stored)) {
            try {
                $settings['api_key'] = $stored['api_key_encrypted'] !== '' ? Crypt::decryptString($stored['api_key_encrypted']) : '';
            } catch (\Throwable) {
                $settings['api_key'] = '';
            }
        }
        $settings['enabled'] = (bool) $settings['enabled'];
        $settings['live_price_cents'] = (int) $settings['live_price_cents'];
        $settings['per_page'] = (int) $settings['per_page'];

        return $settings;
    }

    public static function adminSettings(): array
    {
        $settings = self::settings();
        $settings['api_key_configured'] = $settings['api_key'] !== '';
        $settings['api_key'] = '';
        $settings['provider'] = 'klipy';
        $settings['transport'] = 'direct';

        return $settings;
    }

    public static function isAvailable(): bool
    {
        $settings = self::settings();

        return $settings['enabled'] && $settings['api_key'] !== '';
    }

    public static function save(array $changes): void
    {
        $settings = self::settings();
        foreach (['enabled', 'locale', 'content_filter', 'live_price_cents', 'per_page'] as $field) {
            if (array_key_exists($field, $changes)) {
                $settings[$field] = $changes[$field];
            }
        }
        if ((bool) ($changes['clear_api_key'] ?? false)) {
            $settings['api_key'] = '';
        } elseif (trim((string) ($changes['api_key'] ?? '')) !== '') {
            $settings['api_key'] = trim($changes['api_key']);
        }
        $key = $settings['api_key'];
        unset($settings['api_key']);
        $settings['api_key_encrypted'] = $key !== '' ? Crypt::encryptString($key) : '';
        $path = (string) config('klipy.settings_path');
        File::ensureDirectoryExists(dirname($path), 0700, true);
        File::replace($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, 0600);
    }
}
