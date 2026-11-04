<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoBoostCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'user_id', 'video_id', 'billing_model', 'bid_cents', 'daily_budget_cents',
        'budget_cents', 'spent_cents', 'currency', 'status', 'starts_at', 'ends_at',
        'last_daily_charge_on', 'impressions_count', 'views_count', 'clicks_count',
    ];

    protected $casts = [
        'bid_cents' => 'integer',
        'daily_budget_cents' => 'integer',
        'budget_cents' => 'integer',
        'spent_cents' => 'integer',
        'impressions_count' => 'integer',
        'views_count' => 'integer',
        'clicks_count' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'last_daily_charge_on' => 'date',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
