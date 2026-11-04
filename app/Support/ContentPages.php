<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ContentPages
{
    public static function catalog(?string $locale = null): array
    {
        $locale = self::normalizeLocale($locale);

        return [
            'locale' => $locale,
            'default_locale' => self::normalizeLocale((string) (GeneralSettings::settings()['defaultLanguage'] ?? 'en')),
            'languages' => self::languages(),
            'pages' => collect(self::definitions())->map(fn (array $definition, string $key): array => self::resolvedPage($key, $locale, $definition))->values()->all(),
        ];
    }

    public static function publicCatalog(?string $locale = null, bool $navigationOnly = false): array
    {
        $locale = self::normalizeLocale($locale);
        $pages = collect(self::definitions())
            ->map(fn (array $definition, string $key): array => self::resolvedPage($key, $locale, $definition))
            ->where('enabled')
            ->map(function (array $page) use ($navigationOnly): array {
                if (! $navigationOnly) {
                    return collect($page)->except('default_content')->all();
                }

                return collect($page)->only(['key', 'route', 'group', 'navigation_label'])->all();
            })
            ->values()
            ->all();

        return ['locale' => $locale, 'pages' => $pages];
    }

    public static function page(string $key, ?string $locale = null, bool $includeDisabled = false): ?array
    {
        $definition = self::definitions()[$key] ?? null;
        if (! is_array($definition)) {
            return null;
        }

        $page = self::resolvedPage($key, self::normalizeLocale($locale), $definition);

        return $includeDisabled || $page['enabled'] ? $page : null;
    }

    public static function pageForPath(string $path, ?string $locale = null, bool $includeDisabled = false): ?array
    {
        $path = '/'.trim($path, '/');
        $key = collect(self::definitions())->search(fn (array $definition): bool => $definition['route'] === $path);

        return is_string($key) ? self::page($key, $locale, $includeDisabled) : null;
    }

    public static function save(string $locale, string $key, array $content, bool $enabled): array
    {
        $locale = self::normalizeLocale($locale, true);
        self::definition($key);
        $title = trim((string) ($content['title'] ?? ''));
        $navigationLabel = trim((string) ($content['navigation_label'] ?? ''));
        $subtitle = trim((string) ($content['subtitle'] ?? ''));
        $sections = collect($content['sections'] ?? [])->map(fn (mixed $section): array => [
            'title' => trim((string) data_get($section, 'title', '')),
            'body' => trim(str_replace("\r\n", "\n", str_replace("\r", "\n", (string) data_get($section, 'body', '')))),
        ])->filter(fn (array $section): bool => $section['title'] !== '' && $section['body'] !== '')->values()->all();

        if ($title === '' || $navigationLabel === '' || $subtitle === '' || $sections === []) {
            throw new InvalidArgumentException('Page title, navigation label, summary, and at least one complete section are required.');
        }

        self::mutate(function (array $data) use ($locale, $key, $title, $navigationLabel, $subtitle, $sections, $enabled): array {
            $data['pages'][$key]['enabled'] = $enabled;
            $data['pages'][$key]['updated_at'] = now()->toIso8601String();
            $data['pages'][$key]['locales'][$locale] = [
                'title' => $title,
                'navigation_label' => $navigationLabel,
                'subtitle' => $subtitle,
                'sections' => $sections,
            ];

            return $data;
        });

        return self::page($key, $locale, true);
    }

    public static function reset(string $locale, string $key): array
    {
        $locale = self::normalizeLocale($locale, true);
        self::definition($key);
        self::mutate(function (array $data) use ($locale, $key): array {
            unset($data['pages'][$key]['locales'][$locale]);
            if (empty($data['pages'][$key]['locales'])) {
                unset($data['pages'][$key]['locales']);
            }
            $data['pages'][$key]['updated_at'] = now()->toIso8601String();

            return $data;
        });

        return self::page($key, $locale, true);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    public static function enabledRoutes(): array
    {
        return collect(self::definitions())
            ->keys()
            ->filter(fn (string $key): bool => (bool) (self::settings()['pages'][$key]['enabled'] ?? true))
            ->map(fn (string $key): string => self::definitions()[$key]['route'])
            ->values()
            ->all();
    }

    public static function updatedAt(): ?Carbon
    {
        $timestamps = collect(self::settings()['pages'] ?? [])->pluck('updated_at')->filter();
        if ($timestamps->isEmpty()) {
            return null;
        }

        return Carbon::parse($timestamps->max());
    }

    /** Raw saved legal revisions only; built-in defaults are not owner publication. */
    public static function safetyPolicyMaterial(): array
    {
        $pages = self::settings()['pages'] ?? [];

        return is_array($pages) ? array_intersect_key($pages, array_flip(['terms', 'privacy'])) : [];
    }

    public static function completeLegalContent(mixed $content): bool
    {
        if (! is_array($content)) {
            return false;
        }
        foreach (['title', 'subtitle'] as $field) {
            if (! is_string($content[$field] ?? null) || trim($content[$field]) === '') {
                return false;
            }
        }
        if (! is_array($content['sections'] ?? null) || $content['sections'] === []) {
            return false;
        }
        foreach ($content['sections'] as $section) {
            if (! is_array($section)) {
                return false;
            }
            foreach (['title', 'body'] as $field) {
                if (! is_string($section[$field] ?? null) || trim($section[$field]) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    private static function resolvedPage(string $key, string $locale, array $definition): array
    {
        $stored = self::settings()['pages'][$key] ?? [];
        $stored = is_array($stored) ? $stored : [];
        $overrides = is_array($stored['locales'] ?? null) ? $stored['locales'] : [];
        $sourceLocale = null;
        $content = null;
        $legal = in_array($key, ['terms', 'privacy'], true);
        $candidates = [$locale, explode('_', $locale)[0] ?? ''];
        if ($legal) {
            $default = self::normalizeLocale(null);
            $savedLocales = array_keys($overrides);
            sort($savedLocales, SORT_STRING);
            // Legal links must show saved documents even when a reader's language
            // has no translation; templates cannot stand in for published text.
            $candidates = [...$candidates, $default, explode('_', $default)[0] ?? '', 'en', ...$savedLocales];
        } else {
            $candidates[] = 'en';
        }
        foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
            if (! is_array($overrides[$candidate] ?? null)) {
                continue;
            }
            if ($legal && (! in_array($candidate, GeneralSettings::languageIdentifiers(), true) || ! self::completeLegalContent($overrides[$candidate]))) {
                continue;
            }
            $sourceLocale = $candidate;
            $content = $overrides[$candidate];
            break;
        }
        $content = array_replace($definition['content'], is_array($content) ? $content : []);
        if (! is_array($content['sections'] ?? null) || $content['sections'] === []) {
            $content['sections'] = $definition['content']['sections'];
        }

        return [
            'key' => $key,
            'route' => $definition['route'],
            'group' => $definition['group'],
            'enabled' => (bool) ($stored['enabled'] ?? true),
            'customized' => $sourceLocale === $locale,
            'source_locale' => $sourceLocale,
            'updated_at' => $stored['updated_at'] ?? null,
            ...$content,
            'default_content' => $definition['content'],
        ];
    }

    private static function definition(string $key): array
    {
        $definition = self::definitions()[$key] ?? null;
        if (! is_array($definition)) {
            throw new InvalidArgumentException('Content page not found.');
        }

        return $definition;
    }

    private static function definitions(): array
    {
        return [
            'about' => self::definitionRow('/about', 'Company', 'About us', 'PlayHub is the video platform running on this deployment, built for creators, viewers, communities, and platform operators.', [
                ['What PlayHub does', 'PlayHub provides creator channels, video uploads, scheduled publishing, subscriptions, comments, playlists, watch later, live content pages, and administration tools from one platform.\n\nThe upload system supports categories and languages from the API, processes uploaded videos, stores thumbnails and captions, and keeps scheduled content private until its release time.'],
                ['For creators', 'Creators can upload videos, manage visibility, add thumbnails and captions, publish scheduled content, review their library, and track content from Creator Studio.\n\nCreator profile pages, subscriptions, community posts, comments, and playlists are part of the same account and content system.'],
                ['For viewers', 'Viewers can browse categories, search videos, watch content, subscribe to channels, save videos to watch later, comment, receive notifications, and manage language and accessibility preferences.'],
            ]),
            'contact' => self::definitionRow('/contact', 'Company', 'Contact us', 'Use the right PlayHub channel for account help, content reports, technical issues, and administrator requests.', [
                ['Account and creator support', 'Sign in and use Settings for account, profile, password, notification, privacy, and creator preferences. Creator upload and content issues should include the video title, channel, browser, and any processing error shown on the upload page.'],
                ['Safety and content reports', 'Use the report actions on videos, comments, and community posts when content may violate platform rules. These reports include the content reference so administrators can review the exact item.'],
                ['Business or administrative contact', 'Official contact addresses, billing contacts, and external support channels should be configured by the PlayHub site administrator for this deployment. This page intentionally does not display unverified email addresses.'],
            ]),
            'developers' => self::definitionRow('/developers', 'Company', 'Developers', 'Technical notes for integrations with the current PlayHub API and upload workflow.', [
                ['API surface', 'Public JSON endpoints include /api/health for deployment status and /api/upload-options for upload categories, languages, limits, chunk size, and trending tags.\n\nAuthenticated APIs use the existing Laravel Sanctum token flow. Account, upload, creator, playlist, notification, comment, like, subscription, and admin endpoints are served under /api.'],
                ['Uploads', 'Large uploads use upload sessions: create a session, send numbered chunks, then complete the session with title, description, category, language, visibility, schedule, thumbnail, captions, and monetization fields.\n\nThe processing job encodes renditions, creates thumbnails when needed, stores captions, and marks videos ready when processing completes. Future scheduled videos remain private until the scheduler releases them.'],
                ['Integration rules', 'Use JSON for normal API requests and FormData for file uploads. Do not bypass validation: categories and languages use configured IDs, visibility is limited to public, unlisted, or private, and captions accept SRT, VTT, or TXT files.'],
            ]),
            'faq' => self::definitionRow('/faq', 'Support', 'FAQ', 'Answers for the PlayHub features currently available in this deployment.', [
                ['How do uploads work?', 'Select one or more video files, enter required details, choose category and language, add a thumbnail, choose visibility, and publish. Large files are uploaded in chunks before the server starts processing.'],
                ['Can I schedule a video?', 'Yes. Use the native schedule date and time field on the upload page. The backend stores scheduled_at, processes the video, keeps it private while the date is in the future, and releases it when the Laravel scheduler runs after the scheduled time.'],
                ['Why is my video still processing?', 'Uploaded videos are encoded into playable renditions. Processing time depends on file size, duration, server load, and queue status. The upload page and Creator Studio show the current processing status.'],
                ['Can I change privacy later?', 'Creator Studio content management uses the same visibility model: public, unlisted, or private. Scheduled videos use requested visibility after release.'],
            ]),
            'support' => self::definitionRow('/support', 'Support', 'Help Center', 'Find practical support for account access, uploads, playback, creator tools, and moderation.', [
                ['Account help', 'Use Settings to update your profile, security, password, two-factor delivery, devices, preferences, and notification options. If you cannot access your account, use the login flow and provide the account email to the site administrator.'],
                ['Upload and processing help', 'Check the upload queue, chunk upload progress, processing stage, thumbnail selection, captions file type, and required title, description, category, and visibility fields.'],
                ['Viewing and community help', 'Playback, comments, likes, watch later, subscriptions, notifications, messages, playlists, and language settings are available through the main navigation and account menu.'],
                ['Moderation help', 'Use built-in report actions for videos and comments so admins receive the exact content reference. Admin dashboard pages show users, content, reports, monetization, analytics, support, roles, and system settings.'],
            ]),
            'refund' => self::definitionRow('/refund', 'Support', 'Refund Policy', 'Refund handling for paid PlayHub features depends on billing records attached to this deployment.', [
                ['Eligibility', 'If no paid transaction exists in the connected billing provider or platform records, there is no platform charge to refund. Refunds can only be reviewed against payment records, subscription IDs, invoices, or transaction references.'],
                ['Creator and Pro features', 'If paid creator features or a Pro plan are enabled for this deployment, refund requests should include the account email, transaction ID, subscription ID, payment date, and reason for the request. Administrators should verify the request against payment-provider records before issuing any refund.'],
                ['Non-refundable cases', 'PlayHub cannot refund third-party bank fees, chargeback fees, external service fees, or payments made outside the configured platform billing flow. Content removals or account restrictions do not automatically create a refund unless required by law or the active billing terms.'],
            ]),
            'privacy' => self::definitionRow('/privacy', 'Legal', 'Privacy Policy', 'How this PlayHub deployment handles account, content, activity, security, and preference data.', [
                ['Data processed by PlayHub', 'PlayHub stores account details, creator profile data, uploaded videos, thumbnails, captions, categories, languages, playlists, comments, likes, subscriptions, watch-later records, notifications, messages, reports, device/session data, language preference, theme preference, and security settings needed to operate the platform.'],
                ['How data is used', 'Data is used to authenticate users, publish and process content, show videos and channels, personalize account preferences, prevent abuse, support moderation, provide admin analytics, and keep creator and viewer features working.'],
                ['Controls', 'Users can update profile and preference data in Settings, manage uploaded content in Creator Studio, change password and two-factor options in Security settings, and use report tools for content safety. Administrators can manage users, content, reports, roles, and system settings from the admin dashboard.'],
                ['Retention and access', 'Uploaded media and account records are retained while needed for platform operation, legal compliance, security, moderation, and user-requested features. Deleting users or content should also remove associated server media files where implemented by the admin tools.'],
            ]),
            'terms' => self::definitionRow('/terms', 'Legal', 'Terms of Service', 'Rules for using this PlayHub deployment as a viewer, creator, or administrator.', [
                ['Accounts', 'You are responsible for your account credentials, profile activity, uploaded content, comments, messages, and any creator or admin actions performed from your account. Use strong passwords and keep two-factor delivery methods current.'],
                ['Content rules', 'Do not upload content you do not have rights to publish. Do not post illegal, abusive, harmful, deceptive, spam, malware, harassment, or privacy-violating content. PlayHub administrators may restrict, remove, suspend, verify, or delete users and content according to platform rules and applicable law.'],
                ['Creator uploads', 'Creators must provide accurate titles, descriptions, categories, languages, thumbnails, captions, visibility, and scheduling settings. Scheduled videos are not public until their scheduled time and successful processing.'],
                ['Service operation', 'PlayHub may process uploads, create video renditions, generate thumbnails, store metadata, collect engagement activity, and provide analytics. Availability can depend on server queues, storage, media processing, network conditions, and administrator configuration.'],
            ]),
            'cookies' => self::definitionRow('/cookies', 'Legal', 'Cookie Policy', 'How PlayHub uses cookies and browser storage for sessions, security, preferences, and playback.', [
                ['Essential storage', 'PlayHub uses essential cookies and browser storage for login sessions, API authentication, CSRF/security behavior, language selection, theme selection, account menu state, and other features required for the application to work.'],
                ['Preference storage', 'The frontend stores preferences such as selected language, theme, playback queue, watch progress, and interface state so the experience remains consistent across page navigation and reloads.'],
                ['Media and analytics storage', 'Playback progress, watch-later state, likes, comments, subscriptions, notifications, and admin analytics are saved when linked to authenticated platform features.'],
                ['Your choices', 'You can clear browser cookies and local storage in your browser settings. Clearing essential storage may sign you out, reset language/theme preferences, remove local playback state, and require you to log in again.'],
            ]),
        ];
    }

    private static function definitionRow(string $route, string $group, string $title, string $subtitle, array $sections): array
    {
        return [
            'route' => $route,
            'group' => $group,
            'content' => [
                'title' => $title,
                'navigation_label' => $title,
                'subtitle' => $subtitle,
                'sections' => array_map(fn (array $section): array => ['title' => $section[0], 'body' => $section[1]], $sections),
            ],
        ];
    }

    private static function normalizeLocale(?string $locale, bool $strict = false): string
    {
        $installed = GeneralSettings::languageIdentifiers();
        $locale = strtolower(str_replace('-', '_', trim((string) $locale)));
        if ($locale !== '' && in_array($locale, $installed, true)) {
            return $locale;
        }
        if ($strict) {
            throw new InvalidArgumentException('Language not found.');
        }

        $configured = strtolower(str_replace('-', '_', (string) (GeneralSettings::settings()['defaultLanguage'] ?? 'en')));
        if (in_array($configured, $installed, true)) {
            return $configured;
        }

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

    private static function settings(): array
    {
        $path = self::storagePath();
        $data = is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : [];

        return is_array($data) ? array_replace(['version' => 1, 'pages' => []], $data) : ['version' => 1, 'pages' => []];
    }

    private static function mutate(callable $mutator): void
    {
        $path = self::storagePath();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $lock = fopen($path.'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            throw new InvalidArgumentException('Content pages could not be locked for editing.');
        }

        try {
            $data = $mutator(self::settings());
            $temporary = $path.'.tmp';
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $encoded, LOCK_EX) === false || ! rename($temporary, $path)) {
                throw new InvalidArgumentException('Content pages could not be saved.');
            }
            @chmod($path, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function storagePath(): string
    {
        // Trusted deployment/test configuration only; never populated from a request.
        return (string) (config('playhub.content_pages_path') ?: storage_path('app/playhub-content-pages.json'));
    }
}
