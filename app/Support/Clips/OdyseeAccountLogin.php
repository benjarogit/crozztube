<?php

namespace App\Support\Clips;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
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
        $token = $this->createSession();
        $this->request('user/signin', ['auth_token' => $token, 'email' => $email, 'password' => $password]);
        unset($password);
        $account = $this->request('user/me', ['auth_token' => $token]);
        if (($account['has_verified_email'] ?? null) !== true || ! is_string($account['primary_email'] ?? null)
            || strcasecmp($account['primary_email'], $email) !== 0) {
            throw new OdyseeLoginFailure('account', 'identity');
        }

        return $token;
    }

    /** Check the real first setup request without asking for account credentials. */
    public function checkSession(): void
    {
        $this->createSession();
    }

    private function createSession(): string
    {
        // Odysee's client caps domain + installation ID at 66 characters. The
        // previous hyphenated ID was rejected live with HTTP 400 before sign-in.
        // Keep our own app identity, with random installation entropy, alphanumeric.
        $appId = substr('crozztubeclips'.bin2hex(random_bytes(32)), 0, 66);
        $session = $this->request('user/new', ['auth_token' => '', 'app_id' => $appId, 'language' => 'en']);
        $token = $session['auth_token'] ?? null;
        if (! is_string($token) || ! preg_match('/^[a-zA-Z0-9_+\/=.-]{8,2048}$/D', $token)) {
            throw new OdyseeLoginFailure('session', 'response');
        }

        return $token;
    }

    private function request(string $path, #[\SensitiveParameter] array $parameters): array
    {
        $response = null;
        $stage = match ($path) {
            'user/new' => 'session',
            'user/signin' => 'signin',
            default => 'account',
        };
        try {
            $http = $this->http ?? new Client();
            $response = $http->request('POST', 'https://api.odysee.com/'.$path, [
                'form_params' => $parameters, 'headers' => ['Accept' => 'application/json'],
                'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
                'stream' => true, 'connect_timeout' => 5, 'timeout' => 20,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $reason = $stage === 'signin' && in_array($response->getStatusCode(), [409, 417], true) ? 'confirmation' : 'http';
                throw new OdyseeLoginFailure($stage, $reason, $response->getStatusCode());
            }
            $body = Utils::copyToString($response->getBody(), 1048577);
            if (strlen($body) > 1048576) {
                throw new OdyseeLoginFailure($stage, 'response');
            }
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (($data['success'] ?? null) !== true || ! empty($data['error'])) {
                throw new OdyseeLoginFailure($stage, 'response', $response->getStatusCode());
            }
            // The web client ignores sign-in data and fetches user/me next.
            // Never assume a particular unused payload shape establishes identity.
            if ($stage === 'signin') {
                return [];
            }
            if (! is_array($data['data'] ?? null)) {
                throw new OdyseeLoginFailure($stage, 'response', $response->getStatusCode());
            }

            return $data['data'];
        } catch (OdyseeLoginFailure $error) {
            throw $error;
        } catch (Throwable) {
            // No automatic retries, registration, verification emails or bypasses.
            throw new OdyseeLoginFailure($stage, $response === null ? 'transport' : 'response');
        } finally {
            $response?->getBody()->close();
        }
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'odysee', 'purpose' => 'interactive-account-setup'];
    }
}
