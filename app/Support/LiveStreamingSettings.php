<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

class LiveStreamingSettings
{
    public static function settings(): array
    {
        $path = self::settingsPath();
        $stored = is_file($path) && is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];
        $settings = array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []);
        foreach ((array) data_get($stored, 'options', []) as $name => $values) {
            $settings['options'][$name] = array_values((array) $values);
        }

        return self::normalize($settings);
    }

    public static function adminSettings(): array
    {
        $settings = self::settings();
        $settings['currency'] = IntegrationSettings::currency();
        $settings['monetization']['tip_min'] = number_format($settings['monetization']['tip_min_cents'] / 100, 2, '.', '');
        $settings['monetization']['tip_max'] = number_format($settings['monetization']['tip_max_cents'] / 100, 2, '.', '');
        $settings['monetization']['ad_gross'] = number_format($settings['monetization']['ad_gross_cents'] / 100, 4, '.', '');
        $settings['livekit'] = LiveKitSettings::adminSettings();

        return $settings;
    }

    public static function studioSettings(): array
    {
        $settings = self::settings();
        $liveKitRunning = LiveKitSettings::isRunning();
        $liveKitSettings = LiveKitSettings::settings();
        $docker = LiveKitDockerStatus::read($liveKitSettings['status_path']);
        $availability = self::studioAvailability($liveKitRunning, $docker, LiveKitSettings::ingressAvailable(), LiveKitSettings::egressAvailable());

        return [
            'enabled' => $settings['enabled'],
            'engine' => ['enabled' => $settings['engine']['enabled'] && $availability['engine']['enabled'], 'provider' => $settings['engine']['provider']],
            'docker' => $availability['docker'],
            'rtmp' => $availability['rtmp'],
            'recording' => $availability['recording'],
            'features' => $settings['features'],
            'options' => $settings['options'],
            'defaults' => $settings['defaults'],
            'schedule' => $settings['schedule'],
            'limits' => [...$settings['limits'], 'max_participants' => (int) ($liveKitSettings['max_participants'] ?? 0)],
            'monetization' => [
                'currency' => IntegrationSettings::currency(),
                'tip_min' => number_format($settings['monetization']['tip_min_cents'] / 100, 2, '.', ''),
                'tip_max' => number_format($settings['monetization']['tip_max_cents'] / 100, 2, '.', ''),
                'contribution_fee_percent' => $settings['monetization']['contribution_fee_percent'],
                'live_ads_available' => $settings['features']['ads']
                    && $settings['monetization']['ad_gross_cents'] > 0
                    && $settings['monetization']['ad_creator_share_percent'] > 0,
            ],
        ];
    }

    private static function studioAvailability(bool $coreRunning, array $docker, bool $ingressAvailable, bool $egressAvailable): array
    {
        $freshDocker = ($docker['available'] ?? false) === true && ($docker['stale'] ?? true) === false;
        $containersMissing = $freshDocker ? ($docker['container']['exists'] ?? false) !== true : ! $coreRunning;
        $coreAvailable = $coreRunning && (! $freshDocker || self::dockerContainerRunning((array) ($docker['container'] ?? [])));

        // Webcam publishing needs only Core. Its API can list ingress records
        // even with no Ingress worker, so optional workers need their own gates.
        return [
            'engine' => ['enabled' => $coreAvailable],
            'docker' => ['containers_missing' => $containersMissing],
            'rtmp' => ['enabled' => $coreAvailable && $ingressAvailable && (! $freshDocker || self::dockerContainerRunning((array) ($docker['ingress_container'] ?? [])))],
            'recording' => ['available' => $coreAvailable && $egressAvailable && (! $freshDocker || self::dockerContainerRunning((array) ($docker['egress_container'] ?? [])))],
        ];
    }

    private static function dockerContainerRunning(array $container): bool
    {
        return ($container['exists'] ?? false) === true
            && ($container['running'] ?? false) === true
            && ($container['restarting'] ?? false) === false
            && ($container['status'] ?? 'unknown') === 'running'
            && ! in_array($container['health'] ?? 'none', ['starting', 'unhealthy'], true);
    }

    public static function creatorTipSettings(?User $creator = null): array
    {
        $settings = self::settings();
        $acceptsTips = (bool) data_get($creator?->settings, 'preferences.acceptTips', true);

        return [
            'enabled' => $acceptsTips
                && (bool) $settings['features']['tips']
                && Schema::hasTable('creator_tips')
                && Schema::hasTable('wallet_transactions'),
            'currency' => IntegrationSettings::currency(),
            'minimum' => number_format($settings['monetization']['tip_min_cents'] / 100, 2, '.', ''),
            'maximum' => number_format($settings['monetization']['tip_max_cents'] / 100, 2, '.', ''),
        ];
    }

    public static function save(array $settings): void
    {
        $settings = self::normalize($settings);
        $path = self::settingsPath();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    private static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $settings['enabled'] = (bool) ($settings['enabled'] ?? true);
        $livekitConfigured = LiveKitSettings::configured();
        $settings['engine'] = ['enabled' => $livekitConfigured, 'provider' => $livekitConfigured ? 'livekit' : 'none'];
        $storedFeatures = (array) ($settings['features'] ?? []);
        $settings['features'] = [];
        foreach ($defaults['features'] as $feature => $default) {
            $settings['features'][$feature] = (bool) ($storedFeatures[$feature] ?? $default);
        }
        foreach ($defaults['options'] as $name => $allowed) {
            $values = array_values(array_intersect((array) ($settings['options'][$name] ?? []), $allowed));
            $settings['options'][$name] = $values ?: $allowed;
        }
        $defaultOptions = [
            'resolution' => 'resolutions', 'latency_mode' => 'latency_modes', 'replay_retention' => 'replay_retention',
            'audience_mode' => 'audience_modes', 'moderation_level' => 'moderation_levels',
            'slow_mode_interval' => 'slow_mode_intervals', 'frame_rate' => 'frame_rates',
        ];
        foreach ($defaultOptions as $name => $option) {
            $value = $settings['defaults'][$name] ?? $defaults['defaults'][$name];
            $settings['defaults'][$name] = in_array($value, $settings['options'][$option], true) ? $value : $settings['options'][$option][0];
        }
        $settings['schedule']['strict_start'] = (bool) ($settings['schedule']['strict_start'] ?? true);
        foreach ($defaults['limits'] as $name => $default) {
            $settings['limits'][$name] = $name === 'connection_good_mbps'
                ? max(0.1, (float) ($settings['limits'][$name] ?? $default))
                : max(1, (int) ($settings['limits'][$name] ?? $default));
        }
        $money = $settings['monetization'] ?? [];
        $settings['monetization'] = [
            'tip_min_cents' => max(1, (int) ($money['tip_min_cents'] ?? 100)),
            'tip_max_cents' => max(1, (int) ($money['tip_max_cents'] ?? 50000)),
            'contribution_fee_percent' => max(0, min(100, (float) ($money['contribution_fee_percent'] ?? 0))),
            'ad_gross_cents' => max(0, (int) ($money['ad_gross_cents'] ?? 0)),
            'ad_creator_share_percent' => max(0, min(100, (float) ($money['ad_creator_share_percent'] ?? 0))),
        ];

        return $settings;
    }

    private static function defaults(): array
    {
        return [
            'enabled' => true,
            'engine' => ['enabled' => false, 'provider' => 'none'],
            'features' => [
                'webcam_preview' => true, 'scheduling' => true, 'thumbnails' => true, 'appearance' => true,
                'dvr' => true, 'chat' => true, 'replays' => true, 'ads' => false,
                'super_chat' => true, 'super_stickers' => true, 'gifts' => true,
                'tips' => true, 'goal_bar' => true, 'polls' => true, 'pinned_messages' => true,
                'alerts' => true, 'stream_labels' => true, 'advanced_settings' => true,
            ],
            'options' => [
                'resolutions' => ['auto', '480p', '720p', '1080p', '1440p', '2160p'],
                'latency_modes' => ['normal', 'low', 'ultra_low'],
                'replay_retention' => ['off', '24_hours', '7_days', '30_days', '90_days', 'forever'],
                'audience_modes' => ['everyone', 'signed_in', 'subscribers', 'invited', 'private'],
                'moderation_levels' => ['off', 'basic', 'standard', 'strict', 'custom'],
                'slow_mode_intervals' => [5, 10, 30, 60, 120],
                'frame_rates' => [30, 60],
            ],
            'defaults' => [
                'resolution' => 'auto', 'latency_mode' => 'low', 'replay_retention' => 'forever',
                'audience_mode' => 'everyone', 'moderation_level' => 'standard',
                'slow_mode_interval' => 10, 'frame_rate' => 30,
            ],
            'schedule' => ['strict_start' => true],
            'limits' => [
                'schedule_max_days' => 365, 'thumbnail_max_mb' => 5, 'poll_max_options' => 6,
                'poll_max_duration_minutes' => 1440, 'stream_label_max_count' => 8,
                'connection_probe_kb' => 512, 'connection_good_mbps' => 5, 'connection_good_latency_ms' => 2000,
            ],
            'monetization' => [
                'tip_min_cents' => 100, 'tip_max_cents' => 50000, 'contribution_fee_percent' => 0,
                'ad_gross_cents' => 0, 'ad_creator_share_percent' => 0,
            ],
        ];
    }

    private static function settingsPath(): string
    {
        return storage_path('app/playhub-live-streaming.json');
    }
}
