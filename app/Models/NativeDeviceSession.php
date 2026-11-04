<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class NativeDeviceSession extends Model
{
    use Prunable;

    public const REQUEST_ATTRIBUTE = 'playhub.native_device_session';

    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'client_type',
        'platform',
        'device_name',
        'device_identifier_hash',
        'app_version',
        'os_version',
        'ip_address',
        'user_agent',
        'last_seen_at',
        'expires_at',
        'refresh_expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'device_identifier_hash',
        'personal_access_token_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'refresh_expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(NativeSessionRefreshToken::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $expiry): void {
                $expiry->where('refresh_expires_at', '>', now())
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('refresh_expires_at')
                            ->where('expires_at', '>', now());
                    });
            });
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->refresh_expires_at?->isFuture() ?? $this->expires_at->isFuture());
    }

    public function prunable(): Builder
    {
        $retentionDays = max(1, min((int) config('native_auth.native_session_retention_days', 30), 3650));
        $threshold = now()->subDays($retentionDays);

        return static::query()->where(function (Builder $query) use ($threshold): void {
            $query->where(function (Builder $expiry) use ($threshold): void {
                $expiry->where('refresh_expires_at', '<=', $threshold)
                    ->orWhere(function (Builder $legacy) use ($threshold): void {
                        $legacy->whereNull('refresh_expires_at')
                            ->where('expires_at', '<=', $threshold);
                    });
            })
                ->orWhere('revoked_at', '<=', $threshold);
        });
    }

    protected function pruning(): void
    {
        if (! $this->personal_access_token_id) {
            return;
        }

        PersonalAccessToken::query()
            ->whereKey($this->personal_access_token_id)
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $this->user_id)
            ->delete();
    }
}
