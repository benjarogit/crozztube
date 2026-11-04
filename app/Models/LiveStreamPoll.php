<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveStreamPoll extends Model
{
    protected $fillable = ['live_stream_id', 'question', 'status', 'duration_seconds', 'starts_at', 'ends_at'];
    protected $casts = ['duration_seconds' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function liveStream(): BelongsTo { return $this->belongsTo(LiveStream::class); }
    public function options(): HasMany { return $this->hasMany(LiveStreamPollOption::class, 'poll_id')->orderBy('position'); }
}
