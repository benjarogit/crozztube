<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoTimeMark extends Model
{
    protected $fillable = [
        'video_id',
        'user_id',
        'start_seconds',
        'body',
        'visibility',
    ];

    protected $casts = [
        'start_seconds' => 'integer',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(VideoTimeMarkVote::class);
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }
}
