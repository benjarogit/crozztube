<?php
// Exercise the actual writer against disposable catalogs, never live language files.
$root = dirname(__DIR__, 3);
$temporary = sys_get_temp_dir().'/crozztube-writer-'.bin2hex(random_bytes(6));
foreach (['tools/i18n', 'lang/en', 'lang/de_de', 'lang/ru_ru', 'tools/i18n/reviews'] as $directory) mkdir($temporary.'/'.$directory, 0700, true);
copy($root.'/tools/i18n/apply-reviewed.php', $temporary.'/tools/i18n/apply-reviewed.php');
$packs = [
    'en' => ['Saved' => 'Saved', '__patterns' => ['unread' => '{count} unread', 'count_view' => '{count} views']],
    'de_de' => ['Saved' => 'Gespeichert', '__patterns' => ['unread' => '{count} ungelesen']],
    'ru_ru' => ['Saved' => 'Сохранено', '__patterns' => ['unread' => 'Непрочитанные: {count}']],
];
$file = static fn (string $locale): string => $temporary.'/lang/'.$locale.'/'.($locale === 'en' ? 'english.php' : 'pack.php');
$write = static function (string $locale, array $pack) use ($file): void { file_put_contents($file($locale), '<?php return '.var_export($pack, true).';'); };
foreach ($packs as $locale => $pack) $write($locale, $pack);
$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void { if (! $condition) throw new RuntimeException($message); $count++; };
$existingReview = ['source_hash' => 'kept', 'translation_hash' => 'kept', 'status' => 'verified', 'contexts' => ['Existing independently checked context']];
file_put_contents($temporary.'/tools/i18n/reviews/de_de.json', json_encode(['locale' => 'de_de', 'messages' => [hash('sha256', '__patterns:unread') => $existingReview]]));
$run = static function (string $locale, array $metadata, array $snapshot) use ($temporary): array {
    file_put_contents($temporary.'/reference.json', json_encode($snapshot, JSON_UNESCAPED_UNICODE));
    file_put_contents($temporary.'/patch.json', '{}');
    file_put_contents($temporary.'/metadata.json', json_encode($metadata, JSON_UNESCAPED_UNICODE));
    $process = proc_open([PHP_BINARY, $temporary.'/tools/i18n/apply-reviewed.php', '--locale='.$locale, '--translations='.$temporary.'/patch.json', '--metadata='.$temporary.'/metadata.json', '--reference='.$temporary.'/reference.json', '--review-context=Disposable writer integration check', '--apply'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) throw new RuntimeException('Could not launch the native catalog writer.');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $output, $error];
};
try {
    [$status] = $run('de_de', ['__patterns' => ['count_view' => '{count} Aufrufe']], $packs);
    $check($status === 0, 'A partial metadata patch applies');
    $german = require $file('de_de');
    $check($german['Saved'] === 'Gespeichert' && $german['__patterns']['unread'] === '{count} ungelesen', 'Unrelated copy and patterns stay intact');
    $review = json_decode(file_get_contents($temporary.'/tools/i18n/reviews/de_de.json'), true);
    $check($review['messages'][hash('sha256', '__patterns:unread')] === $existingReview && count($review['messages']) === 2, 'Only the reviewed entry is recorded, without resetting old contexts');
    $german['__patterns']['unread'] = 'Concurrent unrelated copy'; $write('de_de', $german);
    [$status] = $run('de_de', ['__patterns' => ['count_view_one' => '{count} Aufruf']], $packs);
    $german = require $file('de_de');
    $check($status === 0 && $german['__patterns']['unread'] === 'Concurrent unrelated copy', 'A disjoint edit survives the patch');
    $bytes = file_get_contents($file('de_de'));
    [$status] = $run('de_de', ['__patterns' => ['count_view' => 'Aufrufe: {count}']], $packs);
    $check($status !== 0 && file_get_contents($file('de_de')) === $bytes, 'A stale edit to the same pattern is rejected without data loss');
    [$status] = $run('de_de', ['__patterns' => ['count_view' => 'Aufrufe']], ['en' => $packs['en'], 'de_de' => $german]);
    $check($status !== 0 && file_get_contents($file('de_de')) === $bytes, 'A missing count placeholder is rejected');
    [$status] = $run('ru_ru', ['__patterns' => ['count_view_few' => '{count} просмотра']], $packs);
    $review = json_decode(file_get_contents($temporary.'/tools/i18n/reviews/ru_ru.json'), true);
    $sourceHash = hash('sha256', json_encode('{count} views', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $check($status === 0 && $review['messages'][hash('sha256', '__patterns:count_view_few')]['source_hash'] === $sourceHash, 'A native CLDR category references the generic source without fake English forms');
    [$status] = $run('ru_ru', ['__patterns' => ['count_user_few' => '{count} пользователя']], $packs);
    $check($status !== 0, 'An unknown count source must be added to the reference first');
    echo $count." isolated catalog-writer checks passed. No live catalogs changed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($temporary);
}
