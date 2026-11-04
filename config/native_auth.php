<?php

return [
    'mobile_token_ttl_minutes' => (int) env('PLAYHUB_MOBILE_TOKEN_TTL_MINUTES', 43200),
    'tv_token_ttl_minutes' => (int) env('PLAYHUB_TV_TOKEN_TTL_MINUTES', 43200),
    'refresh_token_ttl_minutes' => (int) env('PLAYHUB_REFRESH_TOKEN_TTL_MINUTES', 259200),
    'device_code_ttl_seconds' => (int) env('PLAYHUB_TV_DEVICE_CODE_TTL_SECONDS', 600),
    'device_code_poll_interval_seconds' => (int) env('PLAYHUB_TV_DEVICE_POLL_INTERVAL_SECONDS', 5),
    'max_sessions_per_user' => (int) env('PLAYHUB_NATIVE_MAX_SESSIONS', 10),
    'passwordless_delete_reauthentication_minutes' => (int) env('PLAYHUB_PASSWORDLESS_DELETE_REAUTH_MINUTES', 15),
    'native_session_retention_days' => (int) env('PLAYHUB_NATIVE_SESSION_RETENTION_DAYS', 30),
    'tv_authorization_retention_days' => (int) env('PLAYHUB_TV_AUTHORIZATION_RETENTION_DAYS', 7),
    'verification_uri' => env('PLAYHUB_TV_VERIFICATION_URI', rtrim((string) env('APP_URL', ''), '/').'/activate'),
    'social_return_uris' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PLAYHUB_NATIVE_SOCIAL_RETURN_URIS', 'playhub://auth/callback')),
    ))),
    'hash_key' => env('PLAYHUB_NATIVE_HASH_KEY'),
    'mobile_platforms' => ['ios', 'android'],
    'tv_platforms' => ['tvos', 'android-tv', 'tizen', 'webos'],
];
