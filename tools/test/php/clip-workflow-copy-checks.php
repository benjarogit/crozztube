<?php
$root = dirname(__DIR__, 3);
$batch = json_decode(file_get_contents($root.'/tools/i18n/batches/clip-confirmation-workflow.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = 0;
foreach (['en' => 'english', 'en_au' => 'en_au', 'en_ca' => 'en_ca', 'en_gb' => 'en_gb', 'en_nz' => 'en_nz', 'en_za' => 'en_za', 'de_de' => 'de_de', 'de_at' => 'de_at', 'de_ch' => 'de_ch'] as $locale => $file) {
    $catalog = require $root.'/lang/'.$locale.'/'.$file.'.php';
    foreach ($batch['messages'] as $key => $translations) {
        $expected = $translations[$locale] ?? $translations[str_starts_with($locale, 'de') ? 'de' : 'en'];
        if (($catalog[$key] ?? null) !== $expected) throw new RuntimeException('Incorrect workflow translation: '.$locale.' / '.$key);
        preg_match_all('/\{[a-z_]+\}/i', $key, $source); preg_match_all('/\{[a-z_]+\}/i', $expected, $target);
        if ($source[0] !== $target[0] || (str_starts_with($locale, 'de') && preg_match('/\b(Sie|Ihnen|Ihrer)\b/u', $expected))) throw new RuntimeException('Damaged workflow copy: '.$locale);
        $checks++;
    }
}
echo $checks." clip workflow copy checks passed. Nine reference/regional catalogs, complete messages and named placeholders.\n";
