<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Provider registry, rotation weights and privacy-safe public configuration
 * for the captcha system.
 *
 * Nothing in here contacts a provider. The service resolves the active
 * provider, the controller issues a server-bound challenge and verification
 * happens only on the server.
 */
final class CaptchaSettings
{
    public const SCOPE_OFF = 'off';
    public const SCOPE_RISK = 'risk';
    public const SCOPE_ALWAYS = 'always';

    public const CONTEXT_REGISTRATION = 'registration';
    public const CONTEXT_LOGIN = 'login';

    public const SURFACE_WEB = 'web';
    public const SURFACE_NATIVE = 'native';

    /**
     * Weights start with a level field for the local providers. External
     * providers are weighted twice as high once an administrator enables them,
     * because they usually offer the stronger signal.
     */
    public const PROVIDERS = [
        'simple' => [
            'label' => 'Simple image captcha',
            'kind' => 'simple',
            'weight' => 1,
            'native' => true,
        ],
        'question' => [
            'label' => 'Question captcha',
            'kind' => 'question',
            'weight' => 1,
            'native' => false,
        ],
        'recaptcha_v2' => [
            'label' => 'Google reCAPTCHA v2',
            'kind' => 'recaptcha',
            'weight' => 2,
            'native' => true,
        ],
        'recaptcha_v3' => [
            'label' => 'Google reCAPTCHA v3',
            'kind' => 'recaptcha_v3',
            'weight' => 2,
            'native' => false,
        ],
        'hcaptcha' => [
            'label' => 'hCaptcha',
            'kind' => 'hcaptcha',
            'weight' => 2,
            'native' => false,
        ],
        'turnstile' => [
            'label' => 'Cloudflare Turnstile',
            'kind' => 'turnstile',
            'weight' => 2,
            'native' => false,
        ],
    ];

    public const SCRIPTS = [
        'recaptcha' => 'https://www.google.com/recaptcha/api.js?render=explicit',
        'recaptcha_v3' => 'https://www.google.com/recaptcha/api.js?render=explicit',
        'hcaptcha' => 'https://js.hcaptcha.com/1/api.js?render=explicit',
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
    ];

    /** @return list<string> */
    public static function scopes(): array
    {
        return [self::SCOPE_OFF, self::SCOPE_RISK, self::SCOPE_ALWAYS];
    }

    /** @return list<string> */
    public static function providerIds(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /** @return list<array{question: string, answer: string}> */
    public static function defaultQuestions(): array
    {
        return [
            ['question' => 'What color is the sky on a clear day?', 'answer' => 'blue'],
            ['question' => 'How many legs does a dog have?', 'answer' => '4'],
            ['question' => 'What is two plus three?', 'answer' => '5'],
            ['question' => 'Type the word: crozz', 'answer' => 'crozz'],
            ['question' => 'Which month comes after April?', 'answer' => 'may'],
            ['question' => 'What is the first letter of the alphabet?', 'answer' => 'a'],
        ];
    }

    public static function defaults(): array
    {
        $providers = [];
        foreach (self::PROVIDERS as $id => $meta) {
            $providers[$id] = [
                'enabled' => in_array($id, ['simple', 'question'], true),
                'weight' => $meta['weight'],
                'siteKey' => '',
                'secretKey' => '',
                'threshold' => 0.5,
                'action' => 'submit',
            ];
        }

        return [
            'registrationScope' => self::SCOPE_OFF,
            'loginScope' => self::SCOPE_RISK,
            'riskThreshold' => 6,
            'challengeTtlSeconds' => 600,
            'maxAttempts' => 5,
            'providers' => $providers,
            'questions' => self::defaultQuestions(),
        ];
    }

    /**
     * Carry the single-provider settings forward without changing behaviour:
     * an enabled Google registration captcha becomes reCAPTCHA v2 with the
     * stored keys, an enabled simple captcha stays the sole local provider.
     */
    public static function migrate(array $stored): array
    {
        if (array_key_exists('captcha', $stored) && is_array($stored['captcha'])) {
            return $stored;
        }

        $captcha = self::defaults();
        $enabled = (bool) data_get($stored, 'registration.captchaEnabled', false);
        $google = data_get($stored, 'registration.captchaProvider') === 'google';
        $siteKey = trim((string) data_get($stored, 'registration.googleCaptchaSiteKey', ''));
        $secretKey = trim((string) data_get($stored, 'registration.googleCaptchaSecretKey', ''));

        $captcha['registrationScope'] = $enabled ? self::SCOPE_ALWAYS : self::SCOPE_OFF;
        if ($enabled) {
            foreach (array_keys($captcha['providers']) as $id) {
                $captcha['providers'][$id]['enabled'] = false;
            }
            if ($google) {
                $captcha['providers']['recaptcha_v2']['enabled'] = true;
                $captcha['providers']['recaptcha_v2']['siteKey'] = $siteKey;
                $captcha['providers']['recaptcha_v2']['secretKey'] = $secretKey;
            } else {
                $captcha['providers']['simple']['enabled'] = true;
            }
        }

        $stored['captcha'] = $captcha;

        return $stored;
    }

    public static function normalize(array $settings): array
    {
        $captcha = is_array($settings['captcha'] ?? null) ? $settings['captcha'] : [];

        $captcha['registrationScope'] = self::scopeValue($captcha['registrationScope'] ?? self::SCOPE_OFF);
        $captcha['loginScope'] = self::scopeValue($captcha['loginScope'] ?? self::SCOPE_RISK);
        $captcha['riskThreshold'] = max(1, min(50, (int) ($captcha['riskThreshold'] ?? 6)));
        $captcha['challengeTtlSeconds'] = max(60, min(3600, (int) ($captcha['challengeTtlSeconds'] ?? 600)));
        $captcha['maxAttempts'] = max(1, min(20, (int) ($captcha['maxAttempts'] ?? 5)));

        $providers = is_array($captcha['providers'] ?? null) ? $captcha['providers'] : [];
        $captcha['providers'] = [];
        foreach (self::PROVIDERS as $id => $meta) {
            $provider = is_array($providers[$id] ?? null) ? $providers[$id] : [];
            $captcha['providers'][$id] = [
                'enabled' => (bool) ($provider['enabled'] ?? in_array($id, ['simple', 'question'], true)),
                'weight' => max(1, min(10, (int) ($provider['weight'] ?? $meta['weight']))),
                'siteKey' => trim((string) ($provider['siteKey'] ?? '')),
                'secretKey' => trim((string) ($provider['secretKey'] ?? '')),
                'threshold' => max(0.0, min(1.0, (float) ($provider['threshold'] ?? 0.5))),
                'action' => trim((string) ($provider['action'] ?? 'submit')) ?: 'submit',
            ];
        }

        $captcha['questions'] = self::normalizeQuestions($captcha['questions'] ?? self::defaultQuestions());

        $settings['captcha'] = $captcha;

        return $settings;
    }

    public static function scopeValue(mixed $value): string
    {
        return in_array($value, self::scopes(), true) ? (string) $value : self::SCOPE_OFF;
    }

    public static function scope(array $settings, string $context): string
    {
        $key = $context === self::CONTEXT_LOGIN ? 'loginScope' : 'registrationScope';

        return self::scopeValue(data_get($settings, 'captcha.'.$key, self::SCOPE_OFF));
    }

    public static function threshold(array $settings): int
    {
        return max(1, min(50, (int) data_get($settings, 'captcha.riskThreshold', 6)));
    }

    public static function challengeTtl(array $settings): int
    {
        return max(60, min(3600, (int) data_get($settings, 'captcha.challengeTtlSeconds', 600)));
    }

    public static function maxAttempts(array $settings): int
    {
        return max(1, min(20, (int) data_get($settings, 'captcha.maxAttempts', 5)));
    }

    /** @return array{question: string, answer: string}|null */
    public static function randomQuestion(array $settings, ?callable $random = null): ?array
    {
        $questions = self::questions($settings);
        if ($questions === []) {
            return null;
        }

        $index = $random ? (int) $random(count($questions)) : random_int(0, count($questions) - 1);

        return $questions[$index] ?? $questions[0];
    }

    /** @return list<array{question: string, answer: string}> */
    public static function questions(array $settings): array
    {
        return self::normalizeQuestions(data_get($settings, 'captcha.questions', []));
    }

    /** @return list<array{question: string, answer: string}> */
    public static function normalizeQuestions(mixed $questions): array
    {
        if (! is_array($questions)) {
            return [];
        }

        $normalized = [];
        foreach ($questions as $question) {
            if (! is_array($question)) {
                continue;
            }
            $text = trim((string) ($question['question'] ?? ''));
            $answer = trim((string) ($question['answer'] ?? ''));
            if ($text === '' || $answer === '') {
                continue;
            }
            $normalized[] = [
                'question' => mb_substr($text, 0, 300),
                'answer' => mb_substr($answer, 0, 120),
            ];
            if (count($normalized) >= 50) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Parse the admin textarea. Each line is "Question | Answer"; the answer is
     * matched case-insensitively on the server.
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function parseQuestions(string $text): array
    {
        $questions = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s*[|=\t]\s*/', $line, 2) ?: [];
            if (count($parts) !== 2) {
                continue;
            }
            $questions[] = ['question' => $parts[0], 'answer' => $parts[1]];
        }

        return self::normalizeQuestions($questions);
    }

    public static function formatQuestions(mixed $questions): string
    {
        $lines = [];
        foreach (self::normalizeQuestions($questions) as $question) {
            $lines[] = $question['question'].' | '.$question['answer'];
        }

        return implode("\n", $lines);
    }

    public static function isConfigured(string $id, array $settings): bool
    {
        $config = (array) data_get($settings, 'captcha.providers.'.$id, []);
        if (! (bool) ($config['enabled'] ?? false)) {
            return false;
        }

        return match ($id) {
            'simple' => class_exists(\Mews\Captcha\Captcha::class) && app()->bound('captcha'),
            'question' => self::questions($settings) !== [],
            'recaptcha_v2', 'recaptcha_v3', 'hcaptcha', 'turnstile' => trim((string) ($config['siteKey'] ?? '')) !== ''
                && trim((string) ($config['secretKey'] ?? '')) !== '',
            default => false,
        };
    }

    /**
     * Active providers for a client surface. Native clients only understand the
     * legacy image captcha and reCAPTCHA v2; the web client understands every
     * registered provider.
     *
     * @return array<string, array{label: string, kind: string, weight: int, siteKey: string, action: string, threshold: float}>
     */
    public static function activeProviders(array $settings, string $surface = self::SURFACE_WEB): array
    {
        $active = [];
        foreach (self::PROVIDERS as $id => $meta) {
            if ($surface === self::SURFACE_NATIVE && ! $meta['native']) {
                continue;
            }
            if (! self::isConfigured($id, $settings)) {
                continue;
            }
            $config = (array) data_get($settings, 'captcha.providers.'.$id, []);
            $active[$id] = [
                'label' => $meta['label'],
                'kind' => $meta['kind'],
                'weight' => max(1, min(10, (int) ($config['weight'] ?? $meta['weight']))),
                'siteKey' => trim((string) ($config['siteKey'] ?? '')),
                'action' => trim((string) ($config['action'] ?? 'submit')) ?: 'submit',
                'threshold' => max(0.0, min(1.0, (float) ($config['threshold'] ?? 0.5))),
            ];
        }

        return $active;
    }

    /**
     * Public projection safe for the browser: identifiers and public site keys
     * only, never a secret.
     *
     * @return array<string, array{label: string, kind: string, siteKey: string, action: string}>
     */
    public static function publicProviders(array $settings, string $surface = self::SURFACE_WEB): array
    {
        $public = [];
        foreach (self::activeProviders($settings, $surface) as $id => $provider) {
            $public[$id] = [
                'label' => $provider['label'],
                'kind' => $provider['kind'],
                'siteKey' => $provider['siteKey'],
                'action' => $provider['action'],
            ];
        }

        return $public;
    }

    public static function scriptFor(string $id): ?string
    {
        $meta = self::PROVIDERS[$id] ?? null;
        if (! $meta) {
            return null;
        }

        return self::SCRIPTS[$meta['kind']] ?? null;
    }

    /** Admin projection with masked secrets. */
    public static function masked(array $settings): array
    {
        $captcha = (array) data_get($settings, 'captcha', []);
        foreach (self::providerIds() as $id) {
            $secret = trim((string) data_get($captcha, 'providers.'.$id.'.secretKey', ''));
            data_set($captcha, 'providers.'.$id.'.secretKey', '');
            data_set($captcha, 'providers.'.$id.'.secretKeyConfigured', $secret !== '');
        }
        $captcha['questionsText'] = self::formatQuestions($captcha['questions'] ?? []);

        return $captcha;
    }

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        $rules = [
            'captcha' => ['nullable', 'array'],
            'captcha.registrationScope' => ['nullable', 'string', Rule::in(self::scopes())],
            'captcha.loginScope' => ['nullable', 'string', Rule::in(self::scopes())],
            'captcha.riskThreshold' => ['nullable', 'integer', 'min:1', 'max:50'],
            'captcha.challengeTtlSeconds' => ['nullable', 'integer', 'min:60', 'max:3600'],
            'captcha.maxAttempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'captcha.questionsText' => ['nullable', 'string', 'max:6000'],
            'captcha.providers' => ['nullable', 'array'],
        ];
        foreach (self::providerIds() as $id) {
            $rules['captcha.providers.'.$id] = ['nullable', 'array'];
            $rules['captcha.providers.'.$id.'.enabled'] = ['nullable', 'boolean'];
            $rules['captcha.providers.'.$id.'.weight'] = ['nullable', 'integer', 'min:1', 'max:10'];
            $rules['captcha.providers.'.$id.'.siteKey'] = ['nullable', 'string', 'max:255'];
            $rules['captcha.providers.'.$id.'.secretKey'] = ['nullable', 'string', 'max:500'];
            $rules['captcha.providers.'.$id.'.threshold'] = ['nullable', 'numeric', 'min:0', 'max:1'];
            $rules['captcha.providers.'.$id.'.action'] = ['nullable', 'string', 'max:50'];
        }

        return $rules;
    }
}
