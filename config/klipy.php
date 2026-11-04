<?php

return [
    // KLIPY's standard integration uses a publishable client application key.
    // Never use a server service-account or signing credential here.
    'enabled' => env('KLIPY_ENABLED', true),
    'app_key' => env('KLIPY_APP_KEY', ''),
    'locale' => env('KLIPY_LOCALE', 'en_US'),
    'content_filter' => env('KLIPY_CONTENT_FILTER', 'high'),
    'live_price_cents' => 100,
    'per_page' => 24,
    'settings_path' => storage_path('app/private/klipy-settings.json'),
];
