<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveStreamContribution extends Model
{
    protected $fillable = ['uuid', 'live_stream_id', 'sender_id', 'recipient_id', 'type', 'gift_id', 'super_sticker_id', 'sticker_id', 'gross_cents', 'fee_cents', 'net_cents', 'currency', 'idempotency_key', 'message', 'gift_name', 'gift_image_path', 'super_sticker_name', 'super_sticker_image_path', 'sticker_name', 'sticker_image_path', 'status'];
    protected $casts = ['gross_cents' => 'integer', 'fee_cents' => 'integer', 'net_cents' => 'integer'];

    public function liveStream(): BelongsTo { return $this->belongsTo(LiveStream::class); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_id'); }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_id'); }
    public function gift(): BelongsTo { return $this->belongsTo(Gift::class); }
    public function superSticker(): BelongsTo { return $this->belongsTo(SuperSticker::class); }
    public function sticker(): BelongsTo { return $this->belongsTo(Sticker::class); }
}
