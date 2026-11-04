<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadSession extends Model
{
    protected $fillable = [
        'uuid',
        'client_upload_id',
        'user_id',
        'video_id',
        'status',
        'original_filename',
        'mime_type',
        'file_size',
        'chunk_size',
        'total_chunks',
        'received_chunks',
        'received_chunk_indexes',
        'metadata',
        'error_message',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'chunk_size' => 'integer',
        'total_chunks' => 'integer',
        'received_chunks' => 'integer',
        'received_chunk_indexes' => 'array',
        'metadata' => 'array',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
