<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sticker extends Model
{
    protected $fillable = ['name', 'slug', 'image_path', 'category', 'price_cents', 'enabled', 'sort_order', 'provider', 'provider_id', 'reference_hash'];
    protected $casts = ['price_cents' => 'integer', 'enabled' => 'boolean', 'sort_order' => 'integer'];

    public function contributions(): HasMany
    {
        return $this->hasMany(LiveStreamContribution::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

}
