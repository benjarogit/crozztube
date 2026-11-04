<?php

return [
    'api_version' => 'v1',

    'headers' => [
        'client' => 'X-PlayHub-Client',
        'app_version' => 'X-PlayHub-App-Version',
        'os_version' => 'X-PlayHub-OS-Version',
        'device_id' => 'X-PlayHub-Device-Id',
        'device_model' => 'X-PlayHub-Device-Model',
        'locale' => 'X-PlayHub-Locale',
        'timezone' => 'X-PlayHub-Timezone',
        'capabilities' => 'X-PlayHub-Capabilities',
    ],

    /*
    | These values are fallbacks used before the database migration has run.
    | Runtime values are managed in the platform_app_versions table.
    */
    'platforms' => [
        'android' => ['latest_version' => '1.0.0', 'minimum_version' => '1.0.0', 'supported' => true, 'update_url' => null],
        'ios' => ['latest_version' => '1.0.0', 'minimum_version' => '1.0.0', 'supported' => true, 'update_url' => null],
        'android-tv' => ['latest_version' => '1.0.0', 'minimum_version' => '1.0.0', 'supported' => true, 'update_url' => null],
        'tvos' => ['latest_version' => '1.0.0', 'minimum_version' => '1.0.0', 'supported' => true, 'update_url' => null],
        'tizen' => ['latest_version' => '1.0.0', 'minimum_version' => '1.0.0', 'supported' => true, 'update_url' => null],
        'webos' => ['latest_version' => '1.0.0', 'minimum_version' => '1.0.0', 'supported' => true, 'update_url' => null],
    ],

    // Only capabilities the current PlayHub transcoder and API can actually serve.
    'playback' => [
        'delivery' => ['progressive'],
        'containers' => ['mp4'],
        'video_codecs' => ['h264'],
        'audio_codecs' => ['aac'],
        'subtitle_formats' => ['mov_text', 'srt', 'webvtt'],
        'qualities' => ['original', 'high', 'medium', 'low'],
        'drm' => [],
        'hdr' => [],
    ],
];
