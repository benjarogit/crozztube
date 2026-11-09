<?php

namespace App\Models;

use App\Services\NotificationPreferencePolicy;
use App\Services\Push\PushDeliveryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    use HasFactory;
    use \App\Models\Concerns\FiltersBlockedAuthors;

    protected static function booted(): void
    {
        static::creating(static fn (self $notification): bool => app(NotificationPreferencePolicy::class)->allows($notification));

        static::created(static fn (self $notification) => app(PushDeliveryService::class)->enqueue($notification));

        static::updating(static function (self $notification): bool {
            if ($notification->isDirty('read_at') && $notification->read_at === null) {
                return app(NotificationPreferencePolicy::class)->allows($notification);
            }

            return true;
        });
    }

    protected $fillable = [
        'user_id',
        'actor_id',
        'video_id',
        'comment_id',
        'channel_id',
        'community_post_id',
        'clip_transfer_id',
        'type',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function communityPost(): BelongsTo
    {
        return $this->belongsTo(CommunityPost::class);
    }
}
