<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Playlist extends Model
{
    use HasFactory;
    use \App\Models\Concerns\FiltersBlockedAuthors;

    protected $fillable = [
        'user_id',
        'channel_id',
        'title',
        'description',
        'privacy',
        'color',
        'cover_video_id',
        'is_series',
    ];

    protected $casts = ['is_series' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class)->withTimestamps()->withPivot('sort_order', 'season_number', 'episode_number', 'episode_title')->orderByPivot('sort_order');
    }

    public function coverVideo(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'cover_video_id');
    }
}
