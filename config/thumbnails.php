<?php

return [
    'streaming_only' => env('CROZZTUBE_STREAMING_ONLY', true),
    'max_bytes' => 5 * 1024 * 1024,
    'import_budget' => 10,
    'night_start' => env('THUMBNAIL_NIGHT_START', '02:00'),
    'night_end' => env('THUMBNAIL_NIGHT_END', '05:00'),
    'timezone' => 'Europe/Berlin',
    'check_limit' => 20,
    'upload_limit' => 5,
    'run_budget' => 60,
    // Fixed priority. Directupload uses its public YourUpload form, not an API.
    'providers' => [
        'directupload' => [
            'priority' => 1, 'key' => '', 'requires_key' => false, 'supported' => true,
            // Conservative local budgets for the public form, not published hoster limits.
            'minute_limit' => 10, 'day_limit' => 300,
        ],
        'fastpic' => ['priority' => 2, 'key' => env('FASTPIC_API_KEY', ''), 'supported' => true, 'minute_limit' => 60, 'day_limit' => 5000],
        'postimages' => [
            'priority' => 3, 'key' => env('POSTIMAGES_API_KEY', ''), 'supported' => true,
            // Conservative local budgets, not a claim about undocumented provider limits.
            'minute_limit' => 10, 'day_limit' => 100, 'month_limit' => env('POSTIMAGES_MONTHLY_UPLOAD_LIMIT', 1000),
            // Public client compatibility fields from ShareX issue #472; the account key is separate.
            'client_o' => env('POSTIMAGES_CLIENT_O', '2b819584285c102318568238c7d4a4c7'),
            'client_m' => env('POSTIMAGES_CLIENT_M', '59c2ad4b46b0c1e12d5703302bff0120'),
        ],
    ],
];
