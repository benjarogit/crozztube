<?php

namespace App\Models\Concerns;

use App\Support\UserBlocks;
use Illuminate\Database\Eloquent\Builder;

trait FiltersBlockedAuthors
{
    protected static function bootFiltersBlockedAuthors(): void
    {
        static::addGlobalScope('blocked_authors', static function (Builder $query): void {
            $viewer = UserBlocks::currentViewer();
            if ($viewer) {
                UserBlocks::filter($query, $query->getModel()->qualifyColumn(
                    $query->getModel() instanceof \App\Models\UserNotification ? 'actor_id' : 'user_id'
                ), (int) $viewer->id);
            }
        });
    }
}
