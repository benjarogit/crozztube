<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TvDeviceAuthorization extends Model
{
    use MassPrunable;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_DENIED = 'denied';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'device_code_lookup',
        'device_code_hash',
        'user_code_lookup',
        'user_code_hash',
        'status',
        'approved_by_user_id',
        'platform',
        'device_name',
        'device_identifier_hash',
        'app_version',
        'os_version',
        'request_ip_address',
        'approval_ip_address',
        'user_agent',
        'poll_interval_seconds',
        'poll_attempts',
        'last_polled_at',
        'approved_at',
        'consumed_at',
        'denied_at',
        'expires_at',
    ];

    protected $hidden = [
        'device_code_lookup',
        'device_code_hash',
        'user_code_lookup',
        'user_code_hash',
        'device_identifier_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $authorization): void {
            $authorization->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'poll_attempts' => 'integer',
            'poll_interval_seconds' => 'integer',
            'last_polled_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'denied_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function prunable(): Builder
    {
        $retentionDays = max(1, min((int) config('native_auth.tv_authorization_retention_days', 7), 365));

        return static::query()->where('expires_at', '<=', now()->subDays($retentionDays));
    }
}
