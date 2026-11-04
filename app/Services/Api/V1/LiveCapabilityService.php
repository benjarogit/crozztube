<?php

namespace App\Services\Api\V1;

use App\Models\LiveStream;
use App\Support\IntegrationSettings;
use App\Support\LiveKitSettings;
use App\Support\LiveStreamingSettings;
use App\Support\PlatformFeatureSettings;

class LiveCapabilityService
{
    public function payload(?LiveStream $stream = null): array
    {
        $settings = LiveStreamingSettings::settings();
        $enabled = $settings['enabled'] && (bool) data_get(PlatformFeatureSettings::settings(), 'liveStreaming.enabled', true);
        $features = array_map(fn ($value): bool => $enabled && (bool) $value, $settings['features']);
        foreach (['chat' => 'enable_chat', 'replays' => 'save_replay'] as $feature => $option) {
            if ($stream) {
                $features[$feature] = $features[$feature] && ($feature === 'replays' ? data_get($stream->stream_settings, $option, 'off') !== 'off' : (bool) data_get($stream->stream_settings, $option, true));
            }
        }
        foreach (['ads', 'super_chat', 'super_stickers', 'gifts', 'tips', 'goal_bar'] as $feature) {
            if ($stream) {
                $features[$feature] = $features[$feature] && (bool) data_get($stream->monetization_settings, $feature, false);
            }
        }
        $features['guests'] = $enabled && (! $stream || (bool) data_get($stream->stream_settings, 'accept_participants', true));

        return [
            'enabled' => $enabled,
            'features' => $features,
            'options' => $settings['options'],
            'limits' => [
                ...$settings['limits'],
                'max_participants' => (int) (LiveKitSettings::settings()['max_participants'] ?? 0),
                'tip_min_cents' => max((int) $settings['monetization']['tip_min_cents'], (int) round((float) data_get($stream?->monetization_settings, 'tip_min', $settings['monetization']['tip_min_cents'] / 100) * 100)),
                'tip_max_cents' => min((int) $settings['monetization']['tip_max_cents'], (int) round((float) data_get($stream?->monetization_settings, 'tip_max', $settings['monetization']['tip_max_cents'] / 100) * 100)),
            ],
            'currency' => strtoupper(IntegrationSettings::currency()),
            'contribution_fee_percent' => (float) $settings['monetization']['contribution_fee_percent'],
            'reactions' => 'chat_emojis',
            'pip' => false,
            'native_purchases' => false,
            'background_broadcast' => false,
        ];
    }
}
