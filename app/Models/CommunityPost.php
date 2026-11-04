<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CommunityPost extends Model
{
    use HasFactory;
    use \App\Models\Concerns\FiltersBlockedAuthors;

    protected $fillable = [
        'user_id',
        'channel_id',
        'video_id',
        'type',
        'body',
        'url',
        'url_title',
        'url_description',
        'url_image',
        'attachments',
        'metadata',
        'sale_enabled',
        'price_cents',
        'likes_count',
        'comments_count',
        'shares_count',
        'votes_count',
    ];

    protected $casts = [
        'attachments' => 'array',
        'metadata' => 'array',
        'sale_enabled' => 'boolean',
        'price_cents' => 'integer',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'shares_count' => 'integer',
        'votes_count' => 'integer',
    ];

    public static function hashtagLabels(?string $body): array
    {
        preg_match_all('/(?:^|[^\pL\pM\pN_])#([\pL\pN][\pL\pM\pN_]{1,39})(?![\pL\pM\pN_])/u', (string) $body, $matches);

        return collect($matches[1] ?? [])
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique(fn ($tag) => mb_strtolower($tag))
            ->take(20)
            ->values()
            ->all();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->whereNull('parent_id')->latest();
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function pollVotes(): HasMany
    {
        return $this->hasMany(CommunityPollVote::class);
    }
}
