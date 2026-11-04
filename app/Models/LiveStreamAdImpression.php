<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveStreamAdImpression extends Model
{
    protected $fillable = ['uuid', 'live_stream_id', 'viewer_id', 'ad_id', 'status', 'gross_cents', 'creator_share_percent', 'creator_amount_cents', 'duration_seconds', 'served_at', 'completed_at'];
    protected $casts = ['gross_cents' => 'integer', 'creator_share_percent' => 'float', 'creator_amount_cents' => 'integer', 'duration_seconds' => 'integer', 'served_at' => 'datetime', 'completed_at' => 'datetime'];

    public function liveStream(): BelongsTo { return $this->belongsTo(LiveStream::class); }
    public function viewer(): BelongsTo { return $this->belongsTo(User::class, 'viewer_id'); }
}
