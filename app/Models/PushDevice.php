<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PushDevice extends Model
{
    public const PROVIDER_FCM = 'fcm';

    public const PROVIDER_APNS = 'apns';

    public const PLATFORM_ANDROID = 'android';

    public const PLATFORM_IOS = 'ios';

    public const ENVIRONMENT_PRODUCTION = 'production';

    public const ENVIRONMENT_SANDBOX = 'sandbox';

    public const PROVIDERS = [
        self::PROVIDER_FCM,
        self::PROVIDER_APNS,
    ];

    public const PLATFORMS = [
        self::PLATFORM_ANDROID,
        self::PLATFORM_IOS,
    ];

    public const ENVIRONMENTS = [
        self::ENVIRONMENT_PRODUCTION,
        self::ENVIRONMENT_SANDBOX,
    ];

    protected $fillable = [
        'user_id',
        'provider',
        'platform',
        'environment',
        'token_hash',
        'token',
        'device_identifier_hash',
        'device_name',
        'app_version',
        'app_identifier',
        'locale',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token',
        'token_hash',
        'device_identifier_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (PushDevice $device): void {
            $device->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public static function hashToken(string $provider, string $token): string
    {
        return hash('sha256', strtolower(trim($provider))."\0".trim($token));
    }
}
