<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

class Comment extends Model
{
    use HasFactory;
    use \App\Models\Concerns\FiltersBlockedAuthors;

    protected $fillable = [
        'video_id',
        'commentable_type',
        'commentable_id',
        'user_id',
        'parent_id',
        'sticker_id',
        'body',
        'attachments',
        'likes_count',
        'is_pinned',
        'pinned_at',
        'pinned_by',
    ];

    protected $casts = [
        'likes_count' => 'integer',
        'attachments' => 'array',
        'is_pinned' => 'boolean',
        'pinned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Comment $comment): void {
            Like::query()
                ->where('likeable_type', Comment::class)
                ->whereIn('likeable_id', $comment->threadModels()->pluck('id'))
                ->delete();
        });
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function sticker(): BelongsTo
    {
        return $this->belongsTo(Sticker::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')->oldest();
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    /**
     * Return this comment and every nested reply before a database cascade removes them.
     */
    public function threadModels(): Collection
    {
        $thread = collect([$this]);
        $knownIds = collect([(int) $this->getKey() => true]);
        $frontier = collect([(int) $this->getKey()]);

        while ($frontier->isNotEmpty()) {
            $children = self::withoutGlobalScopes()
                ->whereIn('parent_id', $frontier)
                ->get()
                ->reject(fn (Comment $child): bool => $knownIds->has((int) $child->getKey()));

            if ($children->isEmpty()) {
                break;
            }

            foreach ($children as $child) {
                $knownIds->put((int) $child->getKey(), true);
            }
            $thread = $thread->concat($children);
            $frontier = $children->pluck('id')->map(fn ($id): int => (int) $id);
        }

        return $thread->unique('id')->values();
    }
}
