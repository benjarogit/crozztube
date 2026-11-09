<?php

// Focused reference-copy verification, not linguistic acceptance of all locales.
$root = dirname(__DIR__, 3);
$reference = json_decode(file_get_contents($root.'/resources/localization/media-provider-copy.de.json'), true, 32, JSON_THROW_ON_ERROR);
$catalogs = ['en/english', 'en_au/en_au', 'en_ca/en_ca', 'en_gb/en_gb', 'en_nz/en_nz', 'en_za/en_za', 'de_de/de_de', 'de_at/de_at', 'de_ch/de_ch'];
$placeholders = static function (string $text): array {
    preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $text, $matches);
    sort($matches[0]);
    return $matches[0];
};
$checks = 0;
foreach ($catalogs as $name) {
    $catalog = require $root.'/lang/'.$name.'.php';
    foreach ($reference as $source => $translation) {
        $value = $catalog[$source] ?? null;
        if (! is_string($value) || trim($value) === '' || $placeholders($value) !== $placeholders($source)) {
            throw new RuntimeException('Missing media-provider reference text or damaged placeholder: '.$name.' / '.$source);
        }
        if (str_starts_with($name, 'de_') && (preg_match('/\b(?:Sie|Ihnen|Ihr(?:e|en|em|er|es)?)\b/u', $value)
            || (str_starts_with($name, 'de_ch') && str_contains($value, 'ß')))) {
            throw new RuntimeException('Media-provider copy violates personal address or Swiss spelling: '.$name.' / '.$source);
        }
    }
    if (str_starts_with($name, 'de_')) {
        foreach (['Not configured', 'Testing...', 'Remove credential'] as $source) {
            if ($catalog[$source] !== $reference[$source]) throw new RuntimeException('Unresolved German media-provider control: '.$source);
        }
    }
    $checks++;
}
echo $checks." media-provider reference catalogs passed: complete messages, named placeholders, personal German address and Swiss spelling.\n";
