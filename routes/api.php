<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\CommunityPostController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\LikeController;
use App\Http\Controllers\Api\LiveKitAdmissionController;
use App\Http\Controllers\Api\LiveStreamController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PluginController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SystemUpdateController;
use App\Http\Controllers\Api\TimeMarkController;
use App\Http\Controllers\Api\UploadSessionController;
use App\Http\Controllers\Api\SharedPlaylistController;
use App\Http\Controllers\Api\VideoBoostController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\WebsiteAdController;
use App\Http\Controllers\SpaController;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\CommentResource;
use App\Http\Resources\CommunityPostResource;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\VideoResource;
use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\AuthSettings;
use App\Support\ContentPages;
use App\Support\GeneralSettings;
use App\Support\IntegrationSettings;
use App\Support\LiveStreamingSettings;
use App\Support\MediaStorage;
use App\Support\Images\ImageUploads;
use App\Support\MonetizationSettings;
use App\Support\PlatformFeatureSettings;
use App\Support\PlayerAdSettings;
use App\Support\VideoImportSettings;
use App\Updates\VersionRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

require __DIR__.'/api_v1.php';

Route::get('/health', fn (VersionRepository $versions) => response()->json([
    'status' => 'ok',
    'name' => config('app.name'),
    'version' => $versions->current(),
]));

// Read-only nginx signaling admission; returns no credentials or participant data.
Route::get('/livekit/admission', LiveKitAdmissionController::class);

Route::get('/seo/metadata', [SpaController::class, 'metadata'])->middleware('throttle:120,1');

Route::get('/content-pages', function (Request $request) {
    $locale = (string) $request->header('X-PlayHub-Language', $request->query('locale', ''));

    return response()->json(ContentPages::publicCatalog($locale, $request->boolean('navigation')))
        ->header('Cache-Control', 'no-store');
});
Route::get('/content-pages/{page}', function (Request $request, string $page) {
    $locale = (string) $request->header('X-PlayHub-Language', $request->query('locale', ''));
    $contentPage = ContentPages::page($page, $locale);
    abort_unless($contentPage, 404, 'Page not found.');
    unset($contentPage['default_content']);

    return response()->json(['page' => $contentPage])->header('Cache-Control', 'no-store');
});

Route::get('/live-streams', [LiveStreamController::class, 'index']);
Route::get('/gifts', [LiveStreamController::class, 'gifts']);
Route::get('/super-stickers', [LiveStreamController::class, 'superStickers']);
Route::get('/stickers', [\App\Http\Controllers\Api\StickerController::class, 'index']);
Route::post('/stickers/selection', [\App\Http\Controllers\Api\StickerController::class, 'selection'])
    ->middleware(['auth:sanctum', 'throttle:api-write']);
Route::get('/website-ads', [WebsiteAdController::class, 'index']);
Route::post('/website-ads/{websiteAd}/impression', [WebsiteAdController::class, 'impression'])->middleware('throttle:120,1');
Route::post('/website-ads/{websiteAd}/click', [WebsiteAdController::class, 'click'])->middleware('throttle:120,1');
Route::post('/video-boosts/{campaign:uuid}/impression', [VideoBoostController::class, 'impression'])->middleware('throttle:120,1');
Route::post('/video-boosts/{campaign:uuid}/click', [VideoBoostController::class, 'click'])->middleware('throttle:120,1');
Route::get('/live-room/{liveStream:slug}', [LiveStreamController::class, 'room']);
Route::get('/live-room/{liveStream:slug}/messages', [LiveStreamController::class, 'messages']);
Route::get('/live-room/{liveStream:slug}/polls', [LiveStreamController::class, 'polls']);

Route::get('/about', function () {
    $users = DB::table('users')->count();
    $creators = DB::table('channels')->count();
    $videos = DB::table('videos')->count();
    $publishedVideos = DB::table('videos')->where('status', 'ready')->count();
    $publicVideos = DB::table('videos')->where('status', 'ready')->where('visibility', 'public')->count();
    $scheduledVideos = DB::table('videos')->whereNotNull('scheduled_at')->where('scheduled_at', '>=', now())->count();
    $views = (int) DB::table('videos')->sum('views_count');
    $comments = DB::table('comments')->count();
    $categories = DB::table('video_categories')->count();
    $languages = DB::table('video_languages')->count();

    $topCategories = DB::table('video_categories')
        ->leftJoin('videos', 'videos.category_id', '=', 'video_categories.id')
        ->select('video_categories.name', 'video_categories.slug')
        ->selectRaw('count(videos.id) as videos_count')
        ->groupBy('video_categories.id', 'video_categories.name', 'video_categories.slug')
        ->orderByDesc('videos_count')
        ->orderBy('video_categories.name')
        ->limit(8)
        ->get();

    $recentCreators = Channel::query()
        ->select('channels.id', 'channels.name', 'channels.slug', 'channels.created_at')
        ->whereHas('user', fn ($owner) => $owner->publicProfileEnabled())
        ->withCount(['videos' => fn ($videos) => $videos->public()])
        ->latest('channels.created_at')
        ->limit(4)
        ->get()
        ->map(fn ($channel) => [
            'id' => $channel->id,
            'name' => $channel->name,
            'slug' => $channel->slug,
            'videos_count' => (int) $channel->videos_count,
            'joined' => $channel->created_at ? Carbon::parse($channel->created_at)->diffForHumans() : null,
        ]);

    return response()->json([
        'summary' => config('app.name', 'PlayHub').' is running with '.$users.' registered users, '.$creators.' creator channels, and '.$videos.' uploaded videos on this deployment.',
        'stats' => [
            'users' => $users,
            'creators' => $creators,
            'videos' => $videos,
            'published_videos' => $publishedVideos,
            'public_videos' => $publicVideos,
            'scheduled_videos' => $scheduledVideos,
            'views' => $views,
            'comments' => $comments,
            'categories' => $categories,
            'languages' => $languages,
        ],
        'top_categories' => $topCategories,
        'recent_creators' => $recentCreators,
        'capabilities' => [
            ['title' => 'Platform content', 'text' => $videos.' videos, '.$categories.' categories, and '.$languages.' upload languages are available.'],
            ['title' => 'Creator publishing', 'text' => 'Uploads support chunking, thumbnails, captions, visibility, scheduling, and server-side processing.'],
            ['title' => 'Community features', 'text' => $comments.' comments, subscriptions, playlists, notifications, reports, and watch-later items are available.'],
            ['title' => 'Admin operations', 'text' => 'Users, creators, content, reports, support messages, roles, and settings are managed from the admin dashboard.'],
        ],
        'limits' => [
            'max_upload_mb' => config('playhub.max_upload_mb', 2048),
            'chunk_size_mb' => config('playhub.chunk_size_mb', 8),
        ],
        'generated_at' => now()->toIso8601String(),
    ])->header('Cache-Control', 'private, no-store');
});

Route::post('/contact-messages', function (Request $request) {
    $data = $request->validate([
        'name' => ['required', 'string', 'max:120'],
        'email' => ['required', 'email', 'max:180'],
        'topic' => ['required', 'string', 'in:account,creator,upload,billing,safety,technical,business,other'],
        'subject' => ['nullable', 'string', 'max:180'],
        'message' => ['required', 'string', 'min:10', 'max:5000'],
    ]);
    $user = auth('sanctum')->user();
    $priority = in_array($data['topic'], ['safety', 'technical', 'billing'], true) ? 'High' : 'Normal';
    $id = DB::table('contact_messages')->insertGetId([
        'user_id' => $user?->id,
        'name' => $data['name'],
        'email' => $data['email'],
        'topic' => $data['topic'],
        'subject' => $data['subject'] ?? null,
        'message' => $data['message'],
        'status' => 'open',
        'priority' => $priority,
        'ip_address' => $request->ip(),
        'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $ticketId = '#MSG-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);

    try {
        IntegrationSettings::sendSupportNotification($data, $ticketId);
    } catch (Throwable $exception) {
        report($exception);
    }

    return response()->json([
        'message' => 'Your message has been sent to '.config('app.name', 'PlayHub').' support.',
        'ticket_id' => $ticketId,
    ], 201);
})->middleware('throttle:8,1');

Route::get('/integrations/public', fn (Request $request) => response()->json(array_replace_recursive(IntegrationSettings::publicSettings(), [
    'auth' => AuthSettings::publicSettings(),
    'features' => PlatformFeatureSettings::publicSettings($request->ip()),
    'website' => GeneralSettings::publicSettings(),
]))->header('Cache-Control', 'no-store'));

Route::get('/captcha/simple', [AuthController::class, 'simpleCaptcha']);

Route::get('/i18n', function (\App\Services\LocalizationService $localization) {
    $catalog = $localization->catalog();
    $catalog['languages'] = array_map(static fn (array $language): array => [
        ...$language, 'nativeLabel' => $language['native_label'],
    ], $catalog['languages']);

    return response()->json($catalog)->header('Cache-Control', 'no-cache, must-revalidate');
});

Route::get('/i18n/{locale}', function (string $locale, \App\Services\LocalizationService $localization) {
    $pack = $localization->pack($locale);
    abort_unless($pack !== null, 404);

    return response()->json([...$pack, 'nativeLabel' => $pack['native_label']])
        ->header('Cache-Control', 'no-cache, must-revalidate');
});

Route::prefix('auth')->group(function (): void {
    Route::get('/registration-captcha', [AuthController::class, 'registrationCaptcha']);
    Route::get('/registration-availability', [AuthController::class, 'registrationAvailability']);
    Route::post('/registration-email-challenge', [AuthController::class, 'registrationEmailChallenge']);
    Route::post('/registration-phone-challenge', [AuthController::class, 'registrationPhoneChallenge']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/confirm-registration', [AuthController::class, 'confirmRegistration']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/social/{provider}/redirect', [AuthController::class, 'socialRedirect']);
    Route::match(['get', 'post'], '/social/{provider}/callback', [AuthController::class, 'socialCallback']);
    Route::post('/social/exchange', [AuthController::class, 'socialExchange']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureUgcSafety::class])->group(function (): void {
        Route::get('/studio/overview', [VideoController::class, 'studioOverview']);
        Route::get('/studio/analytics', [VideoController::class, 'studioAnalytics']);
        Route::get('/studio/earnings', [PaymentController::class, 'creatorEarnings']);
        Route::get('/studio/videos', [VideoController::class, 'studioIndex']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/upgrade-creator', [AuthController::class, 'upgradeCreator'])->middleware('throttle:10,1');
        Route::put('/settings', [AuthController::class, 'updateSettings']);
        Route::put('/preferences/autoplay', [AuthController::class, 'updateAutoplayPreference']);
        Route::post('/password', [AuthController::class, 'updatePassword']);
        Route::post('/two-factor/setup', [AuthController::class, 'setupTwoFactor']);
        Route::post('/two-factor/enable', [AuthController::class, 'enableTwoFactor']);
        Route::post('/two-factor/disable', [AuthController::class, 'disableTwoFactor']);
        Route::get('/devices', [AuthController::class, 'devices']);
        Route::delete('/devices/others', [AuthController::class, 'revokeOtherDevices']);
        Route::delete('/devices/{device}', [AuthController::class, 'revokeDevice'])->whereNumber('device');
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::get('/home/categories', [FeedController::class, 'homeCategories']);
Route::get('/videos', [FeedController::class, 'index']);
Route::get('/videos/{video:slug}', [VideoController::class, 'show']);
Route::get('/videos/{video:slug}/playback-session', [VideoController::class, 'playbackSession'])->middleware('throttle:120,1');
Route::get('/videos/{video:slug}/comments', [CommentController::class, 'index']);
Route::get('/videos/{video:slug}/time-marks', [TimeMarkController::class, 'index']);
Route::get('/videos/{video:slug}/time-marks/share', [TimeMarkController::class, 'share']);
Route::get('/videos/{video:slug}/time-marks/rank', [TimeMarkController::class, 'rank']);
Route::get('/comments/{comment}/replies', [CommentController::class, 'replies']);
Route::get('/channels/{channel:slug}/community-posts', [CommunityPostController::class, 'index']);
Route::get('/community-posts', [CommunityPostController::class, 'feed']);
Route::get('/community-posts/{communityPost}/comments', [CommunityPostController::class, 'comments']);
Route::get('/community-posts/{communityPost}/voters', [CommunityPostController::class, 'voters']);
Route::post('/community-posts/{communityPost}/share', [CommunityPostController::class, 'share']);
Route::get('/channels/{channel:slug}', function (Request $request, Channel $channel) {
    $token = $request->bearerToken();
    $user = $request->user() ?: ($token ? PersonalAccessToken::findToken($token)?->tokenable : null);
    $channel->loadMissing('user');
    $isOwner = $user?->id === $channel->user_id;
    abort_unless($isOwner || $channel->user?->hasPublicProfileEnabled(), 404);
    $channel->loadMissing([
        'featuredVideo' => fn ($query) => $query
            ->when($isOwner, fn ($video) => $video->publishedPublic(), fn ($video) => $video->public())
            ->with(['channel.user', 'user', 'category', 'language']),
    ]);

    if ($user) {
        $channel->is_subscribed = DB::table('subscriptions')
            ->where('user_id', $user->id)
            ->where('channel_id', $channel->id)
            ->exists();
        $channel->is_favorite = Schema::hasTable('channel_favorites') && DB::table('channel_favorites')
            ->where('user_id', $user->id)
            ->where('channel_id', $channel->id)
            ->exists();
        $channel->notifications_muted = DB::table('user_mutes')
            ->where('user_id', $user->id)
            ->where('muted_user_id', $channel->user_id)
            ->exists();
        $channel->reported = Schema::hasTable('user_reports') && DB::table('user_reports')
            ->where('user_id', $user->id)
            ->where('reported_user_id', $channel->user_id)
            ->exists();
        $channel->can_report = (int) $user->id !== (int) $channel->user_id && ! $channel->reported;
    }

    return response()->json([
        'channel' => (new ChannelResource($channel))->resolve($request),
        'tips' => LiveStreamingSettings::creatorTipSettings($channel->user),
    ]);
});
Route::get('/channels/{channel:slug}/playlists', function (Request $request, Channel $channel) {
    $token = $request->bearerToken();
    $user = $request->user() ?: ($token ? PersonalAccessToken::findToken($token)?->tokenable : null);
    $channel->loadMissing('user');
    $isOwner = $user?->id === $channel->user_id;
    abort_unless($isOwner || $channel->user?->hasPublicProfileEnabled(), 404);

    $playlists = Playlist::query()
        ->where('channel_id', $channel->id)
        ->when(! $isOwner, fn ($query) => $query->where('privacy', 'public'))
        ->with(['coverVideo' => fn ($query) => $query
            ->when(! $isOwner, fn ($videoQuery) => $videoQuery->public())
            ->with(['channel.user', 'user', 'category', 'language'])])
        ->with(['videos' => fn ($query) => $query
            ->when(! $isOwner, fn ($videoQuery) => $videoQuery->public())
            ->with(['channel.user', 'user', 'category', 'language'])
            ->latest('published_at')])
        ->withCount(['videos' => fn ($query) => $query->when(! $isOwner, fn ($videoQuery) => $videoQuery->public())])
        ->latest()
        ->get();

    return response()->json([
        'playlists' => PlaylistResource::collection($playlists)->resolve($request),
    ]);
});
Route::get('/playlists', function (Request $request) {
    $token = $request->bearerToken();
    $user = $request->user() ?: ($token ? PersonalAccessToken::findToken($token)?->tokenable : null);
    $playlists = Playlist::query()
        ->where(fn ($query) => $query->where(fn ($public) => $public
            ->where('privacy', 'public')
            ->whereHas('user', fn ($owner) => $owner->publicProfileEnabled()))
            ->when($user, fn ($ownedQuery) => $ownedQuery->orWhere('user_id', $user->id)))
        ->with(['channel.user', 'coverVideo' => fn ($query) => $query->public()
            ->with(['channel.user', 'user', 'category', 'language'])])
        ->with(['videos' => fn ($query) => $query
            ->public()
            ->with(['channel.user', 'user', 'category', 'language'])->latest('published_at')])
        ->withCount(['videos' => fn ($query) => $query->public()])
        ->latest()
        ->get();

    return response()->json([
        'playlists' => PlaylistResource::collection($playlists)->resolve($request),
    ]);
});
Route::get('/playlists/{playlist}', function (Request $request, Playlist $playlist) {
    $token = $request->bearerToken();
    $user = $request->user() ?: ($token ? PersonalAccessToken::findToken($token)?->tokenable : null);
    $playlist->loadMissing('user');
    $viewerRole = \App\Support\SharedPlaylists::role($user, $playlist);
    $pendingInvite = \App\Support\SharedPlaylists::hasPendingInvite($user, $playlist);
    $isMember = $viewerRole !== null || $pendingInvite;
    abort_unless($isMember
        || ($playlist->privacy === 'public' && $playlist->user?->hasPublicProfileEnabled())
        || ($playlist->privacy === 'unlisted' && $playlist->user?->hasPublicProfileEnabled()), 403);

    $search = trim((string) $request->query('q', ''));
    $sort = (string) $request->query('sort', 'playlist');

    $playlist->load([
        'channel.user',
        'coverVideo' => fn ($query) => $query
            ->when(! $isMember, fn ($videoQuery) => $videoQuery->public())
            ->with(['channel', 'user', 'category', 'language']),
        'videos' => fn ($query) => $query
            ->when(! $isMember, fn ($videoQuery) => $videoQuery->public())
            ->when($search !== '', fn ($videoQuery) => $videoQuery->where(function ($inner) use ($search) {
                $inner->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhereHas('channel', fn ($channelQuery) => $channelQuery->where('name', 'like', '%'.$search.'%'));
            }))
            ->when(in_array($sort, ['latest', 'oldest', 'popular'], true), fn ($videoQuery) => $videoQuery->reorder())
            ->when($sort === 'popular', fn ($videoQuery) => $videoQuery->orderByDesc('views_count'))
            ->when($sort === 'oldest', fn ($videoQuery) => $videoQuery->oldest('published_at'))
            ->when($sort === 'latest', fn ($videoQuery) => $videoQuery->latest('published_at'))
            ->with(['channel.user', 'user', 'category', 'language']),
    ])->loadCount(['videos' => fn ($query) => $query->when(! $isMember, fn ($videoQuery) => $videoQuery->public())]);

    $payload = (new PlaylistResource($playlist))->resolve($request);
    $payload['viewer_role'] = $viewerRole;
    $payload['pending_invite'] = $pendingInvite;
    $payload['vote_counts'] = \App\Support\SharedPlaylists::voteCounts($playlist);
    if ($viewerRole !== null || $pendingInvite) {
        $payload['members'] = \App\Support\SharedPlaylists::members($playlist);
    }

    return response()->json([
        'playlist' => $payload,
    ]);
});

Route::get('/upload-options', function (Request $request) {
    $token = $request->bearerToken();
    $user = $request->user() ?: ($token ? PersonalAccessToken::findToken($token)?->tokenable : null);
    $categoryVideoCounts = Video::query()
        ->public()
        ->standardVideos()
        ->whereNotNull('category_id')
        ->selectRaw('category_id, count(*) as aggregate')
        ->groupBy('category_id')
        ->pluck('aggregate', 'category_id');
    $uploadCategories = DB::table('video_categories')
        ->select('id', 'name', 'slug')
        ->orderBy('sort_order')
        ->orderBy('name')
        ->get()
        ->each(fn ($item) => $item->videos_count = (int) ($categoryVideoCounts[$item->id] ?? 0));
    $trendingTags = DB::table('videos')
        ->whereNotNull('tags')
        ->pluck('tags')
        ->flatMap(fn ($tags) => json_decode($tags, true) ?: [])
        ->filter()
        ->map(fn ($tag) => trim((string) $tag))
        ->filter()
        ->countBy()
        ->sortDesc()
        ->keys()
        ->take(12)
        ->values();

    return response()->json([
        'categories' => $uploadCategories,
        'languages' => DB::table('video_languages')->select('id', 'name', 'code')->orderBy('sort_order')->orderBy('name')->get(),
        'playlists' => $user ? Playlist::query()->where('user_id', $user->id)->select('id', 'title', 'privacy')->latest()->get() : [],
        'trending_tags' => $trendingTags,
        'max_upload_mb' => config('playhub.max_upload_mb', 2048),
        'chunk_size_mb' => config('playhub.chunk_size_mb', 8),
        'video_import' => VideoImportSettings::publicSettings(),
        'player_ads' => PlayerAdSettings::publicSettings(),
        'video_sales' => MonetizationSettings::paidVideoSettings(),
        'photo_sales' => MonetizationSettings::paidCommunityPhotoSettings(),
    ])->header('Cache-Control', 'private, no-store');
});

Route::post('/player-ads/resolve', function (Request $request) {
    $data = $request->validate([
        'ad_ids' => ['nullable', 'array'],
        'ad_ids.*' => ['string', 'max:80'],
        'video_slug' => ['nullable', 'string', 'max:190'],
    ]);
    $video = ! empty($data['video_slug'])
        ? Video::query()->where('slug', $data['video_slug'])->where('visibility', 'public')->where('status', 'ready')->firstOrFail()
        : null;
    $viewer = $request->user();
    if (! $viewer && $request->bearerToken()) {
        $viewer = PersonalAccessToken::findToken($request->bearerToken())?->tokenable;
    }

    return response()->json([
        'ads' => PlayerAdSettings::resolve($data['ad_ids'] ?? [], $video, $request, $viewer?->id),
    ]);
})->middleware('throttle:60,1');

Route::post('/player-ads/impressions/{impression}/complete', function (Request $request, string $impression) {
    $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
    $viewer = $request->user();
    if (! $viewer && $request->bearerToken()) {
        $viewer = PersonalAccessToken::findToken($request->bearerToken())?->tokenable;
    }

    return response()->json(PlayerAdSettings::completeVideoImpression($impression, $data['token'], $viewer?->id));
})->middleware('throttle:60,1');

Route::get('/search', function (Request $request) {
    $search = trim((string) $request->query('q', ''));
    $type = in_array($request->query('type'), ['videos', 'channels', 'playlists', 'shorts', 'community'], true) ? $request->query('type') : 'all';
    $uploadDate = in_array($request->query('upload_date'), ['hour', 'today', 'week', 'month', 'year'], true) ? $request->query('upload_date') : 'any';
    $duration = in_array($request->query('duration'), ['short', 'medium', 'long'], true) ? $request->query('duration') : 'any';
    $resolution = in_array($request->query('resolution'), ['hd', '4k'], true) ? $request->query('resolution') : 'any';
    $features = in_array($request->query('features'), ['captions', 'kids'], true) ? $request->query('features') : 'any';
    $sort = in_array($request->query('sort'), ['views', 'newest'], true) ? $request->query('sort') : 'relevance';
    $dateCutoff = match ($uploadDate) {
        'hour' => now()->subHour(),
        'today' => now()->subDay(),
        'week' => now()->subWeek(),
        'month' => now()->subMonth(),
        'year' => now()->subYear(),
        default => null,
    };
    $token = $request->bearerToken();
    $user = $request->user() ?: ($token ? PersonalAccessToken::findToken($token)?->tokenable : null);
    $terms = collect(preg_split('/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [])
        ->map(fn ($term) => trim((string) $term))
        ->filter(fn ($term) => strlen($term) > 1)
        ->take(8)
        ->values();
    $tagSearch = trim((string) preg_replace('/(^|\s)#/u', '$1', $search));
    $tagTerms = $terms
        ->map(fn ($term) => trim((string) preg_replace('/^#/u', '', $term)))
        ->filter(fn ($term) => strlen($term) > 1)
        ->unique()
        ->values();
    $hasVideoFilters = $search !== '' || $dateCutoff || $duration !== 'any' || $resolution !== 'any' || $features !== 'any';
    $like = static fn (string $value): string => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';

    $wants = static fn (string $section): bool => $type === 'all' || $type === $section;

    $applyVideoSearch = function ($query) use ($search, $terms, $tagSearch, $tagTerms, $like, $dateCutoff, $duration, $resolution, $features): void {
        if ($dateCutoff) {
            $query->where('published_at', '>=', $dateCutoff);
        }

        if ($duration === 'short') {
            $query->where('duration_seconds', '<', 240);
        } elseif ($duration === 'medium') {
            $query->whereBetween('duration_seconds', [240, 1200]);
        } elseif ($duration === 'long') {
            $query->where('duration_seconds', '>', 1200);
        }

        if ($resolution === 'hd') {
            $query->where(function ($resolutionQuery): void {
                $resolutionQuery->where('renditions', 'like', '%high%')
                    ->orWhere('renditions', 'like', '%original%')
                    ->orWhere('renditions', 'like', '%1080%')
                    ->orWhere('renditions', 'like', '%720%');
            });
        } elseif ($resolution === '4k') {
            $query->where(function ($resolutionQuery): void {
                $resolutionQuery->where('renditions', 'like', '%4k%')
                    ->orWhere('renditions', 'like', '%2160%')
                    ->orWhere('renditions', 'like', '%3840%');
            });
        }

        if ($features === 'captions') {
            $query->where(fn ($captions) => $captions->whereNotNull('captions_path')->orWhereNotNull('caption_metadata->imported_tracks'));
        } elseif ($features === 'kids') {
            $query->where('made_for_kids', true);
        }

        if ($search === '') {
            return;
        }

        $query->where(function ($builder) use ($search, $terms, $tagSearch, $tagTerms, $like): void {
            $builder->where('title', 'like', $like($search))
                ->orWhere('description', 'like', $like($search))
                ->orWhere('tags', 'like', $like($search))
                ->orWhereHas('channel', fn ($channelQuery) => $channelQuery
                    ->where('name', 'like', $like($search))
                    ->orWhere('slug', 'like', $like($search)))
                ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery
                    ->where('name', 'like', $like($search))
                    ->orWhere('slug', 'like', $like($search)));

            if ($tagSearch !== '' && $tagSearch !== $search) {
                $builder->orWhere('tags', 'like', $like($tagSearch));
            }

            foreach ($terms as $term) {
                $builder->orWhere('tags', 'like', $like($term))
                    ->orWhere('title', 'like', $like($term))
                    ->orWhere('description', 'like', $like($term));
            }

            foreach ($tagTerms as $term) {
                $builder->orWhere('tags', 'like', $like($term));
            }
        });
    };

    $sortVideos = function ($query) use ($search, $tagSearch, $like, $sort) {
        if ($sort === 'views') {
            return $query->orderByDesc('views_count')->orderByDesc('likes_count')->orderByDesc('comments_count')->latest('published_at');
        }

        if ($sort === 'newest') {
            return $query->latest('published_at')->latest();
        }

        if ($search !== '') {
            $tagNeedle = $tagSearch !== '' ? $tagSearch : $search;
            $query->orderByRaw(
                'case when title like ? then 0 when tags like ? then 1 when description like ? then 2 else 3 end',
                [$like($search), $like($tagNeedle), $like($search)]
            );
        }

        return $query->orderByDesc('views_count')->latest('published_at')->latest();
    };

    $markSubscriptions = function ($channels) use ($user) {
        if (! $user || $channels->isEmpty()) {
            return $channels;
        }

        $subscribed = DB::table('subscriptions')
            ->where('user_id', $user->id)
            ->whereIn('channel_id', $channels->pluck('id'))
            ->pluck('channel_id')
            ->flip();

        $channels->each(fn (Channel $channel) => $channel->is_subscribed = $subscribed->has($channel->id));

        return $channels;
    };

    $videos = $wants('videos') ? Video::query()
        ->public()
        ->with(['channel.user', 'user', 'category', 'language'])
        ->tap($applyVideoSearch)
        ->tap($sortVideos)
        ->limit(8)
        ->get() : collect();

    $shorts = $wants('shorts') ? Video::query()
        ->public()
        ->shorts()
        ->with(['channel.user', 'user', 'category', 'language'])
        ->tap($applyVideoSearch)
        ->tap($sortVideos)
        ->limit(8)
        ->get() : collect();

    $channels = $wants('channels') ? Channel::query()
        ->withCount(['videos as videos_count' => fn ($videoQuery) => $videoQuery->public()])
        ->when($hasVideoFilters, fn ($query) => $query->where(function ($builder) use ($search, $like, $applyVideoSearch): void {
            if ($search !== '') {
                $builder->where('name', 'like', $like($search))
                    ->orWhere('slug', 'like', $like($search))
                    ->orWhere('bio', 'like', $like($search))
                    ->orWhere('about', 'like', $like($search));
            }

            $builder->orWhereHas('videos', function ($videoQuery) use ($applyVideoSearch): void {
                $videoQuery->public();
                $applyVideoSearch($videoQuery);
            });
        }))
        ->orderByDesc('videos_count')
        ->orderByDesc('subscribers_count')
        ->limit(8)
        ->get() : collect();

    $playlists = $wants('playlists') ? Playlist::query()
        ->where('privacy', 'public')
        ->with(['coverVideo.channel.user', 'coverVideo.user', 'coverVideo.category', 'coverVideo.language'])
        ->with(['videos' => fn ($videoQuery) => $videoQuery->public()->with(['channel.user', 'user', 'category', 'language'])->limit(4)])
        ->withCount('videos')
        ->when($hasVideoFilters, fn ($query) => $query->where(function ($builder) use ($search, $like, $applyVideoSearch): void {
            if ($search !== '') {
                $builder->where('title', 'like', $like($search))
                    ->orWhere('description', 'like', $like($search));
            }

            $builder->orWhereHas('videos', function ($videoQuery) use ($applyVideoSearch): void {
                $videoQuery->public();
                $applyVideoSearch($videoQuery);
            });
        }))
        ->orderByDesc('videos_count')
        ->latest()
        ->limit(8)
        ->get() : collect();

    $communityPosts = $wants('community') ? CommunityPost::query()
        ->with(['user', 'channel', 'video.channel.user', 'video.user', 'video.category', 'video.language'])
        ->where(function ($query): void {
            $query->whereNull('video_id')->orWhereHas('video', fn ($videoQuery) => $videoQuery->public());
        })
        ->when($hasVideoFilters, fn ($query) => $query->where(function ($builder) use ($search, $like, $applyVideoSearch): void {
            if ($search !== '') {
                $builder->where('body', 'like', $like($search))
                    ->orWhere('url_title', 'like', $like($search))
                    ->orWhere('url_description', 'like', $like($search))
                    ->orWhereHas('channel', fn ($channelQuery) => $channelQuery
                        ->where('name', 'like', $like($search))
                        ->orWhere('slug', 'like', $like($search)));
            }

            $builder->orWhereHas('video', function ($videoQuery) use ($applyVideoSearch): void {
                $videoQuery->public();
                $applyVideoSearch($videoQuery);
            });
        }))
        ->latest()
        ->limit(6)
        ->get() : collect();

    $relatedTags = DB::table('videos')
        ->where('visibility', 'public')
        ->where('status', 'ready')
        ->whereNotNull('tags')
        ->when($search !== '', fn ($query) => $query->where(function ($builder) use ($search, $tagSearch, $like): void {
            $builder->where('title', 'like', $like($search))
                ->orWhere('description', 'like', $like($search))
                ->orWhere('tags', 'like', $like($search));
            if ($tagSearch !== '' && $tagSearch !== $search) {
                $builder->orWhere('tags', 'like', $like($tagSearch));
            }
        }))
        ->pluck('tags')
        ->flatMap(fn ($tags) => json_decode($tags, true) ?: [])
        ->map(fn ($tag) => trim((string) $tag))
        ->filter()
        ->unique(fn ($tag) => strtolower($tag))
        ->take(25)
        ->values();

    $recommendedCreators = Channel::query()
        ->withCount(['videos as videos_count' => fn ($videoQuery) => $videoQuery->public()])
        ->orderByDesc('subscribers_count')
        ->orderByDesc('videos_count')
        ->limit(5)
        ->get();

    return response()->json([
        'query' => $search,
        'videos' => VideoResource::collection($videos)->resolve($request),
        'channels' => ChannelResource::collection($markSubscriptions($channels))->resolve($request),
        'playlists' => PlaylistResource::collection($playlists)->resolve($request),
        'shorts' => VideoResource::collection($shorts)->resolve($request),
        'community' => CommunityPostResource::collection($communityPosts)->resolve($request),
        'related_searches' => $relatedTags,
        'recommended_creators' => ChannelResource::collection($markSubscriptions($recommendedCreators))->resolve($request),
        'filters' => compact('type', 'uploadDate', 'duration', 'resolution', 'features', 'sort'),
    ]);
});

Route::middleware(\App\Http\Middleware\EnsureUgcSafety::class)->group(function (): void {
    Route::get('/clips/{uuid}', [\App\Http\Controllers\Api\ClipTransferController::class, 'publicShow'])->whereUuid('uuid');
    Route::get('/clips/{uuid}/playback', [\App\Http\Controllers\Api\ClipTransferController::class, 'publicPlayback'])->whereUuid('uuid')->middleware('throttle:20,1');
});

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureUgcSafety::class])->group(function (): void {
    Route::get('/clip-transfers', [\App\Http\Controllers\Api\ClipTransferController::class, 'index']);
    Route::get('/clip-transfers/{uuid}', [\App\Http\Controllers\Api\ClipTransferController::class, 'show'])->whereUuid('uuid');
    Route::post('/videos/{video:slug}/clip-transfers', [\App\Http\Controllers\Api\ClipTransferController::class, 'store'])->middleware('throttle:api-write');
    Route::post('/clip-transfers/{uuid}/upload-grant', [\App\Http\Controllers\Api\ClipTransferController::class, 'uploadGrant'])->whereUuid('uuid')->middleware('throttle:api-write');
    Route::post('/clip-transfers/{uuid}/uploaded', [\App\Http\Controllers\Api\ClipTransferController::class, 'uploaded'])->whereUuid('uuid')->middleware('throttle:api-write');
    Route::patch('/clip-transfers/{uuid}', [\App\Http\Controllers\Api\ClipTransferController::class, 'update'])->whereUuid('uuid')->middleware('throttle:api-write');
    Route::delete('/clip-transfers/{uuid}', [\App\Http\Controllers\Api\ClipTransferController::class, 'destroy'])->whereUuid('uuid')->middleware('throttle:api-write');
    Route::post('/clip-transfers/{uuid}/cancel', [\App\Http\Controllers\Api\ClipTransferController::class, 'cancel'])->whereUuid('uuid')->middleware('throttle:api-write');
    Route::get('/clip-transfers/{uuid}/playback', [\App\Http\Controllers\Api\ClipTransferController::class, 'playback'])->whereUuid('uuid')->middleware('throttle:20,1');
    Route::get('/clip-collections', [\App\Http\Controllers\Api\ClipCollectionController::class, 'index']);
    Route::post('/clip-collections', [\App\Http\Controllers\Api\ClipCollectionController::class, 'store'])->middleware('throttle:api-write');
    Route::get('/clip-collections/{collection}', [\App\Http\Controllers\Api\ClipCollectionController::class, 'show']);
    Route::patch('/clip-collections/{collection}', [\App\Http\Controllers\Api\ClipCollectionController::class, 'update'])->middleware('throttle:api-write');
    Route::delete('/clip-collections/{collection}', [\App\Http\Controllers\Api\ClipCollectionController::class, 'destroy'])->middleware('throttle:api-write');
    Route::put('/clip-collections/{collection}/clips/{uuid}', [\App\Http\Controllers\Api\ClipCollectionController::class, 'addClip'])->whereUuid('uuid')->middleware('throttle:api-write');
    Route::delete('/clip-collections/{collection}/clips/{uuid}', [\App\Http\Controllers\Api\ClipCollectionController::class, 'removeClip'])->whereUuid('uuid')->middleware('throttle:api-write');
    Route::get('/account/safety', [\App\Http\Controllers\Api\V1\SafetyController::class, 'show']);
    Route::post('/account/safety/consent', [\App\Http\Controllers\Api\V1\SafetyController::class, 'consent'])->middleware('throttle:api-write');
    Route::get('/account/blocks', [\App\Http\Controllers\Api\V1\SafetyController::class, 'blocks']);
    Route::post('/account/blocks', [\App\Http\Controllers\Api\V1\SafetyController::class, 'block'])->middleware('throttle:api-write');
    Route::delete('/account/blocks/{user}', [\App\Http\Controllers\Api\V1\SafetyController::class, 'unblock'])->whereNumber('user')->middleware('throttle:api-write');
    $messageUserPayload = function (?User $user): array {
        if (! $user) {
            return [
                'id' => null,
                'name' => 'Unknown user',
                'handle' => '@unknown',
                'avatar_url' => null,
                'initials' => 'U',
            ];
        }

        $channel = $user->channel;
        $name = $channel?->name ?: $user->name;
        $handle = $channel?->slug ?: $user->username;
        $avatar = $channel?->avatar_path ?: $user->avatar_path;
        $avatarUrl = $avatar ? (preg_match('/^(https?:)?\/\//', $avatar) || str_starts_with($avatar, '/') ? $avatar : MediaStorage::url($avatar)) : null;
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1).substr($parts[1] ?? '', 0, 1));

        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $name,
            'handle' => '@'.$handle,
            'profile_path' => $channel?->slug ? '/channel/'.$channel->slug : null,
            'avatar_url' => $avatarUrl,
            'initials' => $initials ?: 'U',
        ];
    };

    $messagePermission = function (?User $recipient, User $sender): array {
        if (! $recipient || (int) $recipient->id === (int) $sender->id) {
            return [false, 'This user cannot receive private messages.'];
        }
        if (\App\Support\UserBlocks::between((int) $recipient->id, (int) $sender->id)) {
            return [false, 'Private messages are unavailable for this person.'];
        }

        $requestedPolicy = data_get(
            is_array($recipient->settings) ? $recipient->settings : [],
            'preferences.privateMessages',
            'anyone'
        );
        $policy = in_array($requestedPolicy, ['anyone', 'subscribers', 'disabled'], true)
            ? $requestedPolicy
            : 'anyone';

        if ($policy === 'disabled') {
            return [false, 'This user has disabled private messages.'];
        }

        if ($policy === 'subscribers') {
            $recipient->loadMissing('channel');
            $isSubscriber = $recipient->channel
                ? DB::table('subscriptions')
                    ->where('user_id', $sender->id)
                    ->where('channel_id', $recipient->channel->id)
                    ->exists()
                : false;

            if (! $isSubscriber) {
                return [false, 'This user only accepts private messages from subscribers.'];
            }
        }

        return [true, null];
    };

    $messagePayload = function ($row, int $currentUserId) use ($messageUserPayload): array {
        $sender = User::query()->with('channel')->find($row->sender_id);
        $references = is_string($row->attachments ?? null) ? (json_decode($row->attachments, true) ?: []) : ($row->attachments ?? []);
        ImageUploads::prefetch($references);
        $attachments = collect($references)
            ->filter(fn ($attachment) => is_array($attachment))
            ->map(function (array $attachment, int $index) use ($row): array {
                if (! empty($attachment['image_id'])) {
                    return ImageUploads::payload($attachment);
                }
                if (($attachment['type'] ?? null) === 'image' && ! empty($attachment['path'])) {
                    $attachment['url'] = URL::temporarySignedRoute('media.private-message-attachment', now()->addHours(6), [
                        'message' => $row->id, 'index' => $index,
                    ]);
                    unset($attachment['path']);
                }

                return $attachment;
            })
            ->filter()->values()
            ->all();

        return [
            'id' => (int) $row->id,
            'body' => (string) $row->body,
            'attachments' => $attachments,
            'own' => (int) $row->sender_id === $currentUserId,
            'sender' => $messageUserPayload($sender),
            'created_at' => $row->created_at,
            'time' => $row->created_at ? Carbon::parse($row->created_at)->diffForHumans() : '',
            'read_at' => $row->read_at,
        ];
    };
    $findConversation = function (int $conversationId, int $userId) {
        $conversation = DB::table('private_conversations')->where('id', $conversationId)->first();
        abort_unless($conversation && ((int) $conversation->user_one_id === $userId || (int) $conversation->user_two_id === $userId), 404);
        $other = (int) $conversation->user_one_id === $userId ? (int) $conversation->user_two_id : (int) $conversation->user_one_id;
        abort_if(\App\Support\UserBlocks::between($userId, $other), 404);

        return $conversation;
    };

    $conversationPayload = function ($conversation, Request $request, bool $withMessages = false) use ($messageUserPayload, $messagePayload, $messagePermission): array {
        $currentUser = $request->user();
        $currentUserId = (int) $currentUser->id;
        $otherUserId = (int) $conversation->user_one_id === $currentUserId ? (int) $conversation->user_two_id : (int) $conversation->user_one_id;
        $otherUser = User::query()->with('channel')->find($otherUserId);
        $latest = DB::table('private_messages')
            ->where('conversation_id', $conversation->id)
            ->latest('created_at')
            ->latest('id')
            ->first();
        $unread = DB::table('private_messages')
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $currentUserId)
            ->whereNull('read_at')
            ->count();
        $hasOutbound = DB::table('private_messages')
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', $currentUserId)
            ->exists();
        $section = 'primary';
        if ($latest && ! $hasOutbound && (int) $latest->sender_id !== $currentUserId) {
            $section = 'requests';
        }
        $username = strtolower((string) $currentUser->username);
        if ($latest && $username !== '' && str_contains(strtolower((string) $latest->body), '@'.$username)) {
            $section = 'mentions';
        }

        $messages = [];
        if ($withMessages) {
            $messages = DB::table('private_messages')
                ->where('conversation_id', $conversation->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(200)
                ->get();
            ImageUploads::prefetch($messages->flatMap(fn ($message) => json_decode((string) ($message->attachments ?? '[]'), true) ?: [])->all());
            $messages = $messages->map(fn ($message) => $messagePayload($message, $currentUserId))->all();
        }

        $recipient = $messageUserPayload($otherUser);
        [$canMessage, $messageBlockedReason] = $messagePermission($otherUser, $currentUser);
        $recipient['can_message'] = $canMessage;
        $recipient['message_blocked_reason'] = $messageBlockedReason;
        $latestAttachments = $latest ? (json_decode((string) ($latest->attachments ?? '[]'), true) ?: []) : [];
        $preview = $latest ? (trim((string) $latest->body) ?: (count($latestAttachments) ? 'Image attachment' : 'No messages yet.')) : 'No messages yet.';

        return [
            'id' => (int) $conversation->id,
            'section' => $section,
            'name' => $recipient['name'],
            'initials' => $recipient['initials'],
            'avatar_url' => $recipient['avatar_url'],
            'recipient' => $recipient,
            'preview' => Str::limit($preview, 120),
            'time' => $latest?->created_at ? Carbon::parse($latest->created_at)->diffForHumans() : '',
            'unread' => (int) $unread,
            'to' => '/messages/'.$conversation->id,
            'messages' => $messages,
        ];
    };

    Route::prefix('admin')->group(function (): void {
        Route::get('/media-providers', [\App\Http\Controllers\Api\AdminMediaProvidersController::class, 'index']);
        Route::put('/media-providers/{provider}', [\App\Http\Controllers\Api\AdminMediaProvidersController::class, 'update'])->middleware('throttle:30,1');
        Route::post('/media-providers/odysee/connect', [\App\Http\Controllers\Api\AdminMediaProvidersController::class, 'connectOdysee'])->middleware('throttle:5,1');
        Route::post('/media-providers/{provider}/test', [\App\Http\Controllers\Api\AdminMediaProvidersController::class, 'test'])->middleware('throttle:6,1');
        Route::get('/page-data', [AdminController::class, 'pageData']);
        Route::get('/plugins', [PluginController::class, 'index']);
        Route::post('/plugins', [PluginController::class, 'install'])->middleware('throttle:10,1');
        Route::post('/plugins/{plugin}/enable', [PluginController::class, 'enable'])->middleware('throttle:20,1');
        Route::post('/plugins/{plugin}/disable', [PluginController::class, 'disable'])->middleware('throttle:20,1');
        Route::put('/plugins/{plugin}/settings', [PluginController::class, 'updateSettings'])->middleware('throttle:30,1');
        Route::delete('/plugins/{plugin}', [PluginController::class, 'destroy'])->middleware('throttle:20,1');
        Route::get('/system-updates', [SystemUpdateController::class, 'index'])->middleware('throttle:120,1');
        Route::post('/system-updates/check', [SystemUpdateController::class, 'check'])->middleware('throttle:10,1');
        Route::post('/system-updates/install', [SystemUpdateController::class, 'install'])->middleware('throttle:2,10');
        Route::get('/system-updates/{systemUpdate}', [SystemUpdateController::class, 'show'])->middleware('throttle:120,1');
        Route::get('/system-updates/{systemUpdate}/log', [SystemUpdateController::class, 'log'])->middleware('throttle:60,1');
        Route::get('/notifications', [AdminController::class, 'notifications']);
        Route::post('/notifications/read', [AdminController::class, 'markNotificationsRead']);
        Route::post('/notifications/{notification}/read', [AdminController::class, 'markNotificationRead']);
        Route::get('/search', [AdminController::class, 'search']);
        Route::put('/general-settings', [AdminController::class, 'updateGeneralSettings']);
        Route::put('/seo-settings', [AdminController::class, 'updateSeoSettings']);
        Route::post('/website-assets', [AdminController::class, 'uploadWebsiteAsset']);
        Route::put('/storage-settings', [AdminController::class, 'updateStorageSettings']);
        Route::post('/storage-settings/test', [AdminController::class, 'testStorageSettings']);
        Route::put('/video-import-settings', [AdminController::class, 'updateVideoImportSettings']);
        Route::put('/player-ad-settings', [AdminController::class, 'updatePlayerAdSettings']);
        Route::put('/monetization-settings', [AdminController::class, 'updateMonetizationSettings']);
        Route::post('/withdrawals/{withdrawal}/decision', [AdminController::class, 'decideWithdrawal'])->middleware('throttle:30,1');
        Route::put('/integration-settings', [AdminController::class, 'updateIntegrationSettings']);
        Route::post('/integration-settings/test', [AdminController::class, 'testIntegrationSettings']);
        Route::put('/auth-settings', [AdminController::class, 'updateAuthSettings']);
        Route::put('/feature-settings', [AdminController::class, 'updateFeatureSettings']);
        Route::get('/publishing-restrictions', [AdminController::class, 'publishingRestrictions']);
        Route::post('/publishing-restrictions', [AdminController::class, 'restrictPublishing'])->middleware('throttle:30,1');
        Route::delete('/publishing-restrictions/{user}', [AdminController::class, 'clearPublishingRestriction']);
        Route::put('/live-streaming-settings', [AdminController::class, 'updateLiveStreamingSettings']);
        Route::get('/livekit-status', [AdminController::class, 'liveKitStatus']);
        Route::post('/livekit-test', [AdminController::class, 'testLiveKitConnection']);
        Route::post('/livekit-action', [AdminController::class, 'liveKitAction']);
        Route::get('/livekit-logs', [AdminController::class, 'liveKitLogs']);
        Route::post('/roles', [AdminController::class, 'createRole']);
        Route::delete('/roles/{role}', [AdminController::class, 'deleteRole']);
        Route::post('/permission-groups', [AdminController::class, 'savePermissionGroup']);
        Route::post('/system-actions', [AdminController::class, 'runSystemAction']);
        Route::get('/system-logs', [AdminController::class, 'systemLogs']);
        Route::get('/cron-jobs', [AdminController::class, 'cronJobs']);
        Route::put('/cron-jobs', [AdminController::class, 'updateCronJobs']);
        Route::get('/backups', [AdminController::class, 'backups']);
        Route::post('/backups', [AdminController::class, 'createBackup']);
        Route::put('/backups/settings', [AdminController::class, 'updateBackupSettings']);
        Route::get('/backups/{backup}/download', [AdminController::class, 'downloadBackup']);
        Route::delete('/backups/{backup}', [AdminController::class, 'deleteBackup']);
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/gifts', [AdminController::class, 'gifts']);
        Route::post('/gifts', [AdminController::class, 'storeGift']);
        Route::post('/gifts/{gift}', [AdminController::class, 'updateGift']);
        Route::delete('/gifts/{gift}', [AdminController::class, 'deleteGift']);
        Route::get('/email-templates', [AdminController::class, 'emailTemplates']);
        Route::put('/email-templates/{template}', [AdminController::class, 'updateEmailTemplate']);
        Route::delete('/email-templates/{template}', [AdminController::class, 'resetEmailTemplate']);
        Route::get('/content-pages', [AdminController::class, 'contentPages']);
        Route::put('/content-pages/{page}', [AdminController::class, 'updateContentPage']);
        Route::delete('/content-pages/{page}', [AdminController::class, 'resetContentPage']);
        Route::get('/languages', [AdminController::class, 'languages']);
        Route::put('/languages/default', [AdminController::class, 'updateDefaultLanguage']);
        Route::put('/languages/{locale}/status', [AdminController::class, 'updateLanguageStatus']);
        Route::get('/languages/{locale}', [AdminController::class, 'language']);
        Route::put('/languages/{locale}', [AdminController::class, 'updateLanguage']);
        Route::get('/stickers/settings', [\App\Http\Controllers\Api\AdminStickerSettingsController::class, 'index']);
        Route::put('/stickers/settings', [\App\Http\Controllers\Api\AdminStickerSettingsController::class, 'update']);
        Route::post('/stickers/test', [\App\Http\Controllers\Api\AdminStickerSettingsController::class, 'test']);
        Route::get('/stickers', [\App\Http\Controllers\Api\AdminStickerSettingsController::class, 'index']);
        Route::get('/super-stickers', [\App\Http\Controllers\Api\AdminStickerSettingsController::class, 'index']);
        Route::get('/website-ads', [AdminController::class, 'websiteAds']);
        Route::post('/website-ads', [AdminController::class, 'storeWebsiteAd']);
        Route::put('/website-ads/{websiteAd}', [AdminController::class, 'updateWebsiteAd']);
        Route::delete('/website-ads/{websiteAd}', [AdminController::class, 'deleteWebsiteAd']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/export', [AdminController::class, 'exportUsers']);
        Route::get('/users/activity-report', [AdminController::class, 'userActivityReport']);
        Route::get('/creators/export', [AdminController::class, 'exportCreators']);
        Route::post('/creators/bulk-verify', [AdminController::class, 'bulkVerifyCreators']);
        Route::post('/creators/announcement', [AdminController::class, 'sendCreatorAnnouncement']);
        Route::post('/users/invite', [AdminController::class, 'inviteUser']);
        Route::post('/users/bulk-status', [AdminController::class, 'bulkUserStatus']);
        Route::post('/users/bulk-verify', [AdminController::class, 'bulkVerifyUsers']);
        Route::post('/users/bulk-delete', [AdminController::class, 'bulkDeleteUsers']);
        Route::get('/users/{user}', [AdminController::class, 'showUser']);
        Route::put('/users/{user}', [AdminController::class, 'updateUser']);
        Route::post('/users/{user}/password', [AdminController::class, 'updateUserPassword']);
        Route::post('/users/{user}/wallet-credit', [AdminController::class, 'creditUserWallet'])->middleware('throttle:30,1');
        Route::get('/users/{user}/activity', [AdminController::class, 'userActivity']);
        Route::post('/users/{user}/role', [AdminController::class, 'updateUserRole']);
        Route::post('/users/{user}/email', [AdminController::class, 'sendUserEmail']);
        Route::post('/users/{user}/suspend', [AdminController::class, 'suspendUser']);
        Route::post('/users/{user}/ban', [AdminController::class, 'banUser']);
        Route::delete('/users/{user}/ban', [AdminController::class, 'clearUserBans']);
        Route::post('/users/{user}/verify', [AdminController::class, 'verifyUser']);
        Route::post('/users/{user}/profile-verify', [AdminController::class, 'verifyProfileBadge']);
        Route::delete('/users/{user}/profile-verify', [AdminController::class, 'clearProfileBadge']);
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser']);
        Route::get('/content', [AdminController::class, 'content']);
        Route::get('/live-streams', [AdminController::class, 'liveStreams']);
        Route::delete('/live-streams/{liveStream}', [AdminController::class, 'deleteLiveStream']);
        Route::post('/content/mass-import/discover', [AdminController::class, 'discoverMassImportVideo']);
        Route::post('/content/mass-import/complete', [AdminController::class, 'completeMassImport']);
        Route::post('/content/videos/bulk-approve', [AdminController::class, 'bulkApproveVideos']);
        Route::post('/content/videos/bulk-visibility', [AdminController::class, 'bulkUpdateVideoVisibility']);
        Route::post('/content/videos/bulk-category', [AdminController::class, 'bulkUpdateVideoCategory']);
        Route::post('/content/videos/bulk-delete', [AdminController::class, 'bulkDeleteVideos']);
        Route::post('/content/categories', [AdminController::class, 'createContentCategory']);
        Route::put('/content/categories/{category}', [AdminController::class, 'updateContentCategory']);
        Route::delete('/content/categories/{category}', [AdminController::class, 'deleteContentCategory'])->whereNumber('category');
        Route::post('/content/videos/{video}/approve', [AdminController::class, 'approveVideo']);
        Route::put('/content/videos/{video}/visibility', [AdminController::class, 'updateVideoVisibility']);
        Route::delete('/content/videos/{video}', [AdminController::class, 'deleteVideo']);
        Route::get('/reports', [AdminController::class, 'reports']);
        Route::post('/reports/comments/{comment}/dismiss', [AdminController::class, 'dismissCommentReports']);
        Route::delete('/reports/comments/{comment}', [AdminController::class, 'deleteReportedComment']);
        Route::post('/reports/videos/{video}/dismiss', [AdminController::class, 'dismissVideoReports']);
        Route::delete('/reports/videos/{video}', [AdminController::class, 'deleteReportedVideo']);
        Route::post('/reports/users/{user}/dismiss', [AdminController::class, 'dismissUserReports']);
        Route::get('/support/contact-messages/{message}', [AdminController::class, 'showContactMessage']);
        Route::post('/support/contact-messages/{message}/reply', [AdminController::class, 'replyContactMessage']);
        Route::post('/support/contact-messages/{message}/resolve', [AdminController::class, 'resolveContactMessage']);
        Route::put('/support/contact-messages/{message}/status', [AdminController::class, 'updateContactMessageStatus']);
    });

    Route::post('/upload-sessions', [UploadSessionController::class, 'store']);
    Route::get('/live-studio', [LiveStreamController::class, 'studio']);
    Route::post('/live-streams', [LiveStreamController::class, 'store']);
    Route::put('/live-streams/{liveStream}', [LiveStreamController::class, 'update']);
    Route::post('/live-streams/{liveStream}/schedule', [LiveStreamController::class, 'schedule']);
    Route::get('/live-streams/{liveStream}/thumbnail', [LiveStreamController::class, 'thumbnailStatus']);
    Route::post('/live-streams/{liveStream}/thumbnail', [LiveStreamController::class, 'thumbnail']);
    Route::post('/live-streams/{liveStream}/connection-probe', [LiveStreamController::class, 'connectionProbe']);
    Route::post('/live-streams/{liveStream}/rtmp-ingress', [LiveStreamController::class, 'rtmpIngress']);
    Route::post('/live-streams/{liveStream}/preflight', [LiveStreamController::class, 'preflight']);
    Route::post('/live-streams/{liveStream}/start', [LiveStreamController::class, 'start']);
    Route::post('/live-streams/{liveStream}/activate', [LiveStreamController::class, 'activate']);
    Route::post('/live-streams/{liveStream}/end', [LiveStreamController::class, 'end']);
    Route::get('/live-streams/{liveStream}/control-room', [LiveStreamController::class, 'controlRoom']);
    Route::post('/live-streams/{liveStream}/participants/invite', [LiveStreamController::class, 'inviteParticipant']);
    Route::post('/live-streams/{liveStream}/participant-requests/{participantRequest}', [LiveStreamController::class, 'respondParticipantRequest']);
    Route::post('/live-streams/{liveStream}/participants/action', [LiveStreamController::class, 'participantAction']);
    Route::post('/live-streams/{liveStream}/moderators', [LiveStreamController::class, 'addModerator']);
    Route::delete('/live-streams/{liveStream}/moderators/{user}', [LiveStreamController::class, 'removeModerator']);
    Route::post('/live-streams/{liveStream}/users/{user}/restrictions', [LiveStreamController::class, 'restrictViewer']);
    Route::delete('/live-streams/{liveStream}/users/{user}/restrictions/{type}', [LiveStreamController::class, 'removeViewerRestriction']);
    Route::put('/live-streams/{liveStream}/engagement', [LiveStreamController::class, 'engagement']);
    Route::put('/live-streams/{liveStream}/chat-settings', [LiveStreamController::class, 'chatSettings']);
    Route::post('/live-streams/{liveStream}/messages/{message}/pin', [LiveStreamController::class, 'pinMessage']);
    Route::delete('/live-streams/{liveStream}/messages/{message}', [LiveStreamController::class, 'deleteMessage']);
    Route::post('/live-streams/{liveStream}/polls', [LiveStreamController::class, 'createPoll']);
    Route::get('/live-streams/{liveStream}/polls/{poll}/voters', [LiveStreamController::class, 'pollVoters']);
    Route::put('/live-streams/{liveStream}/polls/{poll}', [LiveStreamController::class, 'updatePoll']);
    Route::delete('/live-streams/{liveStream}/polls/{poll}', [LiveStreamController::class, 'deletePoll']);
    Route::post('/live-room/{liveStream:slug}/polls/{poll}/vote', [LiveStreamController::class, 'vote']);
    Route::post('/live-room/{liveStream:slug}/participant-request', [LiveStreamController::class, 'requestParticipation']);
    Route::delete('/live-room/{liveStream:slug}/participant-request', [LiveStreamController::class, 'leaveParticipation']);
    Route::post('/live-room/{liveStream:slug}/messages', [LiveStreamController::class, 'sendMessage']);
    Route::post('/live-room/{liveStream:slug}/contributions', [LiveStreamController::class, 'contribute']);
    Route::post('/live-room/{liveStream:slug}/ads', [LiveStreamController::class, 'serveAd'])->middleware('throttle:live-ad-impressions');
    Route::post('/live-room/{liveStream:slug}/ads/{impression:uuid}/complete', [LiveStreamController::class, 'completeAd'])
        ->withoutScopedBindings()
        ->middleware('throttle:live-ad-impressions');
    Route::get('/upload-sessions/{uploadSession:uuid}', [UploadSessionController::class, 'show']);
    Route::post('/upload-sessions/{uploadSession:uuid}/chunks', [UploadSessionController::class, 'chunk']);
    Route::post('/upload-sessions/{uploadSession:uuid}/complete', [UploadSessionController::class, 'complete']);
    Route::delete('/upload-sessions/{uploadSession:uuid}', [UploadSessionController::class, 'destroy']);
    Route::post('/video-imports/metadata', [VideoController::class, 'importMetadata']);
    Route::post('/video-imports', [VideoController::class, 'import']);
    Route::post('/payments/checkout', [PaymentController::class, 'checkout']);
    Route::post('/payments/stripe/confirm', [PaymentController::class, 'confirmStripe']);
    Route::post('/payments/paypal/capture', [PaymentController::class, 'capturePaypal']);
    Route::get('/payments/history', [PaymentController::class, 'history']);
    Route::get('/payments/billing-information', [PaymentController::class, 'billingInformation']);
    Route::put('/payments/billing-information', [PaymentController::class, 'updateBillingInformation']);
    Route::get('/wallet', [PaymentController::class, 'wallet']);
    Route::post('/wallet/topups', [PaymentController::class, 'walletTopup']);
    Route::post('/wallet/topups/stripe/confirm', [PaymentController::class, 'confirmStripeWalletTopup']);
    Route::post('/wallet/topups/paypal/capture', [PaymentController::class, 'capturePaypalWalletTopup']);
    Route::post('/wallet/withdrawals', [PaymentController::class, 'walletWithdraw']);
    Route::post('/channels/{channel:slug}/tips', [PaymentController::class, 'tipCreator'])->middleware('throttle:20,1');
    Route::post('/videos/{video:slug}/purchase', [PaymentController::class, 'purchaseVideo'])->middleware('throttle:20,1');
    Route::post('/community-posts/{communityPost}/purchase', [PaymentController::class, 'purchaseCommunityPhotos'])->middleware('throttle:20,1');
    Route::get('/video-boosts/video/{video:slug}', [VideoBoostController::class, 'show']);
    Route::post('/video-boosts', [VideoBoostController::class, 'store'])->middleware('throttle:20,1');
    Route::patch('/video-boosts/{campaign:uuid}', [VideoBoostController::class, 'update'])->middleware('throttle:30,1');

    Route::get('/watch-later', [VideoController::class, 'watchLater']);
    Route::get('/bookmarks', [CommunityPostController::class, 'bookmarks']);
    Route::get('/continue-watching', [VideoController::class, 'continueWatching']);
    Route::delete('/continue-watching/{video:slug}', [VideoController::class, 'dismissContinueWatching']);
    Route::post('/videos/{video:slug}/progress/watched', [VideoController::class, 'markWatchProgressWatched']);
    Route::post('/videos/{video:slug}/progress/restart', [VideoController::class, 'restartWatchProgress']);
    Route::get('/liked-videos', [VideoController::class, 'likedVideos']);
    Route::get('/library', [VideoController::class, 'library']);
    Route::get('/subscriptions', [SubscriptionController::class, 'index']);
    Route::get('/favorites', [SubscriptionController::class, 'favorites']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/link-preview', [CommunityPostController::class, 'preview']);
    Route::get('/mentions/users', function (Request $request) use ($messageUserPayload) {
        $search = trim((string) $request->query('q', ''));
        $users = User::query()
            ->when($request->user(), fn ($query) => \App\Support\UserBlocks::filter($query, 'users.id', (int) $request->user()->id))
            ->with('channel')
            ->where('id', '!=', $request->user()->id)
            ->when($search !== '', fn ($query) => $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhereHas('channel', fn ($channelQuery) => $channelQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%'));
            }))
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map($messageUserPayload)
            ->values();

        return response()->json(['users' => $users]);
    });
    Route::get('/messages/users', function (Request $request) use ($messageUserPayload, $messagePermission) {
        $search = trim((string) $request->query('q', ''));
        $requestedUserId = max(0, (int) $request->query('user_id', 0));
        $users = User::query()
            ->with('channel')
            ->where('id', '!=', $request->user()->id)
            ->when($requestedUserId > 0, fn ($query) => $query->whereKey($requestedUserId))
            ->when($search !== '', fn ($query) => $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhereHas('channel', fn ($channelQuery) => $channelQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%'));
            }))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->filter(fn (User $user): bool => $messagePermission($user, $request->user())[0])
            ->take(12)
            ->map(function (User $user) use ($messageUserPayload, $messagePermission, $request): array {
                $payload = $messageUserPayload($user);
                [$canMessage, $blockedReason] = $messagePermission($user, $request->user());
                $payload['can_message'] = $canMessage;
                $payload['message_blocked_reason'] = $blockedReason;

                return $payload;
            })
            ->values();

        return response()->json(['users' => $users]);
    });
    Route::get('/mutes', function (Request $request) use ($messageUserPayload) {
        $mutedIds = DB::table('user_mutes')
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->pluck('muted_user_id');
        $users = User::query()
            ->with('channel')
            ->whereIn('id', $mutedIds)
            ->get()
            ->sortBy(fn (User $user) => $mutedIds->search($user->id))
            ->map($messageUserPayload)
            ->values();

        return response()->json([
            'users' => $users,
            'count' => $users->count(),
        ]);
    });
    Route::post('/mutes/{mutedUser}', function (Request $request, User $mutedUser) {
        abort_if((int) $request->user()->id === (int) $mutedUser->id, 422, 'You cannot mute your own notifications.');
        DB::table('user_mutes')->updateOrInsert([
            'user_id' => $request->user()->id,
            'muted_user_id' => $mutedUser->id,
        ], [
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Notifications muted for this user.',
            'muted' => true,
            'count' => DB::table('user_mutes')->where('user_id', $request->user()->id)->count(),
        ]);
    });
    Route::delete('/mutes/{mutedUser}', function (Request $request, User $mutedUser) use ($messageUserPayload) {
        DB::table('user_mutes')
            ->where('user_id', $request->user()->id)
            ->where('muted_user_id', $mutedUser->id)
            ->delete();

        $mutedIds = DB::table('user_mutes')
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->pluck('muted_user_id');
        $users = User::query()
            ->with('channel')
            ->whereIn('id', $mutedIds)
            ->get()
            ->sortBy(fn (User $user) => $mutedIds->search($user->id))
            ->map($messageUserPayload)
            ->values();

        return response()->json([
            'message' => 'User unmuted.',
            'muted' => false,
            'users' => $users,
            'count' => $users->count(),
        ]);
    });
    Route::get('/comments/moderation', function (Request $request) {
        $limit = min(80, max(1, (int) $request->query('limit', 50)));
        $userId = (int) $request->user()->id;
        $videoComments = Comment::query()
            ->with(['user.channel', 'sticker', 'video:id,title,slug,user_id'])
            ->withCount('replies')
            ->whereHas('video', fn ($query) => $query->where('user_id', $userId))
            ->latest()
            ->limit($limit)
            ->get();
        $communityComments = Comment::query()
            ->with(['user.channel', 'sticker', 'commentable'])
            ->withCount('replies')
            ->where('commentable_type', CommunityPost::class)
            ->whereHasMorph('commentable', [CommunityPost::class], fn ($query) => $query->where('user_id', $userId))
            ->latest()
            ->limit($limit)
            ->get();
        $comments = $videoComments
            ->concat($communityComments)
            ->sortByDesc(fn (Comment $comment) => $comment->created_at?->timestamp ?? 0)
            ->take($limit)
            ->values();

        return response()->json([
            'comments' => $comments->map(function (Comment $comment) use ($request, $userId): array {
                $comment->can_delete = true;
                $comment->can_pin = true;
                $comment->can_report = false;
                $comment->can_mute = (int) $comment->user_id !== $userId;
                $comment->reported = false;
                $comment->is_liked = false;
                $target = $comment->video
                    ? ['type' => 'video', 'title' => $comment->video->title, 'path' => '/watch/'.$comment->video->slug]
                    : [
                        'type' => 'post',
                        'title' => Str::limit(strip_tags((string) ($comment->commentable?->body ?? 'Community post')), 80),
                        'path' => '/communities',
                    ];

                return array_merge((new CommentResource($comment))->resolve($request), [
                    'target' => $target,
                ]);
            })->values(),
        ]);
    });
    Route::get('/messages', function (Request $request) use ($conversationPayload) {
        $folder = (string) $request->query('folder', 'primary');
        $search = strtolower(trim((string) $request->query('q', '')));
        $unreadOnly = $request->boolean('unread');
        $limit = min(50, max(1, (int) $request->query('limit', 24)));
        $rows = DB::table('private_conversations')
            ->where(fn ($query) => $query
                ->where('user_one_id', $request->user()->id)
                ->orWhere('user_two_id', $request->user()->id))
            ->whereNotIn('user_one_id', DB::table('user_blocks')->where('user_id', $request->user()->id)->select('blocked_user_id'))
            ->whereNotIn('user_two_id', DB::table('user_blocks')->where('user_id', $request->user()->id)->select('blocked_user_id'))
            ->whereNotIn('user_one_id', DB::table('user_blocks')->where('blocked_user_id', $request->user()->id)->select('user_id'))
            ->whereNotIn('user_two_id', DB::table('user_blocks')->where('blocked_user_id', $request->user()->id)->select('user_id'))
            ->orderByRaw('coalesce(last_message_at, updated_at) desc')
            ->limit($limit)
            ->get();
        $conversations = $rows
            ->map(fn ($conversation) => $conversationPayload($conversation, $request))
            ->filter(function (array $conversation) use ($folder, $search, $unreadOnly): bool {
                if (in_array($folder, ['requests', 'mentions'], true) && $conversation['section'] !== $folder) {
                    return false;
                }
                if ($unreadOnly && $conversation['unread'] < 1) {
                    return false;
                }
                if ($search !== '' && ! str_contains(strtolower($conversation['name'].' '.$conversation['preview']), $search)) {
                    return false;
                }

                return true;
            })
            ->values();
        $conversationIds = DB::table('private_conversations')
            ->where(fn ($query) => $query
                ->where('user_one_id', $request->user()->id)
                ->orWhere('user_two_id', $request->user()->id))
            ->pluck('id');
        $unreadCount = DB::table('private_messages')
            ->whereIn('conversation_id', $conversationIds)
            ->where('sender_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'conversations' => $conversations,
            'unread_count' => (int) $unreadCount,
        ]);
    });
    Route::post('/messages', function (Request $request) use ($conversationPayload, $messagePayload, $messagePermission) {
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['nullable', 'string', 'max:5000'],
            'images' => ['prohibited'],
            'image_ids' => ['nullable', 'array', 'max:4'],
            'image_ids.*' => ['required', 'uuid', 'distinct'],
        ], ['images.prohibited' => 'Upload images first and submit image_ids. Multipart image attachments are no longer accepted.']);
        abort_if((int) $data['recipient_id'] === $request->user()->id, 422, 'You cannot message yourself.');
        $recipient = User::query()->with('channel')->find((int) $data['recipient_id']);
        [$canMessage, $messageBlockedReason] = $messagePermission($recipient, $request->user());
        abort_unless($canMessage, 403, $messageBlockedReason);
        $body = trim((string) ($data['body'] ?? ''));
        $imageIds = $data['image_ids'] ?? [];
        app(ImageUploads::class)->validateIds($request->user(), 'private_message', $recipient->id, $imageIds);
        abort_if($body === '' && ! $imageIds, 422, 'Message cannot be empty.');
        [$firstUserId, $secondUserId] = collect([$request->user()->id, (int) $data['recipient_id']])->sort()->values()->all();

        [$conversationId, $messageId] = DB::transaction(function () use ($firstUserId, $secondUserId, $request, $body, $imageIds): array {
            \App\Support\UserBlocks::lockPair((int) $firstUserId, (int) $secondUserId);
            $other = (int) $request->user()->id === (int) $firstUserId ? (int) $secondUserId : (int) $firstUserId;
            \App\Support\UserBlocks::assertInteraction($request->user(), $other);
            $conversation = DB::table('private_conversations')
                ->where('user_one_id', $firstUserId)
                ->where('user_two_id', $secondUserId)
                ->first();
            if (! $conversation) {
                $conversationId = DB::table('private_conversations')->insertGetId([
                    'user_one_id' => $firstUserId,
                    'user_two_id' => $secondUserId,
                    'last_message_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $conversationId = (int) $conversation->id;
            }

            $messageId = DB::table('private_messages')->insertGetId([
                'conversation_id' => $conversationId,
                'sender_id' => $request->user()->id,
                'body' => $body,
                'attachments' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($imageIds) {
                $attachments = app(ImageUploads::class)->bind($request->user(), 'private_message', $other, $imageIds, 'private_messages', $messageId);
                abort_unless(count($attachments) === count($imageIds), 422, 'An image is no longer ready to publish.');
                DB::table('private_messages')->where('id', $messageId)->update(['attachments' => json_encode($attachments)]);
            }
            DB::table('private_conversations')->where('id', $conversationId)->update([
                'last_message_at' => now(),
                'updated_at' => now(),
            ]);

            return [$conversationId, $messageId];
        });

        $conversation = DB::table('private_conversations')->where('id', $conversationId)->first();
        $message = DB::table('private_messages')->where('id', $messageId)->first();

        return response()->json([
            'message' => 'Message sent.',
            'conversation' => $conversationPayload($conversation, $request, true),
            'chat_message' => $messagePayload($message, $request->user()->id),
        ], 201);
    });
    Route::get('/messages/{conversation}', function (Request $request, int $conversation) use ($findConversation, $conversationPayload) {
        $privateConversation = $findConversation($conversation, $request->user()->id);
        DB::table('private_messages')
            ->where('conversation_id', $privateConversation->id)
            ->where('sender_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
        $privateConversation = DB::table('private_conversations')->where('id', $privateConversation->id)->first();

        return response()->json([
            'conversation' => $conversationPayload($privateConversation, $request, true),
        ]);
    });
    Route::post('/messages/{conversation}/read', function (Request $request, int $conversation) use ($findConversation, $conversationPayload) {
        $privateConversation = $findConversation($conversation, $request->user()->id);
        DB::table('private_messages')
            ->where('conversation_id', $privateConversation->id)
            ->where('sender_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
        $privateConversation = DB::table('private_conversations')->where('id', $privateConversation->id)->first();

        return response()->json([
            'message' => 'Conversation marked as read.',
            'conversation' => $conversationPayload($privateConversation, $request),
        ]);
    });
    Route::post('/messages/{conversation}/messages', function (Request $request, int $conversation) use ($findConversation, $conversationPayload, $messagePayload, $messagePermission) {
        $privateConversation = $findConversation($conversation, $request->user()->id);
        $recipientId = (int) $privateConversation->user_one_id === (int) $request->user()->id
            ? (int) $privateConversation->user_two_id
            : (int) $privateConversation->user_one_id;
        $recipient = User::query()->with('channel')->find($recipientId);
        [$canMessage, $messageBlockedReason] = $messagePermission($recipient, $request->user());
        abort_unless($canMessage, 403, $messageBlockedReason);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'images' => ['prohibited'],
            'image_ids' => ['nullable', 'array', 'max:4'],
            'image_ids.*' => ['required', 'uuid', 'distinct'],
        ], ['images.prohibited' => 'Upload images first and submit image_ids. Multipart image attachments are no longer accepted.']);
        $body = trim((string) ($data['body'] ?? ''));
        $imageIds = $data['image_ids'] ?? [];
        app(ImageUploads::class)->validateIds($request->user(), 'private_message', $recipientId, $imageIds);
        abort_if($body === '' && ! $imageIds, 422, 'Message cannot be empty.');

        $messageId = DB::transaction(function () use ($privateConversation, $request, $body, $imageIds): int {
            $other = (int) $request->user()->id === (int) $privateConversation->user_one_id ? (int) $privateConversation->user_two_id : (int) $privateConversation->user_one_id;
            \App\Support\UserBlocks::lockPair((int) $request->user()->id, $other);
            \App\Support\UserBlocks::assertInteraction($request->user(), $other);
            $messageId = DB::table('private_messages')->insertGetId([
                'conversation_id' => $privateConversation->id,
                'sender_id' => $request->user()->id,
                'body' => $body,
                'attachments' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($imageIds) {
                $attachments = app(ImageUploads::class)->bind($request->user(), 'private_message', $other, $imageIds, 'private_messages', $messageId);
                abort_unless(count($attachments) === count($imageIds), 422, 'An image is no longer ready to publish.');
                DB::table('private_messages')->where('id', $messageId)->update(['attachments' => json_encode($attachments)]);
            }
            DB::table('private_conversations')->where('id', $privateConversation->id)->update([
                'last_message_at' => now(),
                'updated_at' => now(),
            ]);

            return $messageId;
        });

        $privateConversation = DB::table('private_conversations')->where('id', $privateConversation->id)->first();
        $message = DB::table('private_messages')->where('id', $messageId)->first();

        return response()->json([
            'message' => 'Message sent.',
            'conversation' => $conversationPayload($privateConversation, $request, true),
            'chat_message' => $messagePayload($message, $request->user()->id),
        ], 201);
    });

    Route::get('/videos/{video:slug}/status', [VideoController::class, 'status']);
    Route::get('/videos/{video:slug}/viewer-state', [VideoController::class, 'viewerState']);
    Route::post('/videos/{video:slug}/progress', [VideoController::class, 'updateWatchProgress']);
    Route::put('/videos/{video:slug}/thumbnail', [\App\Http\Controllers\Api\ThumbnailController::class, 'update'])->middleware('throttle:10,1');
    Route::post('/videos', [VideoController::class, 'store']);
    Route::match(['put', 'post'], '/videos/{video:slug}', [VideoController::class, 'update']);
    Route::delete('/videos/{video:slug}', [VideoController::class, 'destroy']);
    Route::post('/videos/{video:slug}/report', [VideoController::class, 'report']);

    Route::post('/videos/{video:slug}/comments', [CommentController::class, 'store']);
    Route::get('/videos/{video:slug}/time-marks/mine', [TimeMarkController::class, 'mine']);
    Route::post('/videos/{video:slug}/time-marks', [TimeMarkController::class, 'store']);
    Route::post('/time-marks/{timeMark}/vote', [TimeMarkController::class, 'vote']);
    Route::put('/comments/{comment}', [CommentController::class, 'update']);
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);
    Route::post('/comments/{comment}/pin', [CommentController::class, 'togglePin']);
    Route::post('/comments/{comment}/report', [CommentController::class, 'report']);
    Route::post('/comments/{comment}/mute-user', [CommentController::class, 'muteUser']);
    Route::post('/channels/{channel:slug}/report', function (Request $request, Channel $channel) {
        app(\App\Services\Api\V1\CommunityAccessService::class)->assertChannelVisible($channel, $request->user());
        abort_unless(Schema::hasTable('user_reports'), 503, 'Profile reports are not available yet.');
        abort_if((int) $request->user()->id === (int) $channel->user_id, 403, 'You cannot report your own profile.');

        $data = $request->validate(\App\Support\ReportReasons::rules(\App\Support\ReportReasons::PROFILE));

        DB::table('user_reports')->updateOrInsert([
            'user_id' => $request->user()->id,
            'reported_user_id' => $channel->user_id,
        ], [
            'reason' => $data['reason'],
            'details' => \App\Support\ReportReasons::details($data['details'] ?? null),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Profile reported for review.']);
    });

    Route::post('/videos/{video:slug}/like', [LikeController::class, 'toggleVideo']);
    Route::post('/videos/{video:slug}/watch-later', [VideoController::class, 'toggleWatchLater']);
    Route::post('/comments/{comment}/like', [LikeController::class, 'toggleComment']);
    Route::post('/channels/{channel:slug}/community-posts', [CommunityPostController::class, 'store']);
    Route::put('/community-posts/{communityPost}', [CommunityPostController::class, 'update']);
    Route::delete('/community-posts/{communityPost}', [CommunityPostController::class, 'destroy']);
    Route::post('/community-posts/{communityPost}/like', [LikeController::class, 'toggleCommunityPost']);
    Route::post('/community-posts/{communityPost}/bookmark', [CommunityPostController::class, 'bookmark']);
    Route::post('/community-posts/{communityPost}/comments', [CommunityPostController::class, 'storeComment']);
    Route::post('/community-posts/{communityPost}/vote', [CommunityPostController::class, 'vote']);
    Route::get('/playlists/{playlist}/members', [SharedPlaylistController::class, 'members']);
    Route::post('/playlists/{playlist}/members', [SharedPlaylistController::class, 'invite']);
    Route::post('/playlists/{playlist}/members/accept', [SharedPlaylistController::class, 'accept']);
    Route::delete('/playlists/{playlist}/members/{user}', [SharedPlaylistController::class, 'revoke']);
    Route::put('/playlists/{playlist}/order', [SharedPlaylistController::class, 'reorder']);
    Route::post('/playlists/{playlist}/videos/{video:slug}/vote', [SharedPlaylistController::class, 'vote']);
    Route::post('/playlists/{playlist}/videos/{video:slug}', function (Request $request, Playlist $playlist, Video $video) {
        abort_unless(Gate::forUser($request->user())->allows('manageVideos', $playlist), 403);
        abort_if($video->visibility === 'private' && $request->user()->id !== $video->user_id, 404);

        $data = $request->validate([
            'attached' => ['nullable', 'boolean'],
        ]);
        $exists = $playlist->videos()->whereKey($video->id)->exists();
        $attached = $data['attached'] ?? ! $exists;

        if ($attached && ! $exists) {
            \App\Support\SharedPlaylists::addVideo($request->user(), $playlist, $video);
        } elseif (! $attached && $exists) {
            \App\Support\SharedPlaylists::removeVideo($request->user(), $playlist, (int) $video->id);
        }

        return response()->json([
            'attached' => (bool) $attached,
            'playlist' => (new PlaylistResource($playlist->fresh()->load(['coverVideo.channel.user', 'coverVideo.user', 'coverVideo.category', 'coverVideo.language', 'videos.channel', 'videos.user', 'videos.category', 'videos.language'])->loadCount('videos')))->resolve($request),
        ]);
    });

    Route::put('/channels/{channel:slug}/bio', function (Request $request, Channel $channel) {
        abort_unless($request->user()?->id === $channel->user_id, 403);

        $data = $request->validate([
            'bio' => ['nullable', 'string', 'max:100'],
        ]);
        $bio = trim((string) ($data['bio'] ?? ''));

        $channel->update(['bio' => $bio !== '' ? $bio : null]);

        return response()->json([
            'message' => 'Bio updated.',
            'channel' => (new ChannelResource($channel->fresh()))->resolve($request),
        ]);
    });
    Route::put('/channels/{channel:slug}/about', function (Request $request, Channel $channel) {
        abort_unless($request->user()?->id === $channel->user_id, 403);

        $data = $request->validate([
            'about' => ['nullable', 'string', 'max:2000'],
        ]);
        $about = trim((string) ($data['about'] ?? ''));

        $channel->update(['about' => $about !== '' ? $about : null]);

        return response()->json([
            'message' => 'About updated.',
            'channel' => (new ChannelResource($channel->fresh()))->resolve($request),
        ]);
    });
    Route::put('/channels/{channel:slug}/featured-video', function (Request $request, Channel $channel) {
        abort_unless($request->user()?->id === $channel->user_id, 403);

        $data = $request->validate([
            'video_id' => ['nullable', 'integer', 'exists:videos,id'],
        ]);
        $videoId = $data['video_id'] ?? null;

        if ($videoId) {
            Video::query()
                ->where('channel_id', $channel->id)
                ->public()
                ->whereKey($videoId)
                ->firstOrFail();
        }

        $channel->update(['featured_video_id' => $videoId]);
        $channel = $channel->fresh()->load([
            'user',
            'featuredVideo' => fn ($query) => $query->public()->with(['channel.user', 'user', 'category', 'language']),
        ]);

        return response()->json([
            'message' => $videoId ? 'Featured video updated.' : 'Featured video cleared.',
            'channel' => (new ChannelResource($channel))->resolve($request),
        ]);
    });
    Route::post('/channels/{channel:slug}/banner', function (Request $request, Channel $channel) {
        abort_unless($request->user()?->id === $channel->user_id, 403);

        $data = $request->validate([
            'banner' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'cover_position_y' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        if (MediaStorage::isLocal()) {
            MediaStorage::ensureLocalDirectory('channels/banners');
        }
        $path = MediaStorage::disk()->putFile('channels/banners', $data['banner']);
        $channel->update([
            'cover_path' => $path,
            'cover_position_y' => $data['cover_position_y'] ?? 50,
        ]);

        $post = CommunityPost::query()->create([
            'user_id' => $request->user()->id,
            'channel_id' => $channel->id,
            'type' => 'cover_update',
            'body' => 'updated their cover photo.',
            'attachments' => [[
                'type' => 'image',
                'url' => MediaStorage::url($path),
                'path' => $path,
                'name' => $data['banner']->getClientOriginalName(),
                'mime' => $data['banner']->getMimeType(),
                'size' => $data['banner']->getSize(),
            ]],
            'metadata' => ['cover_position_y' => $data['cover_position_y'] ?? 50],
        ]);

        return response()->json([
            'message' => 'Banner updated.',
            'channel' => (new ChannelResource($channel->fresh()))->resolve($request),
            'community_post' => (new CommunityPostResource($post->load(['user', 'channel', 'video.channel.user', 'video.user', 'video.category', 'video.language'])))->resolve($request),
        ]);
    });
    Route::post('/channels/{channel:slug}/avatar', function (Request $request, Channel $channel) {
        abort_unless($request->user()?->id === $channel->user_id, 403);

        $data = $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $oldAvatar = $channel->avatar_path;
        if (MediaStorage::isLocal()) {
            MediaStorage::ensureLocalDirectory('channels/avatars');
        }
        $path = MediaStorage::disk()->putFile('channels/avatars', $data['avatar']);
        $channel->update(['avatar_path' => $path]);

        if ($oldAvatar && $oldAvatar !== $path) {
            MediaStorage::delete($oldAvatar);
        }

        return response()->json([
            'message' => 'Avatar updated.',
            'channel' => (new ChannelResource($channel->fresh()))->resolve($request),
        ]);
    });
    Route::delete('/channels/{channel:slug}/avatar', function (Request $request, Channel $channel) {
        abort_unless($request->user()?->id === $channel->user_id, 403);

        $oldAvatar = $channel->avatar_path;
        $channel->update(['avatar_path' => null]);

        if ($oldAvatar) {
            MediaStorage::delete($oldAvatar);
        }

        return response()->json([
            'message' => 'Avatar removed.',
            'channel' => (new ChannelResource($channel->fresh()))->resolve($request),
        ]);
    });
    Route::put('/channels/{channel:slug}/banner-position', function (Request $request, Channel $channel) {
        abort_unless($request->user()?->id === $channel->user_id, 403);

        $data = $request->validate([
            'cover_position_y' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $channel->update(['cover_position_y' => $data['cover_position_y']]);

        return response()->json([
            'message' => 'Banner position saved.',
            'channel' => (new ChannelResource($channel->fresh()))->resolve($request),
        ]);
    });
    Route::post('/channels/{channel:slug}/playlists', function (Request $request, Channel $channel) {
        abort_unless($request->user()?->id === $channel->user_id, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'privacy' => ['nullable', 'string', 'in:public,private,unlisted,Public,Private,Unlisted'],
            'color' => ['nullable', 'string', 'max:20'],
            'cover_video_id' => ['nullable', 'integer'],
            'video_ids' => ['nullable', 'array'],
            'video_ids.*' => ['integer'],
        ]);

        $playlist = Playlist::create([
            'user_id' => $request->user()->id,
            'channel_id' => $channel->id,
            'title' => trim($data['title']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'privacy' => strtolower($data['privacy'] ?? 'public'),
            'color' => $data['color'] ?? '#6c4cff',
        ]);

        $videoIds = DB::table('videos')
            ->whereIn('id', $data['video_ids'] ?? [])
            ->where(function ($query) use ($request) {
                $query->where('user_id', $request->user()->id)
                    ->orWhere(function ($publicQuery) {
                        $publicQuery->where('visibility', 'public')->where('status', 'ready');
                    });
            })
            ->pluck('id')
            ->values();

        if (Schema::hasColumn('playlists', 'cover_video_id')) {
            $playlist->update(['cover_video_id' => $videoIds->contains((int) ($data['cover_video_id'] ?? 0)) ? (int) $data['cover_video_id'] : null]);
        }

        $playlist->videos()->sync($videoIds->mapWithKeys(fn ($id, $index) => [$id => ['sort_order' => $index]])->all());

        return response()->json([
            'message' => 'Playlist created.',
            'playlist' => (new PlaylistResource($playlist->load(['coverVideo.channel.user', 'coverVideo.user', 'coverVideo.category', 'coverVideo.language', 'videos.channel', 'videos.user', 'videos.category', 'videos.language'])->loadCount('videos')))->resolve($request),
        ], 201);
    });
    Route::put('/playlists/{playlist}', function (Request $request, Playlist $playlist) {
        abort_unless($request->user()?->id === $playlist->user_id, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'privacy' => ['nullable', 'string', 'in:public,private,unlisted,Public,Private,Unlisted'],
            'color' => ['nullable', 'string', 'max:20'],
            'cover_video_id' => ['nullable', 'integer'],
            'video_ids' => ['nullable', 'array'],
            'video_ids.*' => ['integer'],
        ]);

        $videoIds = DB::table('videos')
            ->whereIn('id', $data['video_ids'] ?? $playlist->videos()->pluck('videos.id')->all())
            ->where(function ($query) use ($request) {
                $query->where('user_id', $request->user()->id)
                    ->orWhere(function ($publicQuery) {
                        $publicQuery->where('visibility', 'public')->where('status', 'ready');
                    });
            })
            ->pluck('id')
            ->values();

        $updates = [
            'title' => trim($data['title']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'privacy' => strtolower($data['privacy'] ?? 'public'),
            'color' => $data['color'] ?? '#6c4cff',
        ];

        if (Schema::hasColumn('playlists', 'cover_video_id')) {
            $updates['cover_video_id'] = $videoIds->contains((int) ($data['cover_video_id'] ?? 0)) ? (int) $data['cover_video_id'] : null;
        }

        $playlist->update($updates);
        $playlist->videos()->sync($videoIds->mapWithKeys(fn ($id, $index) => [$id => ['sort_order' => $index]])->all());

        return response()->json([
            'message' => 'Playlist updated.',
            'playlist' => (new PlaylistResource($playlist->fresh()->load(['coverVideo.channel.user', 'coverVideo.user', 'coverVideo.category', 'coverVideo.language', 'videos.channel', 'videos.user', 'videos.category', 'videos.language'])->loadCount('videos')))->resolve($request),
        ]);
    });
    Route::delete('/playlists/{playlist}', function (Request $request, Playlist $playlist) {
        abort_unless($request->user()?->id === $playlist->user_id, 403);

        $playlist->delete();

        return response()->json(['message' => 'Playlist deleted.']);
    });
    Route::post('/channels/{channel:slug}/subscribe', [SubscriptionController::class, 'toggle']);
    Route::post('/channels/{channel:slug}/favorite', [SubscriptionController::class, 'toggleFavorite']);
});

Route::get('/developers', function () {
    $schema = DB::getSchemaBuilder();
    $tableExists = fn (string $table) => $schema->hasTable($table);
    $count = fn (string $table) => $tableExists($table) ? DB::table($table)->count() : 0;
    $sum = fn (string $table, string $column) => $tableExists($table) && $schema->hasColumn($table, $column)
        ? (int) DB::table($table)->sum($column)
        : 0;

    $routes = collect(Route::getRoutes())
        ->map(function ($route) {
            $methods = collect($route->methods())->reject(fn ($method) => $method === 'HEAD')->values();
            $uri = $route->uri();

            return [
                'method' => $methods->first() ?: 'GET',
                'methods' => $methods,
                'uri' => '/'.$uri,
                'name' => $route->getName(),
            ];
        })
        ->filter(fn ($route) => str_starts_with($route['uri'], '/api/') && ! str_starts_with($route['uri'], '/api/admin'))
        ->reject(fn ($route) => str_contains($route['uri'], '/password') || str_contains($route['uri'], '/2fa'))
        ->sortBy(fn ($route) => $route['uri'].' '.$route['method'])
        ->values();

    $routeGroups = $routes
        ->map(function ($route) {
            $path = trim(substr($route['uri'], 5), '/');
            $group = explode('/', $path)[0] ?: 'root';

            return str_replace(['-', '_'], ' ', $group);
        })
        ->countBy()
        ->map(fn ($count, $name) => ['name' => ucfirst($name), 'routes' => $count])
        ->sortBy('name')
        ->values();

    $categories = $tableExists('video_categories')
        ? DB::table('video_categories')->select('name', 'slug')->orderBy('name')->limit(12)->get()
        : collect();

    $languages = $tableExists('video_languages')
        ? DB::table('video_languages')->select('name', 'code')->orderBy('name')->limit(12)->get()
        : collect();

    $stats = [
        'users' => $count('users'),
        'creators' => $count('channels'),
        'videos' => $count('videos'),
        'comments' => $count('comments'),
        'playlists' => $count('playlists'),
        'categories' => $count('video_categories'),
        'languages' => $count('video_languages'),
        'views' => $sum('videos', 'views_count'),
        'routes' => $routes->count(),
    ];

    return response()->json([
        'status' => 'operational',
        'database' => 'connected',
        'app' => [
            'name' => config('app.name', 'PlayHub'),
            'environment' => config('app.env'),
            'url' => config('app.url'),
        ],
        'api_base_url' => rtrim(config('app.url') ?: url('/'), '/').'/api',
        'stats' => $stats,
        'route_groups' => $routeGroups,
        'sample_endpoints' => $routes->take(10)->values(),
        'capabilities' => [
            ['title' => 'Public REST API', 'text' => $routes->count().' documented public API routes are registered in Laravel.'],
            ['title' => 'Upload pipeline', 'text' => 'Uploads support '.config('playhub.chunk_size_mb', 8).' MB chunks and files up to '.config('playhub.max_upload_mb', 2048).' MB.'],
            ['title' => 'Creator tools', 'text' => $stats['creators'].' creator channels, '.$stats['videos'].' videos, and '.$stats['playlists'].' playlists are available.'],
            ['title' => 'Discovery metadata', 'text' => $stats['categories'].' categories and '.$stats['languages'].' upload languages are configured.'],
        ],
        'rate_limits' => [
            ['name' => 'Contact messages', 'value' => '8 requests/minute'],
            ['name' => 'Upload chunk size', 'value' => config('playhub.chunk_size_mb', 8).' MB'],
            ['name' => 'Max upload size', 'value' => config('playhub.max_upload_mb', 2048).' MB'],
        ],
        'taxonomies' => [
            'categories' => $categories,
            'languages' => $languages,
        ],
        'generated_at' => now()->toIso8601String(),
    ])->header('Cache-Control', 'public, max-age=60');
});

require __DIR__.'/image-uploads.php';
