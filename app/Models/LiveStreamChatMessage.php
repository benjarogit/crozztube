<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveStreamChatMessage extends Model
{
    use \App\Models\Concerns\FiltersBlockedAuthors;

    protected $fillable = ['live_stream_id', 'user_id', 'body', 'normalized_hash', 'moderation_status', 'moderation_reason', 'pinned_at'];
    protected $casts = ['pinned_at' => 'datetime'];

    public function liveStream(): BelongsTo { return $this->belongsTo(LiveStream::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
