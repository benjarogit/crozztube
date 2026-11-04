<?php

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('PLAYHUB_CORS_ALLOWED_ORIGINS', '*')),
)));

return [
    'paths' => ['api/*', 'media/videos/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins ?: ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [
        'X-Request-ID',
        'X-PlayHub-API-Version',
        'Accept-Ranges',
        'Content-Range',
        'Content-Length',
    ],
    'max_age' => 3600,
    'supports_credentials' => false,
];
