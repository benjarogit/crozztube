<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveStreamPollOption extends Model
{
    protected $fillable = ['poll_id', 'label', 'position', 'votes_count'];
    protected $casts = ['position' => 'integer', 'votes_count' => 'integer'];

    public function poll(): BelongsTo { return $this->belongsTo(LiveStreamPoll::class, 'poll_id'); }
}
