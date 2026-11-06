<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error::class.': '.$error->getMessage()."\n"); exit(1); });

use App\Support\GeneralSettings;
use App\Support\LanguageLocales;

$apply = in_array('--apply', $argv, true);
$root = realpath((string) config('playhub.language_path', base_path('lang')));
if ($root === false) throw new RuntimeException('Language directory not found.');
$files = [];
foreach (LanguageLocales::ALIASES as $alias => $canonical) {
    if ((glob($root.'/'.$canonical.'/*.php') ?: []) === []) throw new RuntimeException('Canonical language missing: '.$canonical);
    foreach (glob($root.'/'.$alias.'/*.php') ?: [] as $file) $files[] = $file;
}
$settingsPath = storage_path('app/playhub-general-settings.json');
$settingsRaw = is_file($settingsPath) ? file_get_contents($settingsPath) : null;
$settings = $settingsRaw !== null ? json_decode($settingsRaw, true, flags: JSON_THROW_ON_ERROR) : [];
$next = $settings;
if (LanguageLocales::isAlias($next['defaultLanguage'] ?? null)) $next['defaultLanguage'] = GeneralSettings::settings()['defaultLanguage'];
if (isset($next['disabledLanguages'])) $next['disabledLanguages'] = array_values(array_filter($next['disabledLanguages'], static fn ($code): bool => ! LanguageLocales::isAlias($code)));
if (! $apply || ($files === [] && $next === $settings)) {
    echo ($apply ? 'Already retired: ' : 'Would retire: ').count($files)." address-style language files.\n";
    exit(0);
}
$archive = storage_path('app/private/localization-retired/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)));
$archiveOwner = fileowner(dirname(__DIR__, 2));
$archiveGroup = filegroup(dirname(__DIR__, 2));
$ownArchive = static function (string $path) use ($archiveOwner, $archiveGroup): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        chown($path, $archiveOwner);
        chgrp($path, $archiveGroup);
    }
};
if (! mkdir($archive.'/packs', 0700, true)) throw new RuntimeException('Cannot create language archive.');
chmod(dirname($archive), 0700);
chmod($archive, 0700);
foreach ([dirname($archive), $archive, $archive.'/packs'] as $directory) $ownArchive($directory);
$manifest = ['aliases' => LanguageLocales::ALIASES, 'files' => []];
foreach ($files as $file) {
    $destination = $archive.'/packs/'.basename(dirname($file)).'.php';
    if (! copy($file, $destination)) throw new RuntimeException('Cannot archive language file.');
    chmod($destination, 0600);
    $ownArchive($destination);
    $manifest['files'][basename(dirname($file))] = hash_file('sha256', $file);
}
foreach (['playhub-general-settings.json', 'playhub-email-templates.json', 'playhub-content-pages.json'] as $name) {
    $file = storage_path('app/'.$name);
    if (is_file($file)) {
        if (! copy($file, $archive.'/'.$name)) throw new RuntimeException('Cannot archive administrator settings.');
        chmod($archive.'/'.$name, 0600);
        $ownArchive($archive.'/'.$name);
    }
}
if ($next !== $settings) {
    if (file_get_contents($settingsPath) !== $settingsRaw) throw new RuntimeException('Settings changed concurrently.');
    $temporary = tempnam(dirname($settingsPath), '.locale-');
    try {
        if (file_put_contents($temporary, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot write canonical settings.');
        chmod($temporary, 0600);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) { chown($temporary, fileowner($settingsPath)); chgrp($temporary, filegroup($settingsPath)); }
        if (! rename($temporary, $settingsPath)) throw new RuntimeException('Cannot replace settings.');
    } finally { if (is_file($temporary)) unlink($temporary); }
}
// Refuse to remove a pack changed after its archive was created.
foreach ($files as $file) if (hash_file('sha256', $file) !== $manifest['files'][basename(dirname($file))]) throw new RuntimeException('Language changed concurrently.');
foreach ($files as $file) {
    if (! unlink($file)) throw new RuntimeException('Cannot retire archived language file.');
    if (count(scandir(dirname($file))) === 2) rmdir(dirname($file));
}
file_put_contents($archive.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
chmod($archive.'/manifest.json', 0600);
$ownArchive($archive.'/manifest.json');
echo 'Retired '.count($files).' language files; original packs and administrator settings preserved in '.$archive.".\n";
