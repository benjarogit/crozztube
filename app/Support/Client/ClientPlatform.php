<?php

namespace App\Support\Client;

enum ClientPlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
    case AndroidTv = 'android-tv';
    case TvOs = 'tvos';
    case Tizen = 'tizen';
    case WebOs = 'webos';

    public function deviceFamily(): string
    {
        return match ($this) {
            self::Android, self::Ios => 'mobile',
            self::AndroidTv, self::TvOs, self::Tizen, self::WebOs => 'tv',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $platform): string => $platform->value, self::cases());
    }
}
