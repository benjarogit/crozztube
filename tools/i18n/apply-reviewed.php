<?php

// Apply reviewed complete messages against an explicit pre-edit snapshot.
// Dry run by default; concurrent administrator edits cause a conflict.
$options = getopt('', ['locale:', 'translations:', 'reference:', 'metadata:', 'review-context:', 'apply']);
foreach (['locale', 'translations', 'reference'] as $required) {
    if (! is_string($options[$required] ?? null)) { fwrite(STDERR, "Required: --locale=CODE[,CODE] --translations=JSON --reference=EXPORT [--metadata=JSON] [--apply]\n"); exit(2); }
}
$translations = json_decode(file_get_contents($options['translations']), true, flags: JSON_THROW_ON_ERROR);
$reference = json_decode(file_get_contents($options['reference']), true, flags: JSON_THROW_ON_ERROR);
$metadata = isset($options['metadata']) ? json_decode(file_get_contents($options['metadata']), true, flags: JSON_THROW_ON_ERROR) : [];
if (isset($options['review-context']) && trim((string) $options['review-context']) === '') throw new RuntimeException('A concrete review context is required.');
$tokens = static function (string $value): array { preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $value, $matches); sort($matches[0]); return $matches[0]; };
foreach ($translations as $key => $value) {
    if (! is_string($key) || ! is_string($value) || str_starts_with($key, '__') || trim($value) === '' || $tokens($key) !== $tokens($value)) throw new RuntimeException('Invalid reviewed message or placeholders: '.$key);
}
foreach (array_keys($metadata) as $key) if (! in_array($key, ['__patterns', '__plurals'], true)) throw new RuntimeException('Unsupported metadata section.');
foreach ($metadata['__patterns'] ?? [] as $key => $value) {
    $referencePattern = $reference['en']['__patterns'][$key] ?? null;
    if ($referencePattern === null && preg_match('/^((?:count_(?:user|video|comment|subscriber|view|like|reply|playlist)|(?:ago|duration)_(?:year|month|week|day|hour|minute|second)))_(?:zero|one|two|few|many|other)$/D', $key, $match)) $referencePattern = $reference['en']['__patterns'][$match[1]] ?? null;
    $expectedTokens = $tokens($referencePattern ?? '');
    if ($referencePattern === null && preg_match('/^(?:count_(?:user|video|comment|subscriber|view|like|reply|playlist)|(?:ago|duration)_(?:year|month|week|day|hour|minute|second))(?:_(?:zero|one|two|few|many|other))?$/D', $key)) {
        foreach (explode(',', $options['locale']) as $target) if (! preg_match('/^en(?:_[a-z]+)?$/D', $target)) throw new RuntimeException('Add the English count reference first.');
        $expectedTokens = ['{count}'];
    }
    if (! is_string($value) || trim($value) === '' || $tokens($value) !== $expectedTokens) throw new RuntimeException('Invalid reviewed pattern: '.$key);
}
foreach ($metadata['__plurals'] ?? [] as $key => $forms) {
    if (! is_array($forms) || $forms === [] || (! array_is_list($forms) && (! isset($forms['other']) || array_diff(array_keys($forms), ['zero', 'one', 'two', 'few', 'many', 'other']) !== []))) throw new RuntimeException('Invalid plural categories: '.$key);
    foreach ($forms as $value) if (! is_string($value) || trim($value) === '' || $tokens($value) !== []) throw new RuntimeException('Invalid reviewed plural: '.$key);
}
$updates = [];
foreach (explode(',', $options['locale']) as $locale) {
    if (! preg_match('/^[a-z0-9_]+$/', $locale) || ! isset($reference[$locale])) throw new RuntimeException('Unknown target locale.');
    $files = glob(dirname(__DIR__, 2).'/lang/'.$locale.'/*.php') ?: [];
    if (count($files) !== 1) throw new RuntimeException('Expected one language file.');
    $file = $files[0]; $pack = require $file; $changes = 0;
    foreach ([...$translations, ...$metadata] as $key => $value) {
        if (array_key_exists($key, $metadata)) {
            foreach ($value as $item => $_) if (($pack[$key][$item] ?? null) !== ($reference[$locale][$key][$item] ?? null)) throw new RuntimeException('Concurrent edit: '.$locale.' '.$key.'.'.$item);
            // A batch reviews only its own metadata entries. Preserve unrelated
            // patterns/forms without silently reclassifying them as reviewed.
            $value = array_replace((array) ($pack[$key] ?? []), $value);
        } elseif (($pack[$key] ?? null) !== ($reference[$locale][$key] ?? null)) throw new RuntimeException('Concurrent edit: '.$locale.' '.$key);
        if (is_string($value) && str_starts_with($locale, 'de_ch')) $value = str_replace('ß', 'ss', $value);
        if (($pack[$key] ?? null) === $value) continue;
        $pack[$key] = $value; $changes++;
    }
    $updates[] = [$file, $pack, $changes];
}
foreach ($updates as [$file, $pack, $changes]) {
    if (isset($options['apply']) && $changes) {
        $temporary = tempnam(dirname($file), '.i18n-');
        try {
            $export = "<?php\n\nreturn ".var_export($pack, true).";\n";
            // Trim generated code whitespace without changing multiline translation literals.
            $export = implode('', array_map(static fn ($token): string => is_array($token)
                ? ($token[0] === T_WHITESPACE ? preg_replace('/[ \t]+\n/', "\n", $token[1]) : $token[1])
                : $token, token_get_all($export)));
            if (file_put_contents($temporary, $export) === false) throw new RuntimeException('Could not write language file.');
            chmod($temporary, fileperms($file) & 0777);
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                chown($temporary, fileowner(dirname($file)));
                chgrp($temporary, filegroup(dirname($file)));
            }
            if (! rename($temporary, $file)) throw new RuntimeException('Could not replace language file.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
    if (isset($options['apply'], $options['review-context'])) {
        $context = trim((string) $options['review-context']);
        if ($context === '') throw new RuntimeException('A concrete review context is required.');
        $directory = dirname(__DIR__, 2).'/tools/i18n/reviews';
        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) throw new RuntimeException('Cannot create review directory.');
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            chown($directory, fileowner(dirname(__DIR__, 2)));
            chgrp($directory, filegroup(dirname(__DIR__, 2)));
        }
        $reviewFile = $directory.'/'.basename(dirname($file)).'.json';
        $reviews = is_file($reviewFile) ? json_decode(file_get_contents($reviewFile), true, flags: JSON_THROW_ON_ERROR) : ['version' => 1, 'locale' => basename(dirname($file)), 'messages' => []];
        $source = require dirname(__DIR__, 2).'/lang/en/english.php';
        foreach ($translations as $key => $_) {
            $reviews['messages'][hash('sha256', $key)] = [
                'source_hash' => hash('sha256', $source[$key] ?? $key),
                'translation_hash' => hash('sha256', $pack[$key]),
                'status' => 'editorial',
                'contexts' => [$context],
                'reviewed_at' => gmdate('c'),
            ];
        }
        foreach ($metadata as $section => $items) {
            foreach ($items as $key => $value) {
                $encode = static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $referenceValue = $source[$section][$key] ?? null;
                if ($referenceValue === null && $section === '__patterns' && preg_match('/^((?:count_(?:user|video|comment|subscriber|view|like|reply|playlist)|(?:ago|duration)_(?:year|month|week|day|hour|minute|second)))_(?:zero|one|two|few|many|other)$/D', $key, $match)) $referenceValue = $source[$section][$match[1]] ?? null;
                $reviews['messages'][hash('sha256', $section.':'.$key)] = [
                    'source_hash' => hash('sha256', $encode($referenceValue)),
                    'translation_hash' => hash('sha256', $encode($value)),
                    'status' => 'editorial',
                    'contexts' => [$context],
                    'reviewed_at' => gmdate('c'),
                ];
            }
        }
        ksort($reviews['messages']);
        $temporary = tempnam($directory, '.review-');
        try {
            chmod($temporary, 0644);
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) { chown($temporary, fileowner($directory)); chgrp($temporary, filegroup($directory)); }
            if (file_put_contents($temporary, json_encode($reviews, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false || ! rename($temporary, $reviewFile)) throw new RuntimeException('Cannot record editorial review.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
    echo (isset($options['apply']) ? 'Updated ' : 'Would update ').basename(dirname($file)).': '.$changes." reviewed values.\n";
}
