<?php

namespace App\Support;

use App\Services\LocalizationService;
use InvalidArgumentException;

class EmailTemplates
{
    public static function catalog(?string $locale = null): array
    {
        $locale = self::normalizeLocale($locale);

        return [
            'locale' => $locale,
            'default_locale' => self::normalizeLocale((string) (GeneralSettings::settings()['defaultLanguage'] ?? 'en')),
            'languages' => self::languages(),
            'templates' => collect(self::definitions())->map(function (array $definition, string $key) use ($locale): array {
                $resolved = self::resolve($key, $locale);
                $defaults = self::localizedDefaults($definition, $locale);

                return [
                    'key' => $key,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'subject' => $resolved['subject'],
                    'body' => $resolved['body'],
                    'default_subject' => $defaults['subject'],
                    'default_body' => $defaults['body'],
                    'customized' => $resolved['source_locale'] === $locale,
                    'source_locale' => $resolved['source_locale'],
                    'placeholders' => collect($definition['placeholders'])->map(function (string $placeholder): array {
                        $details = self::placeholderDefinitions()[$placeholder];

                        return ['token' => '{'.$placeholder.'}', ...$details];
                    })->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    public static function render(string $key, array $variables = [], ?string $locale = null): array
    {
        $locale = self::normalizeLocale($locale);
        $resolved = self::resolve($key, $locale);
        $definition = self::definition($key);
        $settings = GeneralSettings::settings();
        $siteName = trim((string) ($settings['platformName'] ?? config('app.name', 'PlayHub'))) ?: 'PlayHub';
        $siteUrl = rtrim((string) config('app.url', ''), '/') ?: url('/');
        $variables = collect($variables)->mapWithKeys(fn (mixed $value, mixed $name): array => [strtoupper((string) $name) => self::stringValue($value)])->all();
        $variables = array_replace([
            'SITENAME' => $siteName,
            'SITE_URL' => $siteUrl,
            'CURRENT_YEAR' => date('Y'),
        ], $variables);
        $replace = [];
        foreach ($definition['placeholders'] as $placeholder) {
            $replace['{'.$placeholder.'}'] = $variables[$placeholder] ?? '';
        }

        return [
            'subject' => strtr($resolved['subject'], $replace),
            'body' => strtr($resolved['body'], $replace),
            'locale' => $locale,
        ];
    }

    public static function save(string $locale, string $key, string $subject, string $body): array
    {
        $locale = self::normalizeLocale($locale, true);
        $definition = self::definition($key);
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        $body = trim(str_replace("\r\n", "\n", str_replace("\r", "\n", $body)));
        if ($subject === '' || $body === '') {
            throw new InvalidArgumentException('Email subject and body are required.');
        }
        self::validatePlaceholders($subject."\n".$body, $definition['placeholders']);

        self::mutateOverrides(function (array $data) use ($locale, $key, $subject, $body): array {
            $data['locales'][$locale][$key] = ['subject' => $subject, 'body' => $body];

            return $data;
        });

        return self::resolve($key, $locale);
    }

    public static function reset(string $locale, string $key): array
    {
        $locale = self::normalizeLocale($locale, true);
        self::definition($key);
        self::mutateOverrides(function (array $data) use ($locale, $key): array {
            unset($data['locales'][$locale][$key]);
            if (empty($data['locales'][$locale])) unset($data['locales'][$locale]);

            return $data;
        });

        return self::resolve($key, $locale);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    private static function resolve(string $key, string $locale): array
    {
        $definition = self::definition($key);
        $overrides = self::overrides()['locales'] ?? [];
        $candidates = array_values(array_unique(array_filter([$locale, explode('_', $locale)[0] ?? '', 'en'])));
        foreach ($candidates as $candidate) {
            $template = $overrides[$candidate][$key] ?? null;
            if (is_array($template) && is_string($template['subject'] ?? null) && is_string($template['body'] ?? null)) {
                return ['subject' => $template['subject'], 'body' => $template['body'], 'source_locale' => $candidate];
            }
        }

        return [...self::localizedDefaults($definition, $locale), 'source_locale' => null];
    }

    private static function localizedDefaults(array $definition, string $locale): array
    {
        $translations = (array) (app(LocalizationService::class)->pack($locale)['translations'] ?? []);
        $defaults = [];
        foreach (['subject', 'body'] as $field) {
            $source = $definition[$field];
            $translated = $translations[$source] ?? $source;
            // A damaged catalog must never drop a verification code or URL.
            $tokens = static function (string $value): array {
                preg_match_all('/\{[A-Z_]+\}/', $value, $matches);
                sort($matches[0]);

                return $matches[0];
            };
            $defaults[$field] = is_string($translated) && trim($translated) !== '' && $tokens($source) === $tokens($translated) ? $translated : $source;
        }

        return $defaults;
    }

    private static function definition(string $key): array
    {
        $definition = self::definitions()[$key] ?? null;
        if (! is_array($definition)) throw new InvalidArgumentException('Email template not found.');

        return $definition;
    }

    private static function definitions(): array
    {
        $common = ['SITENAME', 'SITE_URL', 'CURRENT_YEAR', 'USERNAME', 'USER_EMAIL'];

        return [
            'verification_code' => [
                'name' => 'Email verification code',
                'description' => 'Sent during email registration and email-based two-factor verification.',
                'subject' => 'Your {SITENAME} verification code',
                'body' => "Your {SITENAME} verification code is: {VERIFICATION_CODE}\n\nThis code expires in {EXPIRY_MINUTES} minutes.",
                'placeholders' => [...$common, 'VERIFICATION_CODE', 'EXPIRY_MINUTES'],
            ],
            'password_reset_code' => [
                'name' => 'Password reset code',
                'description' => 'Sent after a user requests a password reset by email.',
                'subject' => 'Reset your {SITENAME} password',
                'body' => "Hello {USERNAME},\n\nYour {SITENAME} password reset code is: {RESET_CODE}\n\nThis code expires in {EXPIRY_MINUTES} minutes.",
                'placeholders' => [...$common, 'RESET_CODE', 'EXPIRY_MINUTES'],
            ],
            'user_invitation' => [
                'name' => 'User invitation',
                'description' => 'Sent when an administrator creates and invites a new user.',
                'subject' => 'You have been invited to {SITENAME}',
                'body' => "Hello {USERNAME},\n\nYou have been invited to {SITENAME} as {ROLE}.\n\nLogin email: {USER_EMAIL}\nTemporary password: {TEMPORARY_PASSWORD}\nLogin: {LOGIN_URL}\n\nPlease sign in and update your password.",
                'placeholders' => [...$common, 'ROLE', 'TEMPORARY_PASSWORD', 'LOGIN_URL'],
            ],
            'account_restriction' => [
                'name' => 'Account restriction',
                'description' => 'Sent when staff temporarily restricts a user account or capability.',
                'subject' => 'Your {SITENAME} account has been restricted',
                'body' => "Hello {USERNAME},\n\nYour {SITENAME} account has been restricted until {BAN_EXPIRES_AT}.\n\nRestrictions:\n{RESTRICTIONS}\n\nIf you believe this is a mistake, contact {SUPPORT_EMAIL}.",
                'placeholders' => [...$common, 'BAN_EXPIRES_AT', 'RESTRICTIONS', 'SUPPORT_EMAIL'],
            ],
            'moderator_assignment' => [
                'name' => 'Moderator role assignment',
                'description' => 'Sent when an administrator promotes a user to the Moderator role.',
                'subject' => 'You are now a {SITENAME} moderator',
                'body' => "Hello {USERNAME},\n\nYou have been assigned the Moderator role on {SITENAME}. You can now access the moderation tools available to your role.\n\nModeration dashboard: {MODERATION_URL}",
                'placeholders' => [...$common, 'MODERATION_URL'],
            ],
            'live_moderator_assignment' => [
                'name' => 'Live stream moderator assignment',
                'description' => 'Sent when a creator assigns a moderator to a live stream.',
                'subject' => 'You are moderating "{STREAM_TITLE}" on {SITENAME}',
                'body' => "Hello {USERNAME},\n\nYou have been assigned as a moderator for the live stream \"{STREAM_TITLE}\". Open the stream to access your moderation controls.\n\nOpen stream: {STREAM_URL}",
                'placeholders' => [...$common, 'STREAM_TITLE', 'STREAM_URL'],
            ],
        ];
    }

    private static function placeholderDefinitions(): array
    {
        return [
            'SITENAME' => ['name' => 'Website name', 'description' => 'The platform name configured in Website Configuration.', 'usage' => 'Available in every subject and body.', 'example' => (string) (GeneralSettings::settings()['platformName'] ?? 'PlayHub')],
            'SITE_URL' => ['name' => 'Website URL', 'description' => 'The public base URL of the website.', 'usage' => 'Available in every subject and body.', 'example' => rtrim((string) config('app.url', ''), '/') ?: url('/')],
            'CURRENT_YEAR' => ['name' => 'Current year', 'description' => 'The four-digit year when the email is sent.', 'usage' => 'Available in every subject and body.', 'example' => date('Y')],
            'USERNAME' => ['name' => 'Recipient name', 'description' => 'The display name of the user receiving the email.', 'usage' => 'Use when the recipient is an existing or invited user.', 'example' => 'Alex Morgan'],
            'USER_EMAIL' => ['name' => 'Recipient email', 'description' => 'The email address receiving the message.', 'usage' => 'Use when the recipient email should be shown in the message.', 'example' => 'alex@example.com'],
            'VERIFICATION_CODE' => ['name' => 'Verification code', 'description' => 'The one-time email verification or two-factor code.', 'usage' => 'Use only in the Email verification code template.', 'example' => '482913'],
            'RESET_CODE' => ['name' => 'Password reset code', 'description' => 'The one-time code used to authorize a password reset.', 'usage' => 'Use only in the Password reset code template.', 'example' => '731205'],
            'EXPIRY_MINUTES' => ['name' => 'Expiry time', 'description' => 'The number of minutes before the one-time code expires.', 'usage' => 'Use in verification and password reset templates.', 'example' => '15'],
            'ROLE' => ['name' => 'Assigned role', 'description' => 'The account role selected by the administrator.', 'usage' => 'Use only in the User invitation template.', 'example' => 'Creator'],
            'TEMPORARY_PASSWORD' => ['name' => 'Temporary password', 'description' => 'The secure password generated for an invited user.', 'usage' => 'Use only in the User invitation template.', 'example' => 'V7pQ2mR9xL4n'],
            'LOGIN_URL' => ['name' => 'Login URL', 'description' => 'The full URL of the website login page.', 'usage' => 'Use only in the User invitation template.', 'example' => url('/login')],
            'BAN_EXPIRES_AT' => ['name' => 'Restriction expiry', 'description' => 'The formatted date and time when the restriction ends.', 'usage' => 'Use only in the Account restriction template.', 'example' => now()->addWeek()->format('M j, Y g:i A')],
            'RESTRICTIONS' => ['name' => 'Restriction list', 'description' => 'A line-separated list of capabilities restricted by staff.', 'usage' => 'Use only in the Account restriction template.', 'example' => "- Commenting disabled\n- Video uploads disabled"],
            'SUPPORT_EMAIL' => ['name' => 'Support email', 'description' => 'The configured address users can contact for assistance.', 'usage' => 'Use only in the Account restriction template.', 'example' => self::supportEmail()],
            'MODERATION_URL' => ['name' => 'Moderation URL', 'description' => 'The full URL of the administration moderation area.', 'usage' => 'Use only in the Moderator role assignment template.', 'example' => url('/admin/reports')],
            'STREAM_TITLE' => ['name' => 'Live stream title', 'description' => 'The title of the live stream the user will moderate.', 'usage' => 'Use only in the Live stream moderator assignment template.', 'example' => 'Weekly creator Q&A'],
            'STREAM_URL' => ['name' => 'Live stream URL', 'description' => 'The full URL of the assigned live stream.', 'usage' => 'Use only in the Live stream moderator assignment template.', 'example' => url('/live/weekly-creator-qa')],
        ];
    }

    private static function validatePlaceholders(string $content, array $allowed): void
    {
        preg_match_all('/\{([^{}\r\n]+)\}/u', $content, $matches);
        $unknown = collect($matches[1] ?? [])->unique()->reject(fn (string $name): bool => in_array($name, $allowed, true))->values();
        if ($unknown->isNotEmpty()) {
            throw new InvalidArgumentException('Unsupported placeholder: {'.$unknown->implode('}, {').'}');
        }
        if (substr_count($content, '{') !== count($matches[0] ?? []) || substr_count($content, '}') !== count($matches[0] ?? [])) {
            throw new InvalidArgumentException('A placeholder has an unmatched brace.');
        }
    }

    private static function normalizeLocale(?string $locale, bool $strict = false): string
    {
        $installed = GeneralSettings::languageIdentifiers();
        if ($strict && LanguageLocales::isAlias($locale)) throw new InvalidArgumentException('This address-style variant was merged. Choose its regional language.');
        $locale = LanguageLocales::canonical($locale);
        if ($locale !== '' && in_array($locale, $installed, true)) return $locale;
        if ($strict) throw new InvalidArgumentException('Language not found.');

        $configured = strtolower(str_replace('-', '_', (string) (GeneralSettings::settings()['defaultLanguage'] ?? 'en')));
        if (in_array($configured, $installed, true)) return $configured;

        return in_array('en', $installed, true) ? 'en' : ($installed[0] ?? 'en');
    }

    private static function languages(): array
    {
        $settings = GeneralSettings::settings();
        $default = self::normalizeLocale((string) ($settings['defaultLanguage'] ?? 'en'));
        $disabled = $settings['disabledLanguages'] ?? [];
        $root = realpath((string) config('playhub.language_path', base_path('lang')));

        return collect(GeneralSettings::languageIdentifiers())->map(function (string $locale) use ($root, $default, $disabled): array {
            $files = $root ? (glob($root.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.'*.php') ?: []) : [];
            $translations = $files ? require $files[0] : [];
            $meta = is_array($translations['__meta'] ?? null) ? $translations['__meta'] : [];

            return [
                'locale' => $locale,
                'label' => (string) ($meta['label'] ?? strtoupper($locale)),
                'native_label' => (string) ($meta['native_label'] ?? $meta['label'] ?? strtoupper($locale)),
                'direction' => ($meta['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
                'default' => $locale === $default,
                'enabled' => ! in_array($locale, $disabled, true),
            ];
        })->sortBy(fn (array $language): string => strtolower($language['label']))->values()->all();
    }

    private static function overrides(): array
    {
        $path = self::storagePath();
        $data = is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : [];

        return is_array($data) ? array_replace(['version' => 1, 'locales' => []], $data) : ['version' => 1, 'locales' => []];
    }

    private static function mutateOverrides(callable $mutator): void
    {
        $path = self::storagePath();
        if (! is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        $lock = fopen($path.'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) throw new InvalidArgumentException('Email templates could not be locked for editing.');

        try {
            $data = $mutator(self::overrides());
            $temporary = $path.'.tmp';
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $encoded, LOCK_EX) === false || ! rename($temporary, $path)) {
                throw new InvalidArgumentException('Email templates could not be saved.');
            }
            @chmod($path, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function stringValue(mixed $value): string
    {
        if (is_array($value)) return implode("\n", array_map(fn (mixed $item): string => (string) $item, $value));

        return is_scalar($value) || $value === null ? (string) $value : '';
    }

    private static function supportEmail(): string
    {
        $settings = IntegrationSettings::settings();

        return trim((string) data_get($settings, 'sendgrid.notificationsEmail'))
            ?: trim((string) data_get($settings, 'smtp.fromEmail'))
            ?: (string) config('mail.from.address', 'support@example.com');
    }

    private static function storagePath(): string
    {
        return storage_path('app/playhub-email-templates.json');
    }
}
