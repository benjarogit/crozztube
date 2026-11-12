<?php

namespace App\Support;

use App\Models\VideoLanguage;

final class VideoLanguages
{
    public static function idForCode(?string $code): ?int
    {
        if (! $code) return null;
        $code = strtolower(str_replace('_', '-', $code));
        $languages = VideoLanguage::query()->get(['id', 'code']);
        $exact = $languages->first(fn ($language): bool => strtolower(str_replace('_', '-', $language->code)) === $code);
        $base = $languages->first(fn ($language): bool => strtolower($language->code) === explode('-', $code)[0]);

        return $exact?->id ?? $base?->id;
    }
}
