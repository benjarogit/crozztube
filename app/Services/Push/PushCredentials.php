<?php

namespace App\Services\Push;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PushCredentials
{
    public function configured(string $provider): bool
    {
        if (! config('push.enabled')) {
            return false;
        }
        try {
            if ($provider === 'fcm') {
                $this->fcm();

                return true;
            }
            if ($provider === 'apns') {
                $this->apns();

                return function_exists('curl_version') && (curl_version()['features'] & CURL_VERSION_HTTP2) !== 0;
            }
        } catch (Throwable) {
            // Bootstrap advertises availability, never credentials or errors.
        }

        return false;
    }

    public function fcm(): array
    {
        $data = json_decode($this->read((string) config('push.fcm.credentials')), true);
        if (! is_array($data) || ($data['type'] ?? '') !== 'service_account'
            || ! preg_match('/^[a-z0-9][a-z0-9-]{3,100}$/', (string) ($data['project_id'] ?? ''))
            || ! filter_var($data['client_email'] ?? '', FILTER_VALIDATE_EMAIL)
            || empty($data['private_key'])) {
            throw new PushTransportException('fcm_credentials_invalid');
        }
        $this->validateKey($data['private_key'], OPENSSL_KEYTYPE_RSA);

        return $data;
    }

    public function apns(): array
    {
        $keyId = (string) config('push.apns.key_id');
        $teamId = (string) config('push.apns.team_id');
        if (! preg_match('/^[A-Z0-9]{10}$/', $keyId) || ! preg_match('/^[A-Z0-9]{10}$/', $teamId)
            || ! config('push.apns.bundle_ids')) {
            throw new PushTransportException('apns_credentials_invalid');
        }

        $key = $this->read((string) config('push.apns.key_path'));
        $this->validateKey($key, OPENSSL_KEYTYPE_EC);

        return ['key_id' => $keyId, 'team_id' => $teamId, 'key' => $key];
    }

    public function fcmAccessToken(bool $refresh = false): string
    {
        $credentials = $this->fcm();
        $cacheKey = 'push:fcm:'.hash('sha256', $credentials['client_email'].$credentials['private_key']);
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3000, function () use ($credentials): string {
            try {
                $assertion = JWT::encode([
                    'iss' => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => time(), 'exp' => time() + 3600,
                ], $credentials['private_key'], 'RS256');
                $response = Http::asForm()->connectTimeout(5)->timeout(15)->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
                ]);
                if (! $response->successful() || ! is_string($response->json('access_token')) || ! $response->json('access_token')) {
                    throw new PushTransportException('fcm_auth_failed');
                }

                return $response->json('access_token');
            } catch (Throwable) {
                throw new PushTransportException('fcm_auth_failed');
            }
        });
    }

    public function apnsToken(bool $refresh = false): string
    {
        $credentials = $this->apns();
        $cacheKey = 'push:apns:'.hash('sha256', implode('', $credentials));
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3000, function () use ($credentials): string {
            try {
                return JWT::encode(['iss' => $credentials['team_id'], 'iat' => time()], $credentials['key'], 'ES256', $credentials['key_id']);
            } catch (Throwable) {
                throw new PushTransportException('apns_auth_failed');
            }
        });
    }

    private function read(string $path): string
    {
        // Credentials live outside public/ and are read from server-only paths.
        $resolved = $path !== '' ? realpath($path) : false;
        $publicRoot = realpath(public_path());
        if (! $resolved || ! is_readable($resolved) || ! is_file($resolved)
            || ($publicRoot && str_starts_with(strtolower($resolved), strtolower($publicRoot).DIRECTORY_SEPARATOR))) {
            throw new PushTransportException('push_credentials_unavailable');
        }

        return (string) file_get_contents($resolved);
    }

    private function validateKey(string $pem, int $type): void
    {
        $key = @openssl_pkey_get_private($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! $details || $details['type'] !== $type
            || ($type === OPENSSL_KEYTYPE_RSA && $details['bits'] < 2048)
            || ($type === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? '') !== 'prime256v1')) {
            throw new PushTransportException('push_signing_key_invalid');
        }
    }
}
