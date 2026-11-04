<?php

return [
    'enabled' => (bool) env('PLAYHUB_PUSH_ENABLED', false),
    'queue' => env('PLAYHUB_PUSH_QUEUE', 'push'),
    'ttl_seconds' => 3600,
    'max_attempts' => 6,
    'fcm' => [
        'credentials' => env('PLAYHUB_FCM_CREDENTIALS'),
        'packages' => array_filter(array_map('trim', explode(',', (string) env('PLAYHUB_FCM_PACKAGES', 'com.playhub.mobile.dev,com.playhub.mobile.staging,net.kontackt.playhub')))),
    ],
    'apns' => [
        'key_path' => env('PLAYHUB_APNS_KEY_PATH'),
        'key_id' => env('PLAYHUB_APNS_KEY_ID'),
        'team_id' => env('PLAYHUB_APNS_TEAM_ID'),
        'bundle_id' => env('PLAYHUB_APNS_BUNDLE_ID'),
        'bundle_ids' => array_filter(array_map('trim', explode(',', (string) env('PLAYHUB_APNS_BUNDLE_IDS', env('PLAYHUB_APNS_BUNDLE_ID', ''))))),
    ],
];
