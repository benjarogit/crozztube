<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateSystemBackup;
use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\Gift;
use App\Models\LiveStream;
use App\Models\LiveStreamCategory;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Video;
use App\Models\WebsiteAd;
use App\Plugins\PluginHooks;
use App\Support\AuthSettings;
use App\Support\CaptchaSettings;
use App\Support\BrandedEmail;
use App\Support\ContentPages;
use App\Support\EmailTemplates;
use App\Support\GeneralSettings;
use App\Support\LanguageLocales;
use App\Support\IntegrationSettings;
use App\Support\LiveKitDockerStatus;
use App\Support\LiveKitSettings;
use App\Support\LiveStreamMedia;
use App\Support\LiveStreamingSettings;
use App\Support\MediaStorage;
use App\Support\MonetizationSettings;
use App\Support\PlatformFeatureSettings;
use App\Support\PlayerAdSettings;
use App\Support\SeoSettings;
use App\Support\SitemapData;
use App\Support\VideoImportSettings;
use App\Support\WalletLedger;
use App\Updates\VersionRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class AdminController extends Controller
{
    public function contentPages(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $catalog = ContentPages::catalog((string) $request->query('locale', ''));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['locale' => [$exception->getMessage()]]);
        }

        return response()->json($catalog)->header('Cache-Control', 'no-store');
    }

    public function updateContentPage(Request $request, string $page): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(ContentPages::has($page), 404, 'Content page not found.');
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(GeneralSettings::languageIdentifiers())],
            'enabled' => ['required', 'boolean'],
            'title' => ['required', 'string', 'max:160'],
            'navigation_label' => ['required', 'string', 'max:80'],
            'subtitle' => ['required', 'string', 'max:500'],
            'sections' => ['required', 'array', 'min:1', 'max:30'],
            'sections.*.title' => ['required', 'string', 'max:180'],
            'sections.*.body' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $updated = ContentPages::save($data['locale'], $page, $data, $data['enabled']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['page' => [$exception->getMessage()]]);
        }
        $this->recordAdminActivity($request->user(), 'Updated '.$page.' content page for '.$data['locale']);

        return response()->json(['message' => 'Content page updated.', 'page' => $updated])->header('Cache-Control', 'no-store');
    }

    public function resetContentPage(Request $request, string $page): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(ContentPages::has($page), 404, 'Content page not found.');
        $data = $request->validate(['locale' => ['required', 'string', Rule::in(GeneralSettings::languageIdentifiers())]]);

        try {
            $updated = ContentPages::reset($data['locale'], $page);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['page' => [$exception->getMessage()]]);
        }
        $this->recordAdminActivity($request->user(), 'Reset '.$page.' content page for '.$data['locale']);

        return response()->json(['message' => 'Content page reset to its fallback.', 'page' => $updated])->header('Cache-Control', 'no-store');
    }

    public function emailTemplates(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $catalog = EmailTemplates::catalog((string) $request->query('locale', ''));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['locale' => [$exception->getMessage()]]);
        }

        return response()->json($catalog)->header('Cache-Control', 'no-store');
    }

    public function updateEmailTemplate(Request $request, string $template): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(EmailTemplates::has($template), 404, 'Email template not found.');
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(GeneralSettings::languageIdentifiers())],
            'subject' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        try {
            EmailTemplates::save($data['locale'], $template, $data['subject'], $data['body']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['template' => [$exception->getMessage()]]);
        }
        $this->recordAdminActivity($request->user(), 'Updated '.$template.' email template for '.$data['locale']);
        $payload = EmailTemplates::catalog($data['locale']);

        return response()->json([
            'message' => 'Email template updated.',
            'template' => collect($payload['templates'])->firstWhere('key', $template),
        ])->header('Cache-Control', 'no-store');
    }

    public function resetEmailTemplate(Request $request, string $template): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(EmailTemplates::has($template), 404, 'Email template not found.');
        $data = $request->validate(['locale' => ['required', 'string', Rule::in(GeneralSettings::languageIdentifiers())]]);

        try {
            EmailTemplates::reset($data['locale'], $template);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['template' => [$exception->getMessage()]]);
        }
        $this->recordAdminActivity($request->user(), 'Reset '.$template.' email template for '.$data['locale']);
        $payload = EmailTemplates::catalog($data['locale']);

        return response()->json([
            'message' => 'Email template reset to its fallback.',
            'template' => collect($payload['templates'])->firstWhere('key', $template),
        ])->header('Cache-Control', 'no-store');
    }

    public function languages(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $settings = GeneralSettings::settings();
        $defaultLanguage = (string) ($settings['defaultLanguage'] ?? config('app.locale', 'en'));
        $disabledLanguages = $settings['disabledLanguages'] ?? [];
        $languages = collect($this->languageFiles())
            ->map(function (string $file) use ($defaultLanguage, $disabledLanguages): array {
                $translations = $this->loadLanguageFile($file);
                $meta = is_array($translations['__meta'] ?? null) ? $translations['__meta'] : [];
                $locale = basename(dirname($file));

                return [
                    'locale' => $locale,
                    'code' => (string) ($meta['code'] ?? $locale),
                    'label' => (string) ($meta['label'] ?? strtoupper($locale)),
                    'native_label' => (string) ($meta['native_label'] ?? $meta['label'] ?? strtoupper($locale)),
                    'direction' => ($meta['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
                    'file' => basename($file),
                    'writable' => is_writable(dirname($file)),
                    'default' => $locale === $defaultLanguage,
                    'enabled' => ! in_array($locale, $disabledLanguages, true),
                ];
            })
            ->sortBy(fn (array $language): string => Str::lower($language['label']))
            ->values();

        return response()->json(['languages' => $languages, 'default_language' => $defaultLanguage, 'total' => $languages->count()])
            ->header('Cache-Control', 'no-store');
    }

    public function updateDefaultLanguage(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $request->merge(['locale' => strtolower(str_replace('-', '_', trim((string) $request->input('locale', ''))))]);
        $disabledLanguages = GeneralSettings::settings()['disabledLanguages'] ?? [];
        $available = collect($this->languageFiles())->map(fn (string $file): string => basename(dirname($file)))->unique()->diff($disabledLanguages)->values()->all();
        $data = $request->validate(['locale' => ['required', 'string', Rule::in($available)]]);
        $settings = GeneralSettings::settings();
        $settings['defaultLanguage'] = $data['locale'];
        GeneralSettings::save($settings);
        GeneralSettings::apply();
        $this->recordAdminActivity($request->user(), 'Updated default website language to '.$data['locale']);

        return response()->json([
            'message' => 'Default website language updated.',
            'default_language' => $data['locale'],
        ])->header('Cache-Control', 'no-store');
    }

    public function updateLanguageStatus(Request $request, string $locale): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->rejectLanguageAlias($locale);
        $file = $this->languageFile($locale);
        $locale = basename(dirname($file));
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $settings = GeneralSettings::settings();
        $disabledLanguages = $settings['disabledLanguages'] ?? [];

        if (! $data['enabled']) {
            if (($settings['defaultLanguage'] ?? '') === $locale) {
                throw ValidationException::withMessages(['enabled' => ['Set another default language before disabling this language.']]);
            }
            if (count(array_diff(GeneralSettings::languageIdentifiers(), $disabledLanguages)) <= 1) {
                throw ValidationException::withMessages(['enabled' => ['At least one website language must remain enabled.']]);
            }
            $disabledLanguages[] = $locale;
        } else {
            $disabledLanguages = array_values(array_diff($disabledLanguages, [$locale]));
        }

        $settings['disabledLanguages'] = array_values(array_unique($disabledLanguages));
        GeneralSettings::save($settings);
        GeneralSettings::apply();
        $status = $data['enabled'] ? 'enabled' : 'disabled';
        $this->recordAdminActivity($request->user(), ucfirst($status).' website language '.$locale);

        return response()->json([
            'message' => 'Website language '.$status.'.',
            'locale' => $locale,
            'enabled' => $data['enabled'],
            'default_language' => GeneralSettings::settings()['defaultLanguage'],
        ])->header('Cache-Control', 'no-store');
    }

    public function language(Request $request, string $locale): JsonResponse
    {
        $this->authorizeAdmin($request);
        $file = $this->languageFile($locale);
        $data = $this->loadLanguageFile($file);
        $translations = collect($data)
            ->except(['__meta', '__plurals', '__patterns'])
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value));
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $translations = $translations->filter(fn (string $value, string $key): bool => Str::contains(Str::lower($key.' '.$value), Str::lower($search)));
        }

        $perPage = max(25, min(200, (int) $request->query('per_page', 100)));
        $lastPage = max(1, (int) ceil($translations->count() / $perPage));
        $page = max(1, min($lastPage, (int) $request->query('page', 1)));
        $entries = $translations->slice(($page - 1) * $perPage, $perPage)
            ->map(fn (string $value, string $key): array => ['key' => $key, 'value' => $value])
            ->values();
        $allTranslations = collect($data)
            ->except(['__meta', '__plurals', '__patterns'])
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value));
        $meta = is_array($data['__meta'] ?? null) ? $data['__meta'] : [];
        $settings = GeneralSettings::settings();
        $languageLocale = basename(dirname($file));
        $plurals = collect(is_array($data['__plurals'] ?? null) ? $data['__plurals'] : [])
            ->flatMap(function (mixed $forms, mixed $key): array {
                if (! is_string($key) || ! is_array($forms)) {
                    return [];
                }

                return collect($forms)
                    ->filter(fn (mixed $value): bool => is_string($value))
                    ->map(fn (string $value, mixed $form): array => ['key' => $key, 'form' => (string) $form, 'value' => $value])
                    ->values()
                    ->all();
            })
            ->values();
        $patterns = collect(is_array($data['__patterns'] ?? null) ? $data['__patterns'] : [])
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value))
            ->map(fn (string $value, string $key): array => ['key' => $key, 'value' => $value])
            ->values();

        return response()->json([
            'language' => [
                'locale' => $languageLocale,
                'code' => (string) ($meta['code'] ?? $languageLocale),
                'label' => (string) ($meta['label'] ?? strtoupper($languageLocale)),
                'native_label' => (string) ($meta['native_label'] ?? $meta['label'] ?? strtoupper($languageLocale)),
                'direction' => ($meta['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
                'file' => basename($file),
                'writable' => is_writable(dirname($file)),
                'default' => $languageLocale === ($settings['defaultLanguage'] ?? config('app.locale', 'en')),
                'enabled' => ! in_array($languageLocale, $settings['disabledLanguages'] ?? [], true),
                'updated_at' => Carbon::createFromTimestamp((int) filemtime($file))->toIso8601String(),
            ],
            'entries' => $entries,
            'plurals' => $plurals,
            'patterns' => $patterns,
            'stats' => [
                'entries' => $allTranslations->count(),
                'empty' => $allTranslations->filter(fn (string $value): bool => trim($value) === '')->count(),
                'source_matches' => $allTranslations->filter(fn (string $value, string $key): bool => $value === $key)->count(),
                'plural_forms' => $plurals->count(),
                'patterns' => $patterns->count(),
            ],
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $translations->count(), 'from' => $translations->count() ? (($page - 1) * $perPage) + 1 : 0, 'to' => min($page * $perPage, $translations->count())],
            'checksum' => hash_file('sha256', $file),
        ])->header('Cache-Control', 'no-store');
    }

    public function updateLanguage(Request $request, string $locale): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->rejectLanguageAlias($locale);
        $file = $this->languageFile($locale);
        $checksum = (string) $request->input('checksum', '');
        if ($checksum === '' || ! hash_equals(hash_file('sha256', $file), $checksum)) {
            return response()->json(['message' => 'This language file changed after it was loaded. Reload it before saving.'], 409);
        }
        $changes = $request->input('changes');
        if (! is_array($changes) || $changes === [] || count($changes) > 500) {
            throw ValidationException::withMessages(['changes' => ['Submit between 1 and 500 language changes at a time.']]);
        }

        $data = $this->loadLanguageFile($file);
        $applied = 0;
        foreach ($changes as $index => $change) {
            if (! is_array($change) || ! is_string($change['section'] ?? null) || ! is_string($change['key'] ?? null) || ! is_string($change['value'] ?? null)) {
                throw ValidationException::withMessages(['changes.'.$index => ['Each language change must contain a section, key, and text value.']]);
            }
            $section = $change['section'];
            $key = $change['key'];
            $value = $change['value'];
            if (mb_strlen($value) > 20000) {
                throw ValidationException::withMessages(['changes.'.$index.'.value' => ['A language value cannot exceed 20,000 characters.']]);
            }

            $before = null;
            if ($section === 'translation' && ! str_starts_with($key, '__') && array_key_exists($key, $data) && is_string($data[$key])) {
                $before = $data[$key];
                $data[$key] = $value;
            } elseif ($section === 'pattern' && isset($data['__patterns']) && is_array($data['__patterns']) && array_key_exists($key, $data['__patterns']) && is_string($data['__patterns'][$key])) {
                $before = $data['__patterns'][$key];
                $data['__patterns'][$key] = $value;
            } elseif ($section === 'plural' && isset($data['__plurals'][$key]) && is_array($data['__plurals'][$key])) {
                $form = $change['form'] ?? null;
                $form = is_int($form) || (is_string($form) && ctype_digit($form)) ? (int) $form : $form;
                if ((! is_string($form) && ! is_int($form)) || ! array_key_exists($form, $data['__plurals'][$key]) || ! is_string($data['__plurals'][$key][$form])) {
                    throw ValidationException::withMessages(['changes.'.$index => ['The selected plural form does not exist.']]);
                }
                $before = $data['__plurals'][$key][$form];
                $data['__plurals'][$key][$form] = $value;
            } elseif ($section === 'meta' && in_array($key, ['label', 'native_label', 'direction'], true) && isset($data['__meta']) && is_array($data['__meta'])) {
                if ($key === 'direction' && ! in_array($value, ['ltr', 'rtl'], true)) {
                    throw ValidationException::withMessages(['changes.'.$index.'.value' => ['Direction must be ltr or rtl.']]);
                }
                if ($key !== 'direction' && (trim($value) === '' || mb_strlen($value) > 100)) {
                    throw ValidationException::withMessages(['changes.'.$index.'.value' => ['Language names must contain 1 to 100 characters.']]);
                }
                $before = (string) ($data['__meta'][$key] ?? '');
                $data['__meta'][$key] = $value;
            } else {
                throw ValidationException::withMessages(['changes.'.$index => ['The selected language key cannot be edited.']]);
            }
            if ($before !== $value) {
                $applied++;
            }
        }

        if ($applied > 0) {
            $this->writeLanguageFile($file, $data, $checksum);
        }
        logger()->info('Admin language file updated.', ['admin_id' => $request->user()?->id, 'locale' => basename(dirname($file)), 'changes' => $applied]);

        return response()->json([
            'message' => $applied === 1 ? '1 language value updated.' : $applied.' language values updated.',
            'updated' => $applied,
            'checksum' => hash_file('sha256', $file),
            'updated_at' => Carbon::createFromTimestamp((int) filemtime($file))->toIso8601String(),
        ]);
    }

    public function gifts(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $gifts = Gift::query()->orderBy('sort_order')->orderBy('name')->paginate(50);

        return response()->json([
            'gifts' => collect($gifts->items())->map(fn (Gift $gift): array => $this->giftPayload($gift))->all(),
            'meta' => ['current_page' => $gifts->currentPage(), 'last_page' => $gifts->lastPage(), 'per_page' => $gifts->perPage(), 'total' => $gifts->total(), 'from' => $gifts->firstItem() ?: 0, 'to' => $gifts->lastItem() ?: 0],
        ]);
    }

    public function storeGift(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validateGift($request, true);
        $directory = $this->giftDirectory();
        File::ensureDirectoryExists($directory, 0775, true);
        $filename = Str::slug($data['name']).'-'.Str::lower(Str::random(10)).'.webp';
        $request->file('image')->move($directory, $filename);

        try {
            $gift = Gift::query()->create([
                'name' => trim($data['name']),
                'slug' => $this->uniqueGiftSlug($data['name']),
                'image_path' => 'gifts/'.$filename,
                'price_cents' => (int) round(((float) $data['price']) * 100),
                'enabled' => $request->boolean('enabled', true),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);
        } catch (\Throwable $exception) {
            File::delete($directory.'/'.$filename);
            throw $exception;
        }

        return response()->json(['message' => 'Gift added.', 'gift' => $this->giftPayload($gift)], 201);
    }

    public function updateGift(Request $request, Gift $gift): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validateGift($request, false);
        $oldPath = $gift->image_path;
        $newFilename = null;
        if ($request->hasFile('image')) {
            $directory = $this->giftDirectory();
            File::ensureDirectoryExists($directory, 0775, true);
            $newFilename = Str::slug($data['name']).'-'.Str::lower(Str::random(10)).'.webp';
            $request->file('image')->move($directory, $newFilename);
        }

        try {
            $gift->update([
                'name' => trim($data['name']),
                'image_path' => $newFilename ? 'gifts/'.$newFilename : $oldPath,
                'price_cents' => (int) round(((float) $data['price']) * 100),
                'enabled' => $request->boolean('enabled'),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);
        } catch (\Throwable $exception) {
            if ($newFilename) {
                File::delete($this->giftDirectory().'/'.$newFilename);
            }
            throw $exception;
        }
        if ($newFilename) {
            $this->deleteGiftImage($oldPath);
        }

        return response()->json(['message' => 'Gift updated.', 'gift' => $this->giftPayload($gift->fresh())]);
    }

    public function deleteGift(Request $request, Gift $gift): JsonResponse
    {
        $this->authorizeAdmin($request);
        $path = $gift->image_path;
        abort_unless($this->deleteGiftImage($path), 500, 'The gift image could not be removed from local storage.');
        $gift->delete();

        return response()->json(['message' => 'Gift deleted.']);
    }

    public function websiteAds(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $ads = WebsiteAd::query()->orderByDesc('priority')->orderByDesc('id')->get();

        return response()->json([
            'ads' => $ads->map(fn (WebsiteAd $ad): array => $this->websiteAdPayload($ad))->all(),
            'summary' => [
                'total' => $ads->count(),
                'active' => $ads->filter(fn (WebsiteAd $ad): bool => $ad->enabled && (! $ad->starts_at || $ad->starts_at->isPast()) && (! $ad->ends_at || $ad->ends_at->isFuture()))->count(),
                'impressions' => $ads->sum('impressions'),
                'clicks' => $ads->sum('clicks'),
            ],
        ]);
    }

    public function storeWebsiteAd(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $ad = WebsiteAd::query()->create($this->validatedWebsiteAd($request));

        return response()->json(['message' => 'Ad created.', 'ad' => $this->websiteAdPayload($ad)], 201);
    }

    public function updateWebsiteAd(Request $request, WebsiteAd $websiteAd): JsonResponse
    {
        $this->authorizeAdmin($request);
        $websiteAd->update($this->validatedWebsiteAd($request));

        return response()->json(['message' => 'Ad updated.', 'ad' => $this->websiteAdPayload($websiteAd->fresh())]);
    }

    public function deleteWebsiteAd(Request $request, WebsiteAd $websiteAd): JsonResponse
    {
        $this->authorizeAdmin($request);
        $websiteAd->delete();

        return response()->json(['message' => 'Ad deleted.']);
    }

    public function pageData(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $end = now();
        $start = $end->copy()->subDays(27)->startOfDay();
        $previousStart = $start->copy()->subDays(28);
        $previousEnd = $start->copy()->subSecond();
        $labels = $this->chartDateLabels($start, $end);
        $usersSeries = $this->dailyCounts('users', $start, $end);
        $previousUsersSeries = $this->dailyCounts('users', $previousStart, $previousEnd);
        $activeSeries = $this->activeUsersSeries($start, $end);
        $uploadsSeries = $this->dailyCounts('videos', $start, $end);
        $previousUploadsSeries = $this->dailyCounts('videos', $previousStart, $previousEnd);
        $reportsSeries = $this->reportDailyCounts($start, $end);
        $watchHoursSeries = $this->dailyWatchHours($start, $end);
        $viewsSeries = $this->dailyVideoViews($start, $end);
        $totalUsers = User::query()->count();
        $totalChannels = Channel::query()->count();
        $totalVideos = Video::query()->count();
        $shortVideos = Video::query()->shorts()->count();
        $liveReplayVideos = Video::query()->where('source_type', 'live_replay')->count();
        $standardVideos = max($totalVideos - $shortVideos - $liveReplayVideos, 0);
        $communityPosts = CommunityPost::query()->count();
        $readyVideos = Video::query()->where('status', 'ready')->count();
        $publicVideos = Video::query()->where('visibility', 'public')->count();
        $processingVideos = Video::query()->where('status', 'processing')->count();
        $scheduledVideos = Video::query()->whereNotNull('scheduled_at')->where('scheduled_at', '>=', now())->count();
        $liveNow = LiveStream::query()->where('status', 'live')->count();
        $scheduledLiveStreams = LiveStream::query()->where('status', 'scheduled')->where('scheduled_at', '>=', now())->count();
        $endedLiveStreams = LiveStream::query()->where('status', 'ended')->count();
        $totalLiveStreams = LiveStream::query()->count();
        $readyLiveStreams = LiveStream::query()->whereNotNull('preflight_tested_at')->where('preflight->ready', true)->count();
        $attentionLiveStreams = LiveStream::query()->whereNotNull('preflight_tested_at')->where(fn ($query) => $query->whereNull('preflight->ready')->orWhere('preflight->ready', false))->count();
        $untestedLiveStreams = LiveStream::query()->whereNull('preflight_tested_at')->count();
        $pendingReports = $this->pendingReportsCount();
        $pendingProfileReports = $this->userReportsEnabled() ? DB::table('user_reports')->distinct('reported_user_id')->count('reported_user_id') : 0;
        $pendingContentReports = max(0, $pendingReports - $pendingProfileReports);
        $pendingUploads = Video::query()->where('approval_status', 'pending')->count();
        $jobsCount = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        $failedUploads = DB::table('upload_sessions')->where('status', 'failed')->count();
        $commentsCount = Comment::query()->count();
        $viewsCount = (int) Video::query()->sum('views_count');
        $likesCount = (int) Video::query()->sum('likes_count');
        $engagementRate = $viewsCount > 0 ? (($likesCount + $commentsCount) / $viewsCount) * 100 : 0;
        $storage = $this->storageUsage();
        $backupSummary = $this->backupSummary();
        $reportRows = $this->adminReportRows(25);
        $userRows = $this->adminUserRows();
        $creatorRows = $this->adminCreatorRows($start, $end);
        $contentRows = $this->adminContentRows();
        $liveRows = $this->adminLiveRows();
        $supportRows = $this->supportTickets($reportRows, $failedJobs, $failedUploads, $processingVideos + $jobsCount);
        $permissionGroups = $this->permissionGroupRows();
        $roleRows = $this->adminRoleRows();
        $teamRows = $this->teamRows();
        $payoutRows = $this->payoutRows($creatorRows);
        $topCreators = array_slice(array_map(fn (array $row): array => [
            'person' => $row['person'],
            'value' => $row['views'],
            'delta' => $row['subscribers'],
        ], $creatorRows), 0, 5);
        $paymentSummary = $this->paymentSummary($start, $end);
        $earningSummary = MonetizationSettings::earningSummary($start, $end);
        $pendingPayoutCents = $this->pendingPayoutCents();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'date_range' => $start->format('M j').' - '.$end->format('M j, Y'),
            'notifications' => $this->adminNotificationPayload($pendingReports, $failedJobs, $failedUploads, $jobsCount + $processingVideos, (int) $request->user()->id),
            'pages' => [
                'dashboard' => [
                    'metrics' => [
                        $this->metric('Total Users', $totalUsers, array_sum($usersSeries), array_sum($previousUsersSeries), 'violet', 'users', $usersSeries),
                        $this->metric('Active Creators', $this->activeCreatorCount($start, $end), $this->activeCreatorCount($start, $end), $this->activeCreatorCount($previousStart, $previousEnd), 'violet', 'creators', $activeSeries),
                        $this->metric('Published Videos', $readyVideos, array_sum($uploadsSeries), array_sum($previousUploadsSeries), 'blue', 'content', $uploadsSeries),
                        $this->metric('Live Now', $liveNow, $liveNow, Video::query()->where('status', 'live')->whereBetween('created_at', [$previousStart, $previousEnd])->count(), 'violet', 'live', $this->dailyCounts('videos', $start, $end, fn ($query) => $query->where('status', 'live'))),
                        $this->metric('Reports Pending', $pendingReports, array_sum($reportsSeries), array_sum($this->reportDailyCounts($previousStart, $previousEnd)), 'red', 'reports', $reportsSeries, $pendingReports > 0),
                    ],
                    'platformGrowthChart' => $this->chart('Platform Growth', 'Users', $this->formatCompact($totalUsers), $this->percentChange(array_sum($usersSeries), array_sum($previousUsersSeries)), $this->yLabels($usersSeries), $labels, [['points' => $usersSeries, 'color' => 'primary', 'area' => true]]),
                    'watchTimeChart' => $this->chart('Watch Time Trend', 'Watch Time (hours)', $this->formatCompact((int) array_sum($watchHoursSeries)), '0%', $this->yLabels($watchHoursSeries), $labels, [['points' => $watchHoursSeries, 'color' => 'primary', 'area' => true]]),
                    'reportRows' => array_slice($reportRows, 0, 6),
                    'topCreators' => $topCreators,
                    'healthItems' => $this->healthItems($storage, $jobsCount + $processingVideos, $failedJobs + $failedUploads),
                ],
                'users' => [
                    'metrics' => [
                        $this->metric('Total Users', $totalUsers, array_sum($usersSeries), array_sum($previousUsersSeries), 'violet', 'users', $usersSeries),
                        $this->metric('Active Users', (int) max($activeSeries ?: [0]), array_sum($activeSeries), array_sum($this->activeUsersSeries($previousStart, $previousEnd)), 'violet', 'creators', $activeSeries),
                        $this->metric('New Signups', (int) array_sum($usersSeries), array_sum($usersSeries), array_sum($previousUsersSeries), 'violet', 'userPlus', $usersSeries),
                        $this->metric('Suspended Users', $this->countUsersBySetting('status', 'suspended'), $this->countUsersBySetting('status', 'suspended'), 0, 'red', 'ban', $this->zeroSeries(), $this->countUsersBySetting('status', 'suspended') > 0),
                        $this->metric('Verification Rate', $this->verifiedRate(), $this->verifiedRate(), $this->verifiedRate(), 'blue', 'shield', $this->zeroSeries()),
                    ],
                    'tabs' => [['All Users', $this->formatCompact($totalUsers)], ['Active', $this->formatCompact(max($totalUsers - $this->countUsersBySetting('status', 'suspended'), 0))], ['Creators', $this->formatCompact($totalChannels)], ['Suspended', $this->formatCompact($this->countUsersBySetting('status', 'suspended'))], ['Verified', $this->formatCompact(User::query()->whereNotNull('email_verified_at')->count())]],
                    'rows' => $userRows,
                    'footer' => 'Showing 1 to '.count($userRows).' of '.$this->formatCompact($totalUsers).' users',
                    'recentSignups' => $this->recentSignupActivity(),
                    'segments' => $this->segmentRows([['Creators', $totalChannels, '#6b2fff'], ['Active Users', max($totalUsers - $this->countUsersBySetting('status', 'suspended'), 0), '#4f8cff'], ['Suspended', $this->countUsersBySetting('status', 'suspended'), '#ef4444'], ['Pending', User::query()->whereNull('email_verified_at')->count(), '#f8b62d']], $totalUsers),
                ],
                'creators' => [
                    'metrics' => [
                        $this->metric('Total Creators', $totalChannels, $totalChannels, 0, 'violet', 'creators', $this->dailyCounts('channels', $start, $end)),
                        $this->metric('Verified Creators', $this->verifiedCreatorCount(), $this->verifiedCreatorCount(), 0, 'violet', 'shield', $this->zeroSeries()),
                        $this->metric('Active Creators', $this->activeCreatorCount($start, $end), $this->activeCreatorCount($start, $end), $this->activeCreatorCount($previousStart, $previousEnd), 'violet', 'userPlus', $activeSeries),
                        $this->metric('New Applications', Channel::query()->whereBetween('created_at', [$start, $end])->count(), Channel::query()->whereBetween('created_at', [$start, $end])->count(), Channel::query()->whereBetween('created_at', [$previousStart, $previousEnd])->count(), 'violet', 'creators', $this->dailyCounts('channels', $start, $end)),
                    ],
                    'rows' => $creatorRows,
                    'tabs' => [['All Creators', $this->formatCompact($totalChannels)], ['Active', $this->formatCompact($this->activeCreatorCount($start, $end))], ['Verified', $this->formatCompact($this->verifiedCreatorCount())], ['Partnered', $this->formatCompact(Channel::query()->whereHas('user', fn ($query) => $query->where('settings->creator_partnered', true))->count())], ['Applied', $this->formatCompact(Channel::query()->whereBetween('created_at', [$start, $end])->count())], ['Suspended', $this->formatCompact(Channel::query()->whereHas('user', fn ($query) => $query->where('settings->status', 'suspended'))->count())]],
                    'avgRevenue' => $this->formatMoney(0),
                    'topRevenueCreators' => $topCreators,
                    'verificationQueue' => $this->verificationQueue(),
                    'segments' => $this->segmentRows([['Emerging', max($totalChannels - $this->activeCreatorCount($start, $end), 0), '#6b2fff'], ['Established', $this->activeCreatorCount($start, $end), '#4f8cff'], ['Partnered', 0, '#ff8a00'], ['Others', 0, '#c8cfda']], max($totalChannels, 1)),
                ],
                'content' => [
                    'metrics' => [
                        $this->metric('Published Content', $readyVideos, array_sum($uploadsSeries), array_sum($previousUploadsSeries), 'violet', 'creators', $uploadsSeries),
                        $this->metric('Pending Review', $pendingUploads, $pendingUploads, 0, 'orange', 'reports', $reportsSeries, $pendingUploads > 0),
                        $this->metric('Scheduled Content', $scheduledVideos, $scheduledVideos, 0, 'violet', 'calendar', $this->zeroSeries()),
                        $this->metric('Flagged Items', $pendingReports, $pendingReports, 0, 'red', 'flag', $reportsSeries, $pendingReports > 0),
                        ['label' => 'Storage Usage', 'value' => $storage['used'], 'detail' => ' / '.$storage['total'], 'change' => $storage['percent'].'% used', 'sub' => 'current disk', 'icon' => 'storage', 'tone' => 'violet', 'progress' => $storage['percent'], 'points' => $this->zeroSeries()],
                    ],
                    'tabs' => [['All Content', $this->formatCompact($totalVideos)], ['Pending Review', $this->formatCompact($pendingUploads)], ['Videos', $this->formatCompact($standardVideos)], ['Shorts', $this->formatCompact($shortVideos)], ['Live Replays', $this->formatCompact($liveReplayVideos)]],
                    'rows' => $contentRows,
                    'footer' => 'Showing 1 to '.count($contentRows).' of '.$this->formatCompact($totalVideos).' content items',
                    'total' => $totalVideos,
                    'from' => count($contentRows) ? 1 : 0,
                    'to' => count($contentRows),
                    'page' => 1,
                    'per_page' => 25,
                    'last_page' => (int) max(1, ceil($totalVideos / 25)),
                    'types' => $this->segmentRows([['Videos', $standardVideos, '#6b2fff'], ['Shorts', $shortVideos, '#4f8cff'], ['Posts', $communityPosts, '#ff8a00'], ['Live Replays', $liveReplayVideos, '#20c56b']], max($totalVideos + $communityPosts, 1)),
                    'recentUploads' => array_slice($contentRows, 0, 5),
                    'massImport' => $this->adminMassImportOptions(),
                    'moderationSummary' => [['Pending Uploads', (string) $pendingUploads, 'warning'], ['Flagged Content', (string) $pendingReports, 'flag'], ['Copyright Claims', '0', 'copyright'], ['Community Reports', (string) $pendingReports, 'shield']],
                ],
                'live' => [
                    'metrics' => [
                        $this->metric('Live Now', $liveNow, $liveNow, 0, 'violet', 'live', $this->dailyCounts('live_streams', $start, $end, fn ($query) => $query->where('status', 'live'))),
                        $this->metric('Scheduled Today', LiveStream::query()->whereDate('scheduled_at', now()->toDateString())->count(), LiveStream::query()->whereDate('scheduled_at', now()->toDateString())->count(), 0, 'violet', 'calendar', $this->zeroSeries()),
                        $this->metric('Ready Pre-Flights', $readyLiveStreams, $readyLiveStreams, 0, 'violet', 'creators', $this->zeroSeries()),
                        $this->metric('Ended Streams', $endedLiveStreams, $endedLiveStreams, 0, 'violet', 'play', $this->zeroSeries()),
                    ],
                    'rows' => $liveRows,
                    'tabs' => [['Live Now', $this->formatCompact($liveNow)], ['Upcoming', $this->formatCompact($scheduledLiveStreams)], ['Ended', $this->formatCompact($endedLiveStreams)]],
                    'categories' => LiveStreamCategory::query()->orderBy('sort_order')->orderBy('name')->pluck('name')->values(),
                    'scheduleRows' => $this->liveScheduleRows(),
                    'healthItems' => $this->segmentRows([['Ready', $readyLiveStreams, '#76c943'], ['Needs Attention', $attentionLiveStreams, '#f8b62d'], ['Not Tested', $untestedLiveStreams, '#c8cfda']], max($totalLiveStreams, 1)),
                    'topCategories' => $this->topLiveCategoryProgress(),
                ],
                'reports' => [
                    'metrics' => [
                        $this->metric('Open Reports', $pendingReports, $pendingReports, 0, 'red', 'reports', $reportsSeries, $pendingReports > 0),
                        $this->metric('Resolved Today', 0, 0, 0, 'blue', 'shield', $this->zeroSeries()),
                        $this->metric('High Priority Cases', collect($reportRows)->where('priority', 'High')->count(), collect($reportRows)->where('priority', 'High')->count(), 0, 'orange', 'target', $reportsSeries, collect($reportRows)->where('priority', 'High')->count() > 0),
                        $this->metric('Avg Resolution Time', 0, 0, 0, 'blue', 'clock', $this->zeroSeries()),
                    ],
                    'tabs' => [['All Reports', $this->formatCompact($pendingReports)], ['Content', $this->formatCompact($pendingContentReports)], ['Users', $this->formatCompact($pendingProfileReports)], ['Live', '0'], ['Copyright', '0'], ['Appeals', '0']],
                    'rows' => $reportRows,
                    'categories' => $this->reportCategoryRows($pendingContentReports, $pendingProfileReports),
                    'moderators' => $this->moderatorsOnline(),
                    'resolutionItems' => [['Open', $pendingReports.' (100%)', '#ff8a00'], ['Resolved', '0 (0%)', '#6b2fff']],
                ],
                'monetization' => [
                    'metrics' => [
                        ['label' => 'Total Revenue', 'value' => $this->formatMoney($paymentSummary['total_cents']), 'change' => '0%', 'sub' => $paymentSummary['count'].' paid transactions', 'icon' => 'monetization', 'tone' => 'violet', 'points' => $paymentSummary['series']],
                        ['label' => 'Creator Earnings', 'value' => MonetizationSettings::formatMoney($earningSummary['total_cents']), 'change' => '0%', 'sub' => $earningSummary['vast_impressions'].' completed VAST ads', 'icon' => 'creators', 'tone' => 'violet', 'points' => $earningSummary['series']],
                        ['label' => 'Platform Revenue Share', 'value' => MonetizationSettings::formatMoney($earningSummary['commission_cents']), 'change' => '0%', 'sub' => 'VAST share and sales fees', 'icon' => 'ad', 'tone' => 'violet', 'points' => $earningSummary['series']],
                        ['label' => 'Membership Revenue', 'value' => $this->formatMoney($paymentSummary['total_cents']), 'change' => '0%', 'sub' => 'Stripe and PayPal plans', 'icon' => 'crown', 'tone' => 'violet', 'points' => $paymentSummary['series']],
                        ['label' => 'Pending Payout Requests', 'value' => MonetizationSettings::formatMoney($pendingPayoutCents), 'change' => '0%', 'sub' => count($payoutRows).' withdrawal requests', 'icon' => 'clock', 'tone' => 'violet', 'danger' => $pendingPayoutCents > 0, 'points' => $this->zeroSeries()],
                    ],
                    'revenueChart' => $this->chart('Revenue Overview', '', $this->formatMoney($paymentSummary['total_cents'] + $earningSummary['vast_gross_cents']), '0%', ['$100', '$80', '$60', '$40', '$20', '$0'], $labels, [['points' => $paymentSummary['series'], 'color' => 'primary'], ['points' => $earningSummary['series'], 'color' => 'blue'], ['points' => $this->zeroSeries(count($labels)), 'color' => 'green'], ['points' => $this->zeroSeries(count($labels)), 'color' => 'orange']]),
                    'revenueSources' => [['Membership', $this->formatMoney($paymentSummary['total_cents']), '#2f7df5'], ['VAST Ad Gross', MonetizationSettings::formatMoney($earningSummary['vast_gross_cents']), '#6b2fff'], ['Creator Earnings', MonetizationSettings::formatMoney($earningSummary['total_cents']), '#22c55e'], ['Other', '$0', '#ff8a00']],
                    'payoutRows' => $payoutRows,
                    'topEarningCreators' => $this->topEarningCreators($topCreators),
                    'transactions' => array_slice(array_merge($earningSummary['transactions'], $paymentSummary['transactions']), 0, 8),
                    'settings' => MonetizationSettings::adminSettings(),
                ],
                'analytics' => [
                    'metrics' => [
                        $this->metric('Total Views', $viewsCount, array_sum($viewsSeries), array_sum($this->dailyVideoViews($previousStart, $previousEnd)), 'violet', 'eye', $viewsSeries),
                        ['label' => 'Watch Time', 'value' => $this->formatCompact((int) array_sum($watchHoursSeries)).' hrs', 'change' => '0%', 'sub' => 'vs previous 28 days', 'icon' => 'clock', 'tone' => 'violet', 'points' => $watchHoursSeries],
                        ['label' => 'Engagement Rate', 'value' => number_format($engagementRate, 2).'%', 'change' => '0%', 'sub' => 'current', 'icon' => 'heart', 'tone' => 'red', 'points' => $this->zeroSeries()],
                        $this->metric('Monthly Active Users', (int) max($activeSeries ?: [0]), array_sum($activeSeries), array_sum($this->activeUsersSeries($previousStart, $previousEnd)), 'violet', 'creators', $activeSeries),
                        ['label' => 'Retention (Day 7)', 'value' => '0%', 'change' => '0%', 'sub' => 'not tracked yet', 'icon' => 'target', 'tone' => 'blue', 'points' => $this->zeroSeries()],
                    ],
                    'trafficChart' => $this->chart('Platform Traffic', 'Sessions', $this->formatCompact(array_sum($activeSeries)), '0%', $this->yLabels($activeSeries), $labels, [['points' => $activeSeries, 'color' => 'primary', 'area' => true]]),
                    'watchChart' => $this->chart('Watch Time Trend', 'Watch Time (hours)', $this->formatCompact((int) array_sum($watchHoursSeries)), '0%', $this->yLabels($watchHoursSeries), $labels, [['points' => $watchHoursSeries, 'color' => 'primary', 'area' => true]]),
                    'trafficSources' => [['Direct', $this->formatCompact($viewsCount).' (100%)', '#3498ff'], ['YouTube', '0 (0%)', '#6b2fff'], ['Google Search', '0 (0%)', '#f8b62d'], ['Facebook', '0 (0%)', '#4f8cff'], ['Instagram', '0 (0%)', '#ff5d8f'], ['Others', '0 (0%)', '#c8cfda']],
                    'devices' => [['Unknown', $this->formatCompact($viewsCount).' (100%)', '#6b2fff'], ['Mobile', '0 (0%)', '#3fa7ff'], ['Desktop', '0 (0%)', '#f8a51a'], ['Tablet', '0 (0%)', '#55a6ff']],
                    'categoryRows' => $this->categoryRows(),
                    'insights' => [['target', 'Analytics are calculated from video, comment, like, and upload activity.'], ['clock', 'Watch time uses views multiplied by video duration.'], ['game', 'Category growth appears after videos have categories.']],
                ],
                'support' => [
                    'metrics' => [
                        $this->metric('Open Tickets', collect($supportRows)->where('sla', 'Open')->count(), collect($supportRows)->where('sla', 'Open')->count(), 0, 'violet', 'support', $this->zeroSeries()),
                        $this->metric('Resolved Today', collect($supportRows)->where('sla', 'Resolved')->count(), collect($supportRows)->where('sla', 'Resolved')->count(), 0, 'violet', 'resolved', $this->zeroSeries()),
                        ['label' => 'Avg First Response Time', 'value' => '0m', 'change' => '0m', 'sub' => 'no ticket SLA table', 'icon' => 'clock', 'tone' => 'violet', 'points' => $this->zeroSeries()],
                        ['label' => 'CSAT Score', 'value' => 'N/A', 'change' => '0', 'sub' => 'not configured', 'icon' => 'target', 'tone' => 'violet', 'points' => $this->zeroSeries()],
                        $this->metric('Escalated Cases', collect($supportRows)->where('priority', 'High')->count(), collect($supportRows)->where('priority', 'High')->count(), 0, 'violet', 'escalation', $this->zeroSeries(), collect($supportRows)->where('priority', 'High')->count() > 0),
                    ],
                    'tabs' => [['Open', (string) collect($supportRows)->where('sla', 'Open')->count()], ['In Progress', (string) collect($supportRows)->where('sla', 'In Progress')->count()], ['Waiting on User', (string) collect($supportRows)->where('sla', 'Waiting on User')->count()], ['Resolved', (string) collect($supportRows)->where('sla', 'Resolved')->count()], ['Escalated', (string) collect($supportRows)->where('priority', 'High')->count()]],
                    'rows' => $supportRows,
                    'agentsOnline' => $this->adminPeople(),
                    'ticketCategories' => $this->ticketCategoryRows($supportRows),
                    'slaItems' => [['On Track', count($supportRows).' (100%)', '#20c56b'], ['At Risk', '0 (0%)', '#ff8a00'], ['Breached', '0 (0%)', '#ef4444']],
                ],
                'roles' => [
                    'metrics' => [
                        $this->metric('Total Admins', count($this->adminPeople()), count($this->adminPeople()), 0, 'violet', 'users', $this->zeroSeries()),
                        $this->metric('Custom Roles', collect($roleRows)->where('custom', true)->count(), collect($roleRows)->where('custom', true)->count(), 0, 'violet', 'creators', $this->zeroSeries()),
                        $this->metric('Pending Invites', collect($teamRows)->where('status', 'Pending')->count(), collect($teamRows)->where('status', 'Pending')->count(), 0, 'violet', 'userPlus', $this->zeroSeries()),
                        $this->metric('Permission Groups', count($permissionGroups), count($permissionGroups), 0, 'violet', 'settings', $this->zeroSeries()),
                        $this->metric('Security Events', 0, 0, 0, 'violet', 'shield', $this->zeroSeries()),
                    ],
                    'roleRows' => $roleRows,
                    'permissionGroups' => $permissionGroups,
                    'permissionCatalog' => $this->permissionCatalog(),
                    'permissionMatrix' => $this->permissionMatrixRows($roleRows),
                    'teamRows' => $teamRows,
                    'recentActivity' => $this->roleActivity(),
                    'auditRows' => [['Permissions changed (7d)', '0'], ['Roles updated (7d)', '0'], ['Users added (7d)', (string) array_sum($usersSeries)], ['Users removed (7d)', '0'], ['Failed access attempts (7d)', '0']],
                    'sensitiveRows' => [['Role changes', '0'], ['Permission changes', '0'], ['User deactivations', '0'], ['Exported data', '0'], ['Security policy changes', '0']],
                ],
                'website' => [
                    'general' => GeneralSettings::adminSettings(),
                ],
                'seo' => $this->isAdmin($request->user()) ? $this->seoPageData($request) : null,
                'settings' => [
                    'metrics' => [
                        ['label' => 'API Uptime', 'value' => 'Online', 'change' => '0%', 'sub' => 'current', 'icon' => 'target', 'tone' => 'violet', 'points' => $this->zeroSeries()],
                        ['label' => 'Storage Used', 'value' => $storage['used'], 'detail' => ' / '.$storage['total'], 'change' => $storage['percent'].'% used', 'icon' => 'storage', 'tone' => 'violet', 'progress' => $storage['percent'], 'points' => $this->zeroSeries()],
                        $this->metric('Queue Jobs', $jobsCount + $processingVideos, $jobsCount + $processingVideos, 0, 'violet', 'users', $this->zeroSeries(), $jobsCount + $processingVideos > 0),
                        ['label' => 'Backups', 'value' => $backupSummary['metric'], 'sub' => $backupSummary['sub'], 'icon' => 'calendar', 'tone' => 'violet', 'points' => []],
                        ['label' => 'Current Version', 'value' => $this->appVersion(), 'change' => 'Current API version', 'icon' => 'target', 'tone' => 'violet', 'points' => []],
                    ],
                    'general' => GeneralSettings::adminSettings(),
                    'security' => ['websiteProtection' => 'Use the Login section for account access controls.'],
                    'authSettings' => AuthSettings::adminSettings(),
                    'features' => PlatformFeatureSettings::adminSettings(),
                    'liveStreaming' => LiveStreamingSettings::adminSettings(),
                    'notifications' => data_get(PlatformFeatureSettings::adminSettings(), 'notifications', []),
                    'moderation' => data_get(PlatformFeatureSettings::adminSettings(), 'moderation', []),
                    'maintenance' => data_get(PlatformFeatureSettings::adminSettings(), 'maintenance', []),
                    'storage' => [
                        'provider' => MediaStorage::settings()['mediaStorage'] ?? 'default',
                        'cdn' => MediaStorage::settings()[MediaStorage::settings()['mediaStorage'] ?? 'default']['publicBaseUrl'] ?? 'Local',
                        'ttl' => '3600',
                        'capacity' => MediaStorage::capacitySummary(),
                        'config' => MediaStorage::adminSettings(),
                    ],
                    'videoImport' => VideoImportSettings::adminSettings(),
                    'playerAds' => PlayerAdSettings::adminSettings(),
                    'integrations' => IntegrationSettings::integrationRows(),
                    'integrationSettings' => IntegrationSettings::adminSettings(),
                    'email' => ['provider' => IntegrationSettings::smtpEnabled() ? 'smtp' : config('mail.default', 'log'), 'fromEmail' => data_get(IntegrationSettings::settings(), 'smtp.fromEmail', config('mail.from.address', 'noreply@playhub.app'))],
                    'serverRows' => $this->serverStatusRows($failedJobs + $failedUploads),
                    'recentChanges' => $this->settingsActivity(),
                    'backupRows' => $backupSummary['rows'],
                ],
            ],
        ];

        if (! $this->isAdmin($request->user())) {
            $payload['pages'] = array_intersect_key($payload['pages'], array_flip(['dashboard', 'users', 'content', 'reports']));
        }

        if (app()->bound(PluginHooks::class)) {
            $filtered = app(PluginHooks::class)->filter('playhub.admin.page_data', $payload, $request->user());
            if (is_array($filtered)) {
                $payload = $filtered;
            }
        }

        if (! $this->isAdmin($request->user()) && is_array($payload['pages'] ?? null)) {
            $payload['pages'] = array_intersect_key($payload['pages'], array_flip(['dashboard', 'users', 'content', 'reports']));
        }

        return response()->json($payload);
    }

    public function updateStorageSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $settings = $this->validatedStorageSettings($request);

        MediaStorage::save($settings);
        $this->recordAdminActivity($request->user(), 'Updated storage settings');

        return response()->json([
            'message' => 'Storage settings saved.',
            'storage' => MediaStorage::adminSettings(),
        ])->header('Cache-Control', 'no-store');
    }

    public function updateGeneralSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        if ($request->boolean('resetDefaults')) {
            GeneralSettings::save(GeneralSettings::defaultSettings());
            GeneralSettings::apply();
            $this->recordAdminActivity($request->user(), 'Reset general settings');

            return response()->json([
                'message' => 'General settings reset.',
                'general' => GeneralSettings::adminSettings(),
            ]);
        }

        $data = $request->validate([
            'platformName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'websiteTitle' => ['sometimes', 'nullable', 'string', 'max:160'],
            'logoText' => ['sometimes', 'nullable', 'string', 'max:80'],
            'showLogoText' => ['sometimes', 'boolean'],
            'logoSize' => ['sometimes', 'integer', 'min:16', 'max:64'],
            'logoUrl' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'faviconUrl' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'authPreview' => ['sometimes', 'array'],
            'authPreview.title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'authPreview.description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'authPreview.feature1' => ['sometimes', 'nullable', 'string', 'max:160'],
            'authPreview.feature2' => ['sometimes', 'nullable', 'string', 'max:160'],
            'authPreview.feature3' => ['sometimes', 'nullable', 'string', 'max:160'],
            'authPreview.feature4' => ['sometimes', 'nullable', 'string', 'max:160'],
            'defaultPrimaryColor' => ['sometimes', 'nullable', 'string', 'max:80'],
            'defaultSecondaryColor' => ['sometimes', 'nullable', 'string', 'max:80'],
            'defaultPrimarySoftColor' => ['sometimes', 'nullable', 'string', 'max:120'],
            'defaultThemeMode' => ['sometimes', 'nullable', 'string', Rule::in(['light', 'dark', 'system'])],
            'loadingDisplay' => ['sometimes', 'required', 'string', Rule::in(['bar', 'instant'])],
            'fontFamily' => ['sometimes', 'nullable', 'string', 'max:500'],
            'fontImportUrl' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'showSidebarToggle' => ['sometimes', 'boolean'],
            'design' => ['sometimes', 'array'],
            'design.colors' => ['sometimes', 'array'],
            'design.colors.*' => ['nullable', 'string', 'max:120'],
            'design.darkColors' => ['sometimes', 'array'],
            'design.darkColors.*' => ['nullable', 'string', 'max:120'],
            'design.adminColors' => ['sometimes', 'array'],
            'design.adminColors.*' => ['nullable', 'string', 'max:120'],
            'design.adminDarkColors' => ['sometimes', 'array'],
            'design.adminDarkColors.*' => ['nullable', 'string', 'max:120'],
            'design.fontSizes' => ['sometimes', 'array'],
            'design.fontSizes.*' => ['nullable', 'string', 'max:40'],
            'design.fontWeights' => ['sometimes', 'array'],
            'design.fontWeights.*' => ['nullable', 'string', 'max:20'],
            'design.radiusScaleVersion' => ['sometimes', 'integer', 'min:2', 'max:3'],
            'design.radii' => ['sometimes', 'array'],
            'design.radii.*' => ['nullable', 'string', 'max:40'],
            'design.layout' => ['sometimes', 'array'],
            'design.layout.*' => ['nullable', 'string', 'max:40'],
            'timezone' => ['sometimes', 'required', 'string', Rule::in(GeneralSettings::timezoneIdentifiers())],
            'itemsPerPage' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        GeneralSettings::save(array_replace_recursive(GeneralSettings::settings(), $data));
        GeneralSettings::apply();
        $this->recordAdminActivity($request->user(), 'Updated general settings');

        return response()->json([
            'message' => 'General settings saved.',
            'general' => GeneralSettings::adminSettings(),
        ]);
    }

    public function updateSeoSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        if ($request->boolean('resetDefaults')) {
            SeoSettings::save(SeoSettings::defaultSettings());
            $this->recordAdminActivity($request->user(), 'Reset SEO and sitemap settings');

            return response()->json([
                'message' => 'SEO and sitemap settings reset.',
                'seo' => $this->seoPageData($request),
            ])->header('Cache-Control', 'no-store');
        }

        $httpUrl = static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            $parts = is_string($value) ? parse_url($value) : false;
            if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                $fail('The '.$attribute.' must be a valid HTTP or HTTPS URL.');
            }
        };
        $canonicalUrl = static function (string $attribute, mixed $value, \Closure $fail) use ($httpUrl): void {
            $httpUrl($attribute, $value, $fail);
            $parts = is_string($value) && $value !== '' ? parse_url($value) : [];
            if (is_array($parts) && (isset($parts['query']) || isset($parts['fragment']))) {
                $fail('The '.$attribute.' cannot contain a query string or fragment.');
            }
        };
        $imageUrl = static function (string $attribute, mixed $value, \Closure $fail) use ($httpUrl): void {
            if ($value === null || $value === '') {
                return;
            }
            if (is_string($value) && str_starts_with($value, '/') && ! str_starts_with($value, '//') && ! preg_match('/[\r\n]/', $value)) {
                return;
            }
            $httpUrl($attribute, $value, $fail);
        };
        $rules = [
            'siteTitle' => ['sometimes', 'nullable', 'string', 'max:160'],
            'siteDescription' => ['sometimes', 'nullable', 'string', 'max:320'],
            'keywords' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'titleSeparator' => ['sometimes', 'required', 'string', 'max:5', 'not_regex:/[<>]/'],
            'appendSiteName' => ['sometimes', 'boolean'],
            'canonicalBaseUrl' => ['sometimes', 'nullable', 'string', 'max:2048', $canonicalUrl],
            'defaultImageUrl' => ['sometimes', 'nullable', 'string', 'max:2048', $imageUrl],
            'twitterSite' => ['sometimes', 'nullable', 'string', 'regex:/^@?[A-Za-z0-9_]{1,15}$/'],
            'googleSiteVerification' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/[<>"\r\n]/'],
            'bingSiteVerification' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/[<>"\r\n]/'],
            'customMetaTags' => ['sometimes', 'array', 'max:50'],
            'customMetaTags.*' => ['required', 'array:attribute,key,content'],
            'customMetaTags.*.attribute' => ['required', Rule::in(SeoSettings::META_ATTRIBUTES)],
            'customMetaTags.*.key' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z][A-Za-z0-9._:-]*\z/D'],
            'customMetaTags.*.content' => ['required', 'string', 'max:2048'],
            'indexing' => ['sometimes', 'array:enabled,include'],
            'indexing.enabled' => ['sometimes', 'boolean'],
            'indexing.include' => ['sometimes', 'array:'.implode(',', SeoSettings::SECTIONS)],
            'sitemap' => ['sometimes', 'array:enabled,urlsPerFile,include,changeFrequency,priority'],
            'sitemap.enabled' => ['sometimes', 'boolean'],
            'sitemap.urlsPerFile' => ['sometimes', 'integer', 'min:100', 'max:50000'],
            'sitemap.include' => ['sometimes', 'array:'.implode(',', SeoSettings::SECTIONS)],
            'sitemap.changeFrequency' => ['sometimes', 'array:'.implode(',', SeoSettings::SECTIONS)],
            'sitemap.priority' => ['sometimes', 'array:'.implode(',', SeoSettings::SECTIONS)],
            'robots' => ['sometimes', 'array:customDisallow'],
            'robots.customDisallow' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
        foreach (SeoSettings::SECTIONS as $section) {
            $rules['indexing.include.'.$section] = ['sometimes', 'boolean'];
            $rules['sitemap.include.'.$section] = ['sometimes', 'boolean'];
            $rules['sitemap.changeFrequency.'.$section] = ['sometimes', Rule::in(SeoSettings::CHANGE_FREQUENCIES)];
            $rules['sitemap.priority.'.$section] = ['sometimes', 'numeric', 'min:0', 'max:1'];
        }
        $data = $request->validate($rules);

        $metaTagKeys = [];
        foreach ($data['customMetaTags'] ?? [] as $index => $tag) {
            $identity = $tag['attribute']."\0".strtolower($tag['key']);
            if (SeoSettings::isManagedMetaTag($tag['attribute'], $tag['key'])) {
                throw ValidationException::withMessages([
                    'customMetaTags.'.$index.'.key' => ['This tag is managed by another SEO setting above.'],
                ]);
            }
            if (isset($metaTagKeys[$identity])) {
                throw ValidationException::withMessages([
                    'customMetaTags.'.$index.'.key' => ['Each meta tag attribute and key combination must be unique.'],
                ]);
            }
            $metaTagKeys[$identity] = true;
        }

        foreach (preg_split('/\R/u', (string) data_get($data, 'robots.customDisallow', '')) ?: [] as $path) {
            $path = trim($path);
            if ($path !== '' && (! str_starts_with($path, '/') || preg_match('/[\s#]/u', $path))) {
                throw ValidationException::withMessages(['robots.customDisallow' => ['Enter one absolute path per line without spaces or comments.']]);
            }
        }

        $settings = array_replace_recursive(SeoSettings::settings(), $data);
        if (array_key_exists('customMetaTags', $data)) {
            $settings['customMetaTags'] = $data['customMetaTags'];
        }
        SeoSettings::save($settings);
        $this->recordAdminActivity($request->user(), 'Updated SEO and sitemap settings');

        return response()->json([
            'message' => 'SEO and sitemap settings saved.',
            'seo' => $this->seoPageData($request),
        ])->header('Cache-Control', 'no-store');
    }

    public function uploadWebsiteAsset(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'asset' => ['required', Rule::in(['logo', 'favicon'])],
            'file' => ['required', 'file', 'max:2048'],
        ]);

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: ($data['asset'] === 'favicon' ? 'ico' : 'svg'));
        $allowedExtensions = $data['asset'] === 'logo' ? ['svg'] : ['ico', 'png', 'jpg', 'jpeg', 'webp', 'svg'];
        if (! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => [$data['asset'] === 'logo' ? 'The logo must be an SVG file.' : 'The file type is not supported.'],
            ]);
        }
        if ($data['asset'] === 'logo') {
            $svg = (string) file_get_contents($file->getRealPath());
            if (! Str::contains(Str::lower($svg), '<svg') || preg_match('/<script\b|on[a-z]+\s*=/i', $svg)) {
                throw ValidationException::withMessages([
                    'file' => ['The logo must be a safe SVG file.'],
                ]);
            }
        }

        $directory = MediaStorage::ensureLocalDirectory('website');

        $filename = $data['asset'].'-'.now()->format('YmdHis').'-'.Str::random(8).'.'.$extension;
        $file->move($directory, $filename);
        @chmod($directory.'/'.$filename, 0644);

        $key = $data['asset'] === 'favicon' ? 'faviconUrl' : 'logoUrl';
        $url = '/uploads/website/'.$filename;
        GeneralSettings::save(array_replace_recursive(GeneralSettings::settings(), [$key => $url]));
        $this->recordAdminActivity($request->user(), 'Uploaded the website '.$data['asset']);

        return response()->json([
            'message' => ucfirst($data['asset']).' uploaded.',
            'url' => $url,
            'general' => GeneralSettings::adminSettings(),
        ]);
    }

    public function testStorageSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $settings = $this->validatedStorageSettings($request);

        try {
            $result = MediaStorage::test($settings);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Storage connection failed. Check the endpoint, credentials, bucket or container, and read/write/delete permissions.',
            ], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'message' => 'Storage connection successful.',
            'result' => $result,
        ])->header('Cache-Control', 'no-store');
    }

    public function updateVideoImportSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'downloaderCommand' => ['nullable', 'string', 'max:255'],
            'providers' => ['nullable', 'array'],
            'providers.*.enabled' => ['nullable', 'boolean'],
            'providers.*.apiKey' => ['nullable', 'string', 'max:1000'],
        ]);
        $knownProviders = array_keys(VideoImportSettings::providers());
        foreach (array_keys($data['providers'] ?? []) as $provider) {
            abort_unless(in_array($provider, $knownProviders, true), 422, 'Unknown video import provider.');
        }

        $current = VideoImportSettings::settings();
        $settings = array_replace_recursive($current, $data);
        foreach ($knownProviders as $provider) {
            if (($request->input("providers.{$provider}.apiKey") ?? '') === '') {
                data_set($settings, "providers.{$provider}.apiKey", data_get($current, "providers.{$provider}.apiKey"));
            }
        }

        VideoImportSettings::save($settings);
        $this->recordAdminActivity($request->user(), 'Updated video import settings');

        return response()->json([
            'message' => 'Video import settings saved.',
            'videoImport' => VideoImportSettings::adminSettings(),
        ]);
    }

    public function updatePlayerAdSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'loadTimeoutSeconds' => ['nullable', 'integer', 'min:3', 'max:30'],
            'ads' => ['nullable', 'array'],
            'ads.*.id' => ['nullable', 'string', 'max:80'],
            'ads.*.enabled' => ['nullable', 'boolean'],
            'ads.*.name' => ['nullable', 'string', 'max:120'],
            'ads.*.engine' => ['nullable', 'string', Rule::in(['generic_vast', 'google_ima'])],
            'ads.*.placement' => ['nullable', 'string', Rule::in(['preroll', 'midroll', 'postroll'])],
            'ads.*.vastUrl' => ['nullable', 'string', 'max:4000'],
            'ads.*.skipAfter' => ['nullable', 'integer', 'min:0', 'max:60'],
            'ads.*.offsetSeconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'ads.*.grossCpm' => ['nullable', 'numeric', 'min:0', 'max:250'],
        ]);

        PlayerAdSettings::save(array_replace(PlayerAdSettings::settings(), $data));
        $this->recordAdminActivity($request->user(), 'Updated player ad settings');

        return response()->json([
            'message' => 'Player ad settings saved.',
            'playerAds' => PlayerAdSettings::adminSettings(),
        ]);
    }

    public function updateMonetizationSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'vast_ads' => ['sometimes', 'array'],
            'vast_ads.creator_share_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'paid_videos' => ['sometimes', 'array'],
            'paid_videos.enabled' => ['sometimes', 'boolean'],
            'paid_videos.commission_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'paid_videos.minimum_price' => ['sometimes', 'numeric', 'min:0.01', 'max:1000000'],
            'paid_videos.maximum_price' => ['sometimes', 'numeric', 'min:0.01', 'max:1000000', 'gte:paid_videos.minimum_price'],
            'paid_videos.sales_tax' => ['sometimes', 'array'],
            'paid_videos.sales_tax.enabled' => ['sometimes', 'boolean'],
            'paid_videos.sales_tax.type' => ['sometimes', 'string', Rule::in(['vat', 'gst', 'sales_tax'])],
            'paid_videos.sales_tax.rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'paid_videos.sales_tax.registration_number' => ['nullable', 'string', 'max:100'],
            'video_boosts' => ['sometimes', 'array'],
            'video_boosts.enabled' => ['sometimes', 'boolean'],
            'video_boosts.minimum_bid' => ['sometimes', 'numeric', 'min:0.01', 'max:1000000'],
            'video_boosts.maximum_bid' => ['sometimes', 'numeric', 'min:0.01', 'max:1000000', 'gte:video_boosts.minimum_bid'],
            'video_boosts.minimum_budget' => ['sometimes', 'numeric', 'min:0.01', 'max:1000000'],
            'video_boosts.maximum_budget' => ['sometimes', 'numeric', 'min:0.01', 'max:1000000', 'gte:video_boosts.minimum_budget'],
            'video_boosts.max_active_campaigns' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        abort_if(
            $request->boolean('paid_videos.sales_tax.enabled')
                && (float) $request->input('paid_videos.sales_tax.rate_percent', 0) <= 0,
            422,
            'Set a positive sales tax rate before enabling sales tax.'
        );

        MonetizationSettings::save($data);
        $this->recordAdminActivity($request->user(), 'Updated monetization settings');

        return response()->json([
            'message' => 'Monetization settings saved.',
            'settings' => MonetizationSettings::adminSettings(),
        ]);
    }

    public function decideWithdrawal(Request $request, int $withdrawal): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(Schema::hasTable('wallet_transactions'), 422, 'Wallet storage is not ready.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $reviewer = $request->user();
        $status = $data['decision'] === 'approved' ? 'completed' : 'rejected';

        $transaction = DB::transaction(function () use ($withdrawal, $reviewer, $data, $status) {
            $transaction = DB::table('wallet_transactions')
                ->where('id', $withdrawal)
                ->where('type', 'withdraw')
                ->lockForUpdate()
                ->first();
            abort_unless($transaction, 404, 'Withdrawal request not found.');
            abort_unless($transaction->status === 'pending', 409, 'Withdrawal request has already been processed.');

            $payload = json_decode((string) $transaction->payload, true) ?: [];
            $payload['decision'] = $data['decision'];
            $payload['reviewed_by'] = (int) $reviewer->id;
            $payload['reviewer_name'] = (string) $reviewer->name;
            $payload['decision_note'] = trim((string) ($data['note'] ?? '')) ?: null;
            $payload['reviewed_at'] = now()->toIso8601String();

            DB::table('wallet_transactions')->where('id', $transaction->id)->update([
                'status' => $status,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            $amount = IntegrationSettings::formatMoney((int) $transaction->amount_cents, (string) ($transaction->currency ?: IntegrationSettings::currency()));
            UserNotification::query()->create([
                'user_id' => $transaction->user_id,
                'actor_id' => $reviewer->id,
                'type' => $data['decision'] === 'approved' ? 'withdrawal_approved' : 'withdrawal_rejected',
                'data' => [
                    'to' => '/wallet',
                    'withdrawal_id' => (int) $transaction->id,
                    'detail' => $data['decision'] === 'approved'
                        ? $amount.' withdrawal approved.'
                        : $amount.' withdrawal rejected. The reserved funds are available in your wallet again.',
                    'note' => $payload['decision_note'],
                ],
            ]);

            return DB::table('wallet_transactions')->where('id', $transaction->id)->first();
        });

        $this->recordAdminActivity($reviewer, ucfirst($data['decision']).' withdrawal #'.$transaction->id);

        return response()->json([
            'message' => $data['decision'] === 'approved' ? 'Withdrawal approved.' : 'Withdrawal rejected and reserved funds released.',
            'payout' => $this->payoutRow($transaction),
            'pending_payout_cents' => $this->pendingPayoutCents(),
        ]);
    }

    public function updateIntegrationSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $current = IntegrationSettings::settings();
        $settings = array_replace_recursive($current, $this->validatedIntegrationSettings($request));
        foreach (['stripe.secretKey', 'paypal.clientSecret', 'sendgrid.apiKey', 'smtp.password', 'smsOtp.authToken', 'smsOtp.vonageApiSecret', 'smsOtp.messagebirdAccessKey'] as $key) {
            if (($request->input($key) ?? '') === '') {
                data_set($settings, $key, data_get($current, $key));
            }
        }

        IntegrationSettings::save($settings);
        $this->recordAdminActivity($request->user(), 'Updated integration settings');

        return response()->json([
            'message' => 'Integration settings saved.',
            'integrations' => IntegrationSettings::integrationRows(),
            'integrationSettings' => IntegrationSettings::adminSettings(),
        ]);
    }

    public function updateAuthSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $current = AuthSettings::settings();
        $settings = array_replace_recursive($current, $this->validatedAuthSettings($request));
        foreach (CaptchaSettings::providerIds() as $provider) {
            if (($request->input('captcha.providers.'.$provider.'.secretKey') ?? '') === '') {
                data_set($settings, 'captcha.providers.'.$provider.'.secretKey', data_get($current, 'captcha.providers.'.$provider.'.secretKey', ''));
            }
        }
        if ($request->has('captcha.questionsText')) {
            $settings['captcha']['questions'] = CaptchaSettings::parseQuestions((string) $request->input('captcha.questionsText'));
        }
        unset($settings['captcha']['questionsText']);
        if (($request->input('registration.googleCaptchaSecretKey') ?? '') === '') {
            data_set($settings, 'registration.googleCaptchaSecretKey', data_get($current, 'registration.googleCaptchaSecretKey'));
        }
        if (($request->input('social.google.clientSecret') ?? '') === '') {
            data_set($settings, 'social.google.clientSecret', data_get($current, 'social.google.clientSecret'));
        }
        if (($request->input('social.facebook.clientSecret') ?? '') === '') {
            data_set($settings, 'social.facebook.clientSecret', data_get($current, 'social.facebook.clientSecret'));
        }
        if (($request->input('social.apple.privateKey') ?? '') === '') {
            data_set($settings, 'social.apple.privateKey', data_get($current, 'social.apple.privateKey'));
        }
        abort_unless(
            data_get($settings, 'login.email') || data_get($settings, 'login.username') || data_get($settings, 'login.phone'),
            422,
            'At least one login method must be enabled.'
        );

        AuthSettings::save($settings);
        $this->recordAdminActivity($request->user(), 'Updated authentication settings');

        return response()->json([
            'message' => 'Authentication settings saved.',
            'authSettings' => AuthSettings::adminSettings(),
        ]);
    }

    public function updateFeatureSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'liveStreaming' => ['nullable', 'array'],
            'liveStreaming.enabled' => ['nullable', 'boolean'],
            'notifications' => ['nullable', 'array'],
            'notifications.newUsers' => ['nullable', 'boolean'],
            'notifications.weeklyDigest' => ['nullable', 'boolean'],
            'notifications.marketingEmails' => ['nullable', 'boolean'],
            'moderation' => ['nullable', 'array'],
            'moderation.autoApproveUsers' => ['nullable', 'string', Rule::in(['manual', 'email_verified', 'all'])],
            'moderation.defaultVisibility' => ['nullable', 'string', Rule::in(['public', 'unlisted', 'private'])],
            'moderation.uploadApproval' => ['nullable', 'string', Rule::in(['automatic', 'manual'])],
            'moderation.profanityFilter' => ['nullable', 'boolean'],
            'uploads' => ['nullable', 'array'],
            'uploads.renditions' => ['required_with:uploads', 'array', 'min:1'],
            'uploads.renditions.*' => ['string', 'distinct', Rule::in(['original', 'high', 'medium', 'low'])],
            'uploads.defaultQuality' => ['nullable', 'string', Rule::in(['auto', 'original', 'high', 'medium', 'low'])],
            'uploads.chaptersEnabled' => ['nullable', 'boolean'],
            'publishing' => ['nullable', 'array'],
            'publishing.uploadRoles' => ['nullable', 'array', 'max:4'],
            'publishing.uploadRoles.*' => ['string', 'distinct', Rule::in(['user', 'creator', 'moderator', 'admin'])],
            'publishing.liveRoles' => ['nullable', 'array', 'max:4'],
            'publishing.liveRoles.*' => ['string', 'distinct', Rule::in(['user', 'creator', 'moderator', 'admin'])],
            'publishing.allowedUsers' => ['nullable', 'string', 'max:4000'],
            'maintenance' => ['nullable', 'array'],
            'maintenance.enabled' => ['nullable', 'boolean'],
            'maintenance.message' => ['nullable', 'string', 'max:500'],
            'maintenance.allowedIps' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($data['uploads'])) {
            $defaultQuality = (string) ($data['uploads']['defaultQuality'] ?? 'auto');
            if ($defaultQuality !== 'auto' && ! in_array($defaultQuality, $data['uploads']['renditions'] ?? [], true)) {
                throw ValidationException::withMessages([
                    'uploads.defaultQuality' => 'The default quality must be enabled as an upload rendition.',
                ]);
            }
        }

        $settings = array_replace_recursive(PlatformFeatureSettings::settings(), $data);
        if (isset($data['uploads']['renditions'])) {
            $settings['uploads']['renditions'] = array_values($data['uploads']['renditions']);
        }
        foreach (['uploadRoles', 'liveRoles'] as $roleSetting) {
            if (isset($data['publishing'][$roleSetting])) {
                $settings['publishing'][$roleSetting] = array_values($data['publishing'][$roleSetting]);
            }
        }
        PlatformFeatureSettings::save($settings);
        $this->recordAdminActivity($request->user(), 'Updated platform feature settings');

        return response()->json([
            'message' => 'Feature settings saved.',
            'features' => PlatformFeatureSettings::adminSettings(),
        ]);
    }

    public function updateLiveStreamingSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $featureRules = [];
        foreach (array_keys(LiveStreamingSettings::settings()['features']) as $feature) {
            $featureRules['features.'.$feature] = ['nullable', 'boolean'];
        }
        $data = $request->validate(array_merge([
            'enabled' => ['nullable', 'boolean'],
            'engine' => ['nullable', 'array'],
            'engine.enabled' => ['nullable', 'boolean'],
            'engine.provider' => ['nullable', Rule::in(['none', 'ovenmediaengine', 'livekit'])],
            'livekit' => ['nullable', 'array'],
            'livekit.enabled' => ['nullable', 'boolean'],
            'livekit.url' => ['nullable', 'string', 'max:500', 'regex:/^wss?:\/\/[^\s]+$/i'],
            'livekit.api_key' => ['nullable', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'livekit.api_secret' => ['nullable', 'string', 'min:32', 'max:256', 'regex:/^[A-Za-z0-9_-]+$/'],
            'livekit.ingress_image' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/:@-]*$/'],
            'livekit.ingress_container_name' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'livekit.egress_image' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/:@-]*$/'],
            'livekit.egress_container_name' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'livekit.rtmp_url' => ['nullable', 'string', 'max:500', 'regex:/^rtmps?:\/\/[^\s]+$/i'],
            'livekit.rtmp_port' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'livekit.docker_image' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/:@-]*$/'],
            'livekit.container_name' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'livekit.docker_project_name' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'livekit.docker_compose_mode' => ['nullable', Rule::in(['generated', 'custom'])],
            'livekit.livekit_config_mode' => ['nullable', Rule::in(['generated', 'custom'])],
            'livekit.docker_compose_path' => ['nullable', 'string', 'max:255'],
            'livekit.livekit_config_path' => ['nullable', 'string', 'max:255'],
            'livekit.ingress_config_path' => ['nullable', 'string', 'max:255'],
            'livekit.egress_config_path' => ['nullable', 'string', 'max:255'],
            'livekit.keys_path' => ['nullable', 'string', 'max:255'],
            'livekit.restart_trigger_path' => ['nullable', 'string', 'max:255'],
            'livekit.docker_apply_trigger_path' => ['nullable', 'string', 'max:255'],
            'livekit.docker_command_path' => ['nullable', 'string', 'max:255'],
            'livekit.entrypoint_path' => ['nullable', 'string', 'max:255'],
            'livekit.status_path' => ['nullable', 'string', 'max:255'],
            'livekit.log_path' => ['nullable', 'string', 'max:255'],
            'livekit.container_livekit_config_path' => ['nullable', 'string', 'max:255'],
            'livekit.container_ingress_config_path' => ['nullable', 'string', 'max:255'],
            'livekit.container_egress_config_path' => ['nullable', 'string', 'max:255'],
            'livekit.container_keys_path' => ['nullable', 'string', 'max:255'],
            'livekit.container_restart_trigger_path' => ['nullable', 'string', 'max:255'],
            'livekit.container_entrypoint_path' => ['nullable', 'string', 'max:255'],
            'livekit.container_status_path' => ['nullable', 'string', 'max:255'],
            'livekit.container_log_path' => ['nullable', 'string', 'max:255'],
            'livekit.container_certificate_directory' => ['nullable', 'string', 'max:255'],
            'livekit.docker_compose_yaml' => ['nullable', 'string', 'max:200000'],
            'livekit.livekit_config_yaml' => ['nullable', 'string', 'max:200000'],
            'livekit.entrypoint_script' => ['nullable', 'string', 'max:100000'],
            'livekit.signal_port' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'livekit.rtc_tcp_port' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'livekit.rtc_udp_port_start' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'livekit.rtc_udp_port_end' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'livekit.use_external_ip' => ['nullable', 'boolean'],
            'livekit.node_ip' => ['nullable', 'ipv4'],
            'livekit.region' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]*$/'],
            'livekit.log_level' => ['nullable', Rule::in(['debug', 'info', 'warn', 'error'])],
            'livekit.room_empty_timeout' => ['nullable', 'integer', 'min:10', 'max:86400'],
            'livekit.room_departure_timeout' => ['nullable', 'integer', 'min:5', 'max:3600'],
            'livekit.max_participants' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'livekit.certificate_directory' => ['nullable', 'string', 'max:255'],
            'livekit.ssl_enabled' => ['nullable', 'boolean'],
            'livekit.ssl_domain' => ['nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.-]*$/'],
            'livekit.ssl_cert_path' => ['nullable', 'string', 'max:255'],
            'livekit.ssl_key_path' => ['nullable', 'string', 'max:255'],
            'livekit.turn_udp_port' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'livekit.turn_tls_enabled' => ['nullable', 'boolean'],
            'livekit.turn_tls_port' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'livekit.turn_tls_domain' => ['nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.-]*$/'],
            'livekit.turn_tls_cert_path' => ['nullable', 'string', 'max:255'],
            'livekit.turn_tls_key_path' => ['nullable', 'string', 'max:255'],
            'livekit.ice_server_config_json' => ['nullable', 'string', 'max:120000'],
            'livekit.advanced_config_json' => ['nullable', 'string', 'max:120000'],
            'features' => ['nullable', 'array'],
            'options' => ['nullable', 'array'],
            'options.resolutions' => ['nullable', 'array', 'min:1'],
            'options.resolutions.*' => [Rule::in(['auto', '480p', '720p', '1080p', '1440p', '2160p'])],
            'options.latency_modes' => ['nullable', 'array', 'min:1'],
            'options.latency_modes.*' => [Rule::in(['normal', 'low', 'ultra_low'])],
            'options.replay_retention' => ['nullable', 'array', 'min:1'],
            'options.replay_retention.*' => [Rule::in(['off', '24_hours', '7_days', '30_days', '90_days', 'forever'])],
            'options.audience_modes' => ['nullable', 'array', 'min:1'],
            'options.audience_modes.*' => [Rule::in(['everyone', 'signed_in', 'subscribers', 'invited', 'private'])],
            'options.moderation_levels' => ['nullable', 'array', 'min:1'],
            'options.moderation_levels.*' => [Rule::in(['off', 'basic', 'standard', 'strict', 'custom'])],
            'options.slow_mode_intervals' => ['nullable', 'array', 'min:1'],
            'options.slow_mode_intervals.*' => [Rule::in([5, 10, 30, 60, 120])],
            'options.frame_rates' => ['nullable', 'array', 'min:1'],
            'options.frame_rates.*' => [Rule::in([30, 60])],
            'defaults' => ['nullable', 'array'],
            'defaults.resolution' => ['nullable', Rule::in(['auto', '480p', '720p', '1080p', '1440p', '2160p'])],
            'defaults.latency_mode' => ['nullable', Rule::in(['normal', 'low', 'ultra_low'])],
            'defaults.replay_retention' => ['nullable', Rule::in(['off', '24_hours', '7_days', '30_days', '90_days', 'forever'])],
            'defaults.audience_mode' => ['nullable', Rule::in(['everyone', 'signed_in', 'subscribers', 'invited', 'private'])],
            'defaults.moderation_level' => ['nullable', Rule::in(['off', 'basic', 'standard', 'strict', 'custom'])],
            'defaults.slow_mode_interval' => ['nullable', Rule::in([5, 10, 30, 60, 120])],
            'defaults.frame_rate' => ['nullable', Rule::in([30, 60])],
            'schedule' => ['nullable', 'array'],
            'schedule.strict_start' => ['nullable', 'boolean'],
            'limits.schedule_max_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'limits.thumbnail_max_mb' => ['nullable', 'integer', 'min:1', 'max:25'],
            'limits.poll_max_options' => ['nullable', 'integer', 'min:2', 'max:10'],
            'limits.poll_max_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'limits.stream_label_max_count' => ['nullable', 'integer', 'min:1', 'max:30'],
            'limits.connection_probe_kb' => ['nullable', 'integer', 'min:64', 'max:2048'],
            'limits.connection_good_mbps' => ['nullable', 'numeric', 'min:0.1', 'max:10000'],
            'limits.connection_good_latency_ms' => ['nullable', 'integer', 'min:1', 'max:60000'],
            'monetization' => ['nullable', 'array'],
            'monetization.tip_min_cents' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'monetization.tip_max_cents' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'monetization.contribution_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'monetization.ad_gross_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'monetization.ad_creator_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], $featureRules));
        $liveKitAdminKeys = array_diff(LiveKitSettings::editableKeys(), ['redis_host', 'redis_port', 'redis_username', 'redis_password', 'redis_database']);
        $liveKit = array_intersect_key((array) ($data['livekit'] ?? []), array_flip($liveKitAdminKeys));
        $hasLiveKitSettings = $liveKit !== [];
        if ($hasLiveKitSettings) {
            $liveKitForValidation = array_replace(LiveKitSettings::settings(), $liveKit);
            abort_if($liveKitForValidation['rtc_udp_port_start'] > $liveKitForValidation['rtc_udp_port_end'], 422, 'LiveKit UDP port range end must be greater than or equal to its start.');
            abort_if(! $liveKitForValidation['turn_tls_enabled'] && $liveKitForValidation['turn_udp_port'] >= $liveKitForValidation['rtc_udp_port_start'] && $liveKitForValidation['turn_udp_port'] <= $liveKitForValidation['rtc_udp_port_end'], 422, 'LiveKit TURN/UDP port must be outside the WebRTC UDP port range.');
            abort_if($liveKitForValidation['turn_tls_enabled'] && in_array($liveKitForValidation['turn_tls_port'], [$liveKitForValidation['signal_port'], $liveKitForValidation['rtc_tcp_port']], true), 422, 'LiveKit TURN/TLS port must not match the signaling or WebRTC TCP port.');
            abort_unless(LiveKitSettings::certificateDirectoryIsValid((string) ($liveKitForValidation['certificate_directory'] ?? '')), 422, 'LiveKit certificate directory must be inside the PlayHub livekit folder or use a safe absolute server path.');
            foreach (['ssl_cert_path', 'ssl_key_path', 'turn_tls_cert_path', 'turn_tls_key_path'] as $pathField) {
                abort_unless(LiveKitSettings::certificatePathIsValid((string) ($liveKitForValidation[$pathField] ?? '')), 422, 'LiveKit certificate file paths must be relative to the configured certificate directory or absolute server paths.');
                abort_unless(LiveKitSettings::certificatePathMatchesDirectory((string) ($liveKitForValidation[$pathField] ?? ''), $liveKitForValidation), 422, 'Absolute LiveKit certificate files must be inside the configured certificate directory.');
            }
            foreach (['docker_compose_path', 'livekit_config_path', 'ingress_config_path', 'egress_config_path', 'keys_path', 'restart_trigger_path', 'docker_apply_trigger_path', 'docker_command_path', 'entrypoint_path', 'status_path', 'log_path'] as $pathField) {
                abort_unless(LiveKitSettings::projectPathIsValid((string) ($liveKitForValidation[$pathField] ?? '')), 422, 'Every LiveKit host configuration path must remain inside the PlayHub livekit/runtime directory.');
            }
            foreach (['container_livekit_config_path', 'container_ingress_config_path', 'container_egress_config_path', 'container_keys_path', 'container_restart_trigger_path', 'container_entrypoint_path', 'container_status_path', 'container_log_path', 'container_certificate_directory'] as $pathField) {
                abort_unless(LiveKitSettings::containerPathIsValid((string) ($liveKitForValidation[$pathField] ?? '')), 422, 'Every LiveKit Docker path must be a safe absolute container path.');
            }
            abort_if(count(array_unique(array_map(fn (string $field): string => $liveKitForValidation[$field], ['docker_compose_path', 'livekit_config_path', 'ingress_config_path', 'egress_config_path', 'keys_path', 'restart_trigger_path', 'docker_apply_trigger_path', 'docker_command_path', 'entrypoint_path', 'status_path', 'log_path']))) !== 11, 422, 'LiveKit host configuration files must use different paths.');
            abort_if(count(array_unique(array_map(fn (string $field): string => $liveKitForValidation[$field], ['container_livekit_config_path', 'container_ingress_config_path', 'container_egress_config_path', 'container_keys_path', 'container_restart_trigger_path', 'container_entrypoint_path', 'container_status_path', 'container_log_path']))) !== 8, 422, 'LiveKit container files must use different paths.');
            if (($liveKitForValidation['docker_compose_mode'] ?? 'generated') === 'custom' && array_key_exists('docker_compose_yaml', $liveKit)) {
                abort_unless(LiveKitSettings::dockerComposeYamlIsValid((string) $liveKit['docker_compose_yaml']), 422, 'Custom Docker Compose YAML must contain the livekit, ingress, and egress services.');
            }
            if (($liveKitForValidation['livekit_config_mode'] ?? 'generated') === 'custom' && array_key_exists('livekit_config_yaml', $liveKit)) {
                abort_unless(LiveKitSettings::liveKitConfigYamlIsValid((string) $liveKit['livekit_config_yaml']), 422, 'Custom LiveKit YAML must contain a signaling port.');
            }
            if (array_key_exists('entrypoint_script', $liveKit)) {
                abort_unless(LiveKitSettings::entrypointScriptIsValid((string) $liveKit['entrypoint_script']), 422, 'The Docker entrypoint must be a shell script that starts livekit-server.');
            }
            abort_unless(LiveKitSettings::iceServerConfigJsonIsValid((string) ($liveKitForValidation['ice_server_config_json'] ?? '')), 422, 'LiveKit ICE server configuration must contain valid icesConfig JSON.');
            abort_unless(LiveKitSettings::advancedConfigJsonIsValid((string) ($liveKitForValidation['advanced_config_json'] ?? '')), 422, 'LiveKit advanced configuration must be valid JSON object syntax.');
            if ($liveKitForValidation['ssl_enabled']) {
                abort_unless(filled($liveKitForValidation['ssl_domain']), 422, 'A LiveKit SSL domain is required when LiveKit SSL certificates are enabled.');
                abort_unless(LiveKitSettings::certificateFileExists((string) $liveKitForValidation['ssl_cert_path'], $liveKitForValidation) && LiveKitSettings::certificateFileExists((string) $liveKitForValidation['ssl_key_path'], $liveKitForValidation), 422, 'Add readable LiveKit SSL certificate and private-key files inside the configured certificate directory before enabling SSL.');
            }
            if ($liveKitForValidation['turn_tls_enabled']) {
                $turnTlsDomain = $liveKitForValidation['turn_tls_domain'] ?: $liveKitForValidation['ssl_domain'];
                $turnTlsCertPath = $liveKitForValidation['turn_tls_cert_path'] ?: $liveKitForValidation['ssl_cert_path'];
                $turnTlsKeyPath = $liveKitForValidation['turn_tls_key_path'] ?: $liveKitForValidation['ssl_key_path'];
                abort_unless(filled($turnTlsDomain), 422, 'A TURN/TLS domain or shared LiveKit SSL domain is required when embedded TURN/TLS is enabled.');
                abort_unless(LiveKitSettings::certificateFileExists((string) $turnTlsCertPath, $liveKitForValidation) && LiveKitSettings::certificateFileExists((string) $turnTlsKeyPath, $liveKitForValidation), 422, 'Add readable certificate and private-key files inside the configured certificate directory before enabling embedded TURN/TLS.');
            }
        }
        unset($data['engine'], $data['livekit']);
        $hasProductSettings = $data !== [];
        $settings = array_replace_recursive(LiveStreamingSettings::settings(), $data);
        foreach ((array) ($data['options'] ?? []) as $name => $values) {
            $settings['options'][$name] = array_values($values);
        }
        $defaultOptions = [
            'resolution' => 'resolutions', 'latency_mode' => 'latency_modes', 'replay_retention' => 'replay_retention',
            'audience_mode' => 'audience_modes', 'moderation_level' => 'moderation_levels',
            'slow_mode_interval' => 'slow_mode_intervals', 'frame_rate' => 'frame_rates',
        ];
        foreach ($defaultOptions as $default => $option) {
            abort_unless(in_array($settings['defaults'][$default], $settings['options'][$option], true), 422, 'Each default live-stream option must remain enabled.');
        }
        abort_if($settings['monetization']['tip_min_cents'] > $settings['monetization']['tip_max_cents'], 422, 'Minimum tip cannot be greater than maximum tip.');
        abort_if(($settings['features']['ads'] ?? false) && ($settings['monetization']['ad_gross_cents'] <= 0 || $settings['monetization']['ad_creator_share_percent'] <= 0), 422, 'Set a positive live ad gross amount and creator share before enabling live ads.');
        if ($hasProductSettings) {
            LiveStreamingSettings::save($settings);
        }
        if ($hasLiveKitSettings) {
            LiveKitSettings::save($liveKit);
        }
        $this->recordAdminActivity($request->user(), 'Updated live streaming settings');

        return response()->json([
            'message' => $hasLiveKitSettings ? 'Live streaming settings saved. LiveKit container restart requested.' : 'Settings saved successfully.',
            'liveStreaming' => LiveStreamingSettings::adminSettings(),
        ]);
    }

    public function liveKitStatus(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $settings = LiveKitSettings::settings();

        return response()->json([
            'docker' => LiveKitDockerStatus::read($settings['status_path']),
            'ready' => LiveKitSettings::isRunning(),
        ]);
    }

    public function testLiveKitConnection(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $connection = LiveKitSettings::testConnection();
        } catch (\Throwable $exception) {
            return response()->json([
                'connection' => ['success' => false, 'message' => $exception->getMessage()],
            ]);
        }

        return response()->json(['connection' => $connection]);
    }

    public function liveKitAction(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'stop', 'restart', 'recreate', 'pull'])],
        ]);
        LiveKitSettings::requestDockerAction($data['action']);

        return response()->json([
            'message' => 'LiveKit Docker '.($data['action'] === 'recreate' ? 'configuration apply' : $data['action']).' requested.',
        ], 202);
    }

    public function liveKitLogs(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['logs' => LiveKitSettings::logs()]);
    }

    public function systemLogs(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['lines' => ['nullable', 'integer', 'min:20', 'max:500']]);
        $lineLimit = (int) ($data['lines'] ?? 120);
        $files = collect(File::glob(storage_path('logs/*.log')))
            ->filter(fn (string $path): bool => File::isFile($path))
            ->sortByDesc(fn (string $path): int => File::lastModified($path))
            ->values();

        if ($files->isEmpty()) {
            return response()->json(['file' => null, 'updated_at' => null, 'lines' => [], 'truncated' => false]);
        }

        $path = $files->first();
        $size = File::size($path);
        $maxBytes = 262144;
        $offset = max(0, $size - $maxBytes);
        $handle = fopen($path, 'rb');
        abort_if($handle === false, 500, 'The system log could not be read.');
        if ($offset > 0) {
            fseek($handle, $offset);
            fgets($handle);
        }
        $contents = stream_get_contents($handle) ?: '';
        fclose($handle);
        $lines = preg_split('/\R/', trim($contents)) ?: [];
        $lines = array_slice(array_values(array_filter($lines, fn (string $line): bool => $line !== '')), -$lineLimit);

        return response()->json([
            'file' => basename($path),
            'updated_at' => Carbon::createFromTimestamp(File::lastModified($path))->toIso8601String(),
            'lines' => $lines,
            'truncated' => $offset > 0,
        ]);
    }

    public function runSystemAction(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['clear_cache', 'optimize_clear'])],
        ]);

        $commands = $data['action'] === 'clear_cache'
            ? [
                ['config:clear', []],
                ['route:clear', []],
                ['view:clear', []],
                ['event:clear', []],
                ['clear-compiled', []],
            ]
            : [['optimize:clear', []]];
        $ran = [];
        $skipped = [];

        if ($data['action'] === 'clear_cache') {
            foreach (collect([config('cache.default'), 'file'])->filter()->unique() as $store) {
                try {
                    $code = Artisan::call('cache:clear', ['store' => $store]);
                    if ($code !== 0) {
                        $skipped[] = 'cache:'.$store;

                        continue;
                    }
                    $ran[] = 'cache:'.$store;
                } catch (\Throwable $exception) {
                    $skipped[] = 'cache:'.$store;
                }
            }
        }

        foreach ($commands as [$command, $parameters]) {
            try {
                $code = Artisan::call($command, $parameters);
                if ($code !== 0) {
                    $skipped[] = $command;

                    continue;
                }
                $ran[] = $parameters['store'] ?? $command;
            } catch (\Throwable $exception) {
                $skipped[] = $command;
            }
        }
        $this->recordAdminActivity($request->user(), $data['action'] === 'clear_cache' ? 'Cleared application caches' : 'Cleared optimized caches');

        return response()->json([
            'message' => $data['action'] === 'clear_cache'
                ? ($skipped ? 'Cache clear completed with warnings. Skipped unavailable items: '.implode(', ', $skipped).'.' : 'Laravel caches cleared.')
                : ($skipped ? 'Optimized cache clear completed with warnings. Skipped unavailable items: '.implode(', ', $skipped).'.' : 'Laravel optimized caches cleared.'),
            'cleared' => $ran,
            'skipped' => $skipped,
        ]);
    }

    public function cronJobs(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            return response()->json($this->cronJobsPayload($this->readCrontabContent()));
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Unable to read server cron jobs: '.$exception->getMessage(),
                'available' => false,
            ], 500);
        }
    }

    public function updateCronJobs(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'content' => ['present', 'nullable', 'string', 'max:20000'],
        ]);

        $content = str_replace(["\r\n", "\r"], "\n", (string) ($data['content'] ?? ''));
        $content = trim($content) === '' ? '' : rtrim($content)."\n";
        $this->validateCrontabContent($content);

        try {
            $this->writeCrontabContent($content);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Unable to save server cron jobs: '.$exception->getMessage(),
            ], 500);
        }
        $this->recordAdminActivity($request->user(), 'Updated server cron jobs');

        return response()->json(array_merge($this->cronJobsPayload($content), [
            'message' => 'Cron jobs saved.',
        ]));
    }

    public function backups(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        @set_time_limit(120);
        $settings = $this->backupSettings();

        $items = [];
        if ($request->boolean('inventory', true)) {
            foreach (new \DirectoryIterator(base_path()) as $item) {
                if ($item->isDot()) {
                    continue;
                }
                $stats = $this->backupPathStats($item->getPathname());
                $items[] = [
                    'name' => $item->getFilename(),
                    'type' => $item->isLink() ? 'link' : ($item->isDir() ? 'folder' : 'file'),
                    'selectable' => ! $item->isLink() && ($item->isDir() || $item->isFile()),
                    'size_bytes' => $stats['bytes'],
                    'size' => $this->formatBytes($stats['bytes']),
                    'files' => $stats['files'],
                ];
            }
            usort($items, fn (array $left, array $right): int => [$left['type'] !== 'folder', strtolower($left['name'])] <=> [$right['type'] !== 'folder', strtolower($right['name'])]);
        }

        $freeBytes = (int) (@disk_free_space(storage_path('app')) ?: 0);
        $totalBytes = (int) (@disk_total_space(storage_path('app')) ?: 0);

        return response()->json([
            'items' => $items,
            'backups' => $this->backupEntries(),
            'storage' => [
                'free_bytes' => $freeBytes,
                'free' => $freeBytes > 0 ? $this->formatBytes($freeBytes) : 'Unavailable',
                'total_bytes' => $totalBytes,
                'total' => $totalBytes > 0 ? $this->formatBytes($totalBytes) : 'Unavailable',
            ],
            'defaults' => $this->defaultBackupSettings(),
            'settings' => $settings,
            'summary' => $this->backupSummary(),
        ]);
    }

    public function updateBackupSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'time' => ['required', 'date_format:H:i'],
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'day_of_month' => ['required', 'integer', 'min:1', 'max:28'],
            'selections' => ['required', 'array', 'min:1', 'max:256'],
            'selections.*' => ['required', 'string', 'max:255'],
            'include_database' => ['required', 'boolean'],
            'large_file_action' => ['required', Rule::in(['compress', 'store', 'skip'])],
            'large_file_threshold_mb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'compression_level' => ['required', Rule::in([0, 3, 6, 9])],
            'retention_count' => ['required', 'integer', 'min:1', 'max:50'],
        ]);
        $data['selections'] = $this->validBackupSelections($data['selections']);
        $this->writeBackupSettings($data);
        $this->recordAdminActivity($request->user(), ($data['enabled'] ? 'Enabled' : 'Disabled').' automatic backups');

        return response()->json([
            'message' => $data['enabled'] ? 'Automatic backup schedule saved.' : 'Automatic backups disabled.',
            'settings' => $data,
            'summary' => $this->backupSummary(),
        ]);
    }

    public function runScheduledBackup(): void
    {
        $settings = $this->backupSettings();
        if (! $settings['enabled'] || collect($this->backupEntries())->contains(fn (array $backup): bool => in_array($backup['status'], ['queued', 'processing'], true))) {
            return;
        }

        $selections = $this->validBackupSelections($settings['selections']);
        @set_time_limit(120);
        $selectedBytes = (int) collect($selections)->sum(fn (string $selection): int => $this->backupPathStats(base_path($selection))['bytes']);
        $freeBytes = (int) (@disk_free_space(storage_path('app')) ?: 0);
        if ($settings['large_file_action'] !== 'skip' && $freeBytes > 0 && $selectedBytes > (int) floor($freeBytes * .95)) {
            throw new \RuntimeException('The automatic backup does not have enough free disk space.');
        }

        $this->queueBackup($settings, $selections, $selectedBytes, null, 'automatic');
        $this->recordAdminActivity(null, 'Automatic backup queued');
    }

    public function createBackup(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'selections' => ['required', 'array', 'min:1', 'max:256'],
            'selections.*' => ['required', 'string', 'max:255'],
            'include_database' => ['required', 'boolean'],
            'large_file_action' => ['required', Rule::in(['compress', 'store', 'skip'])],
            'large_file_threshold_mb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'compression_level' => ['required', Rule::in([0, 3, 6, 9])],
            'retention_count' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        if (collect($this->backupEntries())->contains(fn (array $backup): bool => in_array($backup['status'], ['queued', 'processing'], true))) {
            return response()->json(['message' => 'Another backup is already queued or running.'], 409);
        }

        $selections = $this->validBackupSelections($data['selections']);

        @set_time_limit(120);
        $selectedBytes = (int) collect($selections)->sum(fn (string $selection): int => $this->backupPathStats(base_path($selection))['bytes']);
        $freeBytes = (int) (@disk_free_space(storage_path('app')) ?: 0);
        if ($data['large_file_action'] !== 'skip' && $freeBytes > 0 && $selectedBytes > (int) floor($freeBytes * .95)) {
            throw ValidationException::withMessages([
                'selections' => ['The selected files need up to '.$this->formatBytes($selectedBytes).', but only '.$this->formatBytes($freeBytes).' is available. Deselect large folders or choose Skip large files.'],
            ]);
        }

        $metadata = $this->queueBackup($data, $selections, $selectedBytes, (int) $request->user()->id);
        $this->recordAdminActivity($request->user(), 'Manual backup queued');

        return response()->json([
            'message' => 'Backup queued. Progress will update here while it is created.',
            'backup' => $this->backupEntry($metadata),
        ], 202);
    }

    public function downloadBackup(Request $request, string $backup)
    {
        $this->authorizeAdmin($request);
        $metadata = $this->backupMetadata($backup);
        abort_unless($metadata && ($metadata['status'] ?? '') === 'completed', 404, 'Backup not found.');
        $filename = basename((string) ($metadata['archive'] ?? ''));
        $path = storage_path('app/backups/'.$filename);
        abort_unless($filename !== '' && is_file($path), 404, 'Backup archive not found.');

        return response()->download($path, $filename, ['Content-Type' => 'application/zip']);
    }

    public function deleteBackup(Request $request, string $backup): JsonResponse
    {
        $this->authorizeAdmin($request);
        $metadata = $this->backupMetadata($backup);
        abort_unless($metadata, 404, 'Backup not found.');
        abort_if(in_array($metadata['status'] ?? '', ['queued', 'processing'], true), 409, 'A running backup cannot be deleted.');

        $archive = basename((string) ($metadata['archive'] ?? ''));
        if ($archive !== '') {
            File::delete(storage_path('app/backups/'.$archive));
        }
        File::delete(storage_path('app/backups/'.$backup.'.json'));
        $this->recordAdminActivity($request->user(), 'Deleted a system backup');

        return response()->json(['message' => 'Backup deleted.']);
    }

    public function createRole(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:220'],
            'scope' => ['required', 'string', Rule::in(['Global', 'Moderation', 'Content', 'Support', 'Analytics', 'Restricted'])],
            'group_id' => ['nullable', 'string', 'max:100'],
        ]);

        $settings = PlatformFeatureSettings::settings();
        $roles = collect(data_get($settings, 'accessControl.customRoles', []))->filter(fn ($role): bool => is_array($role))->values();
        $name = trim($data['name']);
        abort_if(in_array(strtolower($name), ['super admin', 'moderator'], true), 422, 'Built-in role names are reserved.');
        $requestedId = trim((string) ($data['id'] ?? ''));
        abort_if(in_array($requestedId, ['super-admin', 'moderator'], true), 422, 'Built-in roles cannot be edited.');
        $existing = $requestedId === '' ? null : $roles->firstWhere('id', $requestedId);
        abort_if($requestedId !== '' && ! $existing, 404, 'Custom role not found.');
        abort_if($roles->contains(fn (array $role): bool => ($role['id'] ?? '') !== $requestedId && strcasecmp((string) ($role['name'] ?? ''), $name) === 0), 422, 'A role with this name already exists.');
        $id = $requestedId ?: $this->uniqueAccessControlId(array_merge(['super-admin', 'moderator'], $roles->pluck('id')->all()), $name, 'role');
        $group = collect($this->permissionGroupRows())->firstWhere('id', $data['group_id'] ?? '') ?: $this->permissionGroupRows()[0];

        $role = [
            'id' => $id,
            'name' => $name,
            'description' => trim((string) ($data['description'] ?? '')),
            'scope' => $data['scope'],
            'group_id' => $group['id'],
            'permissions' => $group['permissions'],
            'created_at' => $existing['created_at'] ?? now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'created_by' => $existing['created_by'] ?? $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ];
        $roles = $roles->reject(fn (array $item): bool => ($item['id'] ?? '') === $id)->push($role)->values();

        data_set($settings, 'accessControl.customRoles', $roles->values()->all());
        PlatformFeatureSettings::save($settings);

        return response()->json([
            'message' => $existing ? 'Role updated.' : 'Role created.',
            'roles' => $this->adminRoleRows(),
            'permissionGroups' => $this->permissionGroupRows(),
        ], $existing ? 200 : 201);
    }

    public function deleteRole(Request $request, string $role): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_if(in_array($role, ['super-admin', 'moderator'], true), 422, 'Built-in roles cannot be deleted.');

        $settings = PlatformFeatureSettings::settings();
        $roles = collect(data_get($settings, 'accessControl.customRoles', []))->filter(fn ($item): bool => is_array($item))->values();
        abort_unless($roles->contains(fn (array $item): bool => ($item['id'] ?? '') === $role), 404, 'Custom role not found.');
        data_set($settings, 'accessControl.customRoles', $roles->reject(fn (array $item): bool => ($item['id'] ?? '') === $role)->values()->all());
        PlatformFeatureSettings::save($settings);

        return response()->json(['message' => 'Role deleted.']);
    }

    public function savePermissionGroup(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:220'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::in($this->permissionCatalog())],
        ]);

        $settings = PlatformFeatureSettings::settings();
        $groups = collect(data_get($settings, 'accessControl.permissionGroups', []))->filter(fn ($group): bool => is_array($group))->values();
        $existingIds = $groups->pluck('id')->merge(collect($this->defaultPermissionGroups())->pluck('id'))->all();
        $id = trim((string) ($data['id'] ?? '')) ?: $this->uniqueAccessControlId($existingIds, $data['name'], 'group');
        $group = [
            'id' => $id,
            'name' => trim($data['name']),
            'description' => trim((string) ($data['description'] ?? '')),
            'permissions' => array_values(array_unique($data['permissions'])),
            'updated_at' => now()->toIso8601String(),
            'updated_by' => $request->user()?->id,
        ];

        $groups = $groups->reject(fn (array $item): bool => ($item['id'] ?? '') === $id)->push($group)->values();
        data_set($settings, 'accessControl.permissionGroups', $groups->all());

        $roles = collect(data_get($settings, 'accessControl.customRoles', []))->map(function ($role) use ($group): array {
            $role = is_array($role) ? $role : [];
            if (($role['group_id'] ?? '') === $group['id']) {
                $role['permissions'] = $group['permissions'];
                $role['updated_at'] = now()->toIso8601String();
            }

            return $role;
        })->filter(fn ($role): bool => ! empty($role['id']))->values();
        data_set($settings, 'accessControl.customRoles', $roles->all());

        PlatformFeatureSettings::save($settings);

        return response()->json([
            'message' => 'Permission group saved.',
            'roles' => $this->adminRoleRows(),
            'permissionGroups' => $this->permissionGroupRows(),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $page = $request->query->has('page') || $request->query->has('per_page') ? max((int) $request->integer('page', 1), 1) : null;
        $perPage = $request->query->has('page') || $request->query->has('per_page') ? min(max((int) $request->integer('per_page', 20), 1), 100) : null;

        return response()->json($this->adminNotificationPayload(null, null, null, null, (int) $request->user()->id, $page, $perPage));
    }

    public function markNotificationsRead(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $userId = (int) $request->user()->id;
        $payload = $this->adminNotificationPayload(null, null, null, null, $userId, 1, 500);
        $ids = array_values(array_unique(array_merge($this->adminNotificationReadIds($userId), array_column($payload['items'], 'id'))));
        $this->saveAdminNotificationReadIds($userId, $ids);

        return response()->json([
            'message' => 'Notifications marked as read.',
            'notifications' => $this->adminNotificationPayload(null, null, null, null, $userId),
        ]);
    }

    public function markNotificationRead(Request $request, string $notification): JsonResponse
    {
        $this->authorizeStaff($request);
        $userId = (int) $request->user()->id;
        $ids = $this->adminNotificationReadIds($userId);
        if (! in_array($notification, $ids, true)) {
            $ids[] = $notification;
            $this->saveAdminNotificationReadIds($userId, $ids);
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'notifications' => $this->adminNotificationPayload(null, null, null, null, $userId),
        ]);
    }

    public function completeMassImport(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:40'],
            'total' => ['required', 'integer', 'min:0', 'max:50'],
            'succeeded' => ['required', 'integer', 'min:0', 'max:50'],
            'failed' => ['required', 'integer', 'min:0', 'max:50'],
            'skipped' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'errors' => ['nullable', 'array', 'max:5'],
            'errors.*' => ['string', 'max:240'],
        ]);

        $succeeded = (int) $data['succeeded'];
        $failed = (int) $data['failed'];
        $skipped = (int) ($data['skipped'] ?? 0);
        $provider = trim((string) ($data['provider'] ?? ''));
        $message = $succeeded.' videos imported';
        if ($failed > 0) {
            $message .= ', '.$failed.' failed';
        }
        if ($skipped > 0) {
            $message .= ', '.$skipped.' skipped';
        }
        $message .= '.';

        Cache::put($this->adminMassImportNoticeKey((int) $request->user()->id), [
            'provider' => $provider,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'skipped' => $skipped,
            'message' => $message,
            'errors' => array_values($data['errors'] ?? []),
            'completed_at' => now()->toIso8601String(),
        ], now()->addDay());
        $this->forgetAdminNotificationReadId((int) $request->user()->id, 'mass-import-complete-'.(int) $request->user()->id);

        return response()->json([
            'message' => 'Mass import notification sent.',
            'notifications' => $this->adminNotificationPayload(null, null, null, null, (int) $request->user()->id),
        ]);
    }

    public function discoverMassImportVideo(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $providers = array_keys(VideoImportSettings::providers());
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in($providers)],
            'category_id' => ['nullable', 'integer', Rule::exists('video_categories', 'id')],
            'keyword' => ['nullable', 'string', 'max:160'],
            'cursor' => ['nullable', 'string', 'max:5000'],
            'exclude' => ['nullable', 'array', 'max:100'],
            'exclude.*' => ['string', 'max:2000'],
        ]);

        abort_unless(VideoImportSettings::providerEnabled($data['provider']), 403, 'This video import provider is disabled.');

        $category = ! empty($data['category_id']) ? (string) DB::table('video_categories')->where('id', $data['category_id'])->value('name') : '';
        $keyword = trim((string) ($data['keyword'] ?? ''));
        $query = $keyword !== '' ? $keyword : $category;
        abort_if($query === '', 422, 'Choose a category or enter a keyword for provider discovery.');

        try {
            $candidate = $this->discoverProviderVideoUrl($data['provider'], $query, $data['cursor'] ?? null, $data['exclude'] ?? []);
        } catch (\RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json($candidate + ['provider' => $data['provider']]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $query = trim((string) $request->query('q', ''));

        return response()->json([
            'query' => $query,
            'results' => $this->adminSearchResults($query),
        ]);
    }

    public function testIntegrationSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(['sendgrid', 'smtp', 'smsOtp'])],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        if ($data['provider'] === 'sendgrid') {
            IntegrationSettings::sendTestEmail($request->user());
        } elseif ($data['provider'] === 'smtp') {
            IntegrationSettings::sendSmtpTestEmail($request->user());
        } elseif ($data['provider'] === 'smsOtp') {
            IntegrationSettings::sendSmsOtpTest((string) ($data['phone'] ?? ''));
        }

        return response()->json(['message' => 'Integration test completed.']);
    }

    private function validatedStorageSettings(Request $request): array
    {
        $secretKeys = MediaStorage::SECRET_KEYS;
        $storageUrl = static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            $parts = parse_url($value);
            if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7F]/', $value)) {
                $fail('The '.$attribute.' must be an HTTP(S) URL without credentials, a query string, or a fragment.');
            }
        };
        $rules = [
            'mediaStorage' => ['sometimes', 'required', 'string', 'in:default,local,s3,azure,gcs,b2'],
            'defaultUserStorageGb' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'defaultMembership' => ['nullable', 'string', Rule::in(['starter', 'plus', 'premium', 'pro', 'vip'])],
            'membershipQuotasGb' => ['nullable', 'array'],
            'membershipQuotasGb.starter' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'membershipQuotasGb.plus' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'membershipQuotasGb.premium' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'membershipQuotasGb.pro' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'membershipQuotasGb.vip' => ['nullable'],
            'membershipPricesUsd' => ['nullable', 'array'],
            'membershipPricesUsd.starter' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'membershipPricesUsd.plus' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'membershipPricesUsd.premium' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'membershipPricesUsd.pro' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'membershipPricesUsd.vip' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'cloudStorageTotalGb' => ['nullable', 'integer', 'min:0', 'max:1048576'],
            'recordsPathPrefix' => ['nullable', 'string', 'max:120', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\\\\?#\x00-\x1F\x7F]~', $value)) {
                    $fail('The records prefix must be a relative object path without traversal, backslashes, or query characters.');
                }
            }],
            'clearSecrets' => ['sometimes', 'array'],
            'clearSecrets.*' => ['required', 'string', Rule::in($secretKeys)],
            's3' => ['nullable', 'array'],
            's3.publicBaseUrl' => ['nullable', 'string', 'max:500', $storageUrl],
            's3.endpoint' => ['nullable', 'string', 'max:500', $storageUrl],
            's3.keyId' => ['nullable', 'string', 'max:255'],
            's3.secretAccessKey' => ['nullable', 'string', 'max:500'],
            's3.bucketName' => ['nullable', 'string', 'max:255'],
            's3.region' => ['nullable', 'string', 'max:120'],
            's3.usePathStyleEndpoint' => ['nullable', 'boolean'],
            's3.useDefaultCredentials' => ['nullable', 'boolean'],
            's3.sessionToken' => ['nullable', 'string', 'max:8000'],
            's3.omitAcl' => ['nullable', 'boolean'],
            'azure' => ['nullable', 'array'],
            'azure.publicBaseUrl' => ['nullable', 'string', 'max:500', $storageUrl],
            'azure.endpoint' => ['nullable', 'string', 'max:500', $storageUrl],
            'azure.accountName' => ['nullable', 'string', 'max:255'],
            'azure.accountKey' => ['nullable', 'string', 'max:500'],
            'azure.connectionString' => ['nullable', 'string', 'max:8000'],
            'azure.containerName' => ['nullable', 'string', 'max:255'],
            'gcs' => ['nullable', 'array'],
            'gcs.publicBaseUrl' => ['nullable', 'string', 'max:500', $storageUrl],
            'gcs.endpoint' => ['nullable', 'string', 'max:500', $storageUrl],
            'gcs.region' => ['nullable', 'string', 'max:120'],
            'gcs.usePathStyleEndpoint' => ['nullable', 'boolean'],
            'gcs.omitAcl' => ['nullable', 'boolean'],
            'gcs.accessKeyId' => ['nullable', 'string', 'max:255'],
            'gcs.secretAccessKey' => ['nullable', 'string', 'max:500'],
            'gcs.bucketName' => ['nullable', 'string', 'max:255'],
            'b2' => ['nullable', 'array'],
            'b2.publicBaseUrl' => ['nullable', 'string', 'max:500', $storageUrl],
            'b2.endpoint' => ['nullable', 'string', 'max:500', $storageUrl],
            'b2.keyId' => ['nullable', 'string', 'max:255'],
            'b2.masterApplicationKey' => ['nullable', 'string', 'max:500'],
            'b2.bucketId' => ['nullable', 'string', 'max:255'],
            'b2.bucketName' => ['nullable', 'string', 'max:255'],
            'b2.region' => ['nullable', 'string', 'max:120'],
            'b2.usePathStyleEndpoint' => ['nullable', 'boolean'],
            'b2.omitAcl' => ['nullable', 'boolean'],
        ];
        $data = $request->validate($rules);

        $current = MediaStorage::settings();
        // A null provider object is not a request to erase all of its saved credentials.
        foreach (['s3', 'azure', 'gcs', 'b2', 'membershipQuotasGb', 'membershipPricesUsd'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            } elseif (is_array($data[$key] ?? null)) {
                // Laravel array validation can retain unruled child keys; only persist supported options.
                $allowed = [];
                foreach (array_keys($rules) as $ruleKey) {
                    if (str_starts_with($ruleKey, $key.'.')) {
                        $allowed[substr($ruleKey, strlen($key) + 1)] = true;
                    }
                }
                $data[$key] = array_intersect_key($data[$key], $allowed);
            }
        }
        $clearSecrets = $data['clearSecrets'] ?? [];
        unset($data['clearSecrets']);
        $settings = array_replace_recursive($current, $data);
        foreach ($secretKeys as $key) {
            if (($request->input($key) ?? '') === '') {
                data_set($settings, $key, data_get($current, $key));
            }
        }
        foreach ($clearSecrets as $key) {
            data_set($settings, $key, '');
        }
        $settings['recordsPathPrefix'] = trim((string) ($settings['recordsPathPrefix'] ?? ''), '/');
        try {
            // Validate the same resolved configuration used by uploads before accepting a save or test.
            MediaStorage::configuration($settings);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw ValidationException::withMessages(['mediaStorage' => [$exception->getMessage()]]);
        }

        return $settings;
    }

    private function validatedIntegrationSettings(Request $request): array
    {
        return $request->validate([
            'payments' => ['nullable', 'array'],
            'payments.currency' => ['nullable', 'string', 'size:3'],
            'googleAnalytics' => ['nullable', 'array'],
            'googleAnalytics.enabled' => ['nullable', 'boolean'],
            'googleAnalytics.measurementId' => ['nullable', 'string', 'max:80'],
            'stripe' => ['nullable', 'array'],
            'stripe.enabled' => ['nullable', 'boolean'],
            'stripe.publishableKey' => ['nullable', 'string', 'max:255'],
            'stripe.secretKey' => ['nullable', 'string', 'max:500'],
            'paypal' => ['nullable', 'array'],
            'paypal.enabled' => ['nullable', 'boolean'],
            'paypal.environment' => ['nullable', 'string', Rule::in(['sandbox', 'live'])],
            'paypal.clientId' => ['nullable', 'string', 'max:255'],
            'paypal.clientSecret' => ['nullable', 'string', 'max:500'],
            'smtp' => ['nullable', 'array'],
            'smtp.enabled' => ['nullable', 'boolean'],
            'smtp.host' => ['nullable', 'string', 'max:255'],
            'smtp.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp.encryption' => ['nullable', 'string', Rule::in(['none', 'tls', 'ssl'])],
            'smtp.username' => ['nullable', 'string', 'max:255'],
            'smtp.password' => ['nullable', 'string', 'max:500'],
            'smtp.fromEmail' => ['nullable', 'email', 'max:255'],
            'smtp.fromName' => ['nullable', 'string', 'max:120'],
            'smsOtp' => ['nullable', 'array'],
            'smsOtp.enabled' => ['nullable', 'boolean'],
            'smsOtp.provider' => ['nullable', 'string', Rule::in(['twilio', 'vonage', 'messagebird'])],
            'smsOtp.accountSid' => ['nullable', 'string', 'max:255'],
            'smsOtp.authToken' => ['nullable', 'string', 'max:500'],
            'smsOtp.fromNumber' => ['nullable', 'string', 'max:32'],
            'smsOtp.vonageApiKey' => ['nullable', 'string', 'max:255'],
            'smsOtp.vonageApiSecret' => ['nullable', 'string', 'max:500'],
            'smsOtp.vonageFrom' => ['nullable', 'string', 'max:32'],
            'smsOtp.messagebirdAccessKey' => ['nullable', 'string', 'max:500'],
            'smsOtp.messagebirdOriginator' => ['nullable', 'string', 'max:32'],
            'smsOtp.testPhone' => ['nullable', 'string', 'max:32'],
            'sendgrid' => ['nullable', 'array'],
            'sendgrid.enabled' => ['nullable', 'boolean'],
            'sendgrid.apiKey' => ['nullable', 'string', 'max:500'],
            'sendgrid.fromEmail' => ['nullable', 'email', 'max:255'],
            'sendgrid.fromName' => ['nullable', 'string', 'max:120'],
            'sendgrid.notificationsEmail' => ['nullable', 'email', 'max:255'],
        ]);
    }

    private function validatedAuthSettings(Request $request): array
    {
        $rules = [
            'registration' => ['nullable', 'array'],
            'registration.enabled' => ['nullable', 'boolean'],
            'registration.defaultRole' => ['sometimes', 'required', 'string', Rule::in(['user', 'creator'])],
            'registration.phoneEnabled' => ['nullable', 'boolean'],
            'registration.usernameRequired' => ['nullable', 'boolean'],
            'registration.blockedIps' => ['nullable', 'string', 'max:5000'],
            'registration.confirmationMethod' => ['nullable', 'string', Rule::in(['none', 'email', 'sms'])],
            'registration.passwordStrengthEnabled' => ['nullable', 'boolean'],
            'registration.passwordStrengthLevel' => ['nullable', 'integer', 'min:0', 'max:10'],
            'registration.captchaEnabled' => ['nullable', 'boolean'],
            'registration.captchaProvider' => ['nullable', 'string', Rule::in(['simple', 'google'])],
            'registration.googleCaptchaSiteKey' => ['nullable', 'string', 'max:255'],
            'registration.googleCaptchaSecretKey' => ['nullable', 'string', 'max:500'],
            'registration.smsCodeDigits' => ['nullable', 'integer', 'min:4', 'max:8'],
            'registration.smsCodeExpiresMinutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            'registration.smsMaxResends' => ['nullable', 'integer', 'min:0', 'max:10'],
            'registration.smsInvalidAttempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'registration.smsInvalidBlockMinutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'login' => ['nullable', 'array'],
            'login.email' => ['nullable', 'boolean'],
            'login.username' => ['nullable', 'boolean'],
            'login.phone' => ['nullable', 'boolean'],
            'login.twoFactorEnabled' => ['nullable', 'boolean'],
            'login.maxAttempts' => ['nullable', 'integer', 'min:1', 'max:100'],
            'login.captchaAfterAttempts' => ['nullable', 'integer', 'min:1', 'max:100'],
            'login.banMinutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'social' => ['nullable', 'array'],
            'social.google' => ['nullable', 'array'],
            'social.google.enabled' => ['nullable', 'boolean'],
            'social.google.clientId' => ['nullable', 'string', 'max:500'],
            'social.google.clientSecret' => ['nullable', 'string', 'max:1000'],
            'social.facebook' => ['nullable', 'array'],
            'social.facebook.enabled' => ['nullable', 'boolean'],
            'social.facebook.clientId' => ['nullable', 'string', 'max:500'],
            'social.facebook.clientSecret' => ['nullable', 'string', 'max:1000'],
            'social.apple' => ['nullable', 'array'],
            'social.apple.enabled' => ['nullable', 'boolean'],
            'social.apple.clientId' => ['nullable', 'string', 'max:500'],
            'social.apple.teamId' => ['nullable', 'string', 'max:80'],
            'social.apple.keyId' => ['nullable', 'string', 'max:80'],
            'social.apple.privateKey' => ['nullable', 'string', 'max:10000'],
        ];

        return $request->validate(array_merge($rules, CaptchaSettings::rules()));
    }

    private function adminMassImportOptions(): array
    {
        $categories = Schema::hasTable('video_categories')
            ? DB::table('video_categories')->select('id', 'name', 'slug', 'sort_order')->orderBy('sort_order')->orderBy('name')->get()->map(fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name, 'slug' => $row->slug, 'sort_order' => (int) $row->sort_order])->values()->all()
            : [];
        $languages = Schema::hasTable('video_languages')
            ? DB::table('video_languages')->select('id', 'name')->orderBy('name')->get()->map(fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name])->values()->all()
            : [];

        return [
            'video_import' => VideoImportSettings::publicSettings(),
            'categories' => $categories,
            'languages' => $languages,
        ];
    }

    private function discoverProviderVideoUrl(string $provider, string $category, ?string $cursor, array $exclude): array
    {
        return match ($provider) {
            'youtube' => $this->discoverYoutubeVideoUrl($category, $cursor, $exclude),
            'vimeo' => $this->discoverVimeoVideoUrl($category, $cursor, $exclude),
            'dailymotion' => $this->discoverDailymotionVideoUrl($category, $cursor, $exclude),
            'twitch' => $this->discoverTwitchVideoUrl($category, $cursor, $exclude),
            default => throw new \RuntimeException('Provider discovery is not available for this provider.'),
        };
    }

    private function discoverYoutubeVideoUrl(string $category, ?string $cursor, array $exclude): array
    {
        $apiKey = trim((string) data_get(VideoImportSettings::settings(), 'providers.youtube.apiKey', ''));

        if ($apiKey !== '') {
            try {
                $pageToken = $cursor && ! is_numeric($cursor) ? $cursor : null;
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $query = [
                        'part' => 'snippet',
                        'type' => 'video',
                        'maxResults' => 1,
                        'q' => $category,
                        'order' => 'date',
                        'safeSearch' => 'moderate',
                        'videoEmbeddable' => 'true',
                        'key' => $apiKey,
                    ];
                    if ($pageToken) {
                        $query['pageToken'] = $pageToken;
                    }

                    $data = $this->adminFetchJson('https://www.googleapis.com/youtube/v3/search?'.http_build_query($query));
                    $item = $data['items'][0] ?? null;
                    $id = data_get($item, 'id.videoId');
                    $pageToken = $data['nextPageToken'] ?? null;

                    if ($id && VideoImportSettings::youtubeVideoIsShort($id) === false) {
                        $url = 'https://www.youtube.com/watch?v='.$id;
                        if (! $this->massImportCandidateSeen($url, $exclude)) {
                            return ['url' => $url, 'title' => data_get($item, 'snippet.title'), 'cursor' => $pageToken];
                        }
                    }

                    if (! $pageToken) {
                        break;
                    }
                }
            } catch (\RuntimeException) {
                // Fall back to public search below when the stored API key is missing access or invalid.
            }
        }

        return $this->discoverYoutubeWebVideoUrl($category, $cursor, $exclude);
    }

    private function discoverYoutubeWebVideoUrl(string $category, ?string $cursor, array $exclude): array
    {
        $state = $this->decodeYoutubeMassImportCursor($cursor);
        $html = $this->adminFetchText('https://www.youtube.com/results?'.http_build_query([
            'search_query' => $category,
            'sp' => 'EgIQAQ==',
        ]));
        $config = $this->extractYoutubeJavascriptObject($html, 'ytcfg.set');
        $initialData = $this->extractYoutubeJavascriptObject($html, 'ytInitialData');

        if (($state['page'] ?? 'initial') === 'continuation' && ! empty($state['token'])) {
            return $this->discoverYoutubeContinuationVideoUrl($category, $config, (string) $state['token'], (int) ($state['offset'] ?? 0), $exclude, (string) ($state['next'] ?? ''));
        }

        preg_match_all('/"videoRenderer":\s*\{\s*"videoId":"([A-Za-z0-9_-]{6,})"/', $html, $matches);
        $ids = $this->youtubeVideoIds($initialData);
        $ids = array_values(array_unique(array_merge($ids, $matches[1] ?? [])));
        $continuation = (string) (($state['token'] ?? '') ?: ($this->youtubeContinuationToken($initialData) ?? ''));
        $offset = max(0, (int) ($state['offset'] ?? 0));
        for ($index = $offset; $index < count($ids); $index++) {
            $id = $ids[$index];
            if (VideoImportSettings::youtubeVideoIsShort($id) !== false) {
                continue;
            }
            $url = 'https://www.youtube.com/watch?v='.$id;
            if (! $this->massImportCandidateSeen($url, $exclude)) {
                return ['url' => $url, 'title' => null, 'cursor' => $this->encodeYoutubeMassImportCursor([
                    'page' => 'initial',
                    'offset' => $index + 1,
                    'token' => $continuation,
                ])];
            }
        }

        if ($continuation !== '') {
            return $this->discoverYoutubeContinuationVideoUrl($category, $config, $continuation, 0, $exclude);
        }

        throw new \RuntimeException('No new YouTube videos were found for this search.');
    }

    private function discoverYoutubeContinuationVideoUrl(string $category, array $config, string $token, int $offset, array $exclude, string $nextToken = ''): array
    {
        $apiKey = trim((string) data_get($config, 'INNERTUBE_API_KEY', ''));
        $context = data_get($config, 'INNERTUBE_CONTEXT');
        if (! is_array($context)) {
            $context = [
                'client' => [
                    'clientName' => data_get($config, 'INNERTUBE_CLIENT_NAME', 'WEB'),
                    'clientVersion' => data_get($config, 'INNERTUBE_CLIENT_VERSION', '2.20240601.00.00'),
                    'hl' => 'en',
                    'gl' => 'US',
                ],
            ];
        }

        for ($page = 0; $page < 4 && $token !== ''; $page++) {
            $url = 'https://www.youtube.com/youtubei/v1/search?'.http_build_query(array_filter([
                'prettyPrint' => 'false',
                'key' => $apiKey ?: null,
            ]));
            $data = $this->adminPostJson($url, [
                'context' => $context,
                'continuation' => $token,
            ], [
                'Accept: application/json',
                'Content-Type: application/json',
                'Origin: https://www.youtube.com',
                'Referer: https://www.youtube.com/results?'.http_build_query(['search_query' => $category, 'sp' => 'EgIQAQ==']),
            ]);
            $ids = $this->youtubeVideoIds($data);
            $next = (string) ($this->youtubeContinuationToken($data) ?? $nextToken);

            for ($index = $offset; $index < count($ids); $index++) {
                $id = $ids[$index];
                if (VideoImportSettings::youtubeVideoIsShort($id) !== false) {
                    continue;
                }
                $url = 'https://www.youtube.com/watch?v='.$id;
                if (! $this->massImportCandidateSeen($url, $exclude)) {
                    return ['url' => $url, 'title' => null, 'cursor' => $this->encodeYoutubeMassImportCursor([
                        'page' => 'continuation',
                        'offset' => $index + 1,
                        'token' => $token,
                        'next' => $next,
                    ])];
                }
            }

            $token = $next;
            $nextToken = '';
            $offset = 0;
        }

        throw new \RuntimeException('No new YouTube videos were found for this search.');
    }

    private function decodeYoutubeMassImportCursor(?string $cursor): array
    {
        if (! $cursor) {
            return ['page' => 'initial', 'offset' => 0];
        }

        $data = json_decode($cursor, true);
        if (! is_array($data)) {
            return ['page' => 'initial', 'offset' => max(0, (int) $cursor)];
        }

        return [
            'page' => ($data['page'] ?? '') === 'continuation' ? 'continuation' : 'initial',
            'offset' => max(0, (int) ($data['offset'] ?? 0)),
            'token' => is_string($data['token'] ?? null) ? $data['token'] : '',
            'next' => is_string($data['next'] ?? null) ? $data['next'] : '',
        ];
    }

    private function encodeYoutubeMassImportCursor(array $state): string
    {
        return (string) json_encode(array_filter($state, fn ($value) => $value !== null && $value !== ''), JSON_UNESCAPED_SLASHES);
    }

    private function extractYoutubeJavascriptObject(string $html, string $marker): array
    {
        $position = strpos($html, $marker);
        if ($position === false) {
            return [];
        }

        $start = strpos($html, '{', $position);
        if ($start === false) {
            return [];
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($html);
        for ($index = $start; $index < $length; $index++) {
            $char = $html[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($char === '"') {
                $inString = true;

                continue;
            }
            if ($char === '{') {
                $depth++;

                continue;
            }
            if ($char !== '}') {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                $data = json_decode(substr($html, $start, $index - $start + 1), true);

                return is_array($data) ? $data : [];
            }
        }

        return [];
    }

    private function youtubeVideoIds($data): array
    {
        $ids = [];
        $this->collectYoutubeVideoIds($data, $ids);

        return array_values(array_unique($ids));
    }

    private function collectYoutubeVideoIds($data, array &$ids): void
    {
        if (! is_array($data)) {
            return;
        }
        $id = data_get($data, 'videoRenderer.videoId');
        if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{6,}$/', $id)) {
            $ids[] = $id;
        }
        foreach ($data as $item) {
            $this->collectYoutubeVideoIds($item, $ids);
        }
    }

    private function youtubeContinuationToken($data): ?string
    {
        if (! is_array($data)) {
            return null;
        }
        foreach (['continuationCommand.token', 'nextContinuationData.continuation', 'reloadContinuationData.continuation'] as $path) {
            $token = data_get($data, $path);
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }
        foreach ($data as $item) {
            $token = $this->youtubeContinuationToken($item);
            if ($token) {
                return $token;
            }
        }

        return null;
    }

    private function discoverDailymotionVideoUrl(string $category, ?string $cursor, array $exclude): array
    {
        $page = max(1, (int) ($cursor ?: 1));

        for ($attempt = 0; $attempt < 5; $attempt++, $page++) {
            $data = $this->adminFetchJson('https://api.dailymotion.com/videos?'.http_build_query([
                'fields' => 'id,title',
                'search' => $category,
                'sort' => 'recent',
                'limit' => 1,
                'page' => $page,
            ]));
            $item = $data['list'][0] ?? null;
            $id = $item['id'] ?? null;
            if ($id) {
                $url = 'https://www.dailymotion.com/video/'.$id;
                if (! $this->massImportCandidateSeen($url, $exclude)) {
                    return ['url' => $url, 'title' => $item['title'] ?? null, 'cursor' => ($data['has_more'] ?? false) ? (string) ($page + 1) : null];
                }
            }
            if (! ($data['has_more'] ?? false)) {
                break;
            }
        }

        throw new \RuntimeException('No new Dailymotion videos were found for this search.');
    }

    private function discoverVimeoVideoUrl(string $category, ?string $cursor, array $exclude): array
    {
        $apiKey = trim((string) data_get(VideoImportSettings::settings(), 'providers.vimeo.apiKey', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Vimeo discovery requires a Vimeo access token in System Settings.');
        }

        $page = max(1, (int) ($cursor ?: 1));
        for ($attempt = 0; $attempt < 5; $attempt++, $page++) {
            $data = $this->adminFetchJson('https://api.vimeo.com/videos?'.http_build_query([
                'query' => $category,
                'per_page' => 1,
                'page' => $page,
                'sort' => 'date',
                'direction' => 'desc',
            ]), [
                'Authorization: Bearer '.$apiKey,
                'Accept: application/vnd.vimeo.*+json;version=3.4',
            ]);
            $item = $data['data'][0] ?? null;
            $url = $item['link'] ?? null;
            if ($url && ! $this->massImportCandidateSeen($url, $exclude)) {
                return ['url' => $url, 'title' => $item['name'] ?? null, 'cursor' => (string) ($page + 1)];
            }
            if (! $item) {
                break;
            }
        }

        throw new \RuntimeException('No new Vimeo videos were found for this search.');
    }

    private function discoverTwitchVideoUrl(string $category, ?string $cursor, array $exclude): array
    {
        [$clientId, $token] = $this->twitchMassImportCredentials();
        $headers = [
            'Client-Id: '.$clientId,
            'Authorization: Bearer '.$token,
        ];
        $categoryData = $this->adminFetchJson('https://api.twitch.tv/helix/search/categories?'.http_build_query([
            'query' => $category,
            'first' => 1,
        ]), $headers);
        $gameId = data_get($categoryData, 'data.0.id');
        if (! $gameId) {
            throw new \RuntimeException('No Twitch category matched this search.');
        }

        $after = $cursor ?: null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $query = [
                'game_id' => $gameId,
                'first' => 1,
                'sort' => 'time',
                'type' => 'archive',
                'period' => 'week',
            ];
            if ($after) {
                $query['after'] = $after;
            }
            $data = $this->adminFetchJson('https://api.twitch.tv/helix/videos?'.http_build_query($query), $headers);
            $item = $data['data'][0] ?? null;
            $after = data_get($data, 'pagination.cursor');
            $id = $item['id'] ?? null;
            if ($id) {
                $url = 'https://www.twitch.tv/videos/'.$id;
                if (! $this->massImportCandidateSeen($url, $exclude)) {
                    return ['url' => $url, 'title' => $item['title'] ?? null, 'cursor' => $after];
                }
            }
            if (! $after) {
                break;
            }
        }

        throw new \RuntimeException('No new Twitch videos were found for this search.');
    }

    private function twitchMassImportCredentials(): array
    {
        $apiKey = trim((string) data_get(VideoImportSettings::settings(), 'providers.twitch.apiKey', ''));
        $parts = preg_split('/[|:]/', $apiKey, 2);
        $clientId = trim((string) ($parts[0] ?? ''));
        $token = trim(preg_replace('/^Bearer\s+/i', '', (string) ($parts[1] ?? '')));

        if ($clientId === '' || $token === '') {
            throw new \RuntimeException('Twitch discovery requires "Client ID|App access token" in System Settings.');
        }

        return [$clientId, $token];
    }

    private function massImportCandidateSeen(string $url, array $exclude): bool
    {
        $normalized = $this->normalizeMassImportUrl($url);
        foreach ($exclude as $item) {
            if ($this->normalizeMassImportUrl((string) $item) === $normalized) {
                return true;
            }
        }

        return \App\Support\VideoImportIdentity::duplicate(\App\Support\VideoImportIdentity::keys(
            VideoImportSettings::detectProvider($url) ?? 'direct', $url)) !== null;
    }

    private function normalizeMassImportUrl(string $url): string
    {
        $keys = \App\Support\VideoImportIdentity::keys(VideoImportSettings::detectProvider($url) ?? 'direct', $url);

        return $keys['import_source_key'];
    }

    private function adminPostJson(string $url, array $payload, array $headers = []): array
    {
        $body = json_encode($payload);
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_USERAGENT => 'PlayHub Admin Mass Importer',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if (is_string($response) && $response !== '' && $status >= 200 && $status < 300) {
                $data = json_decode($response, true);

                return is_array($data) ? $data : [];
            }
            if (is_string($response) && $response !== '' && $status >= 400) {
                $data = json_decode($response, true);
                $message = data_get($data, 'error.message') ?: data_get($data, 'message') ?: data_get($data, 'error') ?: 'Provider discovery request failed.';
                throw new \RuntimeException((string) $message);
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'content' => $body,
                'timeout' => 12,
                'ignore_errors' => true,
                'user_agent' => 'PlayHub Admin Mass Importer',
                'header' => implode("\r\n", $headers),
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $data = is_string($response) && $response !== '' ? json_decode($response, true) : null;

        return is_array($data) ? $data : [];
    }

    private function adminFetchJson(string $url, array $headers = []): array
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_USERAGENT => 'PlayHub Admin Mass Importer',
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
                $data = json_decode($body, true);

                return is_array($data) ? $data : [];
            }
            if (is_string($body) && $body !== '' && $status >= 400) {
                $data = json_decode($body, true);
                $message = data_get($data, 'error.message') ?: data_get($data, 'message') ?: data_get($data, 'error') ?: 'Provider discovery request failed.';
                throw new \RuntimeException((string) $message);
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 12,
                'ignore_errors' => true,
                'user_agent' => 'PlayHub Admin Mass Importer',
                'header' => implode("\r\n", $headers),
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $data = is_string($body) && $body !== '' ? json_decode($body, true) : null;

        return is_array($data) ? $data : [];
    }

    private function adminFetchText(string $url, array $headers = []): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_USERAGENT => 'PlayHub Admin Mass Importer',
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
                return $body;
            }
            if ($status >= 400) {
                throw new \RuntimeException('Provider discovery request failed.');
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 12,
                'ignore_errors' => true,
                'user_agent' => 'PlayHub Admin Mass Importer',
                'header' => implode("\r\n", $headers),
            ],
        ]);
        $body = @file_get_contents($url, false, $context);

        return is_string($body) ? $body : '';
    }

    private function adminNotificationPayload(?int $pendingReports = null, ?int $failedJobs = null, ?int $failedUploads = null, ?int $queueCount = null, ?int $adminUserId = null, ?int $page = null, ?int $perPage = null): array
    {
        $paginated = $page !== null || $perPage !== null;
        $page = max((int) ($page ?? 1), 1);
        $perPage = min(max((int) ($perPage ?? 20), 1), 500);
        $pendingReports ??= $this->pendingReportsCount();
        $failedJobs ??= Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $failedUploads ??= Schema::hasTable('upload_sessions') ? DB::table('upload_sessions')->where('status', 'failed')->count() : 0;
        $queueCount ??= (Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0) + Video::query()->where('status', 'processing')->count();
        $items = [];

        if ($notice = $this->adminMassImportNotice($adminUserId)) {
            $items[] = $notice;
        }

        $pendingUploads = Video::query()->where('approval_status', 'pending')->count();
        if ($pendingUploads > 0) {
            $items[] = $this->adminNotificationItem('uploads-pending-review', 'content', 'Uploads pending review', $pendingUploads.' uploaded videos need approval.', 'warning', '/admin/content?status=Pending%20Review', $pendingUploads);
        }

        if (Schema::hasTable('wallet_transactions')) {
            $pendingWithdrawalSummary = DB::table('wallet_transactions')
                ->where('type', 'withdraw')
                ->where('status', 'pending')
                ->selectRaw('COUNT(*) as request_count, COALESCE(SUM(amount_cents), 0) as total_cents')
                ->first();
            $pendingWithdrawalCount = (int) ($pendingWithdrawalSummary->request_count ?? 0);
            if ($pendingWithdrawalCount > 0) {
                $staff = $adminUserId ? User::query()->find($adminUserId) : null;
                $target = $this->isAdmin($staff) ? '/admin/monetization' : '/admin/notifications';
                $items[] = $this->adminNotificationItem(
                    'withdrawals-pending',
                    'monetization',
                    'Withdrawal requests pending',
                    $pendingWithdrawalCount.' withdrawal request'.($pendingWithdrawalCount === 1 ? '' : 's').' totaling '.MonetizationSettings::formatMoney((int) ($pendingWithdrawalSummary->total_cents ?? 0)).' require approval.',
                    'warning',
                    $target,
                    $pendingWithdrawalCount
                );

                $pendingWithdrawals = DB::table('wallet_transactions')
                    ->where('type', 'withdraw')
                    ->where('status', 'pending')
                    ->latest()
                    ->limit($paginated ? min(max($pendingWithdrawalCount, 5), 250) : 5)
                    ->get();
                $creators = User::query()->with('channel')->whereIn('id', $pendingWithdrawals->pluck('user_id'))->get()->keyBy('id');
                foreach ($pendingWithdrawals as $pendingWithdrawal) {
                    $creator = $creators->get($pendingWithdrawal->user_id);
                    $creatorName = $creator?->channel?->name ?: $creator?->name ?: 'A creator';
                    $notification = $this->adminNotificationItem(
                        'withdrawal-'.$pendingWithdrawal->id,
                        'monetization',
                        'New withdrawal request',
                        $creatorName.' requested '.MonetizationSettings::formatMoney((int) $pendingWithdrawal->amount_cents).' via '.($pendingWithdrawal->method ?: 'wallet').'.',
                        'warning',
                        $target
                    );
                    $notification['time'] = $pendingWithdrawal->created_at ? Carbon::parse($pendingWithdrawal->created_at)->diffForHumans() : 'now';
                    $items[] = $notification;
                }
            }
        }

        if ($pendingReports > 0) {
            $items[] = $this->adminNotificationItem('reports-open', 'reports', 'Open moderation reports', $pendingReports.' reports require review.', $pendingReports >= 10 ? 'danger' : 'warning', '/admin/reports', $pendingReports);
            foreach ($this->reportRows($paginated ? min(max($pendingReports, 5), 250) : 5) as $report) {
                $items[] = $this->adminNotificationItem('report-'.$report['type'].'-'.$report['id'], 'reports', $report['type'].' report: '.$report['title'], $report['reason'].' · '.$report['reported_at'], $report['priority'] === 'High' ? 'danger' : 'warning', '/admin/reports');
            }
        }

        $capacity = $this->notificationStorageCapacity();
        if (($capacity['percent'] ?? 0) >= 85) {
            $items[] = $this->adminNotificationItem('storage-capacity', 'storage', $capacity['label'].' is almost full', $capacity['used'].' of '.$capacity['total'].' used.', $capacity['percent'] >= 95 ? 'danger' : 'warning', '/admin/system-settings', $capacity['percent']);
        } elseif (($capacity['total_bytes'] ?? 0) <= 0) {
            $items[] = $this->adminNotificationItem('storage-missing-capacity', 'storage', $capacity['label'].' capacity is not configured', 'Set the storage capacity in System Settings.', 'warning', '/admin/system-settings');
        }

        $load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0) : 0;
        if ($load >= 4 || $queueCount >= 25) {
            $items[] = $this->adminNotificationItem('website-slow', 'analytics', 'Website may be slow', 'Server load is '.number_format((float) $load, 2).' and '.$queueCount.' jobs/videos are queued or processing.', $load >= 8 || $queueCount >= 100 ? 'danger' : 'warning', '/admin/analytics', $queueCount);
        }

        if ($failedJobs > 0) {
            $items[] = $this->adminNotificationItem('failed-jobs', 'warning', 'Failed background jobs', $failedJobs.' failed jobs require attention.', 'danger', '/admin/support', $failedJobs);
        }

        $failedMailJobs = $this->failedJobNeedleCount(['mail', 'email', 'smtp', 'sendgrid']);
        if ($failedMailJobs > 0) {
            $items[] = $this->adminNotificationItem('failed-mail', 'support', 'Email delivery failed', $failedMailJobs.' mail jobs failed. Check SMTP/SendGrid settings and failed jobs.', 'danger', '/admin/system-settings', $failedMailJobs);
        } elseif (! IntegrationSettings::smtpEnabled() && ! $this->adminEmailDeliveryConfigured()) {
            $items[] = $this->adminNotificationItem('smtp-missing', 'support', 'Email delivery is not configured', 'SMTP or a supported mail driver is required for admin email and email OTP delivery.', 'warning', '/admin/system-settings');
        }

        $failedSmsJobs = $this->failedJobNeedleCount(['sms', 'twilio', 'vonage', 'messagebird']);
        if ($failedSmsJobs > 0) {
            $items[] = $this->adminNotificationItem('failed-sms', 'mobile', 'SMS delivery failed', $failedSmsJobs.' SMS jobs failed. Check the selected SMS OTP provider.', 'danger', '/admin/system-settings', $failedSmsJobs);
        } elseif (! IntegrationSettings::smsOtpEnabled()) {
            $items[] = $this->adminNotificationItem('sms-missing', 'mobile', 'SMS OTP is not configured', 'Phone-based OTP and phone login need an enabled SMS provider.', 'info', '/admin/system-settings');
        }

        $failedVideos = Video::query()->where('status', 'failed')->count();
        if ($failedVideos > 0 || $failedUploads > 0) {
            $items[] = $this->adminNotificationItem('video-processing-failed', 'content', 'Video processing failed', ($failedVideos + $failedUploads).' videos or upload sessions failed processing.', 'danger', '/admin/content', $failedVideos + $failedUploads);
        }

        $readIds = $adminUserId ? array_flip($this->adminNotificationReadIds($adminUserId)) : [];
        $items = collect($items)->unique('id')->map(function (array $item) use ($readIds): array {
            $item['unread'] = ! isset($readIds[$item['id']]);

            return $item;
        })->values()->all();
        $total = count($items);
        $lastPage = (int) max(1, ceil($total / $perPage));
        $page = min($page, $lastPage);
        $from = $total ? (($page - 1) * $perPage) + 1 : 0;

        $payload = [
            'count' => count($items),
            'critical_count' => collect($items)->where('tone', 'danger')->count(),
            'unread_count' => collect($items)->where('unread', true)->count(),
            'items' => array_slice($items, $paginated ? ($page - 1) * $perPage : 0, $paginated ? $perPage : 20),
            'generated_at' => now()->toIso8601String(),
        ];

        if ($paginated) {
            $payload += [
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => min($from + $perPage - 1, $total),
            ];
        }

        return $payload;
    }

    private function adminNotificationReadIds(int $adminUserId): array
    {
        $ids = Cache::get($this->adminNotificationReadKey($adminUserId), []);

        return is_array($ids) ? array_values(array_filter(array_map('strval', $ids))) : [];
    }

    private function saveAdminNotificationReadIds(int $adminUserId, array $ids): void
    {
        Cache::put($this->adminNotificationReadKey($adminUserId), array_values(array_unique(array_filter(array_map('strval', $ids)))), now()->addMonths(6));
    }

    private function forgetAdminNotificationReadId(int $adminUserId, string $id): void
    {
        $this->saveAdminNotificationReadIds($adminUserId, array_values(array_filter(
            $this->adminNotificationReadIds($adminUserId),
            fn (string $readId): bool => $readId !== $id
        )));
    }

    private function adminNotificationReadKey(int $adminUserId): string
    {
        return 'playhub:admin:notifications:read:'.$adminUserId;
    }

    private function adminNotificationItem(string $id, string $icon, string $title, string $message, string $tone, string $to, int|string|null $value = null): array
    {
        return compact('id', 'icon', 'title', 'message', 'tone', 'to', 'value') + ['time' => 'now'];
    }

    private function adminMassImportNotice(?int $adminUserId): ?array
    {
        if (! $adminUserId) {
            return null;
        }

        $notice = Cache::get($this->adminMassImportNoticeKey($adminUserId));
        if (! is_array($notice)) {
            return null;
        }

        $failed = (int) ($notice['failed'] ?? 0);
        $completedAt = ! empty($notice['completed_at']) ? Carbon::parse($notice['completed_at']) : now();
        $item = $this->adminNotificationItem(
            'mass-import-complete-'.$adminUserId,
            'content',
            $failed > 0 ? 'Mass import completed with errors' : 'Mass import completed',
            (string) ($notice['message'] ?? 'Mass import finished.'),
            $failed > 0 ? 'warning' : 'info',
            '/admin/content',
            (int) ($notice['succeeded'] ?? 0)
        );
        $item['time'] = $completedAt->diffForHumans();

        return $item;
    }

    private function adminMassImportNoticeKey(int $adminUserId): string
    {
        return 'playhub:admin:mass-import:'.$adminUserId;
    }

    private function notificationStorageCapacity(): array
    {
        $capacity = MediaStorage::capacitySummary();
        if (($capacity['used_bytes'] ?? null) === null && ($capacity['total_bytes'] ?? 0) > 0 && Schema::hasTable('upload_sessions')) {
            $usedBytes = (int) DB::table('upload_sessions')->whereIn('status', ['assembling', 'processing', 'completed'])->sum('file_size');
            $capacity['used_bytes'] = $usedBytes;
            $capacity['used'] = $this->formatBytes($usedBytes);
            $capacity['percent'] = (int) min(100, round(($usedBytes / max(1, (int) $capacity['total_bytes'])) * 100));
        }

        return [
            'label' => $capacity['provider_label'] ?? 'Storage',
            'total_bytes' => (int) ($capacity['total_bytes'] ?? 0),
            'used' => $capacity['used'] ?? 'Not available',
            'total' => $capacity['total'] ?? 'Not configured',
            'percent' => (int) ($capacity['percent'] ?? 0),
        ];
    }

    private function failedJobNeedleCount(array $needles): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return DB::table('failed_jobs')->where(function ($query) use ($needles): void {
            foreach ($needles as $needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->orWhere('payload', 'like', $like)->orWhere('exception', 'like', $like);
            }
        })->count();
    }

    private function adminSearchResults(string $search): array
    {
        if ($search === '') {
            return [];
        }

        $like = '%'.addcslashes($search, '%_\\').'%';
        $results = collect($this->adminSearchStaticResults($search));

        User::query()
            ->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)->orWhere('username', 'like', $like)->orWhere('email', 'like', $like);
            })
            ->latest()
            ->limit(6)
            ->get()
            ->each(fn (User $user) => $results->push([
                'id' => 'user-'.$user->id,
                'type' => 'User',
                'title' => $user->name,
                'subtitle' => '@'.$user->username.' · '.$user->email,
                'icon' => 'users',
                'to' => '/admin/users?search='.rawurlencode($user->email ?: $user->username ?: $user->name).'&highlight=user-'.$user->id.'#user-'.$user->id,
            ]));

        Channel::query()
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('slug', 'like', $like)->orWhere('bio', 'like', $like))
            ->latest()
            ->limit(5)
            ->get()
            ->each(fn (Channel $channel) => $results->push([
                'id' => 'creator-'.$channel->id,
                'type' => 'Creator',
                'title' => $channel->name,
                'subtitle' => '@'.$channel->slug,
                'icon' => 'creators',
                'to' => '/channel/'.$channel->slug,
            ]));

        Video::query()
            ->with('channel')
            ->where(fn ($query) => $query->where('title', 'like', $like)->orWhere('description', 'like', $like)->orWhere('slug', 'like', $like))
            ->latest()
            ->limit(6)
            ->get()
            ->each(fn (Video $video) => $results->push([
                'id' => 'video-'.$video->id,
                'type' => 'Content',
                'title' => $video->title,
                'subtitle' => ($video->channel?->name ?: 'Unknown creator').' · '.$video->status,
                'icon' => 'content',
                'to' => $this->videoPlaybackPath($video),
            ]));

        Comment::query()
            ->with(['user', 'video'])
            ->where('body', 'like', $like)
            ->latest()
            ->limit(5)
            ->get()
            ->each(function (Comment $comment) use ($results): void {
                $video = $comment->video;
                $results->push([
                    'id' => 'comment-'.$comment->id,
                    'type' => 'Comment',
                    'title' => $this->shortText($comment->body ?: 'Comment', 80),
                    'subtitle' => ($comment->user?->name ?: 'User').($video?->title ? ' · '.$this->shortText($video->title, 48) : ''),
                    'icon' => 'support',
                    'to' => $this->videoPlaybackPath($video, '/admin/reports').'#comment-'.$comment->id,
                ]);
            });

        foreach ($this->reportRows(20) as $report) {
            if (str_contains(strtolower($report['title'].' '.$report['reason'].' '.$report['reporter']), strtolower($search))) {
                $results->push([
                    'id' => 'report-'.$report['type'].'-'.$report['id'],
                    'type' => 'Report',
                    'title' => $report['title'],
                    'subtitle' => $report['reason'].' · '.$report['reported_at'],
                    'icon' => 'reports',
                    'to' => '/admin/reports?search='.rawurlencode($report['title']).'&highlight=report-'.strtolower($report['type']).'-'.$report['id'].'#report-'.strtolower($report['type']).'-'.$report['id'],
                ]);
            }
        }

        if (Schema::hasTable('contact_messages')) {
            DB::table('contact_messages')
                ->where('status', 'open')
                ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('subject', 'like', $like)->orWhere('message', 'like', $like))
                ->latest()
                ->limit(5)
                ->get()
                ->each(fn ($row) => $results->push([
                    'id' => 'support-'.$row->id,
                    'type' => 'Support',
                    'title' => $row->subject ?: 'Support message',
                    'subtitle' => ($row->name ?: $row->email).' · '.($row->status ?? 'open'),
                    'icon' => 'support',
                    'to' => '/admin/support?search='.rawurlencode($row->subject ?: $row->email ?: $row->name).'&highlight=support-'.$row->id.'#support-'.$row->id,
                ]));
        }

        return $results->unique('id')->take(30)->values()->all();
    }

    private function videoPlaybackPath(?Video $video, string $fallback = '/admin/content'): string
    {
        if (! $video?->slug) {
            return $fallback;
        }
        if ($video->source_type === 'live_replay') {
            $liveStreamSlug = LiveStream::query()->where('replay_video_id', $video->id)->value('slug');
            if ($liveStreamSlug) {
                return '/live/'.$liveStreamSlug;
            }
        }

        return ($video->isShort() ? '/shorts/' : '/watch/').$video->slug;
    }

    private function adminSearchStaticResults(string $search): array
    {
        $rows = [
            ['Dashboard', 'Platform metrics and health', 'dashboard', '/admin/dashboard'],
            ['Users', 'User accounts, roles, status, verification', 'users', '/admin/users'],
            ['Creators', 'Channels, creator verification, creator growth', 'creators', '/admin/creators'],
            ['Content', 'Videos, shorts, processing, failed videos', 'content', '/admin/content'],
            ['Reports', 'Moderation reports and abuse alerts', 'reports', '/admin/reports'],
            ['Notifications', 'System alerts, reports, email and SMS warnings', 'bell', '/admin/notifications'],
            ['Website Configuration', 'Branding, colors, typography, logo, favicon and sidebar behavior', 'globe', '/admin/website-configuration'],
            ['Pages', 'Localized About, FAQ, support, policy and legal page content', 'content', '/admin/pages'],
            ['SEO & Sitemaps', 'Search metadata, indexing, robots.txt, XML sitemaps and site verification', 'search', '/admin/seo'],
            ['Storage settings', 'Local storage, cloud storage, packages and quotas', 'storage', '/admin/system-settings?section=storage&highlight=setting-storage#setting-storage'],
            ['SMTP Email', 'SMTP email configuration and test delivery', 'support', '/admin/system-settings?section=smtp&highlight=setting-smtp#setting-smtp'],
            ['SMS OTP', 'Twilio, Vonage and MessageBird provider settings', 'mobile', '/admin/system-settings?section=sms&highlight=setting-sms#setting-sms'],
            ['Registration settings', 'Enable registration and block registration IPs', 'shield', '/admin/system-settings?section=registration&highlight=setting-registration#setting-registration'],
            ['Login settings', 'Email, username, phone login and bad login ban time', 'shield', '/admin/system-settings?section=login&highlight=setting-login#setting-login'],
            ['Live streaming', 'Enable or disable live streaming on the public website', 'live', '/admin/system-settings?section=live-streaming&highlight=setting-live-streaming#setting-live-streaming'],
            ['Streaming Engine', 'LiveKit connectivity, RTMP ingress, network, SSL and TURN settings', 'live', '/admin/system-settings?section=live-streaming&highlight=setting-streaming-engine#setting-streaming-engine'],
            ['Docker Configuration', 'LiveKit Compose, containers, runtime files, status and logs', 'storage', '/admin/system-settings?section=live-streaming&highlight=setting-docker-configuration#setting-docker-configuration'],
            ['Player ads', 'VAST ad settings', 'ad', '/admin/system-settings?section=ads&highlight=setting-ads#setting-ads'],
            ['Video import', 'YouTube, Vimeo, Dailymotion and direct URL import', 'cloud', '/admin/system-settings?section=video-import&highlight=setting-video-import#setting-video-import'],
            ['Support', 'Support tickets and failed system tasks', 'support', '/admin/support'],
        ];
        $needle = strtolower($search);

        return collect($rows)
            ->filter(fn (array $row): bool => str_contains(strtolower($row[0].' '.$row[1]), $needle))
            ->sortByDesc(fn (array $row): int => str_contains(strtolower($row[0]), $needle) ? 1 : 0)
            ->values()
            ->map(fn (array $row, int $index): array => [
                'id' => 'static-'.$index,
                'type' => 'Admin',
                'title' => $row[0],
                'subtitle' => $row[1],
                'icon' => $row[2],
                'to' => $row[3],
            ])
            ->values()
            ->all();
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $end = now();
        $start = $end->copy()->subDays(27)->startOfDay();
        $previousStart = $start->copy()->subDays(28);
        $previousEnd = $start->copy()->subSecond();

        $newUsersSeries = $this->dailyCounts('users', $start, $end);
        $activeUsersSeries = $this->activeUsersSeries($start, $end);
        $uploadSeries = $this->dailyCounts('videos', $start, $end);
        $watchHoursSeries = $this->dailyWatchHours($start, $end);
        $reportSeries = $this->reportDailyCounts($start, $end);
        $pinnedSeries = $this->dailyCounts('comments', $start, $end, fn ($query) => $query->where('is_pinned', true));

        $pendingReports = $this->pendingReportsCount();
        $currentReports = $this->totalReportsBetween($start, $end);
        $previousReports = $this->totalReportsBetween($previousStart, $previousEnd);
        $processingJobs = DB::table('jobs')->count() + Video::query()->where('status', 'processing')->count();

        return response()->json([
            'date_range' => $start->format('M j').' - '.$end->format('M j, Y'),
            'chart_dates' => $this->chartDateLabels($start, $end),
            'metrics' => [
                $this->metric(
                    'Total Users',
                    User::query()->count(),
                    User::query()->whereBetween('created_at', [$start, $end])->count(),
                    User::query()->whereBetween('created_at', [$previousStart, $previousEnd])->count(),
                    'violet',
                    'users',
                    $newUsersSeries
                ),
                $this->metric(
                    'Active Creators',
                    Channel::query()->whereHas('videos', fn ($query) => $query->whereBetween('created_at', [$start, $end]))->count(),
                    Channel::query()->whereHas('videos', fn ($query) => $query->whereBetween('created_at', [$start, $end]))->count(),
                    Channel::query()->whereHas('videos', fn ($query) => $query->whereBetween('created_at', [$previousStart, $previousEnd]))->count(),
                    'violet',
                    'creators',
                    $activeUsersSeries
                ),
                $this->metric(
                    'Published Videos',
                    Video::query()->where('status', 'ready')->count(),
                    Video::query()->where('status', 'ready')->whereBetween('created_at', [$start, $end])->count(),
                    Video::query()->where('status', 'ready')->whereBetween('created_at', [$previousStart, $previousEnd])->count(),
                    'slate',
                    'content',
                    $uploadSeries
                ),
                $this->metric(
                    'Live Now',
                    Video::query()->where('status', 'live')->count(),
                    Video::query()->where('status', 'live')->whereBetween('created_at', [$start, $end])->count(),
                    Video::query()->where('status', 'live')->whereBetween('created_at', [$previousStart, $previousEnd])->count(),
                    'red',
                    'live',
                    $this->dailyCounts('videos', $start, $end, fn ($query) => $query->where('status', 'live'))
                ),
                $this->metric(
                    'Reports Pending',
                    $pendingReports,
                    $currentReports,
                    $previousReports,
                    'blue',
                    'shield',
                    $reportSeries,
                    $currentReports > $previousReports
                ),
                [
                    'label' => 'Monthly Revenue',
                    'value' => $this->formatMoney(0),
                    'raw' => 0,
                    'change' => '0%',
                    'tone' => 'slate',
                    'icon' => 'monetization',
                    'danger' => false,
                    'points' => array_slice(array_pad([], 12, 0), -12),
                ],
            ],
            'charts' => [
                [
                    'title' => 'Platform Growth',
                    'info' => true,
                    'yLabels' => $this->yLabels(array_merge($newUsersSeries, $activeUsersSeries)),
                    'legends' => [
                        ['label' => 'New Users', 'tone' => 'primary'],
                        ['label' => 'Active Users', 'tone' => 'secondary'],
                    ],
                    'series' => [
                        ['tone' => 'primary', 'points' => $newUsersSeries],
                        ['tone' => 'secondary', 'points' => $activeUsersSeries],
                    ],
                ],
                [
                    'title' => 'Watch Time (Hours)',
                    'info' => true,
                    'value' => $this->formatCompact((int) round(array_sum($watchHoursSeries))),
                    'change' => $this->percentChange(array_sum($watchHoursSeries), array_sum($this->dailyWatchHours($previousStart, $previousEnd))),
                    'yLabels' => $this->yLabels($watchHoursSeries),
                    'series' => [['tone' => 'primary', 'area' => true, 'points' => $watchHoursSeries]],
                ],
                [
                    'title' => 'Uploads',
                    'info' => true,
                    'value' => $this->formatCompact((int) array_sum($uploadSeries)),
                    'change' => $this->percentChange(array_sum($uploadSeries), array_sum($this->dailyCounts('videos', $previousStart, $previousEnd))),
                    'yLabels' => $this->yLabels($uploadSeries),
                    'series' => [['tone' => 'primary', 'area' => true, 'points' => $uploadSeries]],
                ],
                [
                    'title' => 'Moderation Actions',
                    'info' => true,
                    'yLabels' => $this->yLabels(array_merge($reportSeries, $pinnedSeries)),
                    'legends' => [
                        ['label' => 'New Reports', 'tone' => 'danger'],
                        ['label' => 'Pinned Comments', 'tone' => 'warning'],
                    ],
                    'series' => [
                        ['tone' => 'danger', 'area' => true, 'points' => $reportSeries],
                        ['tone' => 'warning', 'points' => $pinnedSeries],
                    ],
                ],
            ],
            'reports' => [
                'total' => $pendingReports,
                'rows' => $this->reportRows(5),
            ],
            'top_creators' => $this->topCreators($start, $end),
            'system_health' => $this->systemHealth($processingJobs),
            'signups' => $this->recentSignups(),
            'support_flags' => $this->supportFlags($pendingReports, $processingJobs),
            'abuse_alerts_count' => $pendingReports,
            'notifications_count' => DB::table('user_notifications')->whereNull('read_at')->count(),
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);
        $total = $this->totalReportsCount();

        return response()->json([
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) max(1, ceil($total / $perPage)),
            'rows' => $this->reportRows($perPage, ($page - 1) * $perPage),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);
        $query = User::query()->with('channel');
        $search = trim((string) $request->query('search', ''));
        $tab = strtolower(str_replace(['_', '-'], ' ', trim((string) $request->query('tab', ''))));
        $role = $this->normalizeAdminUserRole($request->query('role'));
        $status = $this->normalizeAdminUserStatus($request->query('status'));
        $verification = strtolower(trim((string) $request->query('verification', '')));
        $domain = strtolower(ltrim(trim((string) $request->query('email_domain', '')), '@'));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->where('name', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('channel', fn ($channel) => $channel
                        ->where('name', 'like', $like)
                        ->orWhere('slug', 'like', $like));
            });
        }

        if ($tab === 'active') {
            $this->applyAdminUserStatusFilter($query, 'active');
        } elseif ($tab === 'creators') {
            $this->applyAdminUserRoleFilter($query, 'creator');
        } elseif ($tab === 'suspended') {
            $this->applyAdminUserStatusFilter($query, 'suspended');
        } elseif ($tab === 'verified') {
            $query->whereNotNull('email_verified_at');
        }

        if ($request->query('role') && strtolower((string) $request->query('role')) !== 'all') {
            $this->applyAdminUserRoleFilter($query, $role);
        }
        if ($request->query('status') && strtolower((string) $request->query('status')) !== 'all') {
            $this->applyAdminUserStatusFilter($query, $status);
        }
        if ($verification === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif ($verification === 'unverified') {
            $query->whereNull('email_verified_at');
        }
        if ($domain !== '') {
            $query->where('email', 'like', '%@'.$domain);
        }
        if ($request->filled('joined_after')) {
            $query->whereDate('created_at', '>=', $request->date('joined_after')->toDateString());
        }
        if ($request->filled('joined_before')) {
            $query->whereDate('created_at', '<=', $request->date('joined_before')->toDateString());
        }

        $paginator = $query->latest('created_at')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'rows' => $paginator->getCollection()->map(fn (User $user): array => $this->adminUserPayload($user))->values(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem() ?: 0,
            'to' => $paginator->lastItem() ?: 0,
        ]);
    }

    public function content(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);
        $query = $this->adminContentQuery($request);

        $paginator = $query->latest('created_at')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'rows' => $paginator->getCollection()->map(fn (Video $video): array => $this->adminContentPayload($video))->values(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem() ?: 0,
            'to' => $paginator->lastItem() ?: 0,
        ]);
    }

    public function liveStreams(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);
        $query = LiveStream::query()->with(['channel.user', 'category']);
        $search = trim((string) $request->input('search', ''));
        $status = strtolower(trim((string) $request->input('status', '')));
        $category = trim((string) $request->input('category', ''));
        $health = strtolower(trim((string) $request->input('health', '')));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('title', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereRaw('CAST(tags AS CHAR) like ?', [$like])
                    ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', $like))
                    ->orWhereHas('channel', fn ($channelQuery) => $channelQuery
                        ->where('name', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->where('name', 'like', $like)
                            ->orWhere('username', 'like', $like)
                            ->orWhere('email', 'like', $like)));
            });
        }
        if (in_array($status, ['live', 'scheduled', 'ended'], true)) {
            $query->where('status', $status);
        }
        if ($category !== '' && strtolower($category) !== 'all') {
            $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', $category));
        }
        if ($health === 'ready') {
            $query->whereNotNull('preflight_tested_at')->where('preflight->ready', true);
        } elseif ($health === 'needs attention') {
            $query->whereNotNull('preflight_tested_at')->where(fn ($builder) => $builder->whereNull('preflight->ready')->orWhere('preflight->ready', false));
        } elseif ($health === 'not tested') {
            $query->whereNull('preflight_tested_at');
        }

        $paginator = $query->latest('scheduled_at')->latest()->paginate($perPage, ['*'], 'page', $page);
        $streams = $paginator->getCollection();
        [$roomViewerCounts, $streamViewerCounts] = $this->adminLiveViewerCounts($streams);

        return response()->json([
            'rows' => $streams->map(fn (LiveStream $stream): array => $this->adminLivePayload($stream, $roomViewerCounts, $streamViewerCounts))->values(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem() ?: 0,
            'to' => $paginator->lastItem() ?: 0,
        ]);
    }

    public function deleteLiveStream(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeStaff($request);
        $liveStream->loadMissing('replayVideo');
        $video = $liveStream->replayVideo;
        $recording = (array) data_get($liveStream->stream_settings, '_recording', []);
        $title = trim((string) $liveStream->title) ?: 'Untitled live stream';
        $paths = array_filter([
            $liveStream->thumbnail_path,
            $recording['local_path'] ?? null,
            $recording['media_path'] ?? null,
            $video?->video_path,
            $video?->thumbnail_path,
            $video?->captions_path,
            ...collect($video?->renditions ?: [])->pluck('path')->all(),
        ]);

        if (($recording['status'] ?? null) === 'recording' && filled($recording['egress_id'] ?? null)) {
            try {
                LiveKitSettings::stopEgress((string) $recording['egress_id'], (string) ($recording['room'] ?? 'playhub-live-'.$liveStream->uuid));
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        if ($liveStream->livekit_ingress_id) {
            try {
                LiveKitSettings::deleteIngress($liveStream->livekit_ingress_id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        if (in_array($liveStream->status, ['waiting-for-source', 'live'], true)) {
            try {
                LiveKitSettings::deleteRoom('playhub-live-'.$liveStream->uuid);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        MediaStorage::delete(array_values(array_unique($paths)));
        DB::transaction(function () use ($liveStream, $video): void {
            if ($video) {
                $video->channel()->decrement('videos_count');
                $video->delete();
            }
            $liveStream->delete();
        });
        $this->recordAdminActivity($request->user(), 'Deleted live stream '.$title);

        return response()->json(['message' => 'Live stream deleted.']);
    }

    private function adminContentQuery(Request $request, bool $withRelations = true)
    {
        $query = Video::query();
        if ($withRelations) {
            $query->with(['channel.user', 'category']);
        }

        $search = trim((string) $request->input('search', ''));
        $tab = strtolower(str_replace(['_', '-'], ' ', trim((string) $request->input('tab', ''))));
        $type = strtolower(str_replace(['_', '-'], ' ', trim((string) $request->input('type', ''))));
        $category = trim((string) $request->input('category', ''));
        $visibility = strtolower(trim((string) $request->input('visibility', '')));
        $status = strtolower(str_replace(['_', '-'], ' ', trim((string) $request->input('status', ''))));
        $minViews = $request->filled('min_views') ? max(0, (int) $request->integer('min_views')) : null;

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->where('title', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereRaw('CAST(tags AS CHAR) like ?', [$like])
                    ->orWhere('processing_stage', 'like', $like)
                    ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', $like)->orWhere('slug', 'like', $like))
                    ->orWhereHas('channel', fn ($channelQuery) => $channelQuery
                        ->where('name', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->where('name', 'like', $like)
                            ->orWhere('username', 'like', $like)
                            ->orWhere('email', 'like', $like)));
            });
        }

        $contentType = $type !== '' && $type !== 'all' ? $type : $tab;
        if ($contentType === 'pending review') {
            $query->where('approval_status', 'pending');
        } elseif (in_array($contentType, ['videos', 'video'], true)) {
            $query->standardVideos();
        } elseif (in_array($contentType, ['shorts', 'short'], true)) {
            $query->shorts();
        } elseif (in_array($contentType, ['live replays', 'live replay'], true)) {
            $query->where('source_type', 'live_replay');
        } elseif ($contentType === 'live') {
            $query->where('status', 'live');
        }

        if ($category !== '' && strtolower($category) !== 'all') {
            if (ctype_digit($category)) {
                $query->where('category_id', (int) $category);
            } elseif (strtolower($category) === 'uncategorized') {
                $query->whereNull('category_id');
            } else {
                $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', $category));
            }
        }

        if (in_array($visibility, ['public', 'unlisted', 'private'], true)) {
            $query->where('visibility', $visibility);
        }

        if ($status === 'pending review') {
            $query->where('approval_status', 'pending');
        } elseif ($status === 'scheduled') {
            $query->whereNotNull('scheduled_at')->where('scheduled_at', '>', now());
        } elseif ($status !== '' && $status !== 'all') {
            $query->where('status', match ($status) {
                'published' => 'ready',
                'live replay' => 'ended',
                default => $status,
            });
        }
        if ($request->filled('uploaded_after')) {
            $query->whereDate('created_at', '>=', $request->date('uploaded_after')->toDateString());
        }
        if ($request->filled('uploaded_before')) {
            $query->whereDate('created_at', '<=', $request->date('uploaded_before')->toDateString());
        }
        if ($minViews !== null) {
            $query->where('views_count', '>=', $minViews);
        }

        return $query;
    }

    public function dismissCommentReports(Request $request, Comment $comment): JsonResponse
    {
        $this->authorizeStaff($request);

        $count = DB::table('comment_reports')->where('comment_id', $comment->id)->delete();

        return response()->json([
            'message' => $count ? 'Reports dismissed.' : 'No reports were open for this comment.',
            'dismissed' => $count,
        ]);
    }

    public function dismissUserReports(Request $request, User $user): JsonResponse
    {
        $this->authorizeStaff($request);

        $count = $this->userReportsEnabled()
            ? DB::table('user_reports')->where('reported_user_id', $user->id)->delete()
            : 0;

        return response()->json([
            'message' => $count ? 'Reports dismissed.' : 'No reports were open for this profile.',
            'dismissed' => $count,
        ]);
    }

    public function deleteReportedComment(Request $request, Comment $comment): JsonResponse
    {
        $this->authorizeStaff($request);

        $comment->loadMissing('commentable');
        $thread = $comment->threadModels();
        $commentIds = $thread->pluck('id')->all();
        $paths = $thread
            ->flatMap(fn (Comment $item) => collect($item->attachments ?? [])->pluck('path'))
            ->filter()
            ->values()
            ->all();

        MediaStorage::delete($paths);
        DB::transaction(function () use ($comment, $commentIds): void {
            DB::table('comment_reports')->whereIn('comment_id', $commentIds)->delete();
            $deletedCount = count($commentIds);

            if ($comment->video_id) {
                $video = Video::query()->lockForUpdate()->find($comment->video_id);
                $video?->update(['comments_count' => max(0, (int) $video->comments_count - $deletedCount)]);
            } elseif ($comment->commentable instanceof CommunityPost) {
                $post = CommunityPost::query()->lockForUpdate()->find($comment->commentable_id);
                $post?->update(['comments_count' => max(0, (int) $post->comments_count - $deletedCount)]);
            }

            $comment->delete();
        });

        return response()->json(['message' => 'Reported comment deleted.']);
    }

    public function updateVideoVisibility(Request $request, Video $video): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'visibility' => ['required', 'string', Rule::in(['public', 'unlisted', 'private'])],
        ]);

        $publishable = $video->status === 'ready' && ! ($video->scheduled_at && $video->scheduled_at->isFuture());
        $video->update([
            'requested_visibility' => $data['visibility'],
            'visibility' => $publishable ? $data['visibility'] : 'private',
            'approval_status' => 'approved',
            'published_at' => $publishable ? ($video->published_at ?: now()) : $video->published_at,
        ]);

        return response()->json([
            'message' => 'Video visibility updated.',
            'visibility' => ucfirst($data['visibility']),
        ]);
    }

    public function approveVideo(Request $request, Video $video): JsonResponse
    {
        $this->authorizeStaff($request);
        $this->approveVideoRecord($video);

        return response()->json([
            'message' => 'Video approved.',
            'video' => $this->adminContentPayload($video->fresh(['channel.user', 'category'])),
        ]);
    }

    public function deleteVideo(Request $request, Video $video): JsonResponse
    {
        $this->authorizeStaff($request);
        $this->deleteVideoRecord($video);

        return response()->json(['message' => 'Video deleted.']);
    }

    public function bulkUpdateVideoVisibility(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct', 'exists:videos,id'],
            'visibility' => ['required', 'string', Rule::in(['public', 'unlisted', 'private'])],
        ]);

        $updated = 0;
        Video::query()->whereIn('id', $data['ids'])->get()->each(function (Video $video) use ($data, &$updated): void {
            $publishable = $video->status === 'ready' && ! ($video->scheduled_at && $video->scheduled_at->isFuture());
            $video->update([
                'requested_visibility' => $data['visibility'],
                'visibility' => $publishable ? $data['visibility'] : 'private',
                'approval_status' => 'approved',
                'published_at' => $publishable ? ($video->published_at ?: now()) : $video->published_at,
            ]);
            $updated++;
        });

        return response()->json(['message' => $updated.' video(s) updated.', 'updated' => $updated]);
    }

    public function bulkUpdateVideoCategory(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'all' => ['nullable', 'boolean'],
            'ids' => [$request->boolean('all') ? 'nullable' : 'required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct', 'exists:videos,id'],
            'category_id' => ['nullable', 'integer', Rule::exists('video_categories', 'id')],
        ]);

        $categoryId = $data['category_id'] ?? null;
        $updated = $request->boolean('all')
            ? $this->adminContentQuery($request, false)->update(['category_id' => $categoryId])
            : Video::query()->whereIn('id', $data['ids'])->update(['category_id' => $categoryId]);

        return response()->json(['message' => 'Video categories updated.', 'updated' => $updated]);
    }

    public function createContentCategory(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('video_categories', 'name')],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $name = trim((string) $data['name']);
        $baseSlug = Str::slug($name) ?: 'category';
        $slug = $baseSlug;
        $suffix = 2;
        while (DB::table('video_categories')->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        $sortOrder = array_key_exists('sort_order', $data) && $data['sort_order'] !== null
            ? (int) $data['sort_order']
            : ((int) DB::table('video_categories')->max('sort_order')) + 10;

        $id = DB::table('video_categories')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Content category created.',
            'category' => ['id' => (int) $id, 'name' => $name, 'slug' => $slug, 'sort_order' => $sortOrder],
        ], 201);
    }

    public function updateContentCategory(Request $request, int $category): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(DB::table('video_categories')->where('id', $category)->exists(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('video_categories', 'name')->ignore($category)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $name = trim((string) $data['name']);
        $baseSlug = Str::slug($name) ?: 'category';
        $slug = $baseSlug;
        $suffix = 2;
        while (DB::table('video_categories')->where('slug', $slug)->where('id', '!=', $category)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        $sortOrder = array_key_exists('sort_order', $data) && $data['sort_order'] !== null
            ? (int) $data['sort_order']
            : ((int) DB::table('video_categories')->where('id', $category)->value('sort_order'));

        DB::table('video_categories')->where('id', $category)->update([
            'name' => $name,
            'slug' => $slug,
            'sort_order' => $sortOrder,
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Content category updated.',
            'category' => ['id' => $category, 'name' => $name, 'slug' => $slug, 'sort_order' => $sortOrder],
        ]);
    }

    public function deleteContentCategory(Request $request, int $category): JsonResponse
    {
        $this->authorizeAdmin($request);

        $uncategorizedVideos = DB::transaction(function () use ($category): int {
            $record = DB::table('video_categories')->where('id', $category)->lockForUpdate()->first();
            abort_unless($record, 404);

            // Preserve videos even on older installations without the nullable foreign key.
            $updated = DB::table('videos')->where('category_id', $category)->update(['category_id' => null]);
            DB::table('video_categories')->where('id', $category)->delete();

            return $updated;
        });

        return response()->json([
            'message' => 'Content category deleted. Existing videos were kept and are now uncategorized.',
            'uncategorized_videos' => $uncategorizedVideos,
        ]);
    }

    public function bulkApproveVideos(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct', 'exists:videos,id'],
        ]);

        $updated = 0;
        Video::query()->whereIn('id', $data['ids'])->get()->each(function (Video $video) use (&$updated): void {
            $this->approveVideoRecord($video);
            $updated++;
        });

        return response()->json(['message' => $updated.' video(s) approved.', 'updated' => $updated]);
    }

    public function bulkDeleteVideos(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'all' => ['nullable', 'boolean'],
            'ids' => [$request->boolean('all') ? 'nullable' : 'required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct', 'exists:videos,id'],
        ]);

        $deleted = 0;
        if ($request->boolean('all')) {
            $this->authorizeAdmin($request);
            $this->adminContentQuery($request, false)
                ->chunkById(100, function ($videos) use (&$deleted): void {
                    $videos->each(function (Video $video) use (&$deleted): void {
                        $this->deleteVideoRecord($video);
                        $deleted++;
                    });
                });
        } else {
            Video::query()->whereIn('id', $data['ids'])->get()->each(function (Video $video) use (&$deleted): void {
                $this->deleteVideoRecord($video);
                $deleted++;
            });
        }

        return response()->json(['message' => $deleted.' video(s) deleted.', 'deleted' => $deleted]);
    }

    public function dismissVideoReports(Request $request, Video $video): JsonResponse
    {
        $this->authorizeStaff($request);

        $count = $this->videoReportsEnabled()
            ? DB::table('video_reports')->where('video_id', $video->id)->delete()
            : 0;

        return response()->json([
            'message' => $count ? 'Reports dismissed.' : 'No reports were open for this video.',
            'dismissed' => $count,
        ]);
    }

    public function deleteReportedVideo(Request $request, Video $video): JsonResponse
    {
        $this->authorizeStaff($request);
        $this->deleteVideoRecord($video);

        return response()->json(['message' => 'Reported video deleted.']);
    }

    public function resolveContactMessage(Request $request, int $message): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(Schema::hasTable('contact_messages'), 404);

        $updated = DB::table('contact_messages')
            ->where('id', $message)
            ->update(['status' => 'resolved', 'updated_at' => now()]);

        abort_unless($updated > 0, 404);

        return response()->json(['message' => 'Support message resolved.']);
    }

    public function updateContactMessageStatus(Request $request, int $message): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(Schema::hasTable('contact_messages'), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'waiting', 'resolved'])],
        ]);
        $updated = DB::table('contact_messages')->where('id', $message)->update([
            'status' => $data['status'],
            'read_at' => now(),
            'updated_at' => now(),
        ]);
        abort_unless($updated > 0 || DB::table('contact_messages')->where('id', $message)->exists(), 404);

        return response()->json(['message' => 'Support ticket status updated.']);
    }

    public function showContactMessage(Request $request, int $message): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(Schema::hasTable('contact_messages'), 404);

        $row = DB::table('contact_messages')->where('id', $message)->first();
        abort_unless($row, 404);

        if (! $row->read_at) {
            DB::table('contact_messages')->where('id', $message)->update(['read_at' => now()]);
            $row = DB::table('contact_messages')->where('id', $message)->first();
        }

        return response()->json(['ticket' => $this->contactMessageDetail($row)]);
    }

    public function replyContactMessage(Request $request, int $message): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(Schema::hasTable('contact_messages'), 404);

        $data = $request->validate([
            'response' => ['required', 'string', 'min:2', 'max:5000'],
            'resolve' => ['nullable', 'boolean'],
        ]);

        $row = DB::table('contact_messages')->where('id', $message)->first();
        abort_unless($row, 404);

        $resolve = (bool) ($data['resolve'] ?? true);
        DB::table('contact_messages')->where('id', $message)->update([
            'admin_response' => $data['response'],
            'responded_by' => $request->user()?->id,
            'responded_at' => now(),
            'read_at' => $row->read_at ?: now(),
            'status' => $resolve ? 'resolved' : 'open',
            'updated_at' => now(),
        ]);

        $mailSent = false;
        $mailError = null;
        if ($row->email && $this->adminEmailDeliveryConfigured()) {
            try {
                $subject = 'Re: '.($row->subject ?: 'Your '.config('app.name', 'PlayHub').' support request');
                Mail::raw($data['response'], function ($message) use ($row, $subject, $data): void {
                    BrandedEmail::apply($message, $subject, $data['response']);
                    $message->to($row->email, $row->name ?: null)->subject($subject);
                });
                $mailSent = true;
            } catch (\Throwable $exception) {
                report($exception);
                $mailError = 'Email delivery failed, but the response was saved.';
            }
        }

        $ticket = DB::table('contact_messages')->where('id', $message)->first();

        return response()->json([
            'message' => $mailSent ? 'Support response sent.' : ($mailError ?: 'Support response saved. Email delivery is not configured.'),
            'mail_sent' => $mailSent,
            'ticket' => $this->contactMessageDetail($ticket),
        ]);
    }

    public function exportUsers(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Name', 'Username', 'Email', 'Role', 'Status', 'Email Verified', 'Signup Date', 'Last Updated']);

            User::query()
                ->with('channel')
                ->orderBy('id')
                ->chunk(250, function ($users) use ($out): void {
                    foreach ($users as $user) {
                        fputcsv($out, [
                            $user->id,
                            $user->name,
                            $user->username,
                            $user->email,
                            $this->displayUserRole($user),
                            $this->displayUserStatus($user),
                            $user->email_verified_at ? 'Yes' : 'No',
                            optional($user->created_at)->toDateTimeString(),
                            optional($user->updated_at)->toDateTimeString(),
                        ]);
                    }
                });

            fclose($out);
        }, 'playhub-users-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportCreators(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Channel Name', 'Handle', 'Owner Name', 'Owner Email', 'Subscribers', 'Videos', 'Total Views', 'Verified', 'Created Date']);

            Channel::query()
                ->with('user')
                ->withCount('videos')
                ->withSum('videos as total_views', 'views_count')
                ->orderBy('id')
                ->chunk(250, function ($channels) use ($out): void {
                    foreach ($channels as $channel) {
                        fputcsv($out, [
                            $channel->id,
                            $channel->name,
                            $channel->slug ? '@'.$channel->slug : '',
                            $channel->user?->name,
                            $channel->user?->email,
                            (int) $channel->subscribers_count,
                            (int) $channel->videos_count,
                            (int) ($channel->total_views ?? 0),
                            $channel->user?->verified_at ? 'Yes' : 'No',
                            optional($channel->created_at)->toDateTimeString(),
                        ]);
                    }
                });

            fclose($out);
        }, 'playhub-creators-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function bulkVerifyCreators(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'identifiers' => ['required', 'string', 'max:5000'],
        ]);

        $identifiers = collect(preg_split('/[\s,;]+/', $data['identifiers']))
            ->map(fn ($value) => strtolower(ltrim(trim((string) $value), '@')))
            ->filter()
            ->unique()
            ->values();

        abort_if($identifiers->isEmpty(), 422, 'Enter at least one creator email, username, or handle.');

        $identifierList = $identifiers->all();
        $updated = 0;
        Channel::query()
            ->with('user')
            ->where(function ($query) use ($identifierList): void {
                $query
                    ->whereIn('slug', $identifierList)
                    ->orWhereHas('user', function ($userQuery) use ($identifierList): void {
                        $userQuery
                            ->whereIn('email', $identifierList)
                            ->orWhereIn('username', $identifierList);
                    });
            })
            ->get()
            ->each(function (Channel $channel) use (&$updated): void {
                if (! $channel->user || $channel->user->verified_at) {
                    return;
                }

                $channel->user->forceFill(['verified_at' => now()])->save();
                $updated++;
            });

        return response()->json(['message' => 'Creator profile badges verified.', 'updated' => $updated]);
    }

    public function sendCreatorAnnouncement(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        abort_unless($this->adminEmailDeliveryConfigured(), 422, 'Email delivery is not configured. Configure MAIL_* settings before sending creator announcements.');

        $sent = 0;
        Channel::query()
            ->with('user')
            ->whereHas('user', fn ($query) => $query->whereNotNull('email')->where('email', '<>', ''))
            ->orderBy('id')
            ->chunk(100, function ($channels) use ($data, &$sent): void {
                foreach ($channels as $channel) {
                    $user = $channel->user;
                    if (! $user?->email) {
                        continue;
                    }

                    Mail::raw($data['message'], function ($message) use ($data, $user): void {
                        BrandedEmail::apply($message, $data['subject'], $data['message']);
                        $message->to($user->email, $user->name)->subject($data['subject']);
                    });
                    $sent++;
                }
            });

        return response()->json(['message' => 'Creator announcement sent.', 'sent' => $sent]);
    }

    public function inviteUser(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['nullable', 'string', 'max:40'],
        ]);

        $role = $this->normalizeAdminUserRole($data['role'] ?? 'User');
        $email = strtolower(trim($data['email']));
        $name = trim((string) ($data['name'] ?? '')) ?: Str::title(str_replace(['.', '_', '-'], ' ', Str::before($email, '@')));
        $username = $this->uniqueUsername(Str::slug(Str::before($email, '@')) ?: 'user');
        $temporaryPassword = Str::random(24);

        $user = User::query()->create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make($temporaryPassword),
            'registration_provider' => 'admin_invite',
            'settings' => [
                'role' => $role,
                'status' => 'invited',
                'invited_at' => now()->toIso8601String(),
                'invited_by' => $request->user()?->id,
            ],
        ]);
        if ($this->roleGetsDefaultProfileBadge($role)) {
            $user->forceFill(['verified_at' => now()])->save();
        }

        $mailSent = false;
        if ($this->adminEmailDeliveryConfigured()) {
            $template = EmailTemplates::render('user_invitation', [
                'USERNAME' => $user->name,
                'USER_EMAIL' => $user->email,
                'ROLE' => $role,
                'TEMPORARY_PASSWORD' => $temporaryPassword,
                'LOGIN_URL' => url('/login'),
            ]);
            $subject = $template['subject'];
            $body = $template['body'];
            $locale = $template['locale'];
            Mail::raw($body, function ($message) use ($user, $subject, $body, $locale): void {
                BrandedEmail::apply($message, $subject, $body, $locale);
                $message->to($user->email, $user->name)->subject($subject);
            });
            $mailSent = true;
        }

        return response()->json([
            'message' => $mailSent ? 'User invited and email sent.' : 'User created. Email delivery is not configured, so no invite email was sent.',
            'mail_sent' => $mailSent,
            'user' => $this->adminUserPayload($user->fresh('channel')),
        ], 201);
    }

    public function showUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json([
            'user' => $this->adminUserPayload($user->load('channel')),
            'activity' => $this->userActivityPayload($user),
        ]);
    }

    public function userActivity(Request $request, User $user): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json($this->userActivityPayload($user));
    }

    public function userActivityReport(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Name', 'Email', 'Videos', 'Comments', 'Subscriptions', 'Last Activity']);

            User::query()
                ->withCount(['videos', 'comments', 'subscriptions'])
                ->orderBy('id')
                ->chunk(250, function ($users) use ($out): void {
                    foreach ($users as $user) {
                        fputcsv($out, [
                            $user->id,
                            $user->name,
                            $user->email,
                            $user->videos_count,
                            $user->comments_count,
                            $user->subscriptions_count,
                            optional($user->updated_at)->toDateTimeString(),
                        ]);
                    }
                });

            fclose($out);
        }, 'playhub-user-activity-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeStaff($request);
        $this->guardModeratorUserTarget($request, $user, 'edit');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'username' => ['sometimes', 'string', 'max:80', 'unique:users,username,'.$user->id],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'role' => ['sometimes', 'string', 'max:40'],
            'status' => ['sometimes', 'string', 'max:40'],
            'membership' => ['sometimes', 'string', Rule::in(['starter', 'plus', 'premium', 'pro', 'vip'])],
            'storage_quota_gb' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1048576'],
        ]);
        if (! $this->isAdmin($request->user())) {
            $data = collect($data)->only(['name', 'username', 'email'])->all();
        }

        $updates = collect($data)->only(['name', 'username', 'email'])->all();
        if (array_key_exists('email', $updates) && strtolower($updates['email']) !== strtolower($user->email)) {
            $updates['email_verified_at'] = null;
        }

        $settings = $this->settingsForUser($user);
        $nextRole = null;
        if (array_key_exists('role', $data)) {
            $nextRole = $this->validatedAdminUserRole($data['role']);
            abort_if($request->user()?->id === $user->id && ! $this->roleGetsDefaultProfileBadge($nextRole), 422, 'You cannot remove your own admin access.');
            $settings = $this->settingsWithAdminUserRole($settings, $nextRole, $request->user()?->id);
        }
        if (array_key_exists('status', $data)) {
            $settings['status'] = $this->normalizeAdminUserStatus($data['status']);
        }
        if (array_key_exists('membership', $data)) {
            $settings['membership'] = MediaStorage::normalizeMembership($data['membership']);
        }
        if (array_key_exists('storage_quota_gb', $data)) {
            $settings['storage'] = is_array($settings['storage'] ?? null) ? $settings['storage'] : [];
            if ($data['storage_quota_gb'] === null) {
                unset($settings['storage']['quota_gb']);
            } else {
                $settings['storage']['quota_gb'] = (int) $data['storage_quota_gb'];
            }
            if ($settings['storage'] === []) {
                unset($settings['storage']);
            }
        }
        $settings['updated_by_admin_at'] = now()->toIso8601String();
        $settings['updated_by_admin_id'] = $request->user()?->id;
        $updates['settings'] = $settings;
        $grantDefaultProfileBadge = $nextRole && $this->roleGetsDefaultProfileBadge($nextRole) && ! $user->verified_at;

        $user->update($updates);
        if ($grantDefaultProfileBadge) {
            $user->forceFill(['verified_at' => now()])->save();
        }

        return response()->json([
            'message' => 'User updated.',
            'user' => $this->adminUserPayload($user->fresh('channel')),
        ]);
    }

    public function updateUserPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $settings = $this->settingsForUser($user);
        $settings['password_updated_by_admin_at'] = now()->toIso8601String();
        $settings['password_updated_by_admin_id'] = $request->user()?->id;

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'settings' => $settings,
        ])->save();

        if ($request->user()?->id !== $user->id) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Password updated.',
            'user' => $this->adminUserPayload($user->fresh('channel')),
        ]);
    }

    public function creditUserWallet(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(Schema::hasTable('wallet_transactions'), 422, 'Wallet storage is not ready.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $amountCents = (int) round(((float) $data['amount']) * 100);
        $currency = IntegrationSettings::currency();
        $admin = $request->user();

        DB::transaction(function () use ($user, $admin, $amountCents, $currency, $data): void {
            DB::table('wallet_transactions')->insert([
                'user_id' => $user->id,
                'type' => 'topup',
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'status' => 'completed',
                'provider' => 'admin',
                'provider_reference' => 'manual-'.Str::uuid(),
                'method' => 'Admin credit',
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'payload' => json_encode([
                    'source' => 'admin_panel',
                    'admin_user_id' => $admin?->id,
                ], JSON_UNESCAPED_SLASHES),
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $amount = IntegrationSettings::formatMoney($amountCents, $currency);
        $balance = WalletLedger::totals($user);

        return response()->json([
            'message' => $amount.' added to '.$user->name.'\'s wallet. New balance: '.IntegrationSettings::formatMoney($balance['balance_cents'], $currency).'.',
            'balance_cents' => $balance['balance_cents'],
            'currency' => $currency,
        ]);
    }

    public function updateUserRole(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'role' => ['required', 'string', 'max:40'],
        ]);

        $settings = $this->settingsForUser($user);
        $previousRole = $this->normalizeAdminUserRole($settings['role'] ?? $settings['account_role'] ?? 'User');
        $role = $this->validatedAdminUserRole($data['role']);
        abort_if($request->user()?->id === $user->id && ! $this->roleGetsDefaultProfileBadge($role), 422, 'You cannot remove your own admin access.');
        $settings = $this->settingsWithAdminUserRole($settings, $role, $request->user()?->id);
        $user->update(['settings' => $settings]);
        if ($this->roleGetsDefaultProfileBadge($role) && ! $user->verified_at) {
            $user->forceFill(['verified_at' => now()])->save();
        }

        $mailSent = false;
        if ($role === 'moderator' && $previousRole !== 'moderator') {
            UserNotification::query()->create([
                'user_id' => $user->id,
                'actor_id' => $request->user()?->id,
                'type' => 'admin_role_moderator',
                'data' => ['detail' => 'You can now help moderate '.config('app.name', 'PlayHub').' content.', 'to' => '/admin/reports'],
            ]);
            $mailSent = $this->sendModeratorAssignmentEmail($user);
        }

        return response()->json([
            'message' => 'User role updated.'.($role === 'moderator' && $previousRole !== 'moderator' && ! $mailSent ? ' Email notification was not sent.' : ''),
            'user' => $this->adminUserPayload($user->fresh('channel')),
        ]);
    }

    public function sendUserEmail(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        abort_unless($this->adminEmailDeliveryConfigured(), 422, 'Email delivery is not configured. Configure MAIL_* settings before sending admin emails.');

        Mail::raw($data['message'], function ($message) use ($data, $user): void {
            BrandedEmail::apply($message, $data['subject'], $data['message']);
            $message->to($user->email, $user->name)->subject($data['subject']);
        });

        return response()->json(['message' => 'Email sent to '.$user->email.'.']);
    }

    public function suspendUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeStaff($request);
        $this->guardModeratorUserTarget($request, $user, 'suspend');
        $this->guardAgainstSelfLockout($request, $user, 'suspend');

        $settings = $this->settingsForUser($user);
        $suspended = strtolower((string) ($settings['status'] ?? 'active')) === 'suspended';
        $settings['status'] = $suspended ? 'active' : 'suspended';
        $settings[$suspended ? 'restored_at' : 'suspended_at'] = now()->toIso8601String();
        $settings[$suspended ? 'restored_by' : 'suspended_by'] = $request->user()?->id;
        $user->update(['settings' => $settings]);

        if (! $suspended) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => $suspended ? 'User restored.' : 'User suspended.',
            'user' => $this->adminUserPayload($user->fresh('channel')),
        ]);
    }

    public function banUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeStaff($request);
        $this->guardModeratorUserTarget($request, $user, 'ban');

        $data = $request->validate([
            'duration' => ['required', Rule::in(['1_day', '1_week', '2_weeks', '1_month'])],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', Rule::in(['account', 'comments', 'uploads', 'watch'])],
        ]);
        $scopes = array_values(array_unique($data['scopes']));
        if (in_array('account', $scopes, true)) {
            $this->guardAgainstSelfLockout($request, $user, 'lock');
        }

        $expiresAt = match ($data['duration']) {
            '1_week' => now()->addWeek(),
            '2_weeks' => now()->addWeeks(2),
            '1_month' => now()->addMonth(),
            default => now()->addDay(),
        };
        $settings = $this->settingsForUser($user);
        $settings['bans'] = is_array($settings['bans'] ?? null) ? $settings['bans'] : [];
        foreach ($scopes as $scope) {
            $settings['bans'][$scope] = [
                'expires_at' => $expiresAt->toIso8601String(),
                'created_at' => now()->toIso8601String(),
                'created_by' => $request->user()?->id,
            ];
        }
        $user->update(['settings' => $settings]);

        if (in_array('account', $scopes, true)) {
            $user->tokens()->delete();
        }

        UserNotification::query()->create([
            'user_id' => $user->id,
            'actor_id' => $request->user()?->id,
            'type' => 'account_restricted',
            'data' => [
                'detail' => 'Your account has been restricted until '.$expiresAt->format('M j, Y g:i A').'.',
                'to' => '/settings',
                'scopes' => $scopes,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
        $mailSent = $this->sendBanNotificationEmail($user, $expiresAt, $scopes);

        return response()->json([
            'message' => 'User ban applied until '.$expiresAt->format('M j, Y g:i A').'.'.($mailSent ? '' : ' Email notification was not sent.'),
            'user' => $this->adminUserPayload($user->fresh('channel')),
        ]);
    }

    public function clearUserBans(Request $request, User $user): JsonResponse
    {
        $this->authorizeStaff($request);
        $this->guardModeratorUserTarget($request, $user, 'clear bans for');

        $settings = $this->settingsForUser($user);
        unset($settings['bans']);
        $settings['bans_cleared_at'] = now()->toIso8601String();
        $settings['bans_cleared_by'] = $request->user()?->id;
        $user->update(['settings' => $settings]);

        return response()->json([
            'message' => 'User bans cleared.',
            'user' => $this->adminUserPayload($user->fresh('channel')),
        ]);
    }

    public function publishingRestrictions(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['restrictions' => $this->publishingRestrictionRows()]);
    }

    public function restrictPublishing(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:40'],
            'duration' => ['required', Rule::in(['1_day', '1_week', '1_month', '3_months', '6_months', 'forever'])],
            'scopes' => ['required', 'array', 'min:1', 'max:2'],
            'scopes.*' => ['required', 'distinct', Rule::in(['uploads', 'live'])],
        ]);
        $username = strtolower(ltrim(trim($data['username']), '@'));
        $user = User::query()->whereRaw('LOWER(username) = ?', [$username])->first();
        if (! $user) {
            throw ValidationException::withMessages(['username' => ['No user was found with that username.']]);
        }

        $expiresAt = match ($data['duration']) {
            '1_week' => now()->addWeek(),
            '1_month' => now()->addMonth(),
            '3_months' => now()->addMonths(3),
            '6_months' => now()->addMonths(6),
            'forever' => null,
            default => now()->addDay(),
        };
        $settings = $this->settingsForUser($user);
        $settings['bans'] = is_array($settings['bans'] ?? null) ? $settings['bans'] : [];
        foreach ($data['scopes'] as $scope) {
            $settings['bans'][$scope] = [
                'expires_at' => $expiresAt?->toIso8601String(),
                'forever' => $expiresAt === null,
                'created_at' => now()->toIso8601String(),
                'created_by' => $request->user()?->id,
            ];
        }
        $user->update(['settings' => $settings]);

        UserNotification::query()->create([
            'user_id' => $user->id,
            'actor_id' => $request->user()?->id,
            'type' => 'account_restricted',
            'data' => [
                'detail' => 'Your '.implode(' and ', $data['scopes']).' access has been restricted '.($expiresAt ? 'until '.$expiresAt->format('M j, Y g:i A') : 'until further notice').'.',
                'to' => '/settings',
                'scopes' => array_values($data['scopes']),
                'expires_at' => $expiresAt?->toIso8601String(),
            ],
        ]);
        $this->recordAdminActivity($request->user(), 'Restricted publishing for @'.$user->username);

        return response()->json([
            'message' => 'Publishing restriction applied to @'.$user->username.'.',
            'restrictions' => $this->publishingRestrictionRows(),
        ]);
    }

    public function clearPublishingRestriction(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'scopes' => ['nullable', 'array', 'max:2'],
            'scopes.*' => ['required', 'distinct', Rule::in(['uploads', 'live'])],
        ]);
        $scopes = $data['scopes'] ?? ['uploads', 'live'];
        $settings = $this->settingsForUser($user);
        foreach ($scopes as $scope) {
            unset($settings['bans'][$scope]);
        }
        if (empty($settings['bans'])) {
            unset($settings['bans']);
        }
        $user->update(['settings' => $settings]);
        $this->recordAdminActivity($request->user(), 'Cleared publishing restriction for @'.$user->username);

        return response()->json([
            'message' => 'Publishing restriction cleared for @'.$user->username.'.',
            'restrictions' => $this->publishingRestrictionRows(),
        ]);
    }

    public function verifyUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?: now()])->save();

        return response()->json([
            'message' => 'User verified.',
            'user' => $this->adminUserPayload($user->fresh('channel')),
        ]);
    }

    public function verifyProfileBadge(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user->forceFill(['verified_at' => $user->verified_at ?: now()])->save();

        return response()->json([
            'message' => 'Profile badge verified.',
            'user' => $this->adminUserPayload($user->fresh('channel')),
        ]);
    }

    public function clearProfileBadge(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user->forceFill(['verified_at' => null])->save();

        return response()->json([
            'message' => 'Profile badge removed.',
            'user' => $this->adminUserPayload($user->fresh('channel')),
        ]);
    }

    public function deleteUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->guardAgainstSelfLockout($request, $user, 'delete');
        $request->validate(['delete_videos' => ['accepted']]);

        app(\App\Services\Api\V1\AccountDeletionService::class)->permanentlyDelete($user);

        return response()->json(['message' => 'User and uploaded videos permanently deleted.']);
    }

    public function bulkUserStatus(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'emails' => ['required', 'array', 'min:1'],
            'emails.*' => ['required', 'email'],
            'status' => ['required', 'string', 'max:40'],
        ]);

        $status = $this->normalizeAdminUserStatus($data['status']);
        $updated = 0;
        User::query()
            ->whereIn('email', array_map('strtolower', $data['emails']))
            ->get()
            ->each(function (User $user) use ($request, $status, &$updated): void {
                if ($request->user()?->id === $user->id && in_array($status, ['suspended', 'deleted'], true)) {
                    return;
                }

                $settings = $this->settingsForUser($user);
                $settings['status'] = $status;
                $settings['status_updated_at'] = now()->toIso8601String();
                $settings['status_updated_by'] = $request->user()?->id;
                $user->update(['settings' => $settings]);

                if (in_array($status, ['suspended', 'deleted'], true)) {
                    $user->tokens()->delete();
                }

                $updated++;
            });

        return response()->json(['message' => $updated.' user(s) updated.', 'updated' => $updated]);
    }

    public function bulkVerifyUsers(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'emails' => ['required', 'array', 'min:1'],
            'emails.*' => ['required', 'email'],
        ]);

        $updated = User::query()
            ->whereIn('email', array_map('strtolower', $data['emails']))
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => $updated.' user(s) verified.', 'updated' => $updated]);
    }

    public function bulkDeleteUsers(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:users,id'],
            'delete_videos' => ['accepted'],
        ]);

        $deleted = 0;
        User::query()
            ->whereIn('id', $data['ids'])
            ->get()
            ->each(function (User $user) use ($request, &$deleted): void {
                if ($request->user()?->id === $user->id) {
                    return;
                }

                $this->permanentlyDeleteUser($user);
                $deleted++;
            });

        return response()->json(['message' => $deleted.' user(s) and their uploaded videos permanently deleted.', 'deleted' => $deleted]);
    }

    private function languageFiles(): array
    {
        $root = realpath((string) config('playhub.language_path', base_path('lang')));
        if ($root === false) {
            return [];
        }

        return collect(glob($root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*.php') ?: [])
            ->map(fn (string $file): string|false => realpath($file))
            ->filter(fn (string|false $file): bool => is_string($file) && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && ! LanguageLocales::isAlias(basename(dirname($file))))
            ->sort()
            ->values()
            ->all();
    }

    private function languageFile(string $locale): string
    {
        $locale = LanguageLocales::canonical($locale);
        abort_unless((bool) preg_match('/^[a-z0-9_-]+$/', $locale), 404, 'Language not found.');
        $root = realpath((string) config('playhub.language_path', base_path('lang')));
        abort_unless($root !== false, 404, 'Language directory not found.');
        $files = glob($root.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files);
        $file = isset($files[0]) ? realpath($files[0]) : false;
        abort_unless(is_string($file) && str_starts_with($file, $root.DIRECTORY_SEPARATOR), 404, 'Language not found.');

        return $file;
    }

    private function rejectLanguageAlias(string $locale): void
    {
        if (LanguageLocales::isAlias($locale)) {
            throw ValidationException::withMessages(['locale' => ['This address-style variant was merged. Choose its regional language.']]);
        }
    }

    private function loadLanguageFile(string $file): array
    {
        $data = (static fn (string $languageFile): mixed => require $languageFile)($file);
        abort_unless(is_array($data), 500, 'The language file must return an array.');

        return $data;
    }

    private function writeLanguageFile(string $file, array $data, string $expectedChecksum): void
    {
        $directory = dirname($file);
        abort_unless(is_writable($directory), 500, 'The language directory is not writable by the web server.');
        $lock = @fopen($file.'.lock', 'c');
        abort_unless(is_resource($lock), 500, 'Unable to lock the language file.');
        $temporary = null;

        try {
            abort_unless(flock($lock, LOCK_EX), 500, 'Unable to lock the language file.');
            abort_unless(hash_equals($expectedChecksum, (string) hash_file('sha256', $file)), 409, 'This language file changed after it was loaded. Reload it before saving.');
            $temporary = tempnam($directory, '.playhub-language-');
            abort_unless(is_string($temporary), 500, 'Unable to create a temporary language file.');
            $contents = "<?php\n\nreturn ".var_export($data, true).";\n";
            abort_unless(file_put_contents($temporary, $contents, LOCK_EX) !== false, 500, 'Unable to write the language file.');
            $verified = (static fn (string $languageFile): mixed => require $languageFile)($temporary);
            abort_unless(is_array($verified), 500, 'The generated language file is invalid.');
            $mode = ((int) fileperms($file)) & 0777;
            @chmod($temporary, $mode > 0 ? $mode : 0644);
            abort_unless(@rename($temporary, $file), 500, 'Unable to replace the language file.');
            clearstatcache(true, $file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function seoPageData(Request $request): array
    {
        $settings = SeoSettings::adminSettings();
        $inventory = app(SitemapData::class)->inventory();
        $perFile = (int) data_get($settings, 'sitemap.urlsPerFile', 10000);
        $baseUrl = rtrim($settings['canonicalBaseUrl'] ?: $request->getSchemeAndHttpHost(), '/');
        $labels = [
            'pages' => 'Website pages',
            'videos' => 'Videos',
            'shorts' => 'Shorts',
            'channels' => 'Creator channels',
            'playlists' => 'Public playlists',
            'live' => 'Live streams',
        ];
        $sections = collect(SeoSettings::SECTIONS)->map(function (string $section) use ($settings, $inventory, $perFile, $baseUrl, $labels): array {
            $count = (int) data_get($inventory, $section.'.count', 0);
            $indexing = (bool) data_get($settings, 'indexing.enabled') && (bool) data_get($settings, 'indexing.include.'.$section);
            $included = $indexing && (bool) data_get($settings, 'sitemap.enabled') && (bool) data_get($settings, 'sitemap.include.'.$section);
            $files = $included && $count > 0 ? max(1, (int) ceil($count / $perFile)) : 0;

            return [
                'key' => $section,
                'label' => $labels[$section],
                'eligible_urls' => $count,
                'indexing_enabled' => $indexing,
                'sitemap_enabled' => $included,
                'sitemap_files' => $files,
                'first_sitemap_url' => $files > 0 ? $baseUrl.($section === 'pages' ? '/sitemaps/pages.xml' : '/sitemaps/'.$section.'-1.xml') : null,
                'last_modified' => data_get($inventory, $section.'.last_modified'),
            ];
        })->values();
        $general = GeneralSettings::settings();

        return [
            'settings' => $settings,
            'summary' => [
                'eligible_urls' => $sections->where('indexing_enabled', true)->sum('eligible_urls'),
                'sitemap_files' => $sections->sum('sitemap_files'),
                'indexing_enabled' => (bool) data_get($settings, 'indexing.enabled'),
                'sitemap_enabled' => (bool) data_get($settings, 'indexing.enabled') && (bool) data_get($settings, 'sitemap.enabled'),
                'configuration_updated_at' => SeoSettings::updatedAt()->toIso8601String(),
            ],
            'sections' => $sections->all(),
            'urls' => [
                'sitemap' => $baseUrl.'/sitemap.xml',
                'robots' => $baseUrl.'/robots.txt',
            ],
            'site' => [
                'name' => trim((string) ($general['platformName'] ?? '')) ?: config('app.name', 'PlayHub'),
                'title' => $settings['siteTitle'] ?: ($general['websiteTitle'] ?: $general['platformName']),
                'base_url' => $baseUrl,
            ],
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($this->isAdmin($request->user()), 403, 'Admin access required.');
    }

    private function validateGift(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:10000'],
            'image' => [$creating ? 'required' : 'nullable', 'file', 'mimes:webp', 'max:2048'],
            'enabled' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
    }

    private function validatedWebsiteAd(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'placement' => ['required', Rule::in(WebsiteAd::PLACEMENTS)],
            'creative_type' => ['required', Rule::in(WebsiteAd::CREATIVE_TYPES)],
            'title' => ['nullable', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:5000'],
            'html_content' => ['nullable', 'string', 'max:100000', 'required_if:creative_type,html'],
            'image_url' => ['nullable', 'string', 'max:2048', 'required_if:creative_type,image'],
            'target_url' => ['nullable', 'string', 'max:2048'],
            'display_style' => ['required', Rule::in(WebsiteAd::DISPLAY_STYLES)],
            'device' => ['required', Rule::in(WebsiteAd::DEVICES)],
            'audience' => ['required', Rule::in(WebsiteAd::AUDIENCES)],
            'label' => ['nullable', 'string', 'max:40'],
            'background_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'height' => ['required', 'integer', 'min:50', 'max:1200'],
            'max_width' => ['nullable', 'integer', 'min:200', 'max:2400'],
            'every_n_items' => ['required', 'integer', 'min:1', 'max:50'],
            'priority' => ['required', 'integer', 'min:-100000', 'max:100000'],
            'enabled' => ['required', 'boolean'],
            'dismissible' => ['required', 'boolean'],
            'open_new_tab' => ['required', 'boolean'],
            'show_label' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        foreach (['image_url', 'target_url'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '' && ! str_starts_with($value, '/') && ! preg_match('/^https?:\/\//i', $value)) {
                throw ValidationException::withMessages([$field => 'Use an HTTPS, HTTP, or website-relative URL.']);
            }
            $data[$field] = $value ?: null;
        }
        if (! empty($data['starts_at']) && ! empty($data['ends_at']) && Carbon::parse($data['ends_at'])->lte(Carbon::parse($data['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'The end date must be later than the start date.']);
        }

        $data['name'] = trim($data['name']);
        $data['title'] = trim((string) ($data['title'] ?? '')) ?: null;
        $data['body'] = trim((string) ($data['body'] ?? '')) ?: null;
        $data['html_content'] = trim((string) ($data['html_content'] ?? '')) ?: null;
        $data['label'] = trim((string) ($data['label'] ?? '')) ?: 'Sponsored';

        return $data;
    }

    private function giftPayload(Gift $gift): array
    {
        return [
            'id' => $gift->id,
            'name' => $gift->name,
            'slug' => $gift->slug,
            'image_url' => url('/'.ltrim($gift->image_path, '/')),
            'price_cents' => $gift->price_cents,
            'price' => number_format($gift->price_cents / 100, 2, '.', ''),
            'currency' => strtoupper(IntegrationSettings::currency()),
            'enabled' => $gift->enabled,
            'sort_order' => $gift->sort_order,
            'updated_at' => $gift->updated_at?->toIso8601String(),
        ];
    }

    private function giftDirectory(): string
    {
        return (string) config('app.gifts_path', base_path('gifts'));
    }

    private function deleteGiftImage(?string $path): bool
    {
        if (! $path) {
            return true;
        }
        $file = $this->giftDirectory().'/'.basename($path);

        return ! File::exists($file) || File::delete($file);
    }

    private function uniqueGiftSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'gift';
        $slug = $base;
        $suffix = 2;
        while (Gift::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function websiteAdPayload(WebsiteAd $ad): array
    {
        return [
            'id' => (int) $ad->id,
            'name' => $ad->name,
            'placement' => $ad->placement,
            'creative_type' => $ad->creative_type,
            'title' => $ad->title,
            'body' => $ad->body,
            'html_content' => $ad->html_content,
            'image_url' => $ad->image_url,
            'target_url' => $ad->target_url,
            'display_style' => $ad->display_style,
            'device' => $ad->device,
            'audience' => $ad->audience,
            'label' => $ad->label,
            'background_color' => $ad->background_color,
            'height' => (int) $ad->height,
            'max_width' => $ad->max_width ? (int) $ad->max_width : null,
            'every_n_items' => (int) $ad->every_n_items,
            'priority' => (int) $ad->priority,
            'enabled' => (bool) $ad->enabled,
            'dismissible' => (bool) $ad->dismissible,
            'open_new_tab' => (bool) $ad->open_new_tab,
            'show_label' => (bool) $ad->show_label,
            'starts_at' => $ad->starts_at?->toIso8601String(),
            'ends_at' => $ad->ends_at?->toIso8601String(),
            'impressions' => (int) $ad->impressions,
            'clicks' => (int) $ad->clicks,
            'ctr' => $ad->impressions > 0 ? round(($ad->clicks / $ad->impressions) * 100, 2) : 0,
            'updated_at' => $ad->updated_at?->toIso8601String(),
        ];
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless($this->isAdmin($request->user()) || $this->isModerator($request->user()), 403, 'Admin or moderator access required.');
    }

    private function cronJobsPayload(string $content): array
    {
        $recommendedLine = $this->laravelSchedulerCronLine();

        return [
            'available' => true,
            'user' => $this->effectiveCronUser(),
            'content' => $content,
            'jobs' => $this->parseCrontabJobs($content),
            'scheduled_tasks' => $this->scheduledTasksPayload(),
            'recommended_line' => $recommendedLine,
            'has_scheduler' => str_contains($content, 'artisan schedule:run'),
        ];
    }

    private function scheduledTasksPayload(): array
    {
        try {
            app(ConsoleKernel::class)->bootstrap();

            return collect(app(Schedule::class)->events())->map(function ($event, int $index): array {
                $command = trim((string) ($event->command ?? ''));
                $name = trim((string) ($event->description ?? '')) ?: ($command ?: 'Scheduled task '.($index + 1));

                return [
                    'name' => $name,
                    'schedule' => (string) ($event->expression ?? ''),
                    'command' => $command,
                    'without_overlapping' => (bool) ($event->withoutOverlapping ?? false),
                ];
            })->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function readCrontabContent(): string
    {
        $process = new Process(['crontab', '-l']);
        $process->setTimeout(10);
        $process->run();

        if ($process->isSuccessful()) {
            return str_replace(["\r\n", "\r"], "\n", $process->getOutput());
        }

        $error = trim($process->getErrorOutput() ?: $process->getOutput());
        if (str_contains(strtolower($error), 'no crontab')) {
            return '';
        }

        throw new \RuntimeException($error ?: 'crontab -l failed.');
    }

    private function writeCrontabContent(string $content): void
    {
        $process = new Process(['crontab', '-']);
        $process->setInput($content);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException($error ?: 'crontab save failed.');
        }
    }

    private function validateCrontabContent(string $content): void
    {
        foreach (explode("\n", rtrim($content, "\n")) as $index => $line) {
            if (strlen($line) > 1000) {
                throw ValidationException::withMessages(['content' => ['Cron line '.($index + 1).' is too long.']]);
            }

            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || preg_match('/^[A-Z_][A-Z0-9_]*\s*=/i', $trimmed)) {
                continue;
            }

            if (! $this->parseCronJobLine($trimmed)) {
                throw ValidationException::withMessages(['content' => ['Cron line '.($index + 1).' is not a valid cron job.']]);
            }
        }
    }

    private function parseCrontabJobs(string $content): array
    {
        $jobs = [];
        foreach (explode("\n", $content) as $index => $line) {
            $job = $this->parseCronJobLine($line);
            if (! $job) {
                continue;
            }

            $jobs[] = array_merge($job, [
                'line' => $line,
                'line_number' => $index + 1,
                'is_laravel_scheduler' => str_contains($job['command'], 'artisan schedule:run'),
            ]);
        }

        return $jobs;
    }

    private function parseCronJobLine(string $line): ?array
    {
        $trimmed = trim($line);
        $enabled = true;
        if (str_starts_with($trimmed, '#')) {
            $enabled = false;
            $trimmed = trim(substr($trimmed, 1));
        }

        if ($trimmed === '' || preg_match('/^[A-Z_][A-Z0-9_]*\s*=/i', $trimmed)) {
            return null;
        }

        if (preg_match('/^(@(?:reboot|yearly|annually|monthly|weekly|daily|hourly))\s+(.+)$/i', $trimmed, $matches)) {
            return ['schedule' => $matches[1], 'command' => trim($matches[2]), 'enabled' => $enabled];
        }

        if (! preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+)$/', $trimmed, $matches)) {
            return null;
        }

        $schedule = trim($matches[1]);
        if (! $this->validCronSchedule($schedule)) {
            return null;
        }

        return ['schedule' => $schedule, 'command' => trim($matches[2]), 'enabled' => $enabled];
    }

    private function validCronSchedule(string $schedule): bool
    {
        $fields = preg_split('/\s+/', trim($schedule)) ?: [];
        if (count($fields) !== 5) {
            return false;
        }

        foreach ($fields as $field) {
            if (! preg_match('/^[A-Z0-9_*\/,\-?#LW]+$/i', $field)) {
                return false;
            }
        }

        return true;
    }

    private function laravelSchedulerCronLine(): string
    {
        $phpBinary = PHP_BINDIR ? rtrim(PHP_BINDIR, '/').'/php' : 'php';

        return '* * * * * cd '.$this->shellQuote(base_path()).' && '.$this->shellQuote($phpBinary).' artisan schedule:run >> /dev/null 2>&1';
    }

    private function shellQuote(string $value): string
    {
        if (! str_contains($value, '/')) {
            return $value;
        }

        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private function effectiveCronUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user) && ! empty($user['name'])) {
                return (string) $user['name'];
            }
        }

        return get_current_user() ?: 'web server user';
    }

    private function isAdmin(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $settings = is_array($user->settings) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;

        return $adminEnabled
            || (bool) ($settings['is_admin'] ?? false)
            || in_array($role, ['admin', 'super admin', 'owner'], true);
    }

    private function isModerator(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $settings = is_array($user->settings) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));

        return $role === 'moderator';
    }

    private function metric(string $label, int $value, int $current, int $previous, string $tone, string $icon, array $series, bool $danger = false): array
    {
        $isNegative = $label !== 'Reports Pending' && $current < $previous;

        return [
            'label' => $label,
            'value' => $this->formatCompact($value),
            'raw' => $value,
            'change' => $this->percentChange($current, $previous),
            'tone' => $tone,
            'icon' => $icon,
            'danger' => $danger || $isNegative,
            'points' => array_slice(array_pad($series, 12, 0), -12),
        ];
    }

    private function percentChange(float|int $current, float|int $previous): string
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? '0%' : '100%';
        }

        return number_format(abs((($current - $previous) / $previous) * 100), 1).'%';
    }

    private function dailyCounts(string $table, Carbon $start, Carbon $end, ?callable $scope = null): array
    {
        $query = DB::table($table)->whereBetween($table.'.created_at', [$start, $end]);

        if ($scope) {
            $scope($query);
        }

        $rows = $query
            ->selectRaw('DATE('.$table.'.created_at) as date, COUNT(*) as aggregate')
            ->groupBy('date')
            ->pluck('aggregate', 'date');

        return $this->fillDateValues($rows, $start, $end);
    }

    private function dailyWatchHours(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('videos')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as date, COALESCE(SUM(views_count * COALESCE(duration_seconds, 0)) / 3600, 0) as aggregate')
            ->groupBy('date')
            ->pluck('aggregate', 'date');

        return $this->fillDateValues($rows, $start, $end);
    }

    private function activeUsersSeries(Carbon $start, Carbon $end): array
    {
        $activity = DB::table('videos')
            ->selectRaw('DATE(created_at) as activity_date, user_id')
            ->whereBetween('created_at', [$start, $end])
            ->unionAll(DB::table('comments')->selectRaw('DATE(created_at) as activity_date, user_id')->whereBetween('created_at', [$start, $end]))
            ->unionAll(DB::table('subscriptions')->selectRaw('DATE(created_at) as activity_date, user_id')->whereBetween('created_at', [$start, $end]));

        $rows = DB::query()
            ->fromSub($activity, 'activity')
            ->select('activity_date')
            ->selectRaw('COUNT(DISTINCT user_id) as aggregate')
            ->groupBy('activity_date')
            ->pluck('aggregate', 'activity_date');

        return $this->fillDateValues($rows, $start, $end);
    }

    private function fillDateValues($rows, Carbon $start, Carbon $end): array
    {
        $values = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $values[] = (float) ($rows[$cursor->toDateString()] ?? 0);
            $cursor->addDay();
        }

        return $values;
    }

    private function chartDateLabels(Carbon $start, Carbon $end): array
    {
        $days = max(1, $start->diffInDays($end));

        return collect([0, .25, .5, .75, 1])
            ->map(fn (float $step): string => $start->copy()->addDays((int) round($days * $step))->format('M j'))
            ->unique()
            ->values()
            ->all();
    }

    private function yLabels(array $values): array
    {
        $max = max(array_map('floatval', $values ?: [0]));

        if ($max <= 0) {
            return ['3', '2', '1', '0'];
        }

        return [
            $this->formatCompact((int) ceil($max)),
            $this->formatCompact((int) ceil($max * .66)),
            $this->formatCompact((int) ceil($max * .33)),
            '0',
        ];
    }

    private function videoReportsEnabled(): bool
    {
        return Schema::hasTable('video_reports');
    }

    private function userReportsEnabled(): bool
    {
        return Schema::hasTable('user_reports');
    }

    private function pendingReportsCount(): int
    {
        return DB::table('comment_reports')->distinct('comment_id')->count('comment_id')
            + ($this->videoReportsEnabled() ? DB::table('video_reports')->distinct('video_id')->count('video_id') : 0)
            + ($this->userReportsEnabled() ? DB::table('user_reports')->distinct('reported_user_id')->count('reported_user_id') : 0);
    }

    private function totalReportsCount(): int
    {
        return DB::table('comment_reports')->count()
            + ($this->videoReportsEnabled() ? DB::table('video_reports')->count() : 0)
            + ($this->userReportsEnabled() ? DB::table('user_reports')->count() : 0);
    }

    private function totalReportsBetween(Carbon $start, Carbon $end): int
    {
        return DB::table('comment_reports')->whereBetween('created_at', [$start, $end])->count()
            + ($this->videoReportsEnabled() ? DB::table('video_reports')->whereBetween('created_at', [$start, $end])->count() : 0)
            + ($this->userReportsEnabled() ? DB::table('user_reports')->whereBetween('created_at', [$start, $end])->count() : 0);
    }

    private function reportDailyCounts(Carbon $start, Carbon $end): array
    {
        $commentCounts = $this->dailyCounts('comment_reports', $start, $end);
        $videoCounts = $this->videoReportsEnabled() ? $this->dailyCounts('video_reports', $start, $end) : array_fill(0, count($commentCounts), 0);
        $userCounts = $this->userReportsEnabled() ? $this->dailyCounts('user_reports', $start, $end) : array_fill(0, count($commentCounts), 0);

        return array_map(fn ($comments, $videos, $users): int => (int) $comments + (int) $videos + (int) $users, $commentCounts, $videoCounts, $userCounts);
    }

    private function reportPriority(int $reportCount): string
    {
        return $reportCount >= 3 ? 'High' : ($reportCount >= 2 ? 'Medium' : 'Low');
    }

    private function reportRows(int $limit, int $offset = 0): array
    {
        $commentRows = DB::table('comment_reports')
            ->join('comments', 'comments.id', '=', 'comment_reports.comment_id')
            ->join('users as reporters', 'reporters.id', '=', 'comment_reports.user_id')
            ->leftJoin('users as authors', 'authors.id', '=', 'comments.user_id')
            ->leftJoin('channels as author_channels', 'author_channels.user_id', '=', 'authors.id')
            ->leftJoin('videos', 'videos.id', '=', 'comments.video_id')
            ->leftJoin('community_posts', function ($join): void {
                $join->on('community_posts.id', '=', 'comments.commentable_id')
                    ->where('comments.commentable_type', '=', CommunityPost::class);
            })
            ->leftJoin('channels as community_channels', 'community_channels.id', '=', 'community_posts.channel_id')
            ->select([
                'comment_reports.id',
                'comment_reports.comment_id',
                'comment_reports.updated_at',
                'comment_reports.reason',
                'comment_reports.details',
                'comments.body',
                'reporters.username as reporter_username',
                'reporters.name as reporter_name',
                'authors.username as author_username',
                'authors.name as author_name',
                'videos.title as video_title',
                'videos.slug as video_slug',
                'videos.duration_seconds as video_duration_seconds',
                'videos.video_width as video_width',
                'videos.video_height as video_height',
                'videos.source_type as video_source_type',
                'videos.is_short as video_is_short',
                'videos.sale_enabled as video_sale_enabled',
                'videos.thumbnail_path as video_thumbnail_path',
                'author_channels.avatar_path as author_avatar_path',
                'community_posts.id as community_post_id',
                'community_channels.slug as community_channel_slug',
            ])
            ->selectRaw('(select count(*) from comment_reports cr where cr.comment_id = comments.id) as report_count')
            ->orderByDesc('comment_reports.updated_at')
            ->limit($limit + $offset)
            ->get()
            ->map(function ($row): array {
                $reportCount = (int) $row->report_count;
                $isShort = Video::isShortContent($row->video_source_type, $row->video_duration_seconds, $row->video_is_short, (bool) $row->video_sale_enabled, $row->video_width, $row->video_height);
                $targetUrl = $row->video_slug
                    ? ($isShort ? '/shorts/' : '/watch/').$row->video_slug
                    : ($row->community_channel_slug ? '/channel/'.$row->community_channel_slug.'/community' : '/admin/reports');

                return [
                    'id' => (int) $row->id,
                    'comment_id' => (int) $row->comment_id,
                    'type' => 'Comment',
                    'tone' => 'violet',
                    'title' => $this->shortText($row->body ?: 'Reported comment', 64),
                    'byline' => $row->video_title ? 'on "'.$this->shortText($row->video_title, 42).'"' : 'by @'.($row->author_username ?: $row->author_name ?: 'user'),
                    'reporter' => $row->reporter_username ? '@'.$row->reporter_username : ($row->reporter_name ?: 'User'),
                    'reason' => \App\Support\ReportReasons::adminLabel(\App\Support\ReportReasons::COMMENT, $row->reason).($reportCount > 1 ? ' · x'.$reportCount : ''),
                    'details' => $row->details,
                    'priority' => $this->reportPriority($reportCount),
                    'status' => 'Pending',
                    'action' => 'Review',
                    'thumb' => 'comment',
                    'thumbnail_url' => ($row->video_thumbnail_path ?: $row->author_avatar_path) ? MediaStorage::url($row->video_thumbnail_path ?: $row->author_avatar_path) : null,
                    'target_url' => $targetUrl,
                    'reported_at' => optional(Carbon::parse($row->updated_at))->diffForHumans(),
                    '_reported_at_sort' => Carbon::parse($row->updated_at)->timestamp,
                ];
            })
            ->values();

        $videoRows = $this->videoReportRows($limit + $offset);
        $userRows = $this->userReportRows($limit + $offset);

        return $commentRows
            ->concat($videoRows)
            ->concat($userRows)
            ->sortByDesc('_reported_at_sort')
            ->slice($offset, $limit)
            ->map(function (array $row): array {
                unset($row['_reported_at_sort']);

                return $row;
            })
            ->values()
            ->all();
    }

    private function videoReportRows(int $limit)
    {
        if (! $this->videoReportsEnabled()) {
            return collect();
        }

        return DB::table('video_reports')
            ->join('videos', 'videos.id', '=', 'video_reports.video_id')
            ->join('users as reporters', 'reporters.id', '=', 'video_reports.user_id')
            ->leftJoin('users as authors', 'authors.id', '=', 'videos.user_id')
            ->select([
                'video_reports.id',
                'video_reports.video_id',
                'video_reports.updated_at',
                'video_reports.reason',
                'video_reports.details',
                'videos.title',
                'videos.slug',
                'videos.duration_seconds',
                'videos.video_width',
                'videos.video_height',
                'videos.source_type',
                'videos.is_short',
                'videos.sale_enabled',
                'videos.thumbnail_path',
                'reporters.username as reporter_username',
                'reporters.name as reporter_name',
                'authors.username as author_username',
                'authors.name as author_name',
            ])
            ->selectRaw('(select count(*) from video_reports vr where vr.video_id = videos.id) as report_count')
            ->orderByDesc('video_reports.updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                $reportCount = (int) $row->report_count;
                $isShort = Video::isShortContent($row->source_type, $row->duration_seconds, $row->is_short, (bool) $row->sale_enabled, $row->video_width, $row->video_height);

                return [
                    'id' => (int) $row->id,
                    'video_id' => (int) $row->video_id,
                    'type' => 'Video',
                    'tone' => 'red',
                    'title' => $this->shortText($row->title ?: 'Reported video', 64),
                    'byline' => 'by @'.($row->author_username ?: $row->author_name ?: 'user'),
                    'reporter' => $row->reporter_username ? '@'.$row->reporter_username : ($row->reporter_name ?: 'User'),
                    'reason' => \App\Support\ReportReasons::adminLabel(\App\Support\ReportReasons::VIDEO, $row->reason).($reportCount > 1 ? ' · x'.$reportCount : ''),
                    'details' => $row->details,
                    'priority' => $this->reportPriority($reportCount),
                    'status' => 'Pending',
                    'action' => 'Review',
                    'thumb' => 'video',
                    'thumbnail_url' => $row->thumbnail_path ? MediaStorage::url($row->thumbnail_path) : null,
                    'target_url' => $row->slug ? ($isShort ? '/shorts/' : '/watch/').$row->slug : '/admin/reports',
                    'reported_at' => optional(Carbon::parse($row->updated_at))->diffForHumans(),
                    '_reported_at_sort' => Carbon::parse($row->updated_at)->timestamp,
                ];
            })
            ->values();
    }

    private function userReportRows(int $limit)
    {
        if (! $this->userReportsEnabled()) {
            return collect();
        }

        return DB::table('user_reports')
            ->join('users as reporters', 'reporters.id', '=', 'user_reports.user_id')
            ->join('users as reported_users', 'reported_users.id', '=', 'user_reports.reported_user_id')
            ->leftJoin('channels', 'channels.user_id', '=', 'reported_users.id')
            ->select([
                'user_reports.id',
                'user_reports.reported_user_id',
                'user_reports.updated_at',
                'user_reports.reason',
                'user_reports.details',
                'reporters.username as reporter_username',
                'reporters.name as reporter_name',
                'reported_users.username as reported_username',
                'reported_users.name as reported_name',
                'channels.name as channel_name',
                'channels.slug as channel_slug',
                'channels.avatar_path as profile_avatar_path',
            ])
            ->selectRaw('(select count(*) from user_reports ur where ur.reported_user_id = user_reports.reported_user_id) as report_count')
            ->orderByDesc('user_reports.updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                $reportCount = (int) $row->report_count;

                return [
                    'id' => (int) $row->id,
                    'user_id' => (int) $row->reported_user_id,
                    'type' => 'Profile',
                    'tone' => 'orange',
                    'title' => $this->shortText($row->channel_name ?: $row->reported_name ?: 'Reported profile', 64),
                    'byline' => '@'.($row->channel_slug ?: $row->reported_username ?: 'user'),
                    'reporter' => $row->reporter_username ? '@'.$row->reporter_username : ($row->reporter_name ?: 'User'),
                    'reason' => \App\Support\ReportReasons::adminLabel(\App\Support\ReportReasons::PROFILE, $row->reason).($reportCount > 1 ? ' · x'.$reportCount : ''),
                    'details' => $row->details,
                    'priority' => $this->reportPriority($reportCount),
                    'status' => 'Pending',
                    'action' => 'Review',
                    'thumb' => 'users',
                    'thumbnail_url' => $row->profile_avatar_path ? MediaStorage::url($row->profile_avatar_path) : null,
                    'target_url' => $row->channel_slug ? '/channel/'.$row->channel_slug : '/admin/users?search='.rawurlencode((string) ($row->reported_username ?: $row->reported_name)),
                    'reported_at' => optional(Carbon::parse($row->updated_at))->diffForHumans(),
                    '_reported_at_sort' => Carbon::parse($row->updated_at)->timestamp,
                ];
            })
            ->values();
    }

    private function topCreators(Carbon $start, Carbon $end): array
    {
        $tones = ['orange', 'mono', 'dark', 'green', 'blue', 'pink', 'rose', 'violet'];

        return Channel::query()
            ->with('user')
            ->withCount(['videos as videos_28d' => fn ($query) => $query->whereBetween('created_at', [$start, $end])])
            ->withSum(['videos as views_28d' => fn ($query) => $query->whereBetween('created_at', [$start, $end])], 'views_count')
            ->orderByDesc('views_28d')
            ->orderByDesc('subscribers_count')
            ->take(5)
            ->get()
            ->values()
            ->map(fn (Channel $channel, int $index): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'handle' => '@'.$channel->slug,
                'subscribers' => $this->formatCompact((int) $channel->subscribers_count),
                'views' => $this->formatCompact((int) ($channel->views_28d ?? 0)),
                'revenue' => $this->formatMoney(0),
                'tone' => $tones[$index % count($tones)],
                'avatar_url' => MediaStorage::url($channel->avatar_path),
            ])
            ->all();
    }

    private function systemHealth(int $processingJobs): array
    {
        $path = base_path();
        $totalBytes = @disk_total_space($path) ?: 0;
        $freeBytes = @disk_free_space($path) ?: 0;
        $usedBytes = max(0, $totalBytes - $freeBytes);
        $usedPercent = $totalBytes > 0 ? min(100, round(($usedBytes / $totalBytes) * 100)) : 0;
        $failedJobs = DB::table('failed_jobs')->count();
        $failedUploads = DB::table('upload_sessions')->where('status', 'failed')->count();

        return [
            'operational_label' => ($failedJobs || $failedUploads) ? 'Attention needed' : 'All systems operational',
            'api_uptime' => 'Online',
            'storage' => [
                'label' => $this->formatBytes($usedBytes).' / '.$this->formatBytes($totalBytes),
                'percent' => $usedPercent,
            ],
            'transcoding_queue' => $processingJobs.' jobs',
            'server_status' => ($failedJobs || $failedUploads) ? 'Degraded' : 'Operational',
        ];
    }

    private function recentSignups(): array
    {
        $tones = ['green', 'orange', 'pink', 'blue', 'rose'];

        return User::query()
            ->latest()
            ->take(5)
            ->get()
            ->values()
            ->map(fn (User $user, int $index): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'time' => optional($user->created_at)->diffForHumans(),
                'tone' => $tones[$index % count($tones)],
            ])
            ->all();
    }

    private function supportFlags(int $pendingReports, int $processingJobs): array
    {
        $flags = [];
        $failedJobs = DB::table('failed_jobs')->count();
        $failedUploads = DB::table('upload_sessions')->where('status', 'failed')->count();

        if ($pendingReports > 0) {
            $flags[] = ['title' => 'Open moderation reports', 'id' => '#MOD-'.$pendingReports, 'priority' => $pendingReports >= 10 ? 'High' : 'Medium', 'time' => 'now'];
        }

        if ($failedJobs > 0) {
            $flags[] = ['title' => 'Failed background jobs', 'id' => '#JOB-'.$failedJobs, 'priority' => 'High', 'time' => 'now'];
        }

        if ($failedUploads > 0) {
            $flags[] = ['title' => 'Failed upload sessions', 'id' => '#UP-'.$failedUploads, 'priority' => 'Medium', 'time' => 'now'];
        }

        if ($processingJobs > 0) {
            $flags[] = ['title' => 'Transcoding queue active', 'id' => '#TR-'.$processingJobs, 'priority' => 'Low', 'time' => 'now'];
        }

        return array_slice($flags, 0, 4);
    }

    private function chart(string $title, string $subtitle, string $value, string $change, array $yLabels, array $labels, array $series): array
    {
        return compact('title', 'subtitle', 'value', 'change', 'yLabels', 'labels', 'series');
    }

    private function dailyVideoViews(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('videos')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as date, COALESCE(SUM(views_count), 0) as aggregate')
            ->groupBy('date')
            ->pluck('aggregate', 'date');

        return $this->fillDateValues($rows, $start, $end);
    }

    private function zeroSeries(int $count = 16): array
    {
        return array_fill(0, $count, 0);
    }

    private function activeCreatorCount(Carbon $start, Carbon $end): int
    {
        return Channel::query()
            ->whereHas('videos', fn ($query) => $query->whereBetween('created_at', [$start, $end]))
            ->count();
    }

    private function countUsersBySetting(string $key, string $value): int
    {
        return User::query()->get()->filter(function (User $user) use ($key, $value): bool {
            $settings = is_array($user->settings) ? $user->settings : [];

            return strtolower((string) ($settings[$key] ?? '')) === strtolower($value);
        })->count();
    }

    private function verifiedRate(): int
    {
        $total = max(User::query()->count(), 1);

        return (int) round((User::query()->whereNotNull('email_verified_at')->count() / $total) * 100);
    }

    private function verifiedCreatorCount(): int
    {
        return Channel::query()
            ->whereHas('user', fn ($query) => $query->whereNotNull('verified_at'))
            ->count();
    }

    private function personForUser(?User $user, ?string $tone = null): array
    {
        $avatarPath = $user?->channel?->avatar_path ?: $user?->avatar_path;

        return [
            'name' => $user?->name ?: 'Unknown User',
            'handle' => $user?->username ? '@'.$user->username : '@unknown',
            'email' => $user?->email ?: '',
            'tone' => $tone ?: $this->toneForId((int) ($user?->id ?? 0)),
            'avatar_url' => MediaStorage::url($avatarPath),
            'verified' => (bool) $user?->verified_at,
        ];
    }

    private function personForChannel(?Channel $channel, ?string $tone = null): array
    {
        $user = $channel?->relationLoaded('user') ? $channel?->user : null;

        return [
            'name' => $channel?->name ?: $user?->name ?: 'Unknown Creator',
            'handle' => $channel?->slug ? '@'.$channel->slug : ($user?->username ? '@'.$user->username : '@creator'),
            'email' => $user?->email ?: '',
            'tone' => $tone ?: $this->toneForId((int) ($channel?->id ?? $user?->id ?? 0)),
            'avatar_url' => MediaStorage::url($channel?->avatar_path),
            'verified' => (bool) $user?->verified_at,
        ];
    }

    private function toneForId(int $id): string
    {
        $tones = ['orange', 'mono', 'dark', 'green', 'blue', 'pink', 'rose', 'violet'];

        return $tones[$id % count($tones)];
    }

    private function adminUserPayload(User $user): array
    {
        $user->loadMissing('channel');
        $settings = $this->settingsForUser($user);
        $storageQuotaGb = data_get($settings, 'storage.quota_gb');
        $membership = MediaStorage::normalizeMembership($settings['membership'] ?? MediaStorage::defaultMembership());
        $activeBans = $user->activeBans();
        $registrationProvider = strtolower(trim((string) ($user->registration_provider ?: data_get($settings, 'registration_provider', 'local')))) ?: 'local';
        $role = $this->normalizeAdminUserRole($settings['role'] ?? $settings['account_role'] ?? $this->displayUserRole($user));
        if ($this->isAdmin($user) && ! $this->roleGetsDefaultProfileBadge($role)) {
            $role = 'admin';
        }

        return [
            'id' => (int) $user->id,
            'person' => $this->personForUser($user),
            'role' => $this->displayUserRole($user),
            'status' => $this->displayUserStatus($user),
            'membership' => MediaStorage::membershipLabel($membership),
            'date' => optional($user->created_at)->format('M j, Y') ?: '-',
            'active' => optional($user->updated_at)->diffForHumans() ?: '-',
            'profileUrl' => $user->channel?->slug ? '/channel/'.$user->channel->slug : '/profile',
            'verified' => (bool) $user->email_verified_at,
            'profile_verified' => (bool) $user->verified_at,
            'registration_ip' => $this->isAdmin(request()->user()) ? ($user->registration_ip ?: null) : null,
            'registration_provider' => $registrationProvider,
            'registration_provider_label' => match ($registrationProvider) {
                'local' => 'Local registration',
                'admin_invite' => 'Admin invite',
                'installer' => 'Installer',
                default => Str::headline($registrationProvider),
            },
            'raw' => [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $role,
                'status' => strtolower($this->displayUserStatus($user)),
                'membership' => $membership,
                'storage_quota_gb' => $storageQuotaGb === null ? '' : (int) $storageQuotaGb,
                'active_bans' => $activeBans,
                'active_ban_scopes' => array_keys($activeBans),
                'email_verified' => (bool) $user->email_verified_at,
                'profile_verified' => (bool) $user->verified_at,
                'verified_at' => optional($user->verified_at)->toIso8601String(),
            ],
        ];
    }

    private function userActivityPayload(User $user): array
    {
        $latestVideo = $user->videos()->latest()->first();
        $latestComment = $user->comments()->latest()->first();
        $lastActivity = collect([$latestVideo?->created_at, $latestComment?->created_at, $user->updated_at])
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'user_id' => (int) $user->id,
            'videos' => $user->videos()->count(),
            'comments' => $user->comments()->count(),
            'subscriptions' => $user->subscriptions()->count(),
            'last_activity' => $lastActivity ? Carbon::parse($lastActivity)->diffForHumans() : '-',
            'latest_video' => $latestVideo?->title,
            'latest_comment' => $latestComment ? $this->shortText((string) $latestComment->body, 120) : null,
        ];
    }

    private function settingsForUser(User $user): array
    {
        return is_array($user->settings) ? $user->settings : [];
    }

    private function publishingRestrictionRows(): array
    {
        return User::query()
            ->with('channel')
            ->where(function ($query): void {
                $query->whereNotNull('settings->bans->uploads')
                    ->orWhereNotNull('settings->bans->live');
            })
            ->latest('updated_at')
            ->limit(200)
            ->get()
            ->map(function (User $user): ?array {
                $bans = collect($user->activeBans())->only(['uploads', 'live']);
                if ($bans->isEmpty()) {
                    return null;
                }

                return [
                    'user_id' => (int) $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'avatar_url' => MediaStorage::url($user->channel?->avatar_path ?: $user->avatar_path),
                    'restrictions' => $bans->map(fn (array $ban, string $scope): array => [
                        'scope' => $scope,
                        'label' => $scope === 'live' ? 'Go live' : 'Uploads',
                        'expires_at' => $ban['expires_at'] ?? null,
                        'expires_label' => $ban['expires_label'] ?? 'Forever',
                    ])->values()->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function approveVideoRecord(Video $video): void
    {
        $scheduledForFuture = $video->scheduled_at && $video->scheduled_at->isFuture();
        $readyToPublish = $video->status === 'ready' && ! $scheduledForFuture;

        $video->update([
            'approval_status' => 'approved',
            'visibility' => $readyToPublish ? ($video->requested_visibility ?: 'public') : 'private',
            'published_at' => $readyToPublish ? ($video->published_at ?: now()) : $video->published_at,
        ]);
    }

    private function deleteVideoRecord(Video $video): void
    {
        $paths = array_filter([
            $video->video_path,
            $video->thumbnail_path,
            $video->captions_path,
            ...collect($video->renditions ?: [])->pluck('path')->all(),
        ]);

        MediaStorage::delete($paths);
        DB::transaction(function () use ($video): void {
            $video->channel()->decrement('videos_count');
            $video->delete();
        });

    }

    private function deleteUserAvatarFiles(User $user): void
    {
        $user->loadMissing('channel');
        $paths = collect([$user->avatar_path, $user->channel?->avatar_path])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($paths) {
            MediaStorage::delete($paths);
        }

        if ($user->channel && $user->channel->avatar_path) {
            $user->channel->update(['avatar_path' => null]);
        }
    }

    private function permanentlyDeleteUser(User $user): void
    {
        $user->loadMissing(['channel', 'videos', 'liveStreams']);
        $paths = collect([$user->avatar_path, $user->channel?->avatar_path, $user->channel?->cover_path]);
        foreach ($user->videos as $video) {
            $paths->push($video->video_path, $video->thumbnail_path, $video->captions_path);
            $paths->push(...collect($video->renditions ?: [])->pluck('path')->all());
        }
        foreach ($user->liveStreams as $stream) {
            $recording = (array) data_get($stream->stream_settings, '_recording', []);
            $paths->push($stream->thumbnail_path, $recording['local_path'] ?? null, $recording['media_path'] ?? null);
        }

        MediaStorage::delete($paths->filter()->unique()->values()->all());
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
    }

    private function displayUserRole(User $user): string
    {
        if ($this->isAdmin($user)) {
            return 'Admin';
        }

        $settings = $this->settingsForUser($user);
        $storedRole = trim((string) ($settings['role'] ?? $settings['account_role'] ?? ''));
        $settingsRole = $this->normalizeAdminUserRole($storedRole);

        return match ($settingsRole) {
            'creator' => 'Creator',
            'moderator' => 'Moderator',
            'admin', 'super admin', 'owner' => 'Admin',
            default => $storedRole === '' && $user->channel ? 'Creator' : 'User',
        };
    }

    private function displayUserStatus(User $user): string
    {
        $activeBans = $user->activeBans();
        if (isset($activeBans['account'])) {
            return 'Locked';
        }
        if ($activeBans) {
            return 'Restricted';
        }

        $status = $this->normalizeAdminUserStatus($this->settingsForUser($user)['status'] ?? 'active');

        return match ($status) {
            'suspended' => 'Suspended',
            'deleted' => 'Deleted',
            'invited' => 'Pending',
            default => 'Active',
        };
    }

    private function applyAdminUserRoleFilter($query, string $role): void
    {
        $adminRoles = ['admin', 'super admin', 'super_admin', 'super-admin', 'owner'];
        $adminFilter = function ($builder) use ($adminRoles): void {
            $builder->whereIn('settings->role', $adminRoles)
                ->orWhere(function ($builder) use ($adminRoles): void {
                    $builder->where(fn ($builder) => $builder->whereNull('settings->role')->orWhere('settings->role', ''))
                        ->whereIn('settings->account_role', $adminRoles);
                })
                ->orWhere('settings->is_admin', true)
                ->orWhere('settings->admin', true)
                ->orWhere('settings->admin->enabled', true);
        };
        if (in_array($role, ['admin', 'super admin', 'owner'], true)) {
            $query->where($adminFilter);

            return;
        }

        $query->whereNotIn('users.id', User::query()->select('users.id')->where($adminFilter))
            ->where(function ($builder) use ($role): void {
                $builder->where('settings->role', $role)
                    ->orWhere(function ($builder) use ($role): void {
                        $builder->where(fn ($builder) => $builder->whereNull('settings->role')->orWhere('settings->role', ''))
                            ->where(function ($builder) use ($role): void {
                                $builder->where('settings->account_role', $role);
                                if (in_array($role, ['user', 'creator'], true)) {
                                    $builder->orWhere(function ($builder) use ($role): void {
                                        $builder->where(fn ($builder) => $builder->whereNull('settings->account_role')->orWhere('settings->account_role', ''));
                                        $role === 'creator' ? $builder->whereHas('channel') : $builder->whereDoesntHave('channel');
                                    });
                                }
                            });
                    });
            });
    }

    private function validatedAdminUserRole(string $role): string
    {
        $normalized = strtolower(str_replace(['_', '-'], ' ', trim($role)));
        if (! in_array($normalized, ['user', 'creator', 'moderator', 'admin', 'super admin', 'owner'], true)) {
            throw ValidationException::withMessages(['role' => 'Select a valid user role.']);
        }

        return $normalized;
    }

    private function settingsWithAdminUserRole(array $settings, string $role, ?int $actorId): array
    {
        $admin = $this->roleGetsDefaultProfileBadge($role);
        $settings['role'] = $role;
        if (array_key_exists('account_role', $settings)) {
            $settings['account_role'] = $role;
        }
        $settings['is_admin'] = $admin;
        $settings['admin'] = is_array($settings['admin'] ?? null)
            ? [...$settings['admin'], 'enabled' => $admin]
            : $admin;
        $settings['role_updated_at'] = now()->toIso8601String();
        $settings['role_updated_by'] = $actorId;

        return $settings;
    }

    private function applyAdminUserStatusFilter($query, string $status): void
    {
        if ($status === 'active') {
            $query->where(function ($builder): void {
                $builder->whereNull('settings->status')->orWhere('settings->status', 'active');
            });

            return;
        }

        $query->where('settings->status', $status);
    }

    private function normalizeAdminUserRole(?string $role): string
    {
        $role = strtolower(str_replace(['_', '-'], ' ', trim((string) $role)));

        return match ($role) {
            'admin', 'super admin', 'owner', 'creator', 'moderator' => $role,
            default => 'user',
        };
    }

    private function roleGetsDefaultProfileBadge(?string $role): bool
    {
        return in_array($this->normalizeAdminUserRole($role), ['admin', 'super admin', 'owner'], true);
    }

    private function normalizeAdminUserStatus(?string $status): string
    {
        $status = strtolower(str_replace(['_', '-'], ' ', trim((string) $status)));

        return match ($status) {
            'suspended', 'deleted', 'invited', 'pending' => $status === 'pending' ? 'invited' : $status,
            default => 'active',
        };
    }

    private function uniqueUsername(string $base): string
    {
        $base = trim($base, '-') ?: 'user';
        $username = Str::limit($base, 72, '');
        $suffix = 1;

        while (User::query()->where('username', $username)->exists()) {
            $username = Str::limit($base, 64, '').'-'.$suffix++;
        }

        return $username;
    }

    private function uniqueChannelSlug(string $base): string
    {
        $base = trim($base, '-') ?: 'channel';
        $slug = Str::limit($base, 72, '');
        $suffix = 1;

        while (Channel::query()->where('slug', $slug)->exists()) {
            $slug = Str::limit($base, 66, '').'-'.$suffix++;
        }

        return $slug;
    }

    private function appVersion(): string
    {
        return app(VersionRepository::class)->current();
    }

    private function adminEmailDeliveryConfigured(): bool
    {
        if (IntegrationSettings::configureSmtpMailer()) {
            return true;
        }

        $driver = (string) config('mail.default', '');

        return in_array($driver, ['smtp', 'ses', 'mailgun', 'postmark', 'sendmail'], true)
            && filled(config('mail.from.address'));
    }

    private function sendBanNotificationEmail(User $user, Carbon $expiresAt, array $scopes): bool
    {
        if (! filled($user->email) || ! $this->adminEmailDeliveryConfigured()) {
            return false;
        }

        $scopeLabels = [
            'account' => 'Account access locked',
            'comments' => 'Commenting disabled',
            'uploads' => 'Video uploads disabled',
            'watch' => 'Video and Shorts playback disabled',
        ];
        $restrictions = collect($scopes)
            ->map(fn (string $scope): string => '- '.($scopeLabels[$scope] ?? $scope))
            ->implode("\n");
        $template = EmailTemplates::render('account_restriction', [
            'USERNAME' => $user->name,
            'USER_EMAIL' => $user->email,
            'BAN_EXPIRES_AT' => $expiresAt->format('M j, Y g:i A'),
            'RESTRICTIONS' => $restrictions,
            'SUPPORT_EMAIL' => data_get(IntegrationSettings::settings(), 'sendgrid.notificationsEmail') ?: data_get(IntegrationSettings::settings(), 'smtp.fromEmail') ?: config('mail.from.address', ''),
        ]);

        try {
            $subject = $template['subject'];
            $body = $template['body'];
            $locale = $template['locale'];
            Mail::raw($body, function ($message) use ($user, $subject, $body, $locale): void {
                BrandedEmail::apply($message, $subject, $body, $locale);
                $message->to($user->email, $user->name)->subject($subject);
            });

            return true;
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function sendModeratorAssignmentEmail(User $user): bool
    {
        if (! filled($user->email) || ! $this->adminEmailDeliveryConfigured()) {
            return false;
        }

        try {
            $template = EmailTemplates::render('moderator_assignment', [
                'USERNAME' => $user->name,
                'USER_EMAIL' => $user->email,
                'MODERATION_URL' => url('/admin/reports'),
            ]);
            $subject = $template['subject'];
            $body = $template['body'];
            $locale = $template['locale'];
            Mail::raw($body, function ($message) use ($user, $subject, $body, $locale): void {
                BrandedEmail::apply($message, $subject, $body, $locale);
                $message->to($user->email, $user->name)->subject($subject);
            });

            return true;
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function guardAgainstSelfLockout(Request $request, User $user, string $action): void
    {
        abort_if($request->user()?->id === $user->id, 422, 'You cannot '.$action.' your own admin account.');
    }

    private function guardModeratorUserTarget(Request $request, User $user, string $action): void
    {
        if ($this->isAdmin($request->user())) {
            return;
        }

        abort_if($request->user()?->id === $user->id, 403, 'Moderators cannot '.$action.' their own account.');
        abort_if($this->isAdmin($user) || $this->isModerator($user), 403, 'Moderators cannot '.$action.' staff accounts.');
    }

    private function adminUserRows(): array
    {
        return User::query()
            ->with('channel')
            ->latest()
            ->take(10)
            ->get()
            ->map(function (User $user): array {
                return $this->adminUserPayload($user);
            })
            ->values()
            ->all();
    }

    private function adminCreatorRows(Carbon $start, Carbon $end): array
    {
        return Channel::query()
            ->with('user')
            ->withSum('videos as total_views', 'views_count')
            ->withSum(['videos as views_28d' => fn ($query) => $query->whereBetween('created_at', [$start, $end])], 'views_count')
            ->orderByDesc('views_28d')
            ->orderByDesc('subscribers_count')
            ->take(10)
            ->get()
            ->map(function (Channel $channel) use ($start, $end): array {
                $settings = is_array($channel->user?->settings) ? $channel->user->settings : [];

                return [
                    'id' => (int) $channel->id,
                    'user_id' => (int) $channel->user_id,
                    'slug' => $channel->slug,
                    'profileUrl' => '/channel/'.$channel->slug,
                    'person' => $this->personForChannel($channel),
                    'category' => $this->dominantCategoryForChannel($channel->id),
                    'subscribers' => $this->formatCompact((int) $channel->subscribers_count),
                    'views' => $this->formatCompact((int) ($channel->views_28d ?? 0)),
                    'revenue' => '$0',
                    'verification' => $channel->user?->verified_at ? 'Verified' : 'Pending',
                    'status' => strtolower((string) ($settings['status'] ?? 'active')) === 'suspended' ? 'Suspended' : 'Active',
                    'active' => (int) ($channel->views_28d ?? 0) > 0,
                    'partnered' => (bool) ($settings['creator_partnered'] ?? false),
                    'applied' => $channel->created_at && Carbon::parse($channel->created_at)->betweenIncluded($start, $end),
                ];
            })
            ->values()
            ->all();
    }

    private function dominantCategoryForChannel(int $channelId): string
    {
        $category = DB::table('videos')
            ->leftJoin('video_categories', 'video_categories.id', '=', 'videos.category_id')
            ->where('videos.channel_id', $channelId)
            ->whereNotNull('video_categories.name')
            ->select('video_categories.name')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('video_categories.name')
            ->orderByDesc('aggregate')
            ->value('video_categories.name');

        return $category ?: 'Uncategorized';
    }

    private function adminContentRows(int $limit = 25): array
    {
        return Video::query()
            ->with(['channel.user', 'category'])
            ->latest()
            ->take($limit)
            ->get()
            ->map(fn (Video $video): array => $this->adminContentPayload($video))
            ->values()
            ->all();
    }

    private function adminContentPayload(Video $video): array
    {
        return [
            'id' => (int) $video->id,
            'slug' => $video->slug,
            'watchUrl' => $this->videoPlaybackPath($video),
            'editUrl' => '/studio/videos/'.$video->slug.'/edit',
            'title' => $video->title,
            'caption' => $this->shortText((string) ($video->description ?: $video->processing_stage ?: ''), 72),
            'creator' => $this->personForChannel($video->channel),
            'type' => $video->source_type === 'live_replay' ? 'Live Replay' : ($video->status === 'live' ? 'Live' : ($video->isShort() ? 'Short' : 'Video')),
            'category' => $video->category?->name ?: 'Uncategorized',
            'visibility' => ucfirst($video->visibility ?: 'private'),
            'date' => optional($video->published_at ?: $video->created_at)->format('M j, Y g:i A') ?: '-',
            'views' => $this->formatCompact((int) $video->views_count),
            'status' => $this->videoStatusLabel($video),
            'approval_status' => $video->approval_status ?: 'approved',
            'thumb' => $this->thumbForVideo($video),
            'thumbnail_url' => $video->thumbnail_url,
        ];
    }

    private function adminLiveRows(): array
    {
        $streams = LiveStream::query()
            ->with(['channel.user', 'category'])
            ->latest('scheduled_at')
            ->latest()
            ->take(25)
            ->get();
        [$roomViewerCounts, $streamViewerCounts] = $this->adminLiveViewerCounts($streams);

        return $streams
            ->map(fn (LiveStream $stream): array => $this->adminLivePayload($stream, $roomViewerCounts, $streamViewerCounts))
            ->values()
            ->all();
    }

    private function adminLivePayload(LiveStream $stream, array $roomViewerCounts, array $streamViewerCounts): array
    {
        $preflightReady = (bool) data_get($stream->preflight, 'ready', false);
        $healthLabel = ! $stream->preflight_tested_at ? 'Not tested' : ($preflightReady ? 'Ready' : 'Needs attention');
        $healthDetail = $stream->preflight_tested_at
            ? number_format((float) data_get($stream->preflight, 'upload_mbps', 0), 1).' Mbps upload'
            : 'No connection data';
        $viewerCount = $stream->status === 'live'
            ? (int) ($roomViewerCounts['playhub-live-'.$stream->uuid] ?? $streamViewerCounts[$stream->id] ?? 0)
            : (int) ($streamViewerCounts[$stream->id] ?? 0);

        return [
            'id' => (int) $stream->id,
            'slug' => $stream->slug,
            'watchUrl' => '/live/'.$stream->slug,
            'title' => $stream->title ?: 'Untitled live stream',
            'lang' => ucfirst($stream->source_type ?: 'webcam'),
            'streamer' => $this->personForChannel($stream->channel),
            'category' => $stream->category?->name ?: 'All',
            'status' => $stream->status === 'scheduled' ? 'Scheduled' : ucfirst(str_replace('-', ' ', $stream->status)),
            'status_tone' => $stream->status,
            'start' => optional($stream->scheduled_at ?: $stream->actual_started_at ?: $stream->created_at)->format('M j, Y g:i A') ?: '-',
            'viewers' => $this->formatCompact($viewerCount),
            'health' => $healthLabel."\n".$healthDetail,
            'health_status' => $healthLabel,
            'thumb' => 'default',
            'thumbnail_url' => LiveStreamMedia::thumbnailUrl($stream, true),
        ];
    }

    private function adminLiveViewerCounts($streams): array
    {
        $streamIds = $streams->pluck('id');
        $roomViewerCounts = $streams->contains(fn (LiveStream $stream): bool => $stream->status === 'live')
            ? LiveKitSettings::activeRoomViewerCounts()
            : [];
        $streamViewerCounts = $streamIds->isEmpty() || ! Schema::hasTable('live_stream_viewers')
            ? []
            : DB::table('live_stream_viewers')
                ->whereIn('live_stream_id', $streamIds)
                ->selectRaw('live_stream_id, COUNT(DISTINCT user_id) as aggregate')
                ->groupBy('live_stream_id')
                ->pluck('aggregate', 'live_stream_id')
                ->map(fn ($count): int => (int) $count)
                ->all();

        return [$roomViewerCounts, $streamViewerCounts];
    }

    private function videoStatusLabel(Video $video): string
    {
        if (($video->approval_status ?: 'approved') === 'pending') {
            return 'Pending Review';
        }
        if ($video->scheduled_at && $video->scheduled_at->isFuture()) {
            return 'Scheduled';
        }

        return match ($video->status) {
            'ready' => 'Published',
            'processing' => 'Processing',
            'live' => 'Live',
            'ended' => 'Live Replay',
            default => ucfirst((string) ($video->status ?: 'Draft')),
        };
    }

    private function thumbForVideo(Video $video): string
    {
        $category = strtolower((string) ($video->category?->slug ?? $video->category?->name ?? 'default'));

        return match (true) {
            str_contains($category, 'game') => 'game',
            str_contains($category, 'music') => 'music',
            str_contains($category, 'tech') => 'code',
            str_contains($category, 'design') => 'design',
            str_contains($category, 'travel') => 'travel',
            default => 'default',
        };
    }

    private function adminReportRows(int $limit = 10): array
    {
        $commentRows = DB::table('comment_reports')
            ->join('comments', 'comments.id', '=', 'comment_reports.comment_id')
            ->join('users as reporters', 'reporters.id', '=', 'comment_reports.user_id')
            ->leftJoin('users as authors', 'authors.id', '=', 'comments.user_id')
            ->leftJoin('channels as author_channels', 'author_channels.user_id', '=', 'authors.id')
            ->leftJoin('videos', 'videos.id', '=', 'comments.video_id')
            ->select([
                'comment_reports.id',
                'comment_reports.comment_id',
                'comment_reports.updated_at',
                'comments.body',
                'reporters.name as reporter_name',
                'reporters.username as reporter_username',
                'reporters.email as reporter_email',
                'authors.name as author_name',
                'authors.username as author_username',
                'authors.email as author_email',
                'videos.title as video_title',
                'videos.slug as video_slug',
                'videos.duration_seconds as video_duration_seconds',
                'videos.video_width as video_width',
                'videos.video_height as video_height',
                'videos.source_type as video_source_type',
                'videos.is_short as video_is_short',
                'videos.sale_enabled as video_sale_enabled',
                'videos.thumbnail_path as video_thumbnail_path',
                'author_channels.avatar_path as author_avatar_path',
            ])
            ->selectRaw('(select count(*) from comment_reports cr where cr.comment_id = comments.id) as report_count')
            ->orderByDesc('comment_reports.updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                $reportCount = (int) $row->report_count;
                $isShort = Video::isShortContent($row->video_source_type, $row->video_duration_seconds, $row->video_is_short, (bool) $row->video_sale_enabled, $row->video_width, $row->video_height);

                return [
                    'id' => (int) $row->id,
                    'comment_id' => (int) $row->comment_id,
                    'type' => 'Comment',
                    'title' => $this->shortText((string) $row->body, 72) ?: 'Reported comment',
                    'subtitle' => $row->video_title ? 'on '.$this->shortText($row->video_title, 42) : 'by @'.($row->author_username ?: 'unknown'),
                    'target_url' => $row->video_slug ? ($isShort ? '/shorts/' : '/watch/').$row->video_slug.'#comment-'.$row->comment_id : '/admin/reports',
                    'reporter' => ($row->reporter_name ?: 'User')."\n".($row->reporter_email ?: '@'.($row->reporter_username ?: 'unknown')),
                    'reason' => 'User report'.($reportCount > 1 ? ' x'.$reportCount : ''),
                    'priority' => $this->reportPriority($reportCount),
                    'moderator' => $this->adminPeople()[0] ?? ['name' => 'Unassigned', 'handle' => '@admin', 'email' => '', 'tone' => 'mono'],
                    'status' => 'Pending',
                    'thumb' => 'comment',
                    'thumbnail_url' => ($row->video_thumbnail_path ?: $row->author_avatar_path) ? MediaStorage::url($row->video_thumbnail_path ?: $row->author_avatar_path) : null,
                    '_reported_at_sort' => Carbon::parse($row->updated_at)->timestamp,
                ];
            })
            ->values();

        return $commentRows
            ->concat($this->adminVideoReportRows($limit))
            ->concat($this->adminUserReportRows($limit))
            ->sortByDesc('_reported_at_sort')
            ->take($limit)
            ->map(function (array $row): array {
                unset($row['_reported_at_sort']);

                return $row;
            })
            ->values()
            ->all();
    }

    private function adminVideoReportRows(int $limit)
    {
        if (! $this->videoReportsEnabled()) {
            return collect();
        }

        return DB::table('video_reports')
            ->join('videos', 'videos.id', '=', 'video_reports.video_id')
            ->join('users as reporters', 'reporters.id', '=', 'video_reports.user_id')
            ->leftJoin('users as authors', 'authors.id', '=', 'videos.user_id')
            ->select([
                'video_reports.id',
                'video_reports.video_id',
                'video_reports.updated_at',
                'videos.title',
                'reporters.name as reporter_name',
                'reporters.username as reporter_username',
                'reporters.email as reporter_email',
                'authors.name as author_name',
                'authors.username as author_username',
                'authors.email as author_email',
                'videos.slug',
                'videos.duration_seconds',
                'videos.video_width',
                'videos.video_height',
                'videos.source_type',
                'videos.is_short',
                'videos.sale_enabled',
                'videos.thumbnail_path',
            ])
            ->selectRaw('(select count(*) from video_reports vr where vr.video_id = videos.id) as report_count')
            ->orderByDesc('video_reports.updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                $reportCount = (int) $row->report_count;
                $isShort = Video::isShortContent($row->source_type, $row->duration_seconds, $row->is_short, (bool) $row->sale_enabled, $row->video_width, $row->video_height);

                return [
                    'id' => (int) $row->id,
                    'video_id' => (int) $row->video_id,
                    'type' => 'Video',
                    'title' => $this->shortText((string) $row->title, 72) ?: 'Reported video',
                    'subtitle' => 'by @'.($row->author_username ?: $row->author_name ?: 'unknown'),
                    'target_url' => $row->slug ? ($isShort ? '/shorts/' : '/watch/').$row->slug : '/admin/reports',
                    'reporter' => ($row->reporter_name ?: 'User')."\n".($row->reporter_email ?: '@'.($row->reporter_username ?: 'unknown')),
                    'reason' => 'Video report'.($reportCount > 1 ? ' x'.$reportCount : ''),
                    'priority' => $this->reportPriority($reportCount),
                    'moderator' => $this->adminPeople()[0] ?? ['name' => 'Unassigned', 'handle' => '@admin', 'email' => '', 'tone' => 'mono'],
                    'status' => 'Pending',
                    'thumb' => 'video',
                    'thumbnail_url' => $row->thumbnail_path ? MediaStorage::url($row->thumbnail_path) : null,
                    '_reported_at_sort' => Carbon::parse($row->updated_at)->timestamp,
                ];
            })
            ->values();
    }

    private function adminUserReportRows(int $limit)
    {
        if (! $this->userReportsEnabled()) {
            return collect();
        }

        return DB::table('user_reports')
            ->join('users as reporters', 'reporters.id', '=', 'user_reports.user_id')
            ->join('users as reported_users', 'reported_users.id', '=', 'user_reports.reported_user_id')
            ->leftJoin('channels', 'channels.user_id', '=', 'reported_users.id')
            ->select([
                'user_reports.id',
                'user_reports.reported_user_id',
                'user_reports.updated_at',
                'reporters.name as reporter_name',
                'reporters.username as reporter_username',
                'reporters.email as reporter_email',
                'reported_users.name as reported_name',
                'reported_users.username as reported_username',
                'channels.name as channel_name',
                'channels.slug as channel_slug',
                'channels.avatar_path as profile_avatar_path',
            ])
            ->selectRaw('(select count(*) from user_reports ur where ur.reported_user_id = user_reports.reported_user_id) as report_count')
            ->orderByDesc('user_reports.updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                $reportCount = (int) $row->report_count;

                return [
                    'id' => (int) $row->id,
                    'user_id' => (int) $row->reported_user_id,
                    'type' => 'Profile',
                    'title' => $this->shortText((string) ($row->channel_name ?: $row->reported_name), 72) ?: 'Reported profile',
                    'subtitle' => '@'.($row->channel_slug ?: $row->reported_username ?: 'unknown'),
                    'target_url' => $row->channel_slug ? '/channel/'.$row->channel_slug : '/admin/users?search='.rawurlencode((string) ($row->reported_username ?: $row->reported_name)),
                    'reporter' => ($row->reporter_name ?: 'User')."\n".($row->reporter_email ?: '@'.($row->reporter_username ?: 'unknown')),
                    'reason' => 'Profile report'.($reportCount > 1 ? ' x'.$reportCount : ''),
                    'priority' => $this->reportPriority($reportCount),
                    'moderator' => $this->adminPeople()[0] ?? ['name' => 'Unassigned', 'handle' => '@admin', 'email' => '', 'tone' => 'mono'],
                    'status' => 'Pending',
                    'thumb' => 'users',
                    'thumbnail_url' => $row->profile_avatar_path ? MediaStorage::url($row->profile_avatar_path) : null,
                    '_reported_at_sort' => Carbon::parse($row->updated_at)->timestamp,
                ];
            })
            ->values();
    }

    private function contactMessageTickets(int $limit = 20): array
    {
        $agent = $this->adminPeople()[0] ?? ['name' => 'Admin', 'handle' => '@admin', 'email' => '', 'tone' => 'orange'];

        return DB::table('contact_messages')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'raw_id' => (int) $row->id,
                'id' => '#MSG-'.str_pad((string) $row->id, 4, '0', STR_PAD_LEFT),
                'subject' => $row->subject ?: 'Contact request: '.Str::headline((string) $row->topic),
                'preview' => $this->shortText((string) $row->message, 120),
                'user' => ['name' => $row->name ?: 'Contact', 'handle' => '@contact', 'email' => $row->email ?: '', 'tone' => 'blue'],
                'category' => 'Contact / '.Str::headline((string) $row->topic),
                'priority' => $row->priority ?: 'Normal',
                'agent' => $agent,
                'updated' => Carbon::parse($row->created_at)->diffForHumans(),
                'sla' => match ((string) ($row->status ?? 'open')) {
                    'in_progress' => 'In Progress',
                    'waiting' => 'Waiting on User',
                    'resolved' => 'Resolved',
                    default => 'Open',
                },
            ])
            ->values()
            ->all();
    }

    private function contactMessageDetail(object $row): array
    {
        $respondedBy = null;
        if (! empty($row->responded_by)) {
            $respondedBy = User::query()->find((int) $row->responded_by);
        }

        return [
            'raw_id' => (int) $row->id,
            'id' => '#MSG-'.str_pad((string) $row->id, 4, '0', STR_PAD_LEFT),
            'subject' => $row->subject ?: 'Contact request: '.Str::headline((string) $row->topic),
            'message' => (string) $row->message,
            'response' => (string) ($row->admin_response ?? ''),
            'status' => (string) ($row->status ?? 'open'),
            'priority' => (string) ($row->priority ?? 'Normal'),
            'category' => 'Contact / '.Str::headline((string) $row->topic),
            'user' => ['name' => $row->name ?: 'Contact', 'handle' => '@contact', 'email' => $row->email ?: '', 'tone' => 'blue'],
            'submitted_at' => $row->created_at ? Carbon::parse($row->created_at)->format('M j, Y g:i A') : '',
            'updated_at' => $row->updated_at ? Carbon::parse($row->updated_at)->diffForHumans() : '',
            'read_at' => $row->read_at ? Carbon::parse($row->read_at)->format('M j, Y g:i A') : '',
            'responded_at' => $row->responded_at ? Carbon::parse($row->responded_at)->format('M j, Y g:i A') : '',
            'responded_by' => $respondedBy ? $this->personForUser($respondedBy) : null,
            'ip_address' => (string) ($row->ip_address ?? ''),
            'user_agent' => $this->shortText((string) ($row->user_agent ?? ''), 180),
        ];
    }

    private function supportTickets(array $reportRows, int $failedJobs, int $failedUploads, int $queueCount): array
    {
        $agent = $this->adminPeople()[0] ?? ['name' => 'Admin', 'handle' => '@admin', 'email' => '', 'tone' => 'orange'];
        $tickets = collect($this->contactMessageTickets())->concat(collect($reportRows)->map(fn (array $report, int $index): array => [
            'id' => '#MOD-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            'subject' => $report['title'],
            'preview' => $report['reason'],
            'user' => ['name' => explode("\n", $report['reporter'])[0] ?: 'Reporter', 'handle' => '@reporter', 'email' => explode("\n", $report['reporter'])[1] ?? '', 'tone' => 'rose'],
            'category' => 'Moderation',
            'priority' => $report['priority'],
            'agent' => $agent,
            'updated' => 'now',
            'sla' => 'Open',
        ]));

        if ($failedJobs > 0) {
            $tickets->push($this->systemTicket('#JOB-'.$failedJobs, 'Failed background jobs', $failedJobs.' failed jobs require attention.', 'Technical', 'High', $agent));
        }

        if ($failedUploads > 0) {
            $tickets->push($this->systemTicket('#UP-'.$failedUploads, 'Failed upload sessions', $failedUploads.' upload sessions failed.', 'Video Upload', 'Medium', $agent));
        }

        if ($queueCount > 0) {
            $tickets->push($this->systemTicket('#QUEUE-'.$queueCount, 'Processing queue active', $queueCount.' jobs/videos are waiting or processing.', 'Technical', 'Low', $agent));
        }

        return $tickets->take(25)->values()->all();
    }

    private function systemTicket(string $id, string $subject, string $preview, string $category, string $priority, array $agent): array
    {
        return [
            'id' => $id,
            'subject' => $subject,
            'preview' => $preview,
            'user' => ['name' => 'System', 'handle' => '@system', 'email' => 'system@playhub.local', 'tone' => 'mono'],
            'category' => $category,
            'priority' => $priority,
            'agent' => $agent,
            'updated' => 'now',
            'sla' => 'Open',
        ];
    }

    private function adminRoleRows(): array
    {
        $permissionGroups = collect($this->permissionGroupRows())->keyBy('id');
        $admins = User::query()->get()->filter(fn (User $user): bool => $this->isAdmin($user))->values();
        $members = $admins->map(fn (User $user): array => $this->personForUser($user))->all();
        $moderators = User::query()->get()->filter(fn (User $user): bool => $this->isModerator($user))->values();
        $moderatorMembers = $moderators->map(fn (User $user): array => $this->personForUser($user))->all();

        $rows = [[
            'id' => 'super-admin',
            'name' => 'Super Admin',
            'desc' => 'Full platform access',
            'icon' => 'shield',
            'members' => $members ?: [['name' => 'No admins', 'handle' => '@none', 'email' => '', 'tone' => 'mono']],
            'count' => count($members),
            'scope' => 'Global',
            'updated' => optional($admins->max('updated_at'))->format('M j, Y') ?: now()->format('M j, Y'),
            'status' => 'Active',
            'custom' => false,
            'group_id' => 'platform-administration',
            'permissions' => $permissionGroups->get('platform-administration')['permissions'] ?? $this->permissionCatalog(),
        ], [
            'id' => 'moderator',
            'name' => 'Moderator',
            'desc' => 'Content review, comment removal, video approval, and user restrictions',
            'icon' => 'reports',
            'members' => $moderatorMembers ?: [['name' => 'No moderators', 'handle' => '@none', 'email' => '', 'tone' => 'mono']],
            'count' => count($moderatorMembers),
            'scope' => 'Moderation',
            'updated' => optional($moderators->max('updated_at'))->format('M j, Y') ?: now()->format('M j, Y'),
            'status' => 'Active',
            'custom' => false,
            'group_id' => 'moderation',
            'permissions' => $permissionGroups->get('moderation')['permissions'] ?? ['Dashboard access', 'Content moderation', 'Reports moderation', 'User restrictions'],
        ]];

        $customRoles = collect(data_get(PlatformFeatureSettings::settings(), 'accessControl.customRoles', []))
            ->filter(fn ($role): bool => is_array($role) && filled($role['name'] ?? null))
            ->map(function (array $role) use ($permissionGroups): array {
                $group = $permissionGroups->get($role['group_id'] ?? '');

                return [
                    'id' => (string) ($role['id'] ?? Str::slug($role['name'])),
                    'name' => (string) $role['name'],
                    'desc' => (string) ($role['description'] ?? 'Custom admin role'),
                    'icon' => 'shield',
                    'members' => [['name' => 'No members', 'handle' => '@none', 'email' => '', 'tone' => 'mono']],
                    'count' => 0,
                    'scope' => (string) ($role['scope'] ?? 'Restricted'),
                    'updated' => optional(Carbon::parse($role['updated_at'] ?? now()))->format('M j, Y'),
                    'status' => 'Active',
                    'custom' => true,
                    'group_id' => (string) ($role['group_id'] ?? ''),
                    'permissions' => array_values($group['permissions'] ?? ($role['permissions'] ?? [])),
                ];
            })
            ->values()
            ->all();

        return array_values(array_merge($rows, $customRoles));
    }

    private function permissionCatalog(): array
    {
        return [
            'Dashboard access',
            'User management',
            'Creator management',
            'Content moderation',
            'Reports moderation',
            'Support tickets',
            'Analytics reports',
            'Monetization settings',
            'Website settings',
            'Security settings',
            'System settings',
            'Data export',
            'User restrictions',
        ];
    }

    private function defaultPermissionGroups(): array
    {
        return [[
            'id' => 'platform-administration',
            'name' => 'Platform Administration',
            'description' => 'Full platform administration permissions',
            'permissions' => $this->permissionCatalog(),
        ], [
            'id' => 'moderation',
            'name' => 'Moderation',
            'description' => 'Content, reports, and user restriction permissions',
            'permissions' => ['Dashboard access', 'User management', 'Content moderation', 'Reports moderation', 'Support tickets', 'User restrictions'],
        ], [
            'id' => 'support',
            'name' => 'Support',
            'description' => 'Support inbox and user lookup permissions',
            'permissions' => ['Dashboard access', 'User management', 'Support tickets'],
        ], [
            'id' => 'analytics',
            'name' => 'Analytics',
            'description' => 'Analytics and export permissions',
            'permissions' => ['Dashboard access', 'Analytics reports', 'Data export'],
        ]];
    }

    private function permissionGroupRows(): array
    {
        $groups = collect($this->defaultPermissionGroups())->keyBy('id');
        collect(data_get(PlatformFeatureSettings::settings(), 'accessControl.permissionGroups', []))
            ->filter(fn ($group): bool => is_array($group) && filled($group['id'] ?? null) && filled($group['name'] ?? null))
            ->each(function (array $group) use (&$groups): void {
                $permissions = collect($group['permissions'] ?? [])
                    ->filter(fn ($permission): bool => in_array($permission, $this->permissionCatalog(), true))
                    ->values()
                    ->all();

                if ($permissions) {
                    $groups->put((string) $group['id'], [
                        'id' => (string) $group['id'],
                        'name' => (string) $group['name'],
                        'description' => (string) ($group['description'] ?? ''),
                        'permissions' => $permissions,
                    ]);
                }
            });

        $customRoles = collect(data_get(PlatformFeatureSettings::settings(), 'accessControl.customRoles', []));

        return $groups
            ->values()
            ->map(function (array $group) use ($customRoles): array {
                $roles = $customRoles->where('group_id', $group['id'])->count();
                if ($group['id'] === 'platform-administration') {
                    $roles++;
                } elseif ($group['id'] === 'moderation') {
                    $roles++;
                }

                return [
                    'id' => $group['id'],
                    'name' => $group['name'],
                    'description' => $group['description'],
                    'permissions' => array_values($group['permissions']),
                    'permissionSummary' => implode("\n", array_slice($group['permissions'], 0, 4)).(count($group['permissions']) > 4 ? "\n+".(count($group['permissions']) - 4).' more' : ''),
                    'roleCount' => $roles,
                    'status' => 'Active',
                ];
            })
            ->values()
            ->all();
    }

    private function permissionMatrixRows(array $roleRows): array
    {
        return collect($roleRows)
            ->map(fn (array $role): array => [
                'role' => $role['name'] ?? '',
                'scope' => $role['scope'] ?? '',
                'members' => (int) ($role['count'] ?? 0),
                'permissions' => array_values($role['permissions'] ?? []),
                'permissionSummary' => implode("\n", array_slice($role['permissions'] ?? [], 0, 5)).(count($role['permissions'] ?? []) > 5 ? "\n+".(count($role['permissions']) - 5).' more' : ''),
                'status' => $role['status'] ?? 'Active',
            ])
            ->values()
            ->all();
    }

    private function uniqueAccessControlId(array $ids, string $name, string $fallback): string
    {
        $base = Str::slug($name) ?: $fallback;
        $id = $base;
        $index = 2;

        while (in_array($id, $ids, true)) {
            $id = $base.'-'.$index;
            $index++;
        }

        return $id;
    }

    private function teamRows(): array
    {
        return User::query()
            ->get()
            ->filter(fn (User $user): bool => $this->isAdmin($user) || $this->isModerator($user))
            ->map(function (User $user): array {
                $role = $this->adminUserPayload($user)['raw']['role'];

                return [
                    'id' => (int) $user->id,
                    'person' => $this->personForUser($user),
                    'role' => Str::headline($role),
                    'raw' => ['role' => $role],
                    'scope' => $this->isAdmin($user) ? 'Global' : 'Moderation',
                    'joined' => optional($user->created_at)->format('M j, Y') ?: '-',
                    'active' => optional($user->updated_at)->diffForHumans() ?: '-',
                    'status' => $this->displayUserStatus($user),
                ];
            })
            ->values()
            ->all();
    }

    private function payoutRows(array $creatorRows): array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return [];
        }

        return DB::table('wallet_transactions')
            ->where('type', 'withdraw')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn ($transaction): array => $this->payoutRow($transaction))
            ->values()
            ->all();
    }

    private function payoutRow(object $transaction): array
    {
        $user = User::query()->with('channel')->find($transaction->user_id);
        $method = trim((string) ($transaction->method ?: 'Wallet'));
        $payload = json_decode((string) $transaction->payload, true) ?: [];

        return [
            'id' => (int) $transaction->id,
            'user_id' => (int) $transaction->user_id,
            'person' => $this->personForUser($user),
            'method' => $method,
            'account' => (string) ($transaction->account ?? ''),
            'note' => (string) ($transaction->note ?? ''),
            'amount' => MonetizationSettings::formatMoney((int) $transaction->amount_cents),
            'amount_cents' => (int) $transaction->amount_cents,
            'currency' => (string) $transaction->currency,
            'status' => $transaction->status === 'completed' ? 'Approved' : ucfirst((string) $transaction->status),
            'status_key' => (string) $transaction->status,
            'date' => $transaction->created_at ? Carbon::parse($transaction->created_at)->format('M j, Y') : '-',
            'processed_at' => $transaction->processed_at ? Carbon::parse($transaction->processed_at)->toIso8601String() : null,
            'review_note' => (string) ($payload['decision_note'] ?? ''),
            'reviewed_by' => (string) ($payload['reviewer_name'] ?? ''),
            'icon' => str_contains(strtolower($method), 'paypal') ? 'paypal' : 'bank',
        ];
    }

    private function pendingPayoutCents(): int
    {
        return Schema::hasTable('wallet_transactions')
            ? (int) DB::table('wallet_transactions')->where('type', 'withdraw')->where('status', 'pending')->sum('amount_cents')
            : 0;
    }

    private function topEarningCreators(array $fallback): array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return $fallback;
        }

        $rows = DB::table('wallet_transactions')
            ->select('user_id')
            ->selectRaw('SUM(amount_cents) as cents')
            ->selectRaw('COUNT(*) as events')
            ->where('type', 'earning')
            ->where('status', 'completed')
            ->groupBy('user_id')
            ->orderByDesc('cents')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return $fallback;
        }

        $users = User::query()->with('channel')->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        return $rows->map(fn ($row): array => [
            'person' => $this->personForUser($users->get($row->user_id)),
            'value' => MonetizationSettings::formatMoney((int) $row->cents),
            'delta' => $row->events.' events',
        ])->values()->all();
    }

    private function recentSignupActivity(): array
    {
        return User::query()
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (User $user): array => [$this->personForUser($user), $user->email, optional($user->created_at)->diffForHumans() ?: '-'])
            ->values()
            ->all();
    }

    private function segmentRows(array $rows, int $total): array
    {
        $total = max($total, 1);

        return collect($rows)->map(fn (array $row): array => [
            $row[0],
            $this->formatCompact((int) $row[1]).' ('.number_format(((int) $row[1] / $total) * 100, 1).'%)',
            $row[2],
        ])->values()->all();
    }

    private function verificationQueue(): array
    {
        return Channel::query()
            ->with('user')
            ->whereHas('user', fn ($query) => $query->whereNull('verified_at'))
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (Channel $channel): array => [$this->personForChannel($channel), 'Created '.optional($channel->created_at)->diffForHumans(), ''])
            ->values()
            ->all();
    }

    private function scheduleRows(): array
    {
        return Video::query()
            ->with(['channel.user', 'category'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now()->startOfDay())
            ->orderBy('scheduled_at')
            ->take(5)
            ->get()
            ->map(fn (Video $video): array => [
                $this->personForChannel($video->channel),
                $video->title,
                $video->category?->name ?: 'Uncategorized',
                optional($video->scheduled_at)->format('g:i A') ?: '-',
            ])
            ->values()
            ->all();
    }

    private function liveScheduleRows(): array
    {
        return LiveStream::query()
            ->with(['channel.user', 'category'])
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>=', now()->startOfDay())
            ->orderBy('scheduled_at')
            ->take(5)
            ->get()
            ->map(fn (LiveStream $stream): array => [
                $this->personForChannel($stream->channel),
                $stream->title ?: 'Untitled live stream',
                $stream->category?->name ?: 'All',
                optional($stream->scheduled_at)->format('g:i A') ?: '-',
            ])
            ->values()
            ->all();
    }

    private function topCategoryProgress(): array
    {
        return DB::table('videos')
            ->leftJoin('video_categories', 'video_categories.id', '=', 'videos.category_id')
            ->selectRaw('COALESCE(video_categories.name, "Uncategorized") as label, COUNT(*) as aggregate')
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'label' => $row->label,
                'value' => (string) $row->aggregate,
                'color' => '#6b2fff',
                'icon' => 'content',
            ])
            ->values()
            ->all();
    }

    private function topLiveCategoryProgress(): array
    {
        return DB::table('live_streams')
            ->leftJoin('live_stream_categories', 'live_stream_categories.id', '=', 'live_streams.category_id')
            ->selectRaw('COALESCE(live_stream_categories.name, "All") as label, COUNT(*) as aggregate')
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'value' => (string) $row->aggregate, 'color' => '#6b2fff', 'icon' => 'live'])
            ->values()
            ->all();
    }

    private function reportCategoryRows(int $contentReports, int $profileReports): array
    {
        return [
            ['Content reports', (string) $contentReports, '#ff4239'],
            ['Profile reports', (string) $profileReports, '#f8b62d'],
            ['Copyright infringement', '0 (0%)', '#20c56b'],
            ['Appeals', '0 (0%)', '#8b5cf6'],
        ];
    }

    private function moderatorsOnline(): array
    {
        return User::query()
            ->get()
            ->filter(fn (User $user): bool => $this->isAdmin($user) || $this->isModerator($user))
            ->take(5)
            ->map(fn (User $user): array => [...$this->personForUser($user), 'detail' => $this->displayUserRole($user)])
            ->values()
            ->all();
    }

    private function adminPeople(): array
    {
        return User::query()
            ->get()
            ->filter(fn (User $user): bool => $this->isAdmin($user))
            ->take(5)
            ->map(fn (User $user): array => $this->personForUser($user))
            ->values()
            ->all();
    }

    private function ticketCategoryRows(array $tickets): array
    {
        $total = max(count($tickets), 1);

        return collect($tickets)
            ->countBy('category')
            ->map(fn (int $count, string $category): array => [$category, $count.' ('.number_format(($count / $total) * 100, 1).'%)', '#6b2fff'])
            ->values()
            ->all();
    }

    private function categoryRows(): array
    {
        return DB::table('videos')
            ->leftJoin('video_categories', 'video_categories.id', '=', 'videos.category_id')
            ->selectRaw('COALESCE(video_categories.name, "Uncategorized") as name')
            ->selectRaw('COALESCE(SUM(videos.views_count), 0) as views')
            ->selectRaw('COALESCE(SUM(videos.views_count * COALESCE(videos.duration_seconds, 0)) / 3600, 0) as watch_hours')
            ->selectRaw('COALESCE(SUM(videos.likes_count + videos.comments_count), 0) as engagement')
            ->groupBy('name')
            ->orderByDesc('views')
            ->limit(5)
            ->get()
            ->values()
            ->map(function ($row, int $index): array {
                $engagementRate = (int) $row->views > 0 ? (((int) $row->engagement / (int) $row->views) * 100) : 0;

                return [
                    (string) ($index + 1),
                    $row->name,
                    $this->formatCompact((int) $row->views),
                    $this->formatCompact((int) round($row->watch_hours)),
                    number_format($engagementRate, 2).'%',
                    '0%',
                    'up',
                ];
            })
            ->all();
    }

    private function roleActivity(): array
    {
        return User::query()
            ->latest('updated_at')
            ->take(5)
            ->get()
            ->map(fn (User $user): array => [$this->personForUser($user), $this->isAdmin($user) ? 'Admin role active' : 'User settings updated', optional($user->updated_at)->diffForHumans() ?: '-'])
            ->values()
            ->all();
    }

    private function settingsActivity(): array
    {
        $path = storage_path('app/backups/.activity.json');
        $entries = is_file($path) ? json_decode((string) @file_get_contents($path), true) : [];
        if (! is_array($entries) || $entries === []) {
            return User::query()
                ->latest('updated_at')
                ->take(5)
                ->get()
                ->map(fn (User $user): array => [$this->personForUser($user), 'User account updated', optional($user->updated_at)->diffForHumans() ?: '-'])
                ->all();
        }

        return collect($entries)
            ->take(8)
            ->map(function (array $entry): array {
                try {
                    $time = Carbon::parse((string) ($entry['created_at'] ?? ''))->diffForHumans();
                } catch (\Throwable) {
                    $time = '-';
                }

                return [(array) ($entry['person'] ?? []), (string) ($entry['action'] ?? 'Admin settings updated'), $time];
            })
            ->all();
    }

    private function serverStatusRows(int $issues): array
    {
        $status = $issues > 0 ? 'Attention' : 'Healthy';

        return [
            ['Web Server', 'Healthy'],
            ['API Server', 'Healthy'],
            ['Storage', 'Healthy'],
            ['Cache', 'Healthy'],
            ['Storage', $status],
            ['CDN', 'Local'],
        ];
    }

    private function healthItems(array $storage, int $queueCount, int $issues): array
    {
        $backupSettings = $this->backupSettings();

        return [
            'status' => $issues > 0 ? 'Attention needed' : 'All Systems Operational',
            'rows' => [
                ['API Uptime', 'Online', $issues > 0 ? 'Attention' : 'Healthy'],
                ['Storage '.$storage['used'].' / '.$storage['total'], $storage['percent'].'%', $storage['percent'] >= 90 ? 'Attention' : 'Healthy'],
                ['Queue '.$queueCount.' jobs', (string) $queueCount, $queueCount > 0 ? 'Active' : 'Healthy'],
                ['Backups', $backupSettings['enabled'] ? $this->backupScheduleLabel($backupSettings) : 'Manual only', $backupSettings['enabled'] ? 'Active' : 'Info'],
            ],
        ];
    }

    private function storageUsage(): array
    {
        $totalBytes = @disk_total_space(base_path()) ?: 0;
        $freeBytes = @disk_free_space(base_path()) ?: 0;
        $usedBytes = max(0, $totalBytes - $freeBytes);

        return [
            'used' => $this->formatBytes($usedBytes),
            'total' => $this->formatBytes($totalBytes),
            'percent' => $totalBytes > 0 ? (int) min(100, round(($usedBytes / $totalBytes) * 100)) : 0,
        ];
    }

    private function paymentSummary(Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('payment_transactions')) {
            return [
                'total_cents' => 0,
                'count' => 0,
                'series' => $this->zeroSeries(),
                'transactions' => [],
            ];
        }

        $paid = DB::table('payment_transactions')->where('status', 'paid');
        $totalCents = (int) (clone $paid)->sum('amount_cents');
        $count = (int) (clone $paid)->count();
        $daily = DB::table('payment_transactions')
            ->selectRaw('DATE(paid_at) as paid_date, SUM(amount_cents) as cents')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->groupBy('paid_date')
            ->pluck('cents', 'paid_date');

        $series = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $series[] = (int) round(((int) ($daily[$date->toDateString()] ?? 0)) / 100);
        }

        $transactions = DB::table('payment_transactions')
            ->leftJoin('users', 'users.id', '=', 'payment_transactions.user_id')
            ->select('payment_transactions.*', 'users.name')
            ->where('payment_transactions.status', 'paid')
            ->latest('payment_transactions.paid_at')
            ->limit(6)
            ->get()
            ->map(fn ($transaction): array => [
                MediaStorage::membershipLabel($transaction->membership).' plan',
                $transaction->name ?: ucfirst((string) $transaction->provider),
                $this->formatMoney((int) $transaction->amount_cents),
                $transaction->paid_at ? Carbon::parse($transaction->paid_at)->diffForHumans() : '',
                $transaction->provider === 'paypal' ? 'paypal' : 'stripe',
            ])
            ->all();

        return [
            'total_cents' => $totalCents,
            'count' => $count,
            'series' => $series,
            'transactions' => $transactions,
        ];
    }

    private function backupSummary(): array
    {
        $backups = $this->backupEntries();
        $settings = $this->backupSettings();
        $active = collect($backups)->first(fn (array $backup): bool => in_array($backup['status'], ['queued', 'processing'], true));
        $completed = collect($backups)->firstWhere('status', 'completed');
        $latest = $active ?: ($backups[0] ?? null);
        $nextBackup = $settings['enabled'] ? $this->nextBackupAt($settings) : null;
        $status = $active ? 'Running' : (($latest['status'] ?? null) === 'failed' ? 'Failed' : ($settings['enabled'] ? 'Scheduled' : 'Manual only'));

        return [
            'metric' => $active ? 'Running' : ($settings['enabled'] ? 'Automatic' : (string) collect($backups)->where('status', 'completed')->count()),
            'sub' => $active ? ((int) ($active['progress'] ?? 0)).'% complete' : ($settings['enabled'] ? $this->backupScheduleLabel($settings) : ($completed ? 'local backup archives' : 'no backups created')),
            'rows' => [
                ['Last Backup', $completed ? Carbon::parse($completed['completed_at'] ?? $completed['created_at'])->diffForHumans() : 'Never'],
                ['Next Backup', $nextBackup ? $nextBackup->format('M j, g:i A') : 'Not scheduled'],
                ['Status', $status],
                ['Backup Size', $completed['size'] ?? '0 B'],
            ],
        ];
    }

    private function defaultBackupSettings(): array
    {
        return [
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '02:00',
            'day_of_week' => 0,
            'day_of_month' => 1,
            'selections' => [],
            'include_database' => true,
            'large_file_action' => 'store',
            'large_file_threshold_mb' => 512,
            'compression_level' => 3,
            'retention_count' => 5,
        ];
    }

    private function backupSettings(): array
    {
        $path = storage_path('app/backups/.settings.json');
        $stored = is_file($path) ? json_decode((string) @file_get_contents($path), true) : [];

        return array_replace($this->defaultBackupSettings(), is_array($stored) ? $stored : []);
    }

    private function writeBackupSettings(array $settings): void
    {
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory, 0770, true);
        if (File::put($directory.'/.settings.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true) === false) {
            throw new \RuntimeException('Automatic backup settings could not be written.');
        }
    }

    private function validBackupSelections(array $selections): array
    {
        $basePath = realpath(base_path()) ?: base_path();

        return collect($selections)->unique()->values()->map(function (string $selection) use ($basePath): string {
            if ($selection === '' || $selection !== basename($selection) || in_array($selection, ['.', '..'], true)) {
                throw ValidationException::withMessages(['selections' => ['An invalid backup path was selected.']]);
            }
            $path = realpath(base_path($selection));
            if ($path === false || is_link(base_path($selection)) || ! str_starts_with($path, $basePath.DIRECTORY_SEPARATOR)) {
                throw ValidationException::withMessages(['selections' => ['The selected backup path is unavailable.']]);
            }

            return $selection;
        })->all();
    }

    private function queueBackup(array $settings, array $selections, int $selectedBytes, ?int $createdBy, string $source = 'manual'): array
    {
        $id = (string) Str::uuid();
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory, 0770, true);
        $metadata = [
            'id' => $id,
            'status' => 'queued',
            'progress' => 0,
            'created_at' => now()->toIso8601String(),
            'created_by' => $createdBy,
            'source' => $source,
            'selected_paths' => $selections,
            'selected_bytes' => $selectedBytes,
            'include_database' => (bool) $settings['include_database'],
            'large_file_action' => $settings['large_file_action'],
            'large_file_threshold_mb' => (int) $settings['large_file_threshold_mb'],
            'compression_level' => (int) $settings['compression_level'],
            'retention_count' => (int) $settings['retention_count'],
        ];
        $metadataPath = $directory.'/'.$id.'.json';
        if (File::put($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true) === false) {
            throw new \RuntimeException('Backup status metadata could not be written.');
        }

        try {
            CreateSystemBackup::dispatch(
                $id,
                $selections,
                (bool) $settings['include_database'],
                $settings['large_file_action'],
                (int) $settings['large_file_threshold_mb'],
                (int) $settings['compression_level'],
                (int) $settings['retention_count'],
            );
        } catch (\Throwable $exception) {
            File::delete($metadataPath);
            throw $exception;
        }

        return $metadata;
    }

    private function nextBackupAt(array $settings): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) $settings['time']));
        $now = Carbon::now();
        if ($settings['frequency'] === 'weekly') {
            $days = ((int) $settings['day_of_week'] - $now->dayOfWeek + 7) % 7;
            $next = $now->copy()->startOfDay()->addDays($days)->setTime($hour, $minute);

            return $next->lte($now) ? $next->addWeek() : $next;
        }
        if ($settings['frequency'] === 'monthly') {
            $next = $now->copy()->startOfMonth()->day((int) $settings['day_of_month'])->setTime($hour, $minute);

            return $next->lte($now) ? $next->addMonthNoOverflow() : $next;
        }

        $next = $now->copy()->startOfDay()->setTime($hour, $minute);

        return $next->lte($now) ? $next->addDay() : $next;
    }

    private function backupScheduleLabel(array $settings): string
    {
        $time = Carbon::createFromFormat('H:i', (string) $settings['time'])->format('g:i A');
        if ($settings['frequency'] === 'weekly') {
            return 'Weekly on '.Carbon::create()->startOfWeek(Carbon::SUNDAY)->addDays((int) $settings['day_of_week'])->format('l').' at '.$time;
        }
        if ($settings['frequency'] === 'monthly') {
            return 'Monthly on day '.(int) $settings['day_of_month'].' at '.$time;
        }

        return 'Daily at '.$time;
    }

    private function recordAdminActivity(?User $user, string $action): void
    {
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory, 0770, true);
        $path = $directory.'/.activity.json';
        $entries = is_file($path) ? json_decode((string) @file_get_contents($path), true) : [];
        $entries = is_array($entries) ? $entries : [];
        $person = $user ? $this->personForUser($user) : [
            'name' => 'System Scheduler', 'handle' => '@system', 'email' => '', 'tone' => 'violet', 'avatar_url' => null, 'verified' => true,
        ];
        array_unshift($entries, ['person' => $person, 'action' => $action, 'created_at' => now()->toIso8601String()]);
        File::put($path, json_encode(array_slice($entries, 0, 50), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true);
    }

    private function backupEntries(): array
    {
        $entries = [];
        foreach (glob(storage_path('app/backups/*.json')) ?: [] as $path) {
            $metadata = json_decode((string) @file_get_contents($path), true);
            if (is_array($metadata) && isset($metadata['id'])) {
                $entries[] = $this->backupEntry($metadata);
            }
        }
        usort($entries, fn (array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));

        return $entries;
    }

    private function backupEntry(array $metadata): array
    {
        $archive = basename((string) ($metadata['archive'] ?? ''));
        $sizeBytes = (int) ($metadata['size_bytes'] ?? 0);

        return [
            'id' => (string) $metadata['id'],
            'status' => (string) ($metadata['status'] ?? 'unknown'),
            'progress' => (int) ($metadata['progress'] ?? 0),
            'created_at' => $metadata['created_at'] ?? null,
            'started_at' => $metadata['started_at'] ?? null,
            'completed_at' => $metadata['completed_at'] ?? null,
            'failed_at' => $metadata['failed_at'] ?? null,
            'selected_paths' => array_values((array) ($metadata['selected_paths'] ?? [])),
            'selected_bytes' => (int) ($metadata['selected_bytes'] ?? 0),
            'selected_size' => $this->formatBytes((int) ($metadata['selected_bytes'] ?? 0)),
            'size_bytes' => $sizeBytes,
            'size' => $this->formatBytes($sizeBytes),
            'archive' => $archive,
            'include_database' => (bool) ($metadata['include_database'] ?? false),
            'included_files' => (int) ($metadata['included_files'] ?? 0),
            'skipped_count' => (int) ($metadata['skipped_count'] ?? 0),
            'error' => $metadata['error'] ?? null,
            'source' => $metadata['source'] ?? 'manual',
            'download_url' => ($metadata['status'] ?? '') === 'completed' && $archive !== '' ? '/admin/backups/'.$metadata['id'].'/download' : null,
        ];
    }

    private function backupMetadata(string $backup): ?array
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $backup)) {
            return null;
        }
        $path = storage_path('app/backups/'.$backup.'.json');
        if (! is_file($path)) {
            return null;
        }
        $metadata = json_decode((string) file_get_contents($path), true);

        return is_array($metadata) ? $metadata : null;
    }

    private function backupPathStats(string $path): array
    {
        if (is_link($path)) {
            return ['bytes' => 0, 'files' => 0];
        }
        if (is_file($path)) {
            return ['bytes' => (int) (@filesize($path) ?: 0), 'files' => 1];
        }
        if (! is_dir($path)) {
            return ['bytes' => 0, 'files' => 0];
        }

        $bytes = 0;
        $files = 0;
        $backupRoot = realpath(storage_path('app/backups'));
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $item) {
                if ($item->isLink() || ! $item->isFile()) {
                    continue;
                }
                if ($backupRoot && str_starts_with($item->getPathname(), $backupRoot.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $bytes += (int) (@$item->getSize() ?: 0);
                $files++;
            }
        } catch (\Throwable $exception) {
            // Unreadable entries are omitted from the inventory and archive.
        }

        return ['bytes' => $bytes, 'files' => $files];
    }

    private function formatCompact(int|float $value): string
    {
        $value = (float) $value;
        $absolute = abs($value);

        if ($absolute >= 1000000) {
            return number_format($value / 1000000, $absolute >= 10000000 ? 0 : 1).'M';
        }

        if ($absolute >= 1000) {
            return number_format($value / 1000, $absolute >= 100000 ? 0 : 1).'K';
        }

        return (string) (int) round($value);
    }

    private function formatMoney(int $cents): string
    {
        return IntegrationSettings::formatMoney($cents);
    }

    private function formatBytes(int|float $bytes): string
    {
        $bytes = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return number_format($bytes, $index >= 3 ? 1 : 0).' '.$units[$index];
    }

    private function shortText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value));

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'...' : $value;
    }
}
