<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gift extends Model
{
    protected $fillable = ['name', 'slug', 'image_path', 'price_cents', 'enabled', 'sort_order'];
    protected $casts = ['price_cents' => 'integer', 'enabled' => 'boolean', 'sort_order' => 'integer'];

    public function contributions(): HasMany { return $this->hasMany(LiveStreamContribution::class); }
}
