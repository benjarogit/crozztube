<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityPollVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_post_id',
        'user_id',
        'option_index',
    ];

    protected $casts = [
        'option_index' => 'integer',
    ];

    public function communityPost(): BelongsTo
    {
        return $this->belongsTo(CommunityPost::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
