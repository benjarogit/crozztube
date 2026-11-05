<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThumbnailMirror extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_checked_at' => 'datetime', 'next_check_at' => 'datetime', 'next_attempt_at' => 'datetime',
        'failure_count' => 'integer',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ThumbnailProvider::class, 'provider_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
