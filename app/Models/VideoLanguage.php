<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoLanguage extends Model
{
    protected $fillable = [
        'name',
        'code',
        'sort_order',
    ];

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class, 'language_id');
    }
}
