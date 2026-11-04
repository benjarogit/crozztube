<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PushDelivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected static function booted(): void
    {
        static::creating(function (self $delivery): void {
            $delivery->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'expires_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
