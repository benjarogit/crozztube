<?php

namespace App\Services;

use App\Support\GeneralSettings;
use App\Support\LanguageLocales;

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

        return ['default_locale' => $default, 'languages' => $languages, 'aliases' => (object) LanguageLocales::ALIASES];
    }

    public function pack(string $locale): ?array
    {
        $locale = LanguageLocales::canonical($locale);
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
        $settings = GeneralSettings::settings();
        $codes = array_values(array_diff(GeneralSettings::languageIdentifiers(), $settings['disabledLanguages'] ?? []));
        $requested = LanguageLocales::canonical($requested);
        if (in_array($requested, $codes, true)) {
            return $requested;
        }
        $short = explode('_', $requested)[0];
        foreach ($codes as $code) {
            if (explode('_', $code)[0] === $short) {
                return $code;
            }
        }

        return $settings['defaultLanguage'];
    }

    public function translate(string $message, ?string $locale = null, array $values = []): string
    {
        $locale = $this->resolve($locale);
        $dictionary = (array) ($this->pack($locale)['translations'] ?? []);
        $source = $this->readPack('en')[$message] ?? $message;
        $candidate = $dictionary[$message] ?? null;
        // A copied historical English key must not resurrect obsolete default copy.
        $translation = $candidate !== null && $candidate !== $message ? $candidate : $source;
        $tokens = static function (string $text): array {
            preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $text, $matches);
            sort($matches[0]);

            return $matches[0];
        };
        if (! is_string($source) || trim($source) === '' || $tokens($source) !== $tokens($message)) $source = $message;
        if (! is_string($translation) || trim($translation) === '' || $tokens($translation) !== $tokens($message)) $translation = $source;

        return preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $match) use ($values): string {
            $value = $values[$match[1]] ?? null;

            return is_scalar($value) ? (string) $value : $match[0];
        }, $translation);
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
            'language_tag' => LanguageLocales::languageTag($locale),
            'label' => (string) ($meta['label'] ?? strtoupper($locale)),
            'native_label' => (string) ($meta['native_label'] ?? $meta['label'] ?? strtoupper($locale)),
            'direction' => ($meta['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
        ];
    }
}
