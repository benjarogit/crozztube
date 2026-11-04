<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use HasFactory;
    use \App\Models\Concerns\FiltersBlockedAuthors;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'bio',
        'about',
        'avatar_path',
        'cover_path',
        'cover_position_y',
        'featured_video_id',
        'subscribers_count',
        'videos_count',
    ];

    protected $casts = [
        'cover_position_y' => 'integer',
        'featured_video_id' => 'integer',
        'subscribers_count' => 'integer',
        'videos_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function liveStreams(): HasMany
    {
        return $this->hasMany(LiveStream::class);
    }

    public function featuredVideo(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'featured_video_id');
    }

    public function communityPosts(): HasMany
    {
        return $this->hasMany(CommunityPost::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
