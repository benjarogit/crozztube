<?php

namespace App\Services;

use App\Support\GeneralSettings;

final class LocalizationService
{
    public function catalog(): array
    {
        $settings = GeneralSettings::settings();
        $languages = [];
        foreach (GeneralSettings::languageIdentifiers() as $locale) {
            if (in_array($locale, (array) ($settings['disabledLanguages'] ?? []), true)) {
                continue;
            }
            $pack = $this->readPack($locale);
            if ($pack !== null) {
                $languages[] = $this->metadata($locale, $pack);
            }
        }
        $default = (string) ($settings['defaultLanguage'] ?? 'en');
        if (! collect($languages)->contains('code', $default)) {
            $default = collect($languages)->contains('code', 'en') ? 'en' : ($languages[0]['code'] ?? 'en');
        }

        return ['default_locale' => $default, 'languages' => $languages];
    }

    public function pack(string $locale): ?array
    {
        $locale = strtolower(str_replace('-', '_', $locale));
        if (! preg_match('/^[a-z0-9_]+$/', $locale)
            || in_array($locale, (array) (GeneralSettings::settings()['disabledLanguages'] ?? []), true)
            || ! in_array($locale, GeneralSettings::languageIdentifiers(), true)) {
            return null;
        }
        $pack = $this->readPack($locale);
        if ($pack === null) {
            return null;
        }

        return [
            ...$this->metadata($locale, $pack),
            'translations' => (object) array_filter($pack, fn ($value, $key): bool => ! str_starts_with((string) $key, '__') && is_string($value), ARRAY_FILTER_USE_BOTH),
            'plurals' => (object) (array) ($pack['__plurals'] ?? []),
            'patterns' => (object) (array) ($pack['__patterns'] ?? []),
        ];
    }

    public function resolve(?string $requested): string
    {
        $catalog = $this->catalog();
        $codes = array_column($catalog['languages'], 'code');
        $requested = strtolower(str_replace('-', '_', (string) $requested));
        if (in_array($requested, $codes, true)) {
            return $requested;
        }
        $short = explode('_', $requested)[0];
        foreach ($codes as $code) {
            if (explode('_', $code)[0] === $short) {
                return $code;
            }
        }

        return $catalog['default_locale'];
    }

    private function readPack(string $locale): ?array
    {
        $root = realpath((string) config('playhub.language_path', base_path('lang')));
        if ($root === false) {
            return null;
        }
        $files = glob($root.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.'*.php') ?: [];
        $file = $files !== [] ? realpath($files[0]) : false;
        if ($file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }
        $pack = require $file;

        return is_array($pack) ? $pack : null;
    }

    private function metadata(string $locale, array $pack): array
    {
        $meta = (array) ($pack['__meta'] ?? []);

        return [
            'code' => $locale,
            'label' => (string) ($meta['label'] ?? strtoupper($locale)),
            'native_label' => (string) ($meta['native_label'] ?? $meta['label'] ?? strtoupper($locale)),
            'direction' => ($meta['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
        ];
    }
}
