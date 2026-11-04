<?php

$projectRoot = str_replace('\\', '/', dirname(__DIR__));
$defaultLivekitKeyFile = $projectRoot.'/livekit/runtime/keys.yaml';
$configuredLivekitKeyFile = str_replace('\\', '/', trim((string) env('LIVEKIT_KEY_FILE', '')));
$livekitKeyFile = str_starts_with($configuredLivekitKeyFile, '/')
    ? $configuredLivekitKeyFile
    : $projectRoot.'/'.ltrim($configuredLivekitKeyFile, '/');
if ($configuredLivekitKeyFile === '' || ! str_starts_with($livekitKeyFile, $projectRoot.'/livekit/') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $livekitKeyFile)) {
    $livekitKeyFile = $defaultLivekitKeyFile;
}
$livekitKey = '';
$livekitSecret = '';
if (is_file($livekitKeyFile) && is_readable($livekitKeyFile)) {
    $credentials = trim((string) file_get_contents($livekitKeyFile));
    [$livekitKey, $livekitSecret] = array_pad(array_map('trim', explode(':', $credentials, 2)), 2, '');
}
$appHost = parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: '';
$rtmpPort = max(1024, min(65535, (int) env('LIVEKIT_RTMP_PORT', 1936)));
$receiverMediaOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('PLAYHUB_RECEIVER_MEDIA_ORIGINS', '')),
)));
$configuredPluginsPath = trim((string) env('PLAYHUB_PLUGINS_PATH', ''));
$pluginsPath = $configuredPluginsPath === '' ? $projectRoot.'/storage/app/plugins' : (str_starts_with($configuredPluginsPath, '/') ? $configuredPluginsPath : $projectRoot.'/'.ltrim($configuredPluginsPath, '/'));

return [
    'trusted_proxies' => env('PLAYHUB_TRUSTED_PROXIES', '127.0.0.1,::1'),
    'max_upload_mb' => (int) env('PLAYHUB_MAX_UPLOAD_MB', 51200),
    'chunk_size_mb' => (int) env('PLAYHUB_CHUNK_SIZE_MB', 8),
    'ffmpeg_binary' => env('PLAYHUB_FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('PLAYHUB_FFPROBE_BINARY', 'ffprobe'),
    'allow_public_registration' => (bool) env('PLAYHUB_ALLOW_PUBLIC_REGISTRATION', true),
    'video_disk' => env('PLAYHUB_VIDEO_DISK', 'public'),
    'receiver_playback' => [
        // Empty by default: operators must explicitly identify receiver-reachable,
        // publicly trusted HTTPS origins before Cast can expose a media URL.
        'allowed_origins' => $receiverMediaOrigins,
        'minimum_ttl_minutes' => max(5, (int) env('PLAYHUB_RECEIVER_MIN_TTL_MINUTES', 60)),
        'maximum_ttl_minutes' => max(60, (int) env('PLAYHUB_RECEIVER_MAX_TTL_MINUTES', 1440)),
        'safety_minutes' => max(1, (int) env('PLAYHUB_RECEIVER_TTL_SAFETY_MINUTES', 15)),
    ],
    'plugins_enabled' => (bool) env('PLAYHUB_PLUGINS_ENABLED', true),
    'plugins_path' => $pluginsPath,
    'plugin_max_archive_mb' => max(1, (int) env('PLAYHUB_PLUGIN_MAX_ARCHIVE_MB', 25)),
    'plugin_max_uncompressed_mb' => max(1, (int) env('PLAYHUB_PLUGIN_MAX_UNCOMPRESSED_MB', 100)),
    'plugin_max_file_mb' => max(1, (int) env('PLAYHUB_PLUGIN_MAX_FILE_MB', 20)),
    'plugin_max_files' => max(10, (int) env('PLAYHUB_PLUGIN_MAX_FILES', 2000)),
    'livekit' => [
        'url' => env('LIVEKIT_URL', preg_replace('/^http/i', 'ws', rtrim((string) env('APP_URL', ''), '/')).'/livekit'),
        'api_key' => env('LIVEKIT_API_KEY', $livekitKey),
        'api_secret' => env('LIVEKIT_API_SECRET', $livekitSecret),
        'ingress_image' => env('LIVEKIT_INGRESS_IMAGE', 'livekit/ingress:v1.5.0'),
        'rtmp_url' => env('LIVEKIT_RTMP_URL', $appHost !== '' ? 'rtmp://'.$appHost.':'.$rtmpPort.'/live' : ''),
        'rtmp_port' => $rtmpPort,
        'redis' => [
            'host' => env('LIVEKIT_REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('LIVEKIT_REDIS_PORT', 6379),
            'username' => env('LIVEKIT_REDIS_USERNAME', 'default'),
            'password' => env('LIVEKIT_REDIS_PASSWORD', ''),
            'database' => (int) env('LIVEKIT_REDIS_DB', 2),
        ],
    ],
];
