<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveStreamCategory extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order', 'enabled'];

    protected $casts = ['enabled' => 'boolean', 'sort_order' => 'integer'];

    public function liveStreams(): HasMany
    {
        return $this->hasMany(LiveStream::class, 'category_id');
    }
}
