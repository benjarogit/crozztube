<?php
$root = dirname(__DIR__, 3);
$batch = json_decode(file_get_contents($root.'/tools/i18n/batches/watch-actions-polish.json'), true, 512, JSON_THROW_ON_ERROR);
$count = 0;
foreach (['en'=>'english', 'en_au'=>'en_au', 'en_ca'=>'en_ca', 'en_gb'=>'en_gb', 'en_nz'=>'en_nz', 'en_za'=>'en_za', 'de_de'=>'de_de', 'de_at'=>'de_at', 'de_ch'=>'de_ch'] as $locale=>$file) {
    $catalog = require $root.'/lang/'.$locale.'/'.$file.'.php';
    foreach ($batch['messages'] as $key=>$values) {
        $expected = $values[str_starts_with($locale, 'de') ? 'de' : 'en'];
        if (($catalog[$key] ?? null) !== $expected) throw new RuntimeException('Incorrect action copy: '.$locale.' / '.$key);
        if (str_starts_with($locale, 'de') && preg_match('/\b(Sie|Ihnen|Ihrer)\b/u', $expected)) throw new RuntimeException('Formal action copy: '.$locale);
        $count++;
    }
}
echo $count." action copy checks passed. Full reference/regional English and informal German menu labels.\n";
