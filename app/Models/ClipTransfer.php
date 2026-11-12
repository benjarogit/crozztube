<?php

namespace App\Models;

use App\Support\Clips\ClipStagingObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class ClipTransfer extends Model
{
    protected $guarded = [];

    protected $hidden = ['request_key', 'request_hash', 'sha256', 'provider_state', 'lease_token'];

    protected $casts = ['metadata' => 'array', 'provider_state' => 'encrypted:array',
        'bytes' => 'integer', 'reserved_bytes' => 'integer', 'cancel_requested' => 'boolean',
        'grant_until' => 'immutable_datetime', 'cleanup_not_before' => 'immutable_datetime',
        'deadline_at' => 'immutable_datetime', 'reservation_released_at' => 'immutable_datetime',
        'lease_until' => 'immutable_datetime', 'next_check_at' => 'immutable_datetime',
        'available_at' => 'immutable_datetime', 'notification_handled_at' => 'immutable_datetime',
        'cleanup_checked_at' => 'immutable_datetime'];

    public function scopeAvailable($query)
    {
        return $query->where('phase', 'ready')->whereNotNull('available_at')->where('cancel_requested', false);
    }

    public function stagingObject(): ClipStagingObject
    {
        return new ClipStagingObject($this->uuid, $this->bytes, $this->sha256);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(ClipCollection::class, 'clip_collection_clip')
            ->withTimestamps()->withPivot('sort_order');
    }
}
