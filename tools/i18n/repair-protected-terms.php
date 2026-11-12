<?php

// Repair leaked markers only where the English source proves their meaning.
// Dry run by default. This never changes application settings or user content.
$apply = in_array('--apply', $argv, true);
$terms = ['ZXTERM0ZX' => 'PlayHub', 'ZXTERM1ZX' => 'LiveKit', 'ZXTERM2ZX' => 'RTMP', 'ZXTERM3ZX' => 'Docker', 'ZXTERM4ZX' => 'JavaScript', 'ZXTERM5ZX' => 'SVG'];
$changed = $values = 0;
foreach (glob(dirname(__DIR__, 2).'/lang/*/*.php') ?: [] as $file) {
    $pack = require $file;
    $dirty = false;
    foreach ($pack as $source => $value) {
        if (! is_string($value) || ! preg_match('/ZXTERM\d+ZX/', $value)) continue;
        $next = $value;
        foreach ($terms as $marker => $term) {
            if (str_contains((string) $source, $term)) $next = str_replace($marker, $term, $next);
        }
        if ($next === $value) continue;
        $pack[$source] = $next;
        $dirty = true;
        $values++;
    }
    if (! $dirty) continue;
    $changed++;
    if ($apply) {
        $temporary = tempnam(dirname($file), '.i18n-');
        try {
            if (file_put_contents($temporary, "<?php\n\nreturn ".var_export($pack, true).";\n") === false) throw new RuntimeException('Could not write catalog.');
            chmod($temporary, fileperms($file) & 0777);
            if (! rename($temporary, $file)) throw new RuntimeException('Could not replace catalog.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
}
echo ($apply ? 'Repaired ' : 'Would repair ').$values.' values in '.$changed." language packs.\n";
