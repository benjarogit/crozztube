<?php
$root = dirname(__DIR__, 3);
$batch = json_decode(file_get_contents($root.'/tools/i18n/batches/viewer-quality-reference.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = 0;
foreach ($batch['messages'] as $key => $locales) {
    foreach ($locales as $locale => $expected) {
        $catalogs[$locale] ??= require (glob($root.'/lang/'.$locale.'/*.php')[0]);
        if (($catalogs[$locale][$key] ?? null) !== $expected) throw new RuntimeException('Copy mismatch: '.$locale.' '.$key);
        if (str_starts_with($locale, 'de') && preg_match('/\b(Sie|Ihnen|Ihrer)\b/u', $expected)) throw new RuntimeException('Formal address in reference copy.');
        $checks++;
    }
}
echo "Viewer quality copy: {$checks} reviewed reference messages passed.\n";
