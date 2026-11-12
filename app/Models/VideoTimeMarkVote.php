<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoTimeMarkVote extends Model
{
    protected $fillable = [
        'video_time_mark_id',
        'user_id',
    ];

    public function mark(): BelongsTo
    {
        return $this->belongsTo(VideoTimeMark::class, 'video_time_mark_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
