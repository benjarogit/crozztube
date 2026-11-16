<?php

namespace App\Support;

class AuthSettings
{
    public static function settings(): array
    {
        $path = self::settingsPath();
        $stored = is_file($path) && is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];
        $stored = CaptchaSettings::migrate(is_array($stored) ? $stored : []);
        $settings = array_replace_recursive(self::defaults(), $stored);

        // Question lists are replaced wholesale; merging by numeric index would
        // silently keep defaults the administrator deleted.
        if (is_array($stored['captcha']['questions'] ?? null)) {
            $settings['captcha']['questions'] = $stored['captcha']['questions'];
        }

        return CaptchaSettings::normalize($settings);
    }

    public static function save(array $settings): void
    {
        $settings = array_replace_recursive(self::defaults(), $settings);
        if (is_array($settings['captcha']['questions'] ?? null)) {
            $settings['captcha']['questions'] = CaptchaSettings::normalizeQuestions($settings['captcha']['questions']);
        }
        $settings = CaptchaSettings::normalize($settings);
        $path = self::settingsPath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    public static function adminSettings(): array
    {
        $settings = self::settings();
        if (data_get($settings, 'registration.googleCaptchaSecretKey')) {
            data_set($settings, 'registration.googleCaptchaSecretKey', '');
            data_set($settings, 'registration.googleCaptchaSecretKeyConfigured', true);
        }
        if (data_get($settings, 'social.google.clientSecret')) {
            data_set($settings, 'social.google.clientSecret', '');
            data_set($settings, 'social.google.clientSecretConfigured', true);
        }
        if (data_get($settings, 'social.facebook.clientSecret')) {
            data_set($settings, 'social.facebook.clientSecret', '');
            data_set($settings, 'social.facebook.clientSecretConfigured', true);
        }
        if (data_get($settings, 'social.apple.privateKey')) {
            data_set($settings, 'social.apple.privateKey', '');
            data_set($settings, 'social.apple.privateKeyConfigured', true);
        }
        $settings['captcha'] = CaptchaSettings::masked($settings);
        data_set($settings, 'social.google.redirectUrl', url('/api/auth/social/google/callback'));
        data_set($settings, 'social.facebook.redirectUrl', url('/api/auth/social/facebook/callback'));
        data_set($settings, 'social.apple.redirectUrl', url('/api/auth/social/apple/callback'));

        return $settings;
    }

    public static function publicSettings(): array
    {
        $settings = self::settings();

        return [
            'registration' => [
                'enabled' => (bool) data_get($settings, 'registration.enabled', true),
                'phoneEnabled' => self::registrationPhoneEnabled($settings),
                'usernameRequired' => self::registrationUsernameRequired($settings),
                'confirmationMethod' => self::registrationConfirmationMethod($settings),
                'passwordStrengthEnabled' => self::passwordStrengthEnabled($settings),
                'passwordStrengthLevel' => self::passwordStrengthLevel($settings),
                'captchaEnabled' => (bool) data_get($settings, 'registration.captchaEnabled', false),
                'captchaProvider' => data_get($settings, 'registration.captchaProvider') === 'google' ? 'google' : 'simple',
                'googleCaptchaSiteKey' => (string) data_get($settings, 'registration.googleCaptchaSiteKey', ''),
                'codeDigits' => self::registrationCodeDigits($settings),
                'codeExpiresSeconds' => self::registrationCodeExpiresSeconds($settings),
                'maxResends' => self::registrationMaxResends($settings),
            ],
            'login' => [
                'email' => self::loginMethodEnabled('email', $settings),
                'username' => self::loginMethodEnabled('username', $settings),
                'phone' => self::loginMethodEnabled('phone', $settings),
            ],
            'captcha' => [
                'registration' => [
                    'scope' => CaptchaSettings::scope($settings, CaptchaSettings::CONTEXT_REGISTRATION),
                    'providers' => CaptchaSettings::publicProviders($settings),
                ],
                'login' => [
                    'scope' => CaptchaSettings::scope($settings, CaptchaSettings::CONTEXT_LOGIN),
                    'threshold' => CaptchaSettings::threshold($settings),
                ],
            ],
            'social' => [
                'google' => ['enabled' => self::socialProviderEnabled('google', $settings)],
                'facebook' => ['enabled' => self::socialProviderEnabled('facebook', $settings)],
                'apple' => ['enabled' => self::socialProviderEnabled('apple', $settings)],
            ],
        ];
    }

    public static function registrationDeniedMessage(?string $ip): ?string
    {
        $settings = self::settings();
        if (! (bool) data_get($settings, 'registration.enabled', true)) {
            return 'Registration is currently disabled.';
        }

        return self::ipBlocked((string) $ip, (string) data_get($settings, 'registration.blockedIps', ''))
            ? 'Registration is not available from this IP address.'
            : null;
    }

    public static function registrationConfirmationMethod(?array $settings = null): string
    {
        $method = (string) data_get($settings ?? self::settings(), 'registration.confirmationMethod', 'none');

        return in_array($method, ['email', 'sms'], true) ? $method : 'none';
    }

    public static function registrationDefaultRole(?array $settings = null): string
    {
        $role = (string) data_get($settings ?? self::settings(), 'registration.defaultRole', 'user');

        return in_array($role, ['user', 'creator'], true) ? $role : 'user';
    }

    public static function registrationPhoneEnabled(?array $settings = null): bool
    {
        return (bool) data_get($settings ?? self::settings(), 'registration.phoneEnabled', false);
    }

    public static function registrationUsernameRequired(?array $settings = null): bool
    {
        return (bool) data_get($settings ?? self::settings(), 'registration.usernameRequired', false);
    }

    public static function passwordStrengthEnabled(?array $settings = null): bool
    {
        return (bool) data_get($settings ?? self::settings(), 'registration.passwordStrengthEnabled', true);
    }

    public static function passwordStrengthLevel(?array $settings = null): int
    {
        return max(0, min(10, (int) data_get($settings ?? self::settings(), 'registration.passwordStrengthLevel', 0)));
    }

    public static function registrationCodeDigits(?array $settings = null): int
    {
        return max(4, min(8, (int) data_get($settings ?? self::settings(), 'registration.smsCodeDigits', 4)));
    }

    public static function registrationCodeExpiresSeconds(?array $settings = null): int
    {
        return max(60, min(3600, (int) data_get($settings ?? self::settings(), 'registration.smsCodeExpiresMinutes', 5) * 60));
    }

    public static function registrationMaxResends(?array $settings = null): int
    {
        return max(0, min(10, (int) data_get($settings ?? self::settings(), 'registration.smsMaxResends', 3)));
    }

    public static function registrationInvalidAttempts(?array $settings = null): int
    {
        return max(1, min(10, (int) data_get($settings ?? self::settings(), 'registration.smsInvalidAttempts', 3)));
    }

    public static function registrationInvalidBlockSeconds(?array $settings = null): int
    {
        return max(60, min(86400, (int) data_get($settings ?? self::settings(), 'registration.smsInvalidBlockMinutes', 60) * 60));
    }

    public static function registrationSmsCodeDigits(?array $settings = null): int
    {
        return self::registrationCodeDigits($settings);
    }

    public static function registrationSmsCodeExpiresSeconds(?array $settings = null): int
    {
        return self::registrationCodeExpiresSeconds($settings);
    }

    public static function registrationSmsMaxResends(?array $settings = null): int
    {
        return self::registrationMaxResends($settings);
    }

    public static function registrationSmsInvalidAttempts(?array $settings = null): int
    {
        return self::registrationInvalidAttempts($settings);
    }

    public static function registrationSmsInvalidBlockSeconds(?array $settings = null): int
    {
        return self::registrationInvalidBlockSeconds($settings);
    }

    public static function loginMethodEnabled(string $method, ?array $settings = null): bool
    {
        return (bool) data_get($settings ?? self::settings(), 'login.'.$method, in_array($method, ['email', 'username', 'phone'], true));
    }

    public static function twoFactorLoginEnabled(?array $settings = null): bool
    {
        return (bool) data_get($settings ?? self::settings(), 'login.twoFactorEnabled', true);
    }

    public static function socialProviderSettings(string $provider, ?array $settings = null): array
    {
        $provider = strtolower($provider);
        if (! in_array($provider, ['google', 'facebook', 'apple'], true)) {
            return [];
        }

        $config = data_get($settings ?? self::settings(), 'social.'.$provider, []);

        return is_array($config) ? $config : [];
    }

    public static function socialProviderEnabled(string $provider, ?array $settings = null): bool
    {
        $config = self::socialProviderSettings($provider, $settings);
        if (! (bool) ($config['enabled'] ?? false)) {
            return false;
        }

        return match (strtolower($provider)) {
            'google' => trim((string) ($config['clientId'] ?? '')) !== '' && trim((string) ($config['clientSecret'] ?? '')) !== '',
            'facebook' => trim((string) ($config['clientId'] ?? '')) !== '' && trim((string) ($config['clientSecret'] ?? '')) !== '',
            'apple' => trim((string) ($config['clientId'] ?? '')) !== '' && trim((string) ($config['teamId'] ?? '')) !== '' && trim((string) ($config['keyId'] ?? '')) !== '' && trim((string) ($config['privateKey'] ?? '')) !== '',
            default => false,
        };
    }

    public static function maxLoginAttempts(?array $settings = null): int
    {
        return max(1, min(100, (int) data_get($settings ?? self::settings(), 'login.maxAttempts', 5)));
    }

    public static function loginCaptchaAfterAttempts(?array $settings = null): int
    {
        return max(1, min(100, (int) data_get($settings ?? self::settings(), 'login.captchaAfterAttempts', 3)));
    }

    public static function banSeconds(?array $settings = null): int
    {
        return max(60, min(86400, (int) data_get($settings ?? self::settings(), 'login.banMinutes', 15) * 60));
    }

    private static function ipBlocked(string $ip, string $rules): bool
    {
        if ($ip === '') {
            return false;
        }

        foreach (preg_split('/[\s,]+/', $rules) ?: [] as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            if ($rule === $ip) {
                return true;
            }

            if (str_contains($rule, '*')) {
                $prefix = strstr($rule, '*', true);
            } elseif (str_ends_with($rule, '.')) {
                $prefix = $rule;
            } else {
                $prefix = '';
            }

            if ($prefix !== '') {
                $prefix = preg_match('/\.+$/', $prefix) ? rtrim($prefix, '.').'.' : $prefix;
                if (str_starts_with($ip, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function settingsPath(): string
    {
        return storage_path('app/playhub-auth-settings.json');
    }

    private static function defaults(): array
    {
        return [
            'registration' => [
                'enabled' => (bool) config('playhub.allow_public_registration', true),
                'defaultRole' => 'user',
                'phoneEnabled' => false,
                'usernameRequired' => false,
                'blockedIps' => '',
                'confirmationMethod' => 'none',
                'passwordStrengthEnabled' => true,
                'passwordStrengthLevel' => 0,
                'captchaEnabled' => false,
                'captchaProvider' => 'simple',
                'googleCaptchaSiteKey' => '',
                'googleCaptchaSecretKey' => '',
                'smsCodeDigits' => 4,
                'smsCodeExpiresMinutes' => 5,
                'smsMaxResends' => 3,
                'smsInvalidAttempts' => 3,
                'smsInvalidBlockMinutes' => 60,
            ],
            'login' => [
                'email' => true,
                'username' => true,
                'phone' => true,
                'twoFactorEnabled' => true,
                'maxAttempts' => 5,
                'captchaAfterAttempts' => 3,
                'banMinutes' => 15,
            ],
            'social' => [
                'google' => [
                    'enabled' => false,
                    'clientId' => '',
                    'clientSecret' => '',
                ],
                'facebook' => [
                    'enabled' => false,
                    'clientId' => '',
                    'clientSecret' => '',
                ],
                'apple' => [
                    'enabled' => false,
                    'clientId' => '',
                    'teamId' => '',
                    'keyId' => '',
                    'privateKey' => '',
                ],
            ],
            'captcha' => CaptchaSettings::defaults(),
        ];
    }
}
