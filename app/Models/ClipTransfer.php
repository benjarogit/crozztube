<?php

namespace App\Models;

use App\Support\Clips\ClipStagingObject;
use Illuminate\Database\Eloquent\Model;

final class ClipTransfer extends Model
{
    protected $guarded = [];

    protected $hidden = ['request_key', 'request_hash', 'sha256', 'provider_state'];

    protected $casts = ['metadata' => 'array', 'provider_state' => 'encrypted:array',
        'bytes' => 'integer', 'reserved_bytes' => 'integer', 'cancel_requested' => 'boolean',
        'grant_until' => 'immutable_datetime', 'cleanup_not_before' => 'immutable_datetime',
        'deadline_at' => 'immutable_datetime', 'reservation_released_at' => 'immutable_datetime'];

    public function stagingObject(): ClipStagingObject
    {
        return new ClipStagingObject($this->uuid, $this->bytes, $this->sha256);
    }
}
