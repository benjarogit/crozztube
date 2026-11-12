<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class ClipCollection extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clips(): BelongsToMany
    {
        return $this->belongsToMany(ClipTransfer::class, 'clip_collection_clip')
            ->withTimestamps()->withPivot('sort_order')->orderByPivot('sort_order');
    }
}
