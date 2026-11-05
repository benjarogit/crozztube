<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageUploadMirror extends Model
{
    protected $guarded = [];

    protected $casts = [
        'priority' => 'integer', 'failure_count' => 'integer', 'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime', 'next_attempt_at' => 'datetime',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ImageUpload::class, 'image_upload_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ThumbnailProvider::class, 'provider_id');
    }
}
