<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImageUpload extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer', 'target_id' => 'integer', 'size' => 'integer',
        'width' => 'integer', 'height' => 'integer', 'expires_at' => 'datetime',
        'attached_at' => 'datetime', 'detached_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mirrors(): HasMany
    {
        return $this->hasMany(ImageUploadMirror::class);
    }
}
