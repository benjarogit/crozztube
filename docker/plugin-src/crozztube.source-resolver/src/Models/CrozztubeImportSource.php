<?php

namespace CrzSourceResolver\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrozztubeImportSource extends Model
{
    protected $table = 'crozztube_import_sources';

    protected $fillable = [
        'video_id',
        'media_url',
        'download_url',
        'kind',
        'state',
        'expires_at',
        'resolved_at',
        'last_checked_at',
        'last_check_status',
        'refresh_started_at',
        'refresh_attempts',
        'error_message',
        'error_category',
        'title',
        'duration_seconds',
        'thumbnail_url',
        'chapters',
        'audio_url',
        'audio_tracks',
        'variants',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'refresh_started_at' => 'datetime',
        'refresh_attempts' => 'integer',
        'duration_seconds' => 'integer',
        'chapters' => 'array',
        'variants' => 'array',
        'audio_tracks' => 'array',
    ];

    public const STATES = ['pending', 'refreshing', 'fresh', 'stale', 'error'];

    public function video(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Video::class);
    }

    public function isFresh(): bool
    {
        return $this->state === 'fresh'
            && in_array($this->kind, ['hls', 'mp4', 'hls-synth'], true)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasDirectSource(): bool
    {
        return in_array($this->kind, ['hls', 'mp4', 'hls-synth'], true)
            && is_string($this->media_url)
            && $this->media_url !== '';
    }
}
