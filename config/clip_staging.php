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
];
