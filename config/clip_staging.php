<?php

return [
    // Separate from the application's default filesystem and thumbnail storage.
    // This only configures the staging connection; it does not enable clips.
    'oci' => [
        'region' => env('CLIP_STAGING_OCI_REGION', 'eu-frankfurt-1'),
        'namespace' => env('CLIP_STAGING_OCI_NAMESPACE', ''),
        'bucket' => env('CLIP_STAGING_OCI_BUCKET', ''),
        'access_key_id' => env('CLIP_STAGING_OCI_ACCESS_KEY_ID', ''),
        'secret_access_key' => env('CLIP_STAGING_OCI_SECRET_ACCESS_KEY', ''),
    ],
    'limits' => [
        'active_global' => (int) env('CLIP_STAGING_ACTIVE_GLOBAL', 3),
        'new_per_user_day' => (int) env('CLIP_STAGING_NEW_PER_USER_DAY', 5),
        'new_global_month' => (int) env('CLIP_STAGING_NEW_GLOBAL_MONTH', 150),
        'reserved_bytes_per_account' => (int) env('CLIP_STAGING_RESERVED_BYTES_PER_ACCOUNT', 1000000000),
    ],
];
