<?php

namespace App\Support;

/** Address-style aliases never create additional selectable languages. */
final class LanguageLocales
{
    public const ALIASES = [
        'de_de_formal' => 'de_de',
        'nl_nl_formal' => 'nl_nl',
        'de_ch_informal' => 'de_ch',
    ];

    public static function normalize(?string $locale): string
    {
        $locale = strtolower(str_replace('-', '_', trim((string) $locale)));
        foreach (self::registeredTags() as $code => $tag) {
            if ($locale === strtolower(str_replace('-', '_', $tag))) return $code;
        }

        return $locale;
    }

    public static function canonical(?string $locale): string
    {
        $locale = self::normalize($locale);

        return self::ALIASES[$locale] ?? $locale;
    }

    public static function languageTag(?string $locale): string
    {
        $locale = self::canonical($locale);

        return self::registeredTags()[$locale] ?? str_replace('_', '-', $locale);
    }

    private static function registeredTags(): array
    {
        static $tags = null;

        return $tags ??= json_decode(file_get_contents(resource_path('localization/locale-tags.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function isAlias(?string $locale): bool
    {
        return isset(self::ALIASES[self::normalize($locale)]);
    }
}
