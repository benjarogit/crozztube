<?php

namespace App\Support\Thumbnails;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class NightWindow
{
    public static function contains(?CarbonInterface $time = null): bool
    {
        $clock = ($time ?? now())->copy()->timezone(config('thumbnails.timezone'))->format('H:i');
        $start = config('thumbnails.night_start');
        $end = config('thumbnails.night_end');
        return $start < $end ? $clock >= $start && $clock < $end : $clock >= $start || $clock < $end;
    }

    public static function next(int $days = 1): Carbon
    {
        $candidate = now()->timezone(config('thumbnails.timezone'))->setTimeFromTimeString(config('thumbnails.night_start'));
        if ($candidate->lte(now())) $candidate->addDay();
        return $candidate->addDays(max(1, $days) - 1);
    }

    public static function paused(): bool
    {
        return is_file(config('thumbnails.pause_file', storage_path('framework/crozztube-maintenance.pause')));
    }
}
