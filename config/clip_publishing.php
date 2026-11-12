<?php

return [
    // Publishing credentials stay server-side and do not enable clip creation.
    'odysee' => [
        'auth_token' => env('CLIP_PUBLISH_ODYSEE_AUTH_TOKEN', ''),
        'channel_id' => env('CLIP_PUBLISH_ODYSEE_CHANNEL_ID', ''),
    ],
];
