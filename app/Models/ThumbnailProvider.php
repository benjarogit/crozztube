<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThumbnailProvider extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['paused_until' => 'datetime', 'failed_video_ids' => 'array', 'circuit_open' => 'boolean'];
}
