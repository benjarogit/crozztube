<?php

namespace App\Support;

use App\Models\UploadSession;
use App\Models\User;
use App\Models\Video;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class MediaStorage
{
    private const DISK = 'playhub_media';

    private const LOCAL_UPLOAD_DIRECTORY_MODE = 0775;

    public const SECRET_KEYS = [
        's3.secretAccessKey', 's3.sessionToken', 'azure.accountKey',
        'azure.connectionString', 'gcs.secretAccessKey', 'b2.masterApplicationKey',
    ];

    public static function settings(): array
    {
        $path = self::settingsPath();
        $stored = is_file($path) && is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];

        return array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []);
    }

    public static function adminSettings(): array
    {
        $settings = self::settings();
        $settings['configuredSecrets'] = [];
        foreach (self::SECRET_KEYS as $key) {
            $settings['configuredSecrets'][$key] = filled(Arr::get($settings, $key));
            Arr::set($settings, $key, '');
        }

        return $settings;
    }

    public static function save(array $settings): void
    {
        $settings = array_replace_recursive(self::defaults(), $settings);
        unset($settings['configuredSecrets'], $settings['clearSecrets']);
        self::configuration($settings);
        $path = self::settingsPath();

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new \RuntimeException('Unable to create the storage settings directory.');
        }

        $temporary = tempnam(dirname($path), '.playhub-storage-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to save storage settings.');
        }
        try {
            @chmod($temporary, 0600);
            $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || ! rename($temporary, $path)) {
                throw new \RuntimeException('Unable to save storage settings.');
            }
            @chmod($path, 0600);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public static function test(array $settings): array
    {
        $settings = array_replace_recursive(self::defaults(), $settings);
        $provider = strtolower(trim((string) ($settings['mediaStorage'] ?? 'default')));
        $configuration = self::configuration($settings);
        // An on-demand disk cannot reuse the previous test's provider or credentials.
        $disk = in_array($provider, ['default', 'local'], true)
            ? Storage::disk(config('playhub.video_disk', 'public'))
            : Storage::build($configuration);
        $path = 'connection-tests/'.Str::uuid().'.txt';
        $contents = 'PlayHub storage connection test '.now()->toIso8601String();
        $failure = null;
        try {
            if (! $disk->put($path, $contents)) {
                throw new \RuntimeException('Cannot write test file.');
            }
            if (! $disk->fileExists($path)) {
                throw new \RuntimeException('Test file was not found after upload.');
            }
            if ($disk->get($path) !== $contents) {
                throw new \RuntimeException('Test file contents could not be verified.');
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            try {
                if (! $disk->delete($path) || $disk->fileExists($path)) {
                    throw new \RuntimeException('Cannot delete test file; check storage delete permissions.');
                }
            } catch (\Throwable $exception) {
                $failure ??= $exception;
            }
        }
        if ($failure) {
            throw new \RuntimeException('Storage connection test failed. Check the endpoint, credentials and read/write/delete permissions.', 0, $failure);
        }

        return [
            'provider' => $provider,
            'path' => $path,
        ];
    }

    public static function diskName(): string
    {
        $provider = self::provider();
        if (in_array($provider, ['default', 'local'], true)) {
            return config('playhub.video_disk', 'public');
        }

        $configuration = self::configuration(self::settings());
        if (Config::get('filesystems.disks.'.self::DISK) !== $configuration) {
            Storage::forgetDisk(self::DISK);
            Config::set('filesystems.disks.'.self::DISK, $configuration);
        }

        return self::DISK;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function isLocal(): bool
    {
        return in_array(self::provider(), ['default', 'local'], true);
    }

    public static function defaultUserStorageGb(): int
    {
        return self::membershipQuotaGb(self::defaultMembership()) ?? 10;
    }

    public static function defaultMembership(): string
    {
        return self::normalizeMembership((string) (self::settings()['defaultMembership'] ?? 'starter'));
    }

    public static function normalizeMembership(?string $membership): string
    {
        $membership = strtolower(trim((string) $membership));

        return in_array($membership, array_keys(self::defaultMembershipQuotas()), true) ? $membership : 'starter';
    }

    public static function membershipLabel(?string $membership): string
    {
        return match (self::normalizeMembership($membership)) {
            'plus' => 'Plus',
            'premium' => 'Premium',
            'pro' => 'Pro',
            'vip' => 'VIP',
            default => 'Starter',
        };
    }

    public static function membershipFor(User $user): string
    {
        $settings = is_array($user->settings) ? $user->settings : [];

        return self::normalizeMembership($settings['membership'] ?? self::defaultMembership());
    }

    public static function membershipQuotaGb(?string $membership): ?int
    {
        $membership = self::normalizeMembership($membership);
        if ($membership === 'vip') {
            return null;
        }

        $quotas = self::membershipQuotaSettings();
        $quota = $quotas[$membership] ?? 100;

        return $quota === null ? null : max(1, (int) $quota);
    }

    public static function membershipQuotaSettings(): array
    {
        $quotas = self::settings()['membershipQuotasGb'] ?? [];

        return array_replace(self::defaultMembershipQuotas(), is_array($quotas) ? $quotas : []);
    }

    public static function membershipPriceUsd(?string $membership): float
    {
        $prices = self::settings()['membershipPricesUsd'] ?? [];
        $price = (is_array($prices) ? $prices : [])[self::normalizeMembership($membership)] ?? self::defaultMembershipPricesUsd()[self::normalizeMembership($membership)] ?? 0;

        return max(0, round((float) $price, 2));
    }

    public static function membershipPlans(): array
    {
        return array_map(function (string $membership): array {
            $quotaGb = self::membershipQuotaGb($membership);
            $price = self::membershipPriceUsd($membership);

            return [
                'id' => $membership,
                'name' => self::membershipLabel($membership),
                'quota_gb' => $quotaGb,
                'quota_label' => $quotaGb === null ? 'Unlimited' : self::formatQuotaGb($quotaGb),
                'price_usd' => $price,
                'price_label' => $price > 0 ? '$'.number_format($price, $price == floor($price) ? 0 : 2).' / month' : 'Free',
                'paid' => $price > 0,
            ];
        }, array_keys(self::defaultMembershipQuotas()));
    }

    public static function storageSummaryFor(User $user): array
    {
        $usedBytes = self::userUsageBytes($user);
        $quotaBytes = self::userQuotaBytes($user);
        $unlimited = $quotaBytes === null;
        $remainingBytes = $unlimited ? null : max(0, $quotaBytes - $usedBytes);
        $membership = self::membershipFor($user);

        return [
            'used_bytes' => $usedBytes,
            'quota_bytes' => $quotaBytes,
            'remaining_bytes' => $remainingBytes,
            'used' => self::formatBytes($usedBytes),
            'quota' => $unlimited ? 'Unlimited' : self::formatBytes($quotaBytes),
            'remaining' => $unlimited ? 'Unlimited' : self::formatBytes($remainingBytes),
            'percent' => (! $unlimited && $quotaBytes > 0) ? (int) min(100, round(($usedBytes / $quotaBytes) * 100)) : 0,
            'quota_gb' => $unlimited ? null : (int) round($quotaBytes / 1024 / 1024 / 1024),
            'default_quota_gb' => self::defaultUserStorageGb(),
            'membership' => $membership,
            'membership_label' => self::membershipLabel($membership),
            'membership_price_usd' => self::membershipPriceUsd($membership),
            'membership_plans' => self::membershipPlans(),
            'unlimited' => $unlimited,
            'provider' => self::provider(),
            'provider_label' => self::isLocal() ? 'Local storage' : 'Cloud storage',
        ];
    }

    public static function userQuotaBytes(User $user): ?int
    {
        $settings = is_array($user->settings) ? $user->settings : [];
        $membership = self::membershipFor($user);
        if ($membership === 'vip') {
            return null;
        }
        $quotaGb = Arr::get($settings, 'storage.quota_gb');
        if ($quotaGb === null || $quotaGb === '') {
            $quotaGb = self::membershipQuotaGb($membership);
        }
        if ($quotaGb === null) {
            return null;
        }

        return max(1, (int) $quotaGb) * 1024 * 1024 * 1024;
    }

    public static function capacitySummary(): array
    {
        $settings = self::settings();
        if (self::isLocal()) {
            $totalBytes = @disk_total_space(base_path()) ?: 0;
            $freeBytes = @disk_free_space(base_path()) ?: 0;
            $usedBytes = max(0, $totalBytes - $freeBytes);
        } else {
            $totalBytes = max(0, (int) ($settings['cloudStorageTotalGb'] ?? 0)) * 1024 * 1024 * 1024;
            $freeBytes = null;
            $usedBytes = null;
        }

        return [
            'provider' => self::provider(),
            'provider_label' => self::isLocal() ? 'Local storage' : 'Cloud storage',
            'total_bytes' => $totalBytes,
            'free_bytes' => $freeBytes,
            'used_bytes' => $usedBytes,
            'total' => $totalBytes > 0 ? self::formatBytes($totalBytes) : 'Not configured',
            'free' => $freeBytes === null ? 'Not available' : self::formatBytes($freeBytes),
            'used' => $usedBytes === null ? 'Not available' : self::formatBytes($usedBytes),
            'percent' => $totalBytes > 0 && $usedBytes !== null ? (int) min(100, round(($usedBytes / $totalBytes) * 100)) : 0,
            'source' => self::isLocal() ? 'Server disk' : 'Configured cloud capacity',
        ];
    }

    public static function userUsageBytes(User $user): int
    {
        $videosBytes = $user->videos()
            ->select(['id', 'video_path', 'thumbnail_path', 'captions_path', 'renditions'])
            ->get()
            ->sum(fn (Video $video): int => self::videoStorageBytes($video));

        $reservedBytes = UploadSession::query()
            ->where('user_id', $user->id)
            ->whereNull('video_id')
            ->whereIn('status', ['pending', 'uploading', 'assembling'])
            ->sum('file_size');

        return (int) $videosBytes + (int) $reservedBytes;
    }

    public static function exists(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        try {
            return self::disk()->fileExists(self::objectPath($path));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function delete(array|string|null $paths): void
    {
        $paths = array_values(array_unique(array_filter((array) $paths, fn ($path): bool => is_string($path)
            && $path !== '' && ! str_starts_with($path, '/') && ! str_starts_with($path, 'external:')
            && ! preg_match('/^(https?:)?\/\//i', $path))));
        if ($paths) {
            try {
                $paths = array_map(self::objectPath(...), $paths);
                if (! self::disk()->delete($paths)) {
                    throw new \RuntimeException('Storage deletion failed.');
                }
            } catch (\Throwable $exception) {
                throw new \RuntimeException('Unable to delete media from the configured storage. Local copies have been kept.', 0, $exception);
            }
            foreach ($paths as $path) {
                self::forgetPathSize($path);
            }
            if ($images = array_values(array_filter($paths, self::isImagePath(...)))) {
                MediaImageCache::forget($images);
            }
            foreach (array_diff(['public', 'local'], [self::diskName()]) as $disk) {
                try {
                    Storage::disk($disk)->delete($paths);
                } catch (\Throwable) {
                    // Ignore unavailable fallback disks.
                }
            }
        }
    }

    public static function put(string $path, mixed $contents): bool
    {
        $path = self::objectPath($path);
        self::ensureLocalDirectoryForPath($path);
        $size = is_string($contents) ? strlen($contents) : null;
        if (is_resource($contents) && (stream_get_meta_data($contents)['seekable'] ?? false) && ftell($contents) === 0) {
            $statistics = fstat($contents);
            if (($statistics['size'] ?? 0) > 0) {
                $size = (int) $statistics['size'];
            }
        }
        $stored = (bool) self::disk()->put(ltrim($path, '/'), $contents);
        if ($stored) {
            self::forgetPathSize($path);
            if ($size !== null && ! self::isLocal()) {
                self::cachePathSize($path, $size);
            }
        }
        if ($stored && self::isImagePath($path)) {
            MediaImageCache::forget($path);
        }
        self::ensureLocalDirectoryForPath($path);

        return $stored;
    }

    public static function ensureLocalDirectory(string $relativeDirectory = '', ?int $mode = null): string
    {
        $mode ??= self::LOCAL_UPLOAD_DIRECTORY_MODE;
        $relativeDirectory = trim(str_replace('\\', '/', $relativeDirectory), '/');
        if (str_contains('/'.$relativeDirectory.'/', '/../')) {
            throw new \RuntimeException('Invalid uploads directory.');
        }

        $root = app()->environment('testing') ? rtrim(Storage::disk(config('playhub.video_disk', 'public'))->path(''), '/') : base_path('uploads');
        $directory = $relativeDirectory === '' ? $root : $root.'/'.$relativeDirectory;
        if (! is_dir($directory) && ! mkdir($directory, self::LOCAL_UPLOAD_DIRECTORY_MODE, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create uploads directory.');
        }

        self::chmodLocalDirectoryChain($relativeDirectory);
        if (is_dir($directory)) {
            @chmod($directory, $mode);
        }

        return $directory;
    }

    private static function ensureLocalDirectoryForPath(string $path): void
    {
        if (! self::isLocal()) {
            return;
        }

        $directory = dirname(self::objectPath($path));
        if ($directory !== '.') {
            self::ensureLocalDirectory($directory);
        }
    }

    private static function chmodLocalDirectoryChain(string $relativeDirectory = ''): void
    {
        $path = base_path('uploads');
        if (is_dir($path)) {
            @chmod($path, self::LOCAL_UPLOAD_DIRECTORY_MODE);
        }

        foreach (array_filter(explode('/', trim($relativeDirectory, '/'))) as $part) {
            $path .= '/'.$part;
            if (is_dir($path)) {
                @chmod($path, self::LOCAL_UPLOAD_DIRECTORY_MODE);
            }
        }
    }

    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (preg_match('/^(https?:)?\/\//', $path)) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return url($path);
        }

        $settings = self::settings();
        $provider = self::provider();
        $base = trim((string) Arr::get($settings, "{$provider}.publicBaseUrl", ''));

        try {
            $path = self::objectPath($path);
            if (! self::isLocal()) {
                // URL generation must not perform a billable existence HEAD.
                // Explicit public CDN configuration is honored without probing it.
                if ($base !== '') {
                    return rtrim($base, '/').'/'.self::encodePath(self::prefixedPath($path));
                }
                if (self::isPublicImagePath($path)) {
                    return URL::temporarySignedRoute('media.image', now()->addHours(6), ['path' => $path]);
                }

                // Protected video/community/comment/message images retain their
                // resource-specific authorization routes, never the generic image route.
                return self::temporaryStreamUrl($path, now()->addHours(6));
            }

            return self::disk()->url($path);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isImagePath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp'], true);
    }

    public static function isPublicImagePath(string $path): bool
    {
        try {
            $path = self::objectPath($path);
        } catch (\Throwable) {
            return false;
        }

        // These directories contain display assets, not protected content. Keep
        // this an allowlist so a signed generic URL cannot bypass video/photo access.
        return self::isImagePath($path)
            && (bool) preg_match('#^(avatars|covers|gifts|stickers|images|logos|branding|banners)/#', $path);
    }

    public static function streamUrl(string $path): string
    {
        if ($temporary = self::temporaryStreamUrl($path, now()->addHours(6))) {
            return $temporary;
        }

        return self::url($path) ?: '';
    }

    public static function temporaryStreamUrl(string $path, \DateTimeInterface $expiresAt): ?string
    {
        if (self::isLocal()) {
            return null;
        }

        try {
            $url = self::disk()->temporaryUrl(self::objectPath($path), $expiresAt);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : null;
    }

    private static function provider(): string
    {
        return strtolower(trim((string) (self::settings()['mediaStorage'] ?? 'default')));
    }

    private static function settingsPath(): string
    {
        return config('playhub.storage_settings_path') ?: storage_path('app/playhub-storage.json');
    }

    private static function defaults(): array
    {
        return [
            'mediaStorage' => 'default',
            'defaultUserStorageGb' => 10,
            'defaultMembership' => 'starter',
            'membershipQuotasGb' => self::defaultMembershipQuotas(),
            'membershipPricesUsd' => self::defaultMembershipPricesUsd(),
            'cloudStorageTotalGb' => 0,
            'recordsPathPrefix' => 'records',
            's3' => [
                'urlScheme' => '//%s.s3.%s.amazonaws.com/%s/streams/%s',
                'publicBaseUrl' => '',
                'endpoint' => '',
                'keyId' => '',
                'secretAccessKey' => '',
                'bucketName' => '',
                'region' => '',
                'sessionToken' => '',
                'useDefaultCredentials' => false,
                'usePathStyleEndpoint' => null,
                'omitAcl' => true,
            ],
            'azure' => [
                'publicBaseUrl' => '',
                'accountName' => '',
                'accountKey' => '',
                'containerName' => '',
                'endpoint' => '',
                'connectionString' => '',
            ],
            'gcs' => [
                'publicBaseUrl' => '',
                'accessKeyId' => '',
                'secretAccessKey' => '',
                'bucketName' => '',
                'endpoint' => 'https://storage.googleapis.com',
                'region' => 'auto',
                'usePathStyleEndpoint' => true,
                'omitAcl' => true,
            ],
            'b2' => [
                'urlScheme' => '//f004.backblazeb2.com/file/%s/%s/streams/%s',
                'publicBaseUrl' => '',
                'keyId' => '',
                'masterApplicationKey' => '',
                'bucketId' => '',
                'bucketName' => '',
                'region' => '',
                'endpoint' => '',
                'usePathStyleEndpoint' => false,
                'omitAcl' => true,
            ],
        ];
    }

    private static function defaultMembershipQuotas(): array
    {
        return [
            'starter' => 10,
            'plus' => 50,
            'premium' => 250,
            'pro' => 1024,
            'vip' => null,
        ];
    }

    private static function defaultMembershipPricesUsd(): array
    {
        return [
            'starter' => 0,
            'plus' => 9,
            'premium' => 19,
            'pro' => 49,
            'vip' => 99,
        ];
    }

    private static function formatQuotaGb(int $quotaGb): string
    {
        if ($quotaGb >= 1024) {
            $tb = $quotaGb / 1024;

            return number_format($tb, 1).' TB';
        }

        return number_format($quotaGb).' GB';
    }

    public static function configuration(array $settings): array
    {
        $settings = array_replace_recursive(self::defaults(), $settings);
        $provider = strtolower(trim((string) $settings['mediaStorage']));
        if (! in_array($provider, ['default', 'local', 's3', 'gcs', 'b2', 'azure'], true)) {
            throw new \InvalidArgumentException('Unsupported media storage provider.');
        }

        return self::diskConfig($provider, $settings);
    }

    private static function diskConfig(string $provider, array $settings): array
    {
        $prefix = trim((string) ($settings['recordsPathPrefix'] ?? ''), '/');
        if ($prefix !== '') {
            $prefix = self::objectPath($prefix);
        }
        $required = match ($provider) {
            's3' => (Arr::get($settings, 's3.useDefaultCredentials') ? [] : ['s3.keyId' => 'S3 access key', 's3.secretAccessKey' => 'S3 secret key']) + ['s3.bucketName' => 'S3 bucket name'],
            'gcs' => ['gcs.accessKeyId' => 'Google Cloud access key', 'gcs.secretAccessKey' => 'Google Cloud secret key', 'gcs.bucketName' => 'Google Cloud bucket name'],
            'b2' => ['b2.keyId' => 'Backblaze B2 key ID', 'b2.masterApplicationKey' => 'Backblaze B2 application key', 'b2.bucketName' => 'Backblaze B2 bucket name'],
            'azure' => (filled(Arr::get($settings, 'azure.connectionString')) ? [] : ['azure.accountName' => 'Azure account name', 'azure.accountKey' => 'Azure account key']) + ['azure.containerName' => 'Azure container name'],
            default => [],
        };
        foreach ($required as $key => $label) {
            if (trim((string) Arr::get($settings, $key, '')) === '') {
                throw new \RuntimeException($label.' is required.');
            }
        }

        foreach (['s3', 'gcs', 'b2', 'azure'] as $cloudProvider) {
            foreach (['endpoint', 'publicBaseUrl'] as $field) {
                $value = trim((string) Arr::get($settings, $cloudProvider.'.'.$field, ''));
                if ($value === '') {
                    continue;
                }
                $parts = parse_url($value);
                if (! filter_var($value, FILTER_VALIDATE_URL) || ! is_array($parts)
                    || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                    || isset($parts['user']) || isset($parts['pass'])
                    || isset($parts['query']) || isset($parts['fragment'])) {
                    throw new \InvalidArgumentException('Storage endpoints and public base URLs must be HTTP(S) URLs without credentials, queries or fragments.');
                }
            }
        }
        $b2Endpoint = trim((string) Arr::get($settings, 'b2.endpoint', ''));
        $b2Region = trim((string) Arr::get($settings, 'b2.region', ''));
        if (preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/i', (string) parse_url($b2Endpoint, PHP_URL_HOST), $matches)) {
            $endpointRegion = strtolower($matches[1]);
            if ($provider === 'b2' && $b2Region !== '' && strtolower($b2Region) !== $endpointRegion) {
                throw new \InvalidArgumentException('The Backblaze region must match the region in its S3 endpoint.');
            }
            $b2Region = $b2Region ?: $endpointRegion;
        }
        $b2Region = $b2Region ?: 'us-west-004';

        $configuration = match ($provider) {
            's3' => [
                'driver' => 'playhub_s3',
                'key' => Arr::get($settings, 's3.keyId'),
                'secret' => Arr::get($settings, 's3.secretAccessKey'),
                'token' => Arr::get($settings, 's3.sessionToken') ?: null,
                'region' => Arr::get($settings, 's3.region') ?: 'us-east-1',
                'bucket' => Arr::get($settings, 's3.bucketName'),
                'endpoint' => Arr::get($settings, 's3.endpoint') ?: null,
                'url' => Arr::get($settings, 's3.publicBaseUrl') ?: null,
                'root' => $prefix ?: null,
                'use_path_style_endpoint' => Arr::get($settings, 's3.usePathStyleEndpoint') ?? (bool) Arr::get($settings, 's3.endpoint'),
                'omit_acl' => (bool) Arr::get($settings, 's3.omitAcl', true),
                'throw' => true,
            ],
            'gcs' => [
                'driver' => 'playhub_s3',
                'key' => Arr::get($settings, 'gcs.accessKeyId'),
                'secret' => Arr::get($settings, 'gcs.secretAccessKey'),
                'region' => Arr::get($settings, 'gcs.region') ?: 'auto',
                'bucket' => Arr::get($settings, 'gcs.bucketName'),
                'endpoint' => Arr::get($settings, 'gcs.endpoint') ?: 'https://storage.googleapis.com',
                'url' => Arr::get($settings, 'gcs.publicBaseUrl') ?: null,
                'root' => $prefix ?: null,
                'use_path_style_endpoint' => (bool) Arr::get($settings, 'gcs.usePathStyleEndpoint', true),
                'omit_acl' => (bool) Arr::get($settings, 'gcs.omitAcl', true),
                'throw' => true,
            ],
            'b2' => [
                'driver' => 'playhub_s3',
                'key' => Arr::get($settings, 'b2.keyId'),
                'secret' => Arr::get($settings, 'b2.masterApplicationKey'),
                'region' => $b2Region,
                'bucket' => Arr::get($settings, 'b2.bucketName'),
                'endpoint' => $b2Endpoint ?: 'https://s3.'.$b2Region.'.backblazeb2.com',
                'url' => Arr::get($settings, 'b2.publicBaseUrl') ?: null,
                'root' => $prefix ?: null,
                'use_path_style_endpoint' => (bool) Arr::get($settings, 'b2.usePathStyleEndpoint', false),
                'omit_acl' => (bool) Arr::get($settings, 'b2.omitAcl', true),
                'throw' => true,
            ],
            'azure' => [
                'driver' => 'azure-storage-blob',
                'directory_separator' => '/',
                'container' => Arr::get($settings, 'azure.containerName'),
                'url' => Arr::get($settings, 'azure.publicBaseUrl') ?: null,
                'root' => $prefix,
                'is_public_container' => false,
                'timeout' => 60,
                'connect_timeout' => 5,
                'throw' => true,
            ],
            default => Config::get('filesystems.disks.'.config('playhub.video_disk', 'public')),
        };
        if ($provider === 'azure') {
            if (filled(Arr::get($settings, 'azure.connectionString'))) {
                $configuration['connection_string'] = trim((string) Arr::get($settings, 'azure.connectionString'));
                AzureStorageConfiguration::validateConnectionString($configuration['connection_string']);
            } else {
                $configuration += [
                    'credential' => 'shared_key',
                    'account_name' => trim((string) Arr::get($settings, 'azure.accountName')),
                    'account_key' => trim((string) Arr::get($settings, 'azure.accountKey')),
                ];
                if (filled(Arr::get($settings, 'azure.endpoint'))) {
                    $configuration['endpoint'] = rtrim(trim((string) Arr::get($settings, 'azure.endpoint')), '/');
                }
            }
        } elseif (in_array($provider, ['s3', 'gcs', 'b2'], true)) {
            // Current AWS SDK defaults add optional checksums not implemented by
            // several XML/S3-compatible services. Required checksums still apply.
            $configuration += [
                'request_checksum_calculation' => 'when_required',
                'response_checksum_validation' => 'when_required',
                'stream_reads' => true,
                'http' => ['connect_timeout' => 5, 'timeout' => 60],
                'retries' => 2,
            ];
            if ($provider === 's3' && Arr::get($settings, 's3.useDefaultCredentials')) {
                unset($configuration['key'], $configuration['secret'], $configuration['token']);
            }
        }

        return $configuration;
    }

    private static function objectPath(string $path): string
    {
        if (preg_match('/^(https?:)?\/\//i', $path) || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new \InvalidArgumentException('Invalid media object path.');
        }
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || preg_match('#(^|/)\.{1,2}(/|$)#', $path) || str_contains($path, ':')) {
            throw new \InvalidArgumentException('Invalid media object path.');
        }

        return $path;
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function prefixedPath(string $path): string
    {
        if (self::isLocal()) {
            return $path;
        }

        $prefix = trim((string) (self::settings()['recordsPathPrefix'] ?? ''), '/');

        return $prefix ? $prefix.'/'.ltrim($path, '/') : $path;
    }

    private static function videoStorageBytes(Video $video): int
    {
        return collect(self::videoStoragePaths($video))
            ->unique()
            ->sum(fn (string $path): int => self::pathSize($path));
    }

    private static function videoStoragePaths(Video $video): array
    {
        $paths = [$video->video_path, $video->thumbnail_path, $video->captions_path];
        foreach ((array) ($video->renditions ?: []) as $rendition) {
            if (is_array($rendition)) {
                $paths[] = $rendition['path'] ?? null;
            }
        }

        return array_values(array_filter($paths, fn ($path): bool => is_string($path)
            && $path !== ''
            && ! str_starts_with($path, 'external:')
            && ! preg_match('/^(https?:)?\/\//', $path)
            && ! str_starts_with($path, '/')));
    }

    private static function pathSize(string $path): int
    {
        if (! self::isLocal()) {
            try {
                $cached = Cache::get(self::pathSizeCacheKey($path));
                if (is_int($cached) && $cached >= 0) {
                    return $cached;
                }
            } catch (\Throwable) {
                // A cache outage must not prevent storage/quota checks.
            }
        }
        foreach (array_unique([self::diskName(), 'public', 'local']) as $diskName) {
            try {
                // size() already checks remote metadata. A preceding exists()
                // doubled the serial cloud requests on every account response.
                $size = Storage::disk($diskName)->size($path);
                if (! is_int($size) || $size < 0) {
                    continue;
                }
                if (! self::isLocal() && $diskName === self::diskName()) {
                    self::cachePathSize($path, $size);
                }

                return $size;
            } catch (\Throwable) {
                // Try the next configured disk.
            }
        }

        return 0;
    }

    private static function pathSizeCacheKey(string $path): string
    {
        $configuration = self::configuration(self::settings());
        $identity = array_intersect_key($configuration, array_flip([
            'driver', 'bucket', 'container', 'account_name', 'endpoint', 'root', 'region', 'connection_string',
        ]));

        return 'playhub.media-size.'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR).'|'.$path);
    }

    private static function cachePathSize(string $path, int $size): void
    {
        try {
            // Generated media keys are immutable. Writes through this service
            // invalidate/refresh sizes, and expiry catches external changes.
            Cache::put(self::pathSizeCacheKey($path), $size, now()->addDay());
        } catch (\Throwable) {
            // A completed media operation must not fail because of caching.
        }
    }

    private static function forgetPathSize(string $path): void
    {
        if (self::isLocal()) {
            return;
        }
        try {
            Cache::forget(self::pathSizeCacheKey($path));
        } catch (\Throwable) {
            // The cache is optional; its entries also have a bounded lifetime.
        }
    }

    private static function formatBytes(int|float $bytes): string
    {
        $bytes = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return number_format($bytes, $index >= 3 ? 1 : 0).' '.$units[$index];
    }
}
