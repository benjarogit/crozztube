<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class ShortVideos
{
    public const MAX_SECONDS = 60;

    public static function qualifies(mixed $duration, mixed $width, mixed $height, ?string $source = null): bool
    {
        return $source !== 'live_replay' && (float) $duration > 0 && (float) $duration <= self::MAX_SECONDS
            && (int) $width > 0 && (int) $height > (int) $width;
    }

    public static function scope(Builder $query): Builder
    {
        return $query->where('source_type', '!=', 'live_replay')->whereBetween('duration_seconds', [1, self::MAX_SECONDS])
            ->where('video_width', '>', 0)->whereColumn('video_height', '>', 'video_width');
    }
}
