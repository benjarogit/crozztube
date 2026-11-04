<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WebsiteAd extends Model
{
    public const PLACEMENTS = [
        'header_after', 'sidebar_left', 'footer_before', 'home_feed', 'video_grid',
        'comments', 'watch_below_player', 'watch_sidebar', 'watch_up_next', 'shorts_feed',
        'search_results', 'channel_content', 'live_content', 'community_feed', 'floating_bottom',
    ];

    public const CREATIVE_TYPES = ['html', 'image', 'text'];
    public const DISPLAY_STYLES = ['card', 'banner', 'seamless', 'sticky', 'floating'];
    public const DEVICES = ['all', 'desktop', 'mobile', 'tablet'];
    public const AUDIENCES = ['all', 'guests', 'members'];

    protected $fillable = [
        'name', 'placement', 'creative_type', 'title', 'body', 'html_content', 'image_url',
        'target_url', 'display_style', 'device', 'audience', 'label', 'background_color',
        'height', 'max_width', 'every_n_items', 'priority', 'enabled', 'dismissible',
        'open_new_tab', 'show_label', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'dismissible' => 'boolean',
        'open_new_tab' => 'boolean',
        'show_label' => 'boolean',
        'height' => 'integer',
        'max_width' => 'integer',
        'every_n_items' => 'integer',
        'priority' => 'integer',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('enabled', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
