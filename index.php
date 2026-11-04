<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Entry markers remain in memory; only opt-in local/testing diagnostics emit them.
$playhubProbeRun = $_SERVER['HTTP_X_PLAYHUB_PROBE_RUN'] ?? null;
if (is_string($playhubProbeRun) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $playhubProbeRun)) {
    $_SERVER['PLAYHUB_PROBE_ENTRY'] = ['entry' => hrtime(true)];
}

if (! file_exists(__DIR__.'/.env')) {
    header('Location: /install.php', true, 302);
    exit;
}

if (file_exists($maintenance = __DIR__.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

if (isset($_SERVER['PLAYHUB_PROBE_ENTRY'])) {
    $_SERVER['PLAYHUB_PROBE_ENTRY']['autoload_start'] = hrtime(true);
}
require __DIR__.'/vendor/autoload.php';
if (isset($_SERVER['PLAYHUB_PROBE_ENTRY'])) {
    $_SERVER['PLAYHUB_PROBE_ENTRY']['autoload_end'] = hrtime(true);
}

$playhubDefaultLivekitKeyFile = __DIR__.'/livekit/runtime/keys.yaml';
$playhubConfiguredLivekitKeyFile = getenv('LIVEKIT_KEY_FILE') ?: '';
if ($playhubConfiguredLivekitKeyFile === '' || (! @is_readable($playhubConfiguredLivekitKeyFile) && $playhubConfiguredLivekitKeyFile !== $playhubDefaultLivekitKeyFile)) {
    $_ENV['LIVEKIT_KEY_FILE'] = $playhubDefaultLivekitKeyFile;
    $_SERVER['LIVEKIT_KEY_FILE'] = $playhubDefaultLivekitKeyFile;
}
$app = require_once __DIR__.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
