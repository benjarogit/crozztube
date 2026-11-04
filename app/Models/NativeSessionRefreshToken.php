<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NativeSessionRefreshToken extends Model
{
    protected $fillable = [
        'native_device_session_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            $token->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(NativeDeviceSession::class, 'native_device_session_id');
    }
}
