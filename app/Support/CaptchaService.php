<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Server-bound captcha challenges.
 *
 * The provider is chosen on the server from the enabled and configured set.
 * A challenge token remembers the provider, context and (for local providers)
 * the expected answer, so a client can never downgrade or swap the provider
 * after the fact. Verification always happens on the server.
 */
final class CaptchaService
{
    private const CHALLENGE_PREFIX = 'captcha:challenge:';
    private const FAILURE_PREFIX = 'captcha:failures:';
    private const ESCALATE_PREFIX = 'captcha:escalate:';

    public function required(Request $request, string $context, ?array $settings = null, ?int $attempts = null): bool
    {
        $settings ??= AuthSettings::settings();
        $scope = CaptchaSettings::scope($settings, $context);
        if ($scope === CaptchaSettings::SCOPE_OFF) {
            return false;
        }
        if ($scope === CaptchaSettings::SCOPE_ALWAYS) {
            return true;
        }

        return $this->riskScore($request, $context, $settings, $attempts) >= CaptchaSettings::threshold($settings);
    }

    public function riskScore(Request $request, string $context, ?array $settings = null, ?int $attempts = null): int
    {
        $settings ??= AuthSettings::settings();
        $score = max(0, (int) ($attempts ?? 0)) * 2;
        $score += (int) Cache::get($this->failureKey($request, $context), 0) * 2;

        $honeypot = trim((string) $request->input('captcha_honeypot', ''));
        if ($honeypot !== '') {
            $score += 20;
        }

        $elapsed = $request->input('captcha_elapsed_ms');
        if (is_numeric($elapsed) && (int) $elapsed < 1200) {
            $score += 2;
        }

        if (trim((string) $request->userAgent()) === '') {
            $score += 2;
        }

        return max(0, min(50, $score));
    }

    /**
     * @return array{required: bool, reason?: string, token?: string, provider?: string, label?: string, kind?: string, image?: string, question?: string, siteKey?: string, script?: string, action?: string, expiresIn?: int, invisible?: bool}
     */
    public function challenge(Request $request, string $context, ?array $settings = null, ?callable $random = null): array
    {
        $settings ??= AuthSettings::settings();
        if (! $this->required($request, $context, $settings)) {
            return ['required' => false, 'reason' => 'not_needed'];
        }

        $surface = self::surface($request);
        $providers = CaptchaSettings::activeProviders($settings, $surface);
        if ($this->escalating($request) && isset($providers['recaptcha_v3'])) {
            unset($providers['recaptcha_v3']);
        }
        if ($providers === []) {
            // Misconfiguration must never lock every visitor out. The admin UI
            // surfaces the missing configuration instead.
            return ['required' => false, 'reason' => 'no_provider'];
        }

        $id = self::pickProvider($providers, $random);
        if ($id === null) {
            return ['required' => false, 'reason' => 'no_provider'];
        }

        $provider = $providers[$id];
        $ttl = CaptchaSettings::challengeTtl($settings);
        $token = Str::random(64);
        $record = [
            'provider' => $id,
            'context' => $context,
            'surface' => $surface,
            'ip' => $this->ipHash($request),
            'created_at' => time(),
        ];

        $payload = [
            'required' => true,
            'token' => $token,
            'provider' => $id,
            'label' => $provider['label'],
            'kind' => $provider['kind'],
            'expiresIn' => $ttl,
        ];

        if ($provider['kind'] === 'simple') {
            $captcha = app('captcha')->create('default', true);
            $record['captcha_id'] = (string) ($captcha['key'] ?? '');
            $payload['image'] = (string) ($captcha['img'] ?? '');
        } elseif ($provider['kind'] === 'question') {
            $question = CaptchaSettings::randomQuestion($settings, $random);
            if ($question === null) {
                return ['required' => false, 'reason' => 'no_provider'];
            }
            $record['answer_hash'] = self::answerHash($question['answer']);
            $payload['question'] = $question['question'];
        } else {
            $payload['siteKey'] = $provider['siteKey'];
            $payload['script'] = CaptchaSettings::scriptFor($id);
            $payload['action'] = $provider['action'];
            if ($provider['kind'] === 'recaptcha_v3') {
                $payload['invisible'] = true;
            }
        }

        Cache::put(self::CHALLENGE_PREFIX.hash('sha256', $token), $record, $ttl);

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $settings
     *
     * @throws ValidationException
     */
    public function verify(Request $request, string $context, ?array $settings = null): void
    {
        $settings ??= AuthSettings::settings();
        $token = trim((string) $request->input('captcha_token', ''));
        $key = self::CHALLENGE_PREFIX.hash('sha256', $token);
        $record = $token === '' ? null : Cache::pull($key);

        if (! is_array($record)) {
            $this->recordFailure($request, $context);
            throw ValidationException::withMessages([
                'captcha' => ['The security check has expired. Try again.'],
                'captcha_token' => ['The security check has expired. Try again.'],
            ]);
        }

        if (($record['context'] ?? '') !== $context) {
            throw ValidationException::withMessages(['captcha' => ['Complete the captcha to continue.']]);
        }

        $provider = (string) ($record['provider'] ?? '');
        if (! isset(CaptchaSettings::PROVIDERS[$provider])) {
            throw ValidationException::withMessages(['captcha' => ['Complete the captcha to continue.']]);
        }

        try {
            $valid = $this->verifyProvider($provider, $record, $request, $settings);
        } catch (CaptchaVerificationUnavailable) {
            throw ValidationException::withMessages([
                'captcha' => ['The security check could not be verified. Try again.'],
            ]);
        }

        if (! $valid) {
            $this->recordFailure($request, $context);
            if ($provider === 'recaptcha_v3') {
                // A silent v3 rejection escalates to a visible provider next time.
                Cache::put($this->escalateKey($request), true, 600);
            }
            throw ValidationException::withMessages([
                'captcha' => ['The security check failed. Try again.'],
                'captcha_token' => ['The security check failed. Try again.'],
            ]);
        }

        Cache::forget($this->failureKey($request, $context));
        Cache::forget($this->escalateKey($request));
    }

    /** @param array<string, mixed> $record */
    private function verifyProvider(string $provider, array $record, Request $request, array $settings): bool
    {
        $answer = trim((string) $request->input('captcha_answer', ''));
        $response = trim((string) $request->input('captcha_response', ''));
        $kind = CaptchaSettings::PROVIDERS[$provider]['kind'];

        return match ($kind) {
            'simple' => $this->verifySimple((string) ($record['captcha_id'] ?? ''), $answer),
            'question' => hash_equals((string) ($record['answer_hash'] ?? ''), self::answerHash($answer)),
            'recaptcha' => $this->verifyRemote('https://www.google.com/recaptcha/api/siteverify', $provider, $response, $request, $settings),
            'recaptcha_v3' => $this->verifyRemote('https://www.google.com/recaptcha/api/siteverify', $provider, $response, $request, $settings, true),
            'hcaptcha' => $this->verifyRemote('https://api.hcaptcha.com/siteverify', $provider, $response, $request, $settings),
            'turnstile' => $this->verifyRemote('https://challenges.cloudflare.com/turnstile/v0/siteverify', $provider, $response, $request, $settings),
            default => false,
        };
    }

    private function verifySimple(string $captchaId, string $answer): bool
    {
        if ($captchaId === '' || $answer === '') {
            return false;
        }

        try {
            return (bool) captcha_api_check($answer, $captchaId, 'default');
        } catch (\Throwable) {
            return false;
        }
    }

    private function verifyRemote(string $endpoint, string $provider, string $response, Request $request, array $settings, bool $scored = false): bool
    {
        if ($response === '') {
            return false;
        }

        $secret = trim((string) data_get($settings, 'captcha.providers.'.$provider.'.secretKey', ''));
        if ($secret === '') {
            throw new CaptchaVerificationUnavailable('Missing secret');
        }

        try {
            $result = Http::asForm()->timeout(8)->post($endpoint, [
                'secret' => $secret,
                'response' => $response,
                'remoteip' => $request->ip(),
            ]);
        } catch (\Throwable) {
            throw new CaptchaVerificationUnavailable('Provider unreachable');
        }

        if (! $result->successful()) {
            throw new CaptchaVerificationUnavailable('Provider rejected the request');
        }

        $body = (array) $result->json();
        if (! (bool) ($body['success'] ?? false)) {
            return false;
        }

        if ($scored) {
            $threshold = max(0.0, min(1.0, (float) data_get($settings, 'captcha.providers.'.$provider.'.threshold', 0.5)));
            $score = (float) ($body['score'] ?? 0.0);
            $expected = trim((string) data_get($settings, 'captcha.providers.'.$provider.'.action', 'submit')) ?: 'submit';
            $action = (string) ($body['action'] ?? '');
            if ($score < $threshold) {
                return false;
            }
            if ($action !== '' && $action !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Weighted random choice. A callable returning 1..total is accepted so the
     * rotation can be tested deterministically.
     *
     * @param  array<string, array{weight: int}>  $providers
     */
    public static function pickProvider(array $providers, ?callable $random = null): ?string
    {
        if ($providers === []) {
            return null;
        }

        $total = array_sum(array_map(static fn (array $provider): int => max(1, (int) $provider['weight']), $providers));
        $roll = $random ? (int) $random($total) : random_int(1, $total);
        $roll = max(1, min($total, $roll));

        foreach ($providers as $id => $provider) {
            $roll -= max(1, (int) $provider['weight']);
            if ($roll <= 0) {
                return $id;
            }
        }

        return array_key_last($providers);
    }

    public static function surface(Request $request): string
    {
        return str_starts_with($request->path(), 'api/v1/') ? CaptchaSettings::SURFACE_NATIVE : CaptchaSettings::SURFACE_WEB;
    }

    private static function answerHash(string $answer): string
    {
        return hash('sha256', mb_strtolower(trim($answer)));
    }

    private function recordFailure(Request $request, string $context): void
    {
        $settings = AuthSettings::settings();
        $window = max(600, CaptchaSettings::challengeTtl($settings));
        $key = $this->failureKey($request, $context);
        $current = (int) Cache::get($key, 0);
        Cache::put($key, min(50, $current + 1), $window);
    }

    private function failureKey(Request $request, string $context): string
    {
        return self::FAILURE_PREFIX.$context.':'.$this->ipHash($request);
    }

    private function escalateKey(Request $request): string
    {
        return self::ESCALATE_PREFIX.$this->ipHash($request);
    }

    private function escalating(Request $request): bool
    {
        return (bool) Cache::get($this->escalateKey($request), false);
    }

    private function ipHash(Request $request): string
    {
        return hash('sha256', (string) $request->ip());
    }
}
