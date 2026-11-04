<?php

namespace App\Services;

use App\Services\Push\PushCredentials;
use App\Support\AuthSettings;
use App\Support\Client\ClientContext;
use App\Support\LiveKitSettings;
use App\Support\PlatformFeatureSettings;
use App\Support\PlayerAdSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ApplicationFeatureService
{
    /**
     * This is the single public feature map consumed by first-party clients.
     * It contains availability only; controllers and policies remain authoritative.
     */
    public function publicFeatures(?string $ip = null, ?ClientContext $client = null): array
    {
        $platform = PlatformFeatureSettings::publicSettings($ip);
        $auth = AuthSettings::publicSettings();
        $ads = PlayerAdSettings::publicSettings();

        $isTv = $client?->isTv() ?? false;
        $isMobile = $client?->platform?->deviceFamily() === 'mobile';
        $live = (bool) data_get($platform, 'live_streaming.enabled', false)
            && LiveKitSettings::configured()
            && $this->tableReady('live_streams');

        return [
            'shorts' => $this->columnReady('videos', 'is_short'),
            'live' => $live,
            'live_publishing' => $live && ! $isTv,
            'uploads' => ! $isTv && $this->tableReady('upload_sessions'),
            'comments' => $this->tableReady('comments'),
            'registration' => (bool) data_get($auth, 'registration.enabled', false)
                && AuthSettings::registrationDeniedMessage($ip) === null,
            'messaging' => ! $isTv && $this->tableReady('private_conversations') && $this->tableReady('private_messages'),
            'advertising' => (bool) ($ads['enabled'] ?? false),
            'creator' => ! $isTv && $this->tableReady('channels'),
            'playlists' => $this->tableReady('playlists'),
            'watch_history' => $this->tableReady('video_watch_progress'),
            'native_authentication' => $this->tableReady('native_device_sessions'),
            'tv_pairing' => $this->tableReady('tv_device_authorizations'),
            'push_registration' => $isMobile && $this->tableReady('push_devices'),
            'push_delivery' => $isMobile && $this->tableReady('push_deliveries')
                && app(PushCredentials::class)->configured($client?->platform?->value === 'ios' ? 'apns' : 'fcm'),
            // StoreKit / Google Play receipt validation is not implemented yet.
            'native_purchases' => false,
        ];
    }

    private function tableReady(string $table): bool
    {
        try {
            return (bool) Cache::remember(
                'playhub:client-feature-table:'.$table,
                now()->addMinutes(10),
                static fn (): bool => Schema::hasTable($table),
            );
        } catch (Throwable) {
            try {
                return Schema::hasTable($table);
            } catch (Throwable) {
                return false;
            }
        }
    }

    private function columnReady(string $table, string $column): bool
    {
        try {
            return (bool) Cache::remember(
                'playhub:client-feature-column:'.$table.':'.$column,
                now()->addMinutes(10),
                static fn (): bool => Schema::hasColumn($table, $column),
            );
        } catch (Throwable) {
            try {
                return Schema::hasColumn($table, $column);
            } catch (Throwable) {
                return false;
            }
        }
    }
}
