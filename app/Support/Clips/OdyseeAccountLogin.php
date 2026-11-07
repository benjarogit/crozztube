<?php

namespace App\Support\Clips;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** One interactive server-side setup session, following Odysee's web client. */
final class OdyseeAccountLogin
{
    public function __construct(private ?ClientInterface $http = null) {}

    public function authenticate(string $email, #[\SensitiveParameter] string $password): string
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $password === '' || strlen($password) > 4096) {
            throw new InvalidArgumentException('Enter a valid account email and password.');
        }
        // This is an anonymous client session, not registration of an email/account.
        $session = $this->request('user/new', ['auth_token' => '', 'app_id' => 'crozztube-clips-'.bin2hex(random_bytes(16)), 'language' => 'en']);
        $token = $session['auth_token'] ?? null;
        if (! is_string($token) || ! preg_match('/^[a-zA-Z0-9_+\/=.-]{8,2048}$/D', $token)) {
            throw new RuntimeException('Odysee did not return a usable client session.');
        }
        $this->request('user/signin', ['auth_token' => $token, 'email' => $email, 'password' => $password]);
        unset($password);
        $account = $this->request('user/me', ['auth_token' => $token]);
        if (($account['has_verified_email'] ?? null) !== true || ! is_string($account['primary_email'] ?? null)
            || strcasecmp($account['primary_email'], $email) !== 0) {
            throw new RuntimeException('Odysee account confirmation is incomplete. No credentials were saved.');
        }

        return $token;
    }

    private function request(string $path, #[\SensitiveParameter] array $parameters): array
    {
        $response = null;
        try {
            $http = $this->http ?? new Client();
            $response = $http->request('POST', 'https://api.odysee.com/'.$path, [
                'form_params' => $parameters, 'headers' => ['Accept' => 'application/json'],
                'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
                'stream' => true, 'connect_timeout' => 5, 'timeout' => 20,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new RuntimeException();
            }
            $body = Utils::copyToString($response->getBody(), 1048577);
            if (strlen($body) > 1048576) {
                throw new RuntimeException();
            }
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (($data['success'] ?? null) !== true || ! empty($data['error']) || ! is_array($data['data'] ?? null)) {
                throw new RuntimeException();
            }

            return $data['data'];
        } catch (Throwable) {
            // No automatic retries, registration, verification emails or bypasses.
            throw new RuntimeException('Odysee sign-in failed or needs extra confirmation. No credentials were saved.');
        } finally {
            $response?->getBody()->close();
        }
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'odysee', 'purpose' => 'interactive-account-setup'];
    }
}
