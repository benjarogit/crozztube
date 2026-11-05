<?php

namespace App\Support;

class PlatformFeatureSettings
{
    public static function settings(): array
    {
        $path = self::settingsPath();
        $stored = is_file($path) && is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];
        $uploadRenditions = data_get($stored, 'uploads.renditions');
        $uploadRoles = data_get($stored, 'publishing.uploadRoles');
        $liveRoles = data_get($stored, 'publishing.liveRoles');
        $settings = array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []);
        if (is_array($uploadRenditions)) {
            $settings['uploads']['renditions'] = array_values($uploadRenditions);
        }
        if (is_array($uploadRoles)) {
            $settings['publishing']['uploadRoles'] = array_values($uploadRoles);
        }
        if (is_array($liveRoles)) {
            $settings['publishing']['liveRoles'] = array_values($liveRoles);
        }

        return $settings;
    }

    public static function adminSettings(): array
    {
        return self::settings();
    }

    public static function publicSettings(?string $ip = null): array
    {
        $settings = self::settings();
        $maintenanceEnabled = (bool) data_get($settings, 'maintenance.enabled', false);
        if ($maintenanceEnabled && self::matchesAllowedIp($ip, (string) data_get($settings, 'maintenance.allowedIps', ''))) {
            $maintenanceEnabled = false;
        }

        return [
            'live_streaming' => [
                'enabled' => (bool) data_get($settings, 'liveStreaming.enabled', true) && LiveStreamingSettings::settings()['enabled'],
            ],
            'maintenance' => [
                'enabled' => $maintenanceEnabled,
                'message' => (string) data_get($settings, 'maintenance.message', "We'll be back soon."),
            ],
            'moderation' => [
                'default_visibility' => (string) data_get($settings, 'moderation.defaultVisibility', 'public'),
                'upload_approval' => (string) data_get($settings, 'moderation.uploadApproval', 'automatic'),
                'profanity_filter' => (bool) data_get($settings, 'moderation.profanityFilter', true),
            ],
            'uploads' => [
                'file_uploads_enabled' => ! config('thumbnails.streaming_only'),
                'default_quality' => (string) data_get($settings, 'uploads.defaultQuality', 'auto'),
                'chapters_enabled' => (bool) data_get($settings, 'uploads.chaptersEnabled', true),
            ],
        ];
    }

    public static function videoChaptersEnabled(?array $settings = null): bool
    {
        return (bool) data_get($settings ?? self::settings(), 'uploads.chaptersEnabled', true);
    }

    public static function defaultVideoVisibility(?array $settings = null): string
    {
        $visibility = data_get($settings ?? self::settings(), 'moderation.defaultVisibility', 'public');

        return in_array($visibility, ['public', 'unlisted', 'private'], true) ? $visibility : 'public';
    }

    public static function requiresUploadApproval(?array $settings = null): bool
    {
        return data_get($settings ?? self::settings(), 'moderation.uploadApproval', 'automatic') === 'manual';
    }

    public static function profanityFilterEnabled(?array $settings = null): bool
    {
        return (bool) data_get($settings ?? self::settings(), 'moderation.profanityFilter', true);
    }

    public static function filterProfanity(?string $value): string
    {
        $value = (string) $value;
        if ($value === '' || ! self::profanityFilterEnabled()) {
            return $value;
        }

        $words = ['asshole', 'bastard', 'bitch', 'cunt', 'dick', 'fuck', 'motherfucker', 'pussy', 'shit'];
        $pattern = '/\b('.implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), $words)).')\b/iu';

        return preg_replace_callback($pattern, fn (array $match): string => str_repeat('*', mb_strlen($match[0])), $value) ?? $value;
    }

    public static function save(array $settings): void
    {
        $uploadRenditions = data_get($settings, 'uploads.renditions');
        $uploadRoles = data_get($settings, 'publishing.uploadRoles');
        $liveRoles = data_get($settings, 'publishing.liveRoles');
        $settings = array_replace_recursive(self::defaults(), $settings);
        if (is_array($uploadRenditions)) {
            $settings['uploads']['renditions'] = array_values($uploadRenditions);
        }
        if (is_array($uploadRoles)) {
            $settings['publishing']['uploadRoles'] = array_values($uploadRoles);
        }
        if (is_array($liveRoles)) {
            $settings['publishing']['liveRoles'] = array_values($liveRoles);
        }
        $path = self::settingsPath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    private static function settingsPath(): string
    {
        return storage_path('app/playhub-features.json');
    }

    private static function matchesAllowedIp(?string $ip, string $rules): bool
    {
        if (! $ip || trim($rules) === '') {
            return false;
        }

        foreach (preg_split('/[\s,]+/', $rules) ?: [] as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if ($rule === $ip) {
                return true;
            }
            if (str_ends_with($rule, '*') && str_starts_with($ip, rtrim($rule, '*'))) {
                return true;
            }
            if (str_ends_with($rule, '.') && str_starts_with($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    private static function defaults(): array
    {
        return [
            'liveStreaming' => [
                'enabled' => true,
            ],
            'notifications' => [
                'newUsers' => true,
                'weeklyDigest' => false,
                'marketingEmails' => false,
            ],
            'moderation' => [
                'autoApproveUsers' => 'email_verified',
                'defaultVisibility' => 'public',
                'uploadApproval' => 'automatic',
                'profanityFilter' => true,
            ],
            'uploads' => [
                'renditions' => ['original', 'high', 'medium', 'low'],
                'defaultQuality' => 'auto',
                'chaptersEnabled' => true,
            ],
            'publishing' => [
                'uploadRoles' => ['creator', 'moderator', 'admin'],
                'liveRoles' => ['creator', 'moderator', 'admin'],
                'allowedUsers' => '',
            ],
            'maintenance' => [
                'enabled' => false,
                'message' => "We'll be back soon.",
                'allowedIps' => '',
            ],
        ];
    }
}
