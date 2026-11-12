<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LiveStreamController as LegacyLiveStreamController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AdminPlatformAppVersionController;
use App\Http\Controllers\Api\V1\AdvertisementController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\NativeDeviceSessionController;
use App\Http\Controllers\Api\V1\Auth\NativeLoginController;
use App\Http\Controllers\Api\V1\Auth\RegistrationCaptchaController;
use App\Http\Controllers\Api\V1\Auth\TvDeviceAuthorizationController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\BuildLicenseController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\CommentModerationController;
use App\Http\Controllers\Api\V1\CommerceController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\CreatorAnalyticsController;
use App\Http\Controllers\Api\V1\CreatorChannelController;
use App\Http\Controllers\Api\V1\CreatorController;
use App\Http\Controllers\Api\V1\CreatorMediaController;
use App\Http\Controllers\Api\V1\DiscoveryController;
use App\Http\Controllers\Api\V1\EngagementController;
use App\Http\Controllers\Api\V1\FavoriteChannelController;
use App\Http\Controllers\Api\V1\HistoryController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\LiveStreamController;
use App\Http\Controllers\Api\V1\LocalizationController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\MuteController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PlaylistController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PushDeviceController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SafetyController;
use App\Http\Controllers\Api\V1\ShortsController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\VideoController;
use App\Http\Middleware\Api\V1\AttachRequestId;
use App\Http\Middleware\Api\V1\EnsureAccountActive;
use App\Http\Middleware\Api\V1\NormalizeApiResponse;
use App\Http\Middleware\Api\V1\RejectTvCreator;
use App\Http\Middleware\Api\V1\RejectTvPublishing;
use App\Http\Middleware\Api\V1\RequireTokenAbility;
use App\Http\Middleware\Api\V1\ResolveOptionalSanctum;
use App\Http\Middleware\ResolveClientContext;
use App\Http\Middleware\TrackNativeDeviceSession;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware([
        AttachRequestId::class,
        NormalizeApiResponse::class,
        ResolveClientContext::class,
        'throttle:api-v1',
        \App\Http\Middleware\EnsureUgcSafety::class,
    ])
    ->group(function (): void {
        Route::post('/build/license/verify', BuildLicenseController::class)
            ->middleware([\App\Http\Middleware\Api\V1\ProtectBuildLicenseRequest::class, 'throttle:build-license'])
            ->name('build.license.verify');
        Route::get('/bootstrap', BootstrapController::class)->name('bootstrap');
        Route::get('/safety/policy', [SafetyController::class, 'policy'])->name('safety.policy');
        Route::get('/localization', [LocalizationController::class, 'index'])->name('localization.index');
        Route::get('/localization/{locale}', [LocalizationController::class, 'show'])->name('localization.show');
        Route::get('/content-pages', [LocalizationController::class, 'pages'])->name('content-pages.index');

        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::get('/login-captcha', [AuthController::class, 'simpleCaptcha'])
                ->middleware('throttle:auth-captcha')->name('login-captcha');
            Route::get('/registration-captcha', [AuthController::class, 'registrationCaptcha'])->name('registration-captcha');
            Route::get('/registration-captcha/google', [RegistrationCaptchaController::class, 'show'])
                ->middleware('throttle:auth-captcha')->name('registration-google-captcha');
            Route::post('/registration-captcha/google/complete', [RegistrationCaptchaController::class, 'complete'])
                ->middleware('throttle:auth-captcha')->name('registration-google-captcha-complete');
            Route::post('/registration-captcha/google/exchange', [RegistrationCaptchaController::class, 'exchange'])
                ->middleware('throttle:auth-captcha')->name('registration-google-captcha-exchange');
            Route::get('/registration-availability', [AuthController::class, 'registrationAvailability'])->name('registration-availability');
            Route::post('/registration-email-challenge', [AuthController::class, 'registrationEmailChallenge'])
                ->middleware('throttle:auth-register')->name('registration-email-challenge');
            Route::post('/registration-phone-challenge', [AuthController::class, 'registrationPhoneChallenge'])
                ->middleware('throttle:auth-register')->name('registration-phone-challenge');
            Route::post('/login', [NativeLoginController::class, 'store'])
                ->middleware('throttle:auth-login')->name('login');
            Route::post('/register', [NativeLoginController::class, 'register'])
                ->middleware('throttle:auth-register')->name('register');
            Route::post('/confirm-registration', [NativeLoginController::class, 'confirmRegistration'])
                ->middleware('throttle:auth-register')->name('confirm-registration');
            Route::post('/social/exchange', [NativeLoginController::class, 'socialExchange'])
                ->middleware('throttle:auth-login')->name('social-exchange');
            Route::post('/refresh', [NativeLoginController::class, 'refresh'])
                ->middleware('throttle:auth-refresh')->name('refresh');
            Route::get('/social/{provider}/redirect', [AuthController::class, 'socialRedirect'])
                ->middleware('throttle:auth-login')->name('social-redirect');
            Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
                ->middleware('throttle:auth-password')->name('forgot-password');
            Route::post('/reset-password', [AuthController::class, 'resetPassword'])
                ->middleware('throttle:auth-password')->name('reset-password');
            Route::post('/tv/device-authorizations', [TvDeviceAuthorizationController::class, 'store'])
                ->middleware('throttle:device-pair-create')->name('tv.create');
            Route::post('/tv/device-authorizations/token', [TvDeviceAuthorizationController::class, 'token'])
                ->middleware('throttle:device-pair-poll')->name('tv.token');

            Route::middleware(['auth:sanctum', EnsureAccountActive::class])->group(function (): void {
                Route::get('/me', CurrentUserController::class)->name('me');
                Route::post('/tv/device-authorizations/preview', [TvDeviceAuthorizationController::class, 'preview'])
                    ->middleware([RequireTokenAbility::class.':account:write', 'throttle:device-pair-approve'])
                    ->name('tv.preview');
                Route::post('/tv/device-authorizations/approve', [TvDeviceAuthorizationController::class, 'approve'])
                    ->middleware([RequireTokenAbility::class.':account:write', 'throttle:device-pair-approve'])
                    ->name('tv.approve');
            });
        });

        Route::middleware(['auth:sanctum', EnsureAccountActive::class])->group(function (): void {
            Route::get('/admin/client-versions', [AdminPlatformAppVersionController::class, 'index'])
                ->middleware(RequireTokenAbility::class.':account:read')->name('admin.client-versions.index');
            Route::put('/admin/client-versions/{platformAppVersion:platform}', [AdminPlatformAppVersionController::class, 'update'])
                ->middleware([RequireTokenAbility::class.':account:write', 'throttle:api-write'])->name('admin.client-versions.update');
        });

        Route::middleware([
            ResolveOptionalSanctum::class,
            EnsureAccountActive::class,
            TrackNativeDeviceSession::class.':optional',
        ])->group(function (): void {
            Route::get('/home', [HomeController::class, 'index'])->name('home');
            Route::get('/discovery/options', [DiscoveryController::class, 'options'])->name('discovery.options');
            Route::get('/home/sections/{section}', [HomeController::class, 'section'])->name('home.section');

            Route::get('/catalog/categories', [CatalogController::class, 'categories'])->name('catalog.categories');
            Route::get('/catalog/languages', [CatalogController::class, 'languages'])->name('catalog.languages');

            Route::get('/videos', [VideoController::class, 'index'])->name('videos.index');
            Route::get('/videos/{video:slug}/playback', [VideoController::class, 'playback'])
                ->middleware('throttle:120,1')->name('videos.playback');
            Route::post('/videos/{video:slug}/receiver-playback', [VideoController::class, 'receiverPlayback'])
                ->middleware('throttle:60,1')->name('videos.receiver-playback');
            Route::get('/videos/{video:slug}/related', [VideoController::class, 'related'])->name('videos.related');
            Route::get('/videos/{video:slug}/comments', [CommentController::class, 'index'])->name('videos.comments.index');
            Route::get('/videos/{video:slug}/comments/{comment}/context', [CommentController::class, 'context'])
                ->name('videos.comments.context');
            Route::get('/videos/{video:slug}', [VideoController::class, 'show'])->name('videos.show');
            Route::get('/clips/{uuid}', [\App\Http\Controllers\Api\ClipTransferController::class, 'publicShow'])
                ->whereUuid('uuid')->name('clips.show');
            Route::get('/clips/{uuid}/playback', [\App\Http\Controllers\Api\ClipTransferController::class, 'publicPlayback'])
                ->whereUuid('uuid')->middleware('throttle:20,1')->name('clips.playback');
            Route::get('/comments/{comment}/replies', [CommentController::class, 'replies'])->name('comments.replies');
            Route::get('/community-posts', [CommunityController::class, 'index'])->name('community.index');
            Route::get('/community-posts/discovery', [CommunityController::class, 'discovery'])->name('community.discovery');
            Route::get('/community-posts/options', [CommunityController::class, 'options'])
                ->middleware(['auth:sanctum', RequireTokenAbility::class.':engagement:write'])->name('community.options');
            Route::get('/community-posts/{communityPost}/voters', [CommunityController::class, 'voters'])->name('community.voters');
            Route::get('/profiles/{channel:slug}/community-posts', [CommunityController::class, 'channel'])->name('community.channel');
            Route::get('/community-posts/{communityPost}/comments', [CommunityController::class, 'comments'])->name('community.comments.index');
            Route::get('/community-posts/{communityPost}/comments/{comment}/context', [CommunityController::class, 'commentContext'])
                ->name('community.comments.context');
            Route::get('/community-posts/{communityPost}', [CommunityController::class, 'show'])->name('community.show');

            Route::get('/shorts', [ShortsController::class, 'index'])->name('shorts.index');
            Route::get('/shorts/{short:slug}/playback', [ShortsController::class, 'playback'])->name('shorts.playback');
            Route::post('/shorts/{short:slug}/receiver-playback', [ShortsController::class, 'receiverPlayback'])
                ->middleware('throttle:60,1')->name('shorts.receiver-playback');
            Route::get('/shorts/{short:slug}', [ShortsController::class, 'show'])->name('shorts.show');

            Route::get('/live-streams', [LiveStreamController::class, 'index'])->name('live.index');
            Route::get('/live/options', [LiveStreamController::class, 'options'])->name('live.options');
            Route::get('/live/gifts', [LegacyLiveStreamController::class, 'gifts'])->name('live.gifts');
            Route::get('/live/stickers', [LegacyLiveStreamController::class, 'superStickers'])->name('live.stickers');
            Route::get('/stickers', [\App\Http\Controllers\Api\StickerController::class, 'index'])->name('stickers.index');
            Route::post('/stickers/selection', [\App\Http\Controllers\Api\StickerController::class, 'selection'])
                ->middleware(['auth:sanctum', RequireTokenAbility::class.':engagement:write', 'throttle:api-write'])->name('stickers.selection');
            Route::get('/live-streams/{liveStream:slug}/messages', [LegacyLiveStreamController::class, 'messages'])->name('live.messages');
            Route::get('/live-streams/{liveStream:slug}/polls', [LegacyLiveStreamController::class, 'polls'])->name('live.polls');
            Route::get('/live-streams/{liveStream:slug}/room', [LiveStreamController::class, 'room'])->name('live.room');
            Route::post('/live-streams/{liveStream:slug}/join', [LiveStreamController::class, 'viewerJoin'])
                ->middleware('throttle:live-token')->name('live.join');
            Route::get('/live-streams/{liveStream:slug}', [LiveStreamController::class, 'show'])->name('live.show');

            Route::get('/profiles/{channel:slug}/videos', [ProfileController::class, 'videos'])->name('profiles.videos');
            Route::get('/profiles/{channel:slug}/playlists', [ProfileController::class, 'playlists'])->name('profiles.playlists');
            Route::get('/profiles/{channel:slug}/subscribers', [ProfileController::class, 'subscribers'])->name('profiles.subscribers');
            Route::get('/profiles/{channel:slug}', [ProfileController::class, 'show'])->name('profiles.show');
            Route::get('/mentions/{handle}', [ProfileController::class, 'resolveMention'])
                ->where('handle', '[A-Za-z0-9_.-]{2,40}')
                ->middleware('throttle:search')->name('mentions.resolve');
            Route::get('/playlists/{playlist}', [PlaylistController::class, 'show'])->name('playlists.show');

            Route::get('/search/suggestions', [SearchController::class, 'suggestions'])
                ->middleware('throttle:search')->name('search.suggestions');
            Route::get('/search', [SearchController::class, 'index'])
                ->middleware('throttle:search')->name('search');

            Route::get('/ads/configuration', [AdvertisementController::class, 'configuration'])->name('ads.configuration');
            Route::post('/ads/resolve', [AdvertisementController::class, 'resolve'])
                ->middleware('throttle:ads-resolve')->name('ads.resolve');
            Route::post('/ads/impressions/{impression}/complete', [AdvertisementController::class, 'complete'])
                ->middleware([
                    'auth:sanctum',
                    RequireTokenAbility::class.':playback:read',
                    'throttle:ads-complete',
                ])->name('ads.complete');
        });

        Route::middleware([
            'auth:sanctum',
            EnsureAccountActive::class,
            TrackNativeDeviceSession::class,
        ])->group(function (): void {
            Route::middleware(RequireTokenAbility::class.':account:read')->group(function (): void {
                Route::get('/clip-transfers', [\App\Http\Controllers\Api\ClipTransferController::class, 'index'])->name('clip-transfers.index');
                Route::get('/clip-transfers/{uuid}', [\App\Http\Controllers\Api\ClipTransferController::class, 'show'])->whereUuid('uuid')->name('clip-transfers.show');
                Route::get('/clip-transfers/{uuid}/playback', [\App\Http\Controllers\Api\ClipTransferController::class, 'playback'])->whereUuid('uuid')->middleware('throttle:20,1')->name('clip-transfers.playback');
                Route::get('/clip-collections', [\App\Http\Controllers\Api\ClipCollectionController::class, 'index'])->name('clip-collections.index');
                Route::get('/clip-collections/{collection}', [\App\Http\Controllers\Api\ClipCollectionController::class, 'show'])->name('clip-collections.show');
            });
            Route::post('/clip-transfers/{uuid}/cancel', [\App\Http\Controllers\Api\ClipTransferController::class, 'cancel'])
                ->whereUuid('uuid')->middleware([RequireTokenAbility::class.':account:write', 'throttle:api-write'])->name('clip-transfers.cancel');
            Route::post('/videos/{video:slug}/clip-transfers', [\App\Http\Controllers\Api\ClipTransferController::class, 'store'])
                ->middleware([RequireTokenAbility::class.':account:write', 'throttle:api-write'])->name('clip-transfers.store');
            Route::post('/clip-transfers/{uuid}/upload-grant', [\App\Http\Controllers\Api\ClipTransferController::class, 'uploadGrant'])
                ->whereUuid('uuid')->middleware([RequireTokenAbility::class.':account:write', 'throttle:api-write'])->name('clip-transfers.upload-grant');
            Route::post('/clip-transfers/{uuid}/uploaded', [\App\Http\Controllers\Api\ClipTransferController::class, 'uploaded'])
                ->whereUuid('uuid')->middleware([RequireTokenAbility::class.':account:write', 'throttle:api-write'])->name('clip-transfers.uploaded');
            Route::patch('/clip-transfers/{uuid}', [\App\Http\Controllers\Api\ClipTransferController::class, 'update'])
                ->whereUuid('uuid')->middleware([RequireTokenAbility::class.':account:write', 'throttle:api-write'])->name('clip-transfers.update');
            Route::delete('/clip-transfers/{uuid}', [\App\Http\Controllers\Api\ClipTransferController::class, 'destroy'])
                ->whereUuid('uuid')->middleware([RequireTokenAbility::class.':account:write', 'throttle:api-write'])->name('clip-transfers.destroy');
            Route::middleware(RequireTokenAbility::class.':account:write')->group(function (): void {
                Route::post('/clip-collections', [\App\Http\Controllers\Api\ClipCollectionController::class, 'store'])->middleware('throttle:api-write')->name('clip-collections.store');
                Route::patch('/clip-collections/{collection}', [\App\Http\Controllers\Api\ClipCollectionController::class, 'update'])->middleware('throttle:api-write')->name('clip-collections.update');
                Route::delete('/clip-collections/{collection}', [\App\Http\Controllers\Api\ClipCollectionController::class, 'destroy'])->middleware('throttle:api-write')->name('clip-collections.destroy');
                Route::put('/clip-collections/{collection}/clips/{uuid}', [\App\Http\Controllers\Api\ClipCollectionController::class, 'addClip'])->whereUuid('uuid')->middleware('throttle:api-write')->name('clip-collections.clips.add');
                Route::delete('/clip-collections/{collection}/clips/{uuid}', [\App\Http\Controllers\Api\ClipCollectionController::class, 'removeClip'])->whereUuid('uuid')->middleware('throttle:api-write')->name('clip-collections.clips.remove');
            });
            Route::middleware(RequireTokenAbility::class.':account:read')->group(function (): void {
                Route::get('/auth/sessions', [NativeDeviceSessionController::class, 'index'])->name('auth.sessions.index');
                Route::delete('/auth/sessions/current', [NativeDeviceSessionController::class, 'destroyCurrent'])->name('auth.sessions.current.destroy');
            });
            Route::middleware(RequireTokenAbility::class.':account:write')->group(function (): void {
                Route::delete('/auth/sessions/others', [NativeDeviceSessionController::class, 'destroyOthers'])->name('auth.sessions.others.destroy');
                Route::delete('/auth/sessions/all', [NativeDeviceSessionController::class, 'destroyAll'])->name('auth.sessions.all.destroy');
                Route::patch('/auth/sessions/{deviceSession}', [NativeDeviceSessionController::class, 'update'])->name('auth.sessions.update');
                Route::delete('/auth/sessions/{deviceSession}', [NativeDeviceSessionController::class, 'destroy'])->name('auth.sessions.destroy');
            });

            Route::get('/account', [AccountController::class, 'show'])
                ->middleware(RequireTokenAbility::class.':account:read')->name('account.show');
            Route::middleware(RequireTokenAbility::class.':account:read')->group(function (): void {
                Route::get('/account/safety', [SafetyController::class, 'show'])->name('account.safety');
                Route::get('/account/blocks', [SafetyController::class, 'blocks'])->name('account.blocks.index');
                Route::get('/admin/safety-reports', [SafetyController::class, 'reports'])->name('admin.safety-reports');
            });
            Route::middleware([RequireTokenAbility::class.':account:write', 'throttle:api-write'])->group(function (): void {
                Route::post('/account/safety/consent', [SafetyController::class, 'consent'])->name('account.safety.consent');
                Route::post('/account/blocks', [SafetyController::class, 'block'])->name('account.blocks.store');
                Route::delete('/account/blocks/{user}', [SafetyController::class, 'unblock'])->whereNumber('user')->name('account.blocks.destroy');
            });
            Route::middleware([RequireTokenAbility::class.':engagement:write', 'throttle:reports'])->group(function (): void {
                Route::post('/community-posts/{id}/reports', [SafetyController::class, 'reportPost'])->whereNumber('id')->name('community.reports');
                Route::post('/messages/{id}/reports', [SafetyController::class, 'reportMessage'])->whereNumber('id')->name('messages.reports');
                Route::post('/live-streams/{id}/reports', [SafetyController::class, 'reportLive'])->whereNumber('id')->name('live.reports');
                Route::post('/users/{id}/reports', [SafetyController::class, 'reportUser'])->whereNumber('id')->name('users.reports');
            });
            Route::middleware(RequireTokenAbility::class.':account:write')->group(function (): void {
                Route::patch('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
                Route::patch('/account/settings', [AccountController::class, 'updateSettings'])->name('account.settings.update');
                Route::post('/account/avatar', [AccountController::class, 'updateAvatar'])->name('account.avatar.update');
                Route::delete('/account/avatar', [AccountController::class, 'removeAvatar'])->name('account.avatar.destroy');
                Route::post('/account/cover', [AccountController::class, 'updateCover'])->name('account.cover.update');
                Route::delete('/account/cover', [AccountController::class, 'removeCover'])->name('account.cover.destroy');
                Route::post('/account/password', [AuthController::class, 'updatePassword'])
                    ->middleware('throttle:auth-password')->name('account.password.update');
                Route::post('/account/two-factor/setup', [AuthController::class, 'setupTwoFactor'])
                    ->middleware('throttle:auth-password')->name('account.two-factor.setup');
                Route::post('/account/two-factor/enable', [AuthController::class, 'enableTwoFactor'])
                    ->middleware('throttle:auth-password')->name('account.two-factor.enable');
                Route::post('/account/two-factor/disable', [AuthController::class, 'disableTwoFactor'])
                    ->middleware('throttle:auth-password')->name('account.two-factor.disable');
                Route::post('/account/upgrade-creator', [AuthController::class, 'upgradeCreator'])
                    ->middleware('throttle:10,1')->name('account.upgrade-creator');
                Route::delete('/account', [AccountController::class, 'destroy'])
                    ->middleware('throttle:auth-password')->name('account.destroy');

                Route::get('/push-devices', [PushDeviceController::class, 'index'])->name('push-devices.index');
                Route::post('/push-devices', [PushDeviceController::class, 'store'])
                    ->middleware('throttle:push-devices')->name('push-devices.store');
                Route::delete('/push-devices', [PushDeviceController::class, 'destroyAll'])->name('push-devices.destroy-all');
                Route::delete('/push-devices/{pushDevice}', [PushDeviceController::class, 'destroy'])
                    ->whereUuid('pushDevice')->name('push-devices.destroy');
            });

            Route::middleware(RequireTokenAbility::class.':library:write')->group(function (): void {
                Route::get('/library/subscription-feed', [DiscoveryController::class, 'subscriptions'])->name('library.subscription-feed');
                Route::get('/library/community-bookmarks', [CommunityController::class, 'bookmarks'])->name('library.community-bookmarks');
                Route::post('/community-posts/{communityPost}/bookmark', [CommunityController::class, 'bookmark'])
                    ->middleware('throttle:api-write')->name('community.bookmark');
                Route::get('/library', [LibraryController::class, 'overview'])->name('library.overview');
                Route::get('/library/favorite-channels', [FavoriteChannelController::class, 'index'])->name('library.favorite-channels');
                Route::get('/library/favorite-channels/videos', [FavoriteChannelController::class, 'videos'])->name('library.favorite-channels.videos');
                Route::put('/profiles/{channel:slug}/favorite', [FavoriteChannelController::class, 'store'])
                    ->middleware('throttle:api-write')->name('profiles.favorite.store');
                Route::delete('/profiles/{channel:slug}/favorite', [FavoriteChannelController::class, 'destroy'])
                    ->middleware('throttle:api-write')->name('profiles.favorite.destroy');
                Route::get('/library/watch-later', [LibraryController::class, 'watchLater'])->name('library.watch-later');
                Route::get('/library/liked-videos', [LibraryController::class, 'likedVideos'])->name('library.liked-videos');
                Route::get('/library/purchased-videos', [LibraryController::class, 'purchasedVideos'])->name('library.purchased-videos');
                Route::get('/library/subscriptions', [LibraryController::class, 'subscribedChannels'])->name('library.subscriptions');
                Route::get('/library/subscription-videos', [LibraryController::class, 'subscriptionVideos'])->name('library.subscription-videos');
                Route::get('/library/playlists', [LibraryController::class, 'playlists'])->name('library.playlists');
                Route::get('/library/playlist-videos', [LibraryController::class, 'playlistVideos'])->name('library.playlist-videos');
                Route::put('/library/watch-later/{video:slug}', [LibraryController::class, 'saveWatchLater'])
                    ->middleware('throttle:api-write')->name('library.watch-later.store');
                Route::delete('/library/watch-later/{video:slug}', [LibraryController::class, 'removeWatchLater'])
                    ->middleware('throttle:api-write')->name('library.watch-later.destroy');

                Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
                Route::get('/history/continue-watching', [HistoryController::class, 'continueWatching'])->name('history.continue');
                Route::delete('/history/continue-watching/{video:slug}', [HistoryController::class, 'dismissContinue'])
                    ->middleware('throttle:api-write')->name('history.continue.dismiss');
                Route::post('/videos/{video:slug}/progress/watched', [HistoryController::class, 'markWatched'])
                    ->middleware('throttle:api-write')->name('videos.progress.watched');
                Route::post('/videos/{video:slug}/progress/restart', [HistoryController::class, 'restart'])
                    ->middleware('throttle:api-write')->name('videos.progress.restart');
                Route::delete('/history', [HistoryController::class, 'clear'])->name('history.clear');
                Route::put('/videos/{video:slug}/progress', [HistoryController::class, 'update'])
                    ->middleware('throttle:progress')->name('videos.progress');
                Route::delete('/history/{video:slug}', [HistoryController::class, 'destroy'])->name('history.destroy');

                Route::post('/playlists', [PlaylistController::class, 'store'])
                    ->middleware('throttle:api-write')->name('playlists.store');
                Route::patch('/playlists/{playlist}', [PlaylistController::class, 'update'])
                    ->middleware('throttle:api-write')->name('playlists.update');
                Route::delete('/playlists/{playlist}', [PlaylistController::class, 'destroy'])
                    ->middleware('throttle:api-write')->name('playlists.destroy');
                Route::post('/playlists/{playlist}/members', [PlaylistController::class, 'invite'])
                    ->middleware('throttle:api-write')->name('playlists.members.invite');
                Route::post('/playlists/{playlist}/members/accept', [PlaylistController::class, 'accept'])
                    ->middleware('throttle:api-write')->name('playlists.members.accept');
                Route::delete('/playlists/{playlist}/members/{user}', [PlaylistController::class, 'revoke'])
                    ->middleware('throttle:api-write')->name('playlists.members.revoke');
                Route::put('/playlists/{playlist}/order', [PlaylistController::class, 'reorder'])
                    ->middleware('throttle:api-write')->name('playlists.order');
                Route::post('/playlists/{playlist}/videos/{video:slug}/vote', [PlaylistController::class, 'vote'])
                    ->withoutScopedBindings()->middleware('throttle:api-write')->name('playlists.videos.vote');
                Route::put('/playlists/{playlist}/videos/{video:slug}', [PlaylistController::class, 'addVideo'])
                    ->withoutScopedBindings()->middleware('throttle:api-write')->name('playlists.videos.store');
                Route::delete('/playlists/{playlist}/videos/{video}', [PlaylistController::class, 'removeVideo'])
                    ->withoutScopedBindings()->middleware('throttle:api-write')->name('playlists.videos.destroy');
            });

            Route::middleware(RequireTokenAbility::class.':engagement:write')->group(function (): void {
                require __DIR__.'/image-uploads.php';
                Route::post('/community-posts/link-preview', [CommunityController::class, 'preview'])
                    ->middleware('throttle:api-write')->name('community.link-preview');
                Route::post('/comments/{comment}/mute-user', [CommentModerationController::class, 'mute'])
                    ->middleware('throttle:api-write')->name('comments.mute-user');
                Route::post('/profiles/{channel:slug}/community-posts', [CommunityController::class, 'store'])
                    ->middleware([RequireTokenAbility::class.':creator:write', RejectTvCreator::class, 'throttle:api-write'])->name('community.store');
                Route::patch('/community-posts/{communityPost}', [CommunityController::class, 'update'])
                    ->middleware([RequireTokenAbility::class.':creator:write', RejectTvCreator::class, 'throttle:api-write'])->name('community.update');
                Route::delete('/community-posts/{communityPost}', [CommunityController::class, 'destroy'])
                    ->middleware([RequireTokenAbility::class.':creator:write', RejectTvCreator::class, 'throttle:api-write'])->name('community.destroy');
                Route::post('/community-posts/{communityPost}/vote', [CommunityController::class, 'vote'])
                    ->middleware('throttle:api-write')->name('community.vote');
                Route::post('/community-posts/{communityPost}/share', [CommunityController::class, 'share'])
                    ->middleware('throttle:api-write')->name('community.share');
                Route::put('/videos/{video:slug}/likes', [EngagementController::class, 'likeVideo'])
                    ->middleware('throttle:api-write')->name('videos.likes.store');
                Route::delete('/videos/{video:slug}/likes', [EngagementController::class, 'unlikeVideo'])
                    ->middleware('throttle:api-write')->name('videos.likes.destroy');
                Route::post('/videos/{video:slug}/reports', [EngagementController::class, 'reportVideo'])
                    ->middleware('throttle:reports')->name('videos.reports.store');
                Route::post('/profiles/{channel:slug}/reports', [EngagementController::class, 'reportProfile'])
                    ->middleware('throttle:reports')->name('profiles.reports.store');
                Route::put('/comments/{comment}/likes', [EngagementController::class, 'likeComment'])
                    ->middleware('throttle:api-write')->name('comments.likes.store');
                Route::delete('/comments/{comment}/likes', [EngagementController::class, 'unlikeComment'])
                    ->middleware('throttle:api-write')->name('comments.likes.destroy');
                Route::put('/profiles/{channel:slug}/subscription', [EngagementController::class, 'subscribe'])
                    ->middleware('throttle:api-write')->name('profiles.subscription.store');
                Route::delete('/profiles/{channel:slug}/subscription', [EngagementController::class, 'unsubscribe'])
                    ->middleware('throttle:api-write')->name('profiles.subscription.destroy');

                Route::post('/videos/{video:slug}/comments', [CommentController::class, 'store'])
                    ->middleware('throttle:comments')->name('videos.comments.store');
                Route::patch('/comments/{comment}', [CommentController::class, 'update'])
                    ->middleware('throttle:api-write')->name('comments.update');
                Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])
                    ->middleware('throttle:api-write')->name('comments.destroy');
                Route::post('/comments/{comment}/report', [CommentController::class, 'report'])
                    ->middleware('throttle:reports')->name('comments.report');
                Route::post('/comments/{comment}/pin', [CommentController::class, 'pin'])
                    ->middleware('throttle:api-write')->name('comments.pin');
                Route::put('/community-posts/{communityPost}/likes', [CommunityController::class, 'like'])
                    ->middleware('throttle:api-write')->name('community.likes.store');
                Route::delete('/community-posts/{communityPost}/likes', [CommunityController::class, 'unlike'])
                    ->middleware('throttle:api-write')->name('community.likes.destroy');
                Route::post('/community-posts/{communityPost}/comments', [CommunityController::class, 'storeComment'])
                    ->middleware('throttle:comments')->name('community.comments.store');
                Route::post('/messages', [MessageController::class, 'store'])
                    ->middleware('throttle:api-write')->name('messages.store');
                Route::put('/mutes/{user}', [MuteController::class, 'store'])
                    ->middleware('throttle:api-write')->name('mutes.store');
                Route::delete('/mutes/{user}', [MuteController::class, 'destroy'])
                    ->middleware('throttle:api-write')->name('mutes.destroy');
                Route::post('/messages/{conversation}/messages', [MessageController::class, 'send'])
                    ->middleware('throttle:api-write')->whereNumber('conversation')->name('messages.messages.store');
                Route::put('/messages/{conversation}/read', [MessageController::class, 'markRead'])
                    ->middleware('throttle:api-write')->whereNumber('conversation')->name('messages.read');
            });

            Route::middleware(RequireTokenAbility::class.':account:read')->group(function (): void {
                Route::get('/comments/moderation', [CommentModerationController::class, 'index'])->name('comments.moderation');
                Route::get('/me/billing-information', [CommerceController::class, 'billingInformation'])->name('me.billing-information.show');
                Route::get('/me/capabilities', [CommerceController::class, 'capabilities'])->name('me.capabilities');
                Route::get('/me/plan', [CommerceController::class, 'plan'])->name('me.plan');
                Route::get('/me/billing-history', [CommerceController::class, 'billingHistory'])->name('me.billing-history');
                Route::get('/me/wallet', [CommerceController::class, 'wallet'])->name('me.wallet');
                Route::get('/me/wallet/transactions', [CommerceController::class, 'transactions'])->name('me.wallet.transactions');
                Route::get('/messages/users', [MessageController::class, 'users'])->name('messages.users');
                Route::get('/mutes', [MuteController::class, 'index'])->name('mutes.index');
                Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
                Route::get('/messages/{conversation}/messages', [MessageController::class, 'messages'])
                    ->whereNumber('conversation')->name('messages.messages.index');
                Route::get('/messages/{conversation}', [MessageController::class, 'show'])
                    ->whereNumber('conversation')->name('messages.show');
                Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
                Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
                Route::get('/notifications/settings', [NotificationController::class, 'settings'])->name('notifications.settings');
                Route::patch('/notifications/settings', [NotificationController::class, 'updateSettings'])
                    ->middleware('throttle:api-write')->name('notifications.settings.update');
                Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
                Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
            });

            Route::post('/me/wallet/withdrawals', [CommerceController::class, 'withdraw'])
                ->middleware([RequireTokenAbility::class.':account:write', RejectTvCreator::class, 'throttle:api-write'])->name('me.wallet.withdrawals');
            Route::put('/me/billing-information', [CommerceController::class, 'updateBillingInformation'])
                ->middleware([RequireTokenAbility::class.':account:write', RejectTvCreator::class, 'throttle:api-write'])->name('me.billing-information.update');
            Route::get('/creator/earnings', [CommerceController::class, 'earnings'])
                ->middleware([RequireTokenAbility::class.':uploads:write', RejectTvCreator::class])->name('creator.earnings');

            Route::post('/live-streams/{liveStream:slug}/creator-join', [LiveStreamController::class, 'creatorJoin'])
                ->middleware([RequireTokenAbility::class.':live:publish', RejectTvPublishing::class, 'throttle:live-token'])
                ->name('live.creator-join');

            Route::middleware([RequireTokenAbility::class.':live:publish', RejectTvPublishing::class, 'throttle:api-write'])
                ->group(function (): void {
                    Route::get('/creator/live/options', [LegacyLiveStreamController::class, 'studio'])->name('creator.live.options');
                    Route::post('/live-streams', [LegacyLiveStreamController::class, 'store'])->name('live.store');
                    Route::put('/live-streams/{liveStream:slug}', [LegacyLiveStreamController::class, 'update'])->name('live.update');
                    Route::post('/live-streams/{liveStream:slug}/schedule', [LegacyLiveStreamController::class, 'schedule'])->name('live.schedule');
                    Route::get('/live-streams/{liveStream:slug}/thumbnail', [LegacyLiveStreamController::class, 'thumbnailStatus'])->name('live.thumbnail.show');
                    Route::post('/live-streams/{liveStream:slug}/thumbnail', [LegacyLiveStreamController::class, 'thumbnail'])->name('live.thumbnail.update');
                    Route::post('/live-streams/{liveStream:slug}/preflight', [LegacyLiveStreamController::class, 'preflight'])->name('live.preflight');
                    Route::post('/live-streams/{liveStream:slug}/connection-probe', [LegacyLiveStreamController::class, 'connectionProbe'])->name('live.connection-probe');
                    Route::post('/live-streams/{liveStream:slug}/rtmp-ingress', [LegacyLiveStreamController::class, 'rtmpIngress'])->name('live.rtmp-ingress');
                    Route::post('/live-streams/{liveStream:slug}/polls', [LegacyLiveStreamController::class, 'createPoll'])->name('live.polls.store');
                    Route::put('/live-streams/{liveStream:slug}/polls/{poll}', [LegacyLiveStreamController::class, 'updatePoll'])->name('live.polls.update');
                    Route::delete('/live-streams/{liveStream:slug}/polls/{poll}', [LegacyLiveStreamController::class, 'deletePoll'])->name('live.polls.destroy');
                    Route::post('/live-streams/{liveStream:slug}/messages/{message}/pin', [LegacyLiveStreamController::class, 'pinMessage'])->name('live.messages.pin');
                    Route::get('/live-streams/{liveStream:slug}/polls/{poll}/voters', [LegacyLiveStreamController::class, 'pollVoters'])->name('live.polls.voters');
                    Route::post('/live-streams/{liveStream:slug}/participants/invite', [LegacyLiveStreamController::class, 'inviteParticipant'])->name('live.participants.invite');
                    Route::post('/live-streams/{liveStream:slug}/moderators', [LegacyLiveStreamController::class, 'addModerator'])->name('live.moderators.store');
                    Route::delete('/live-streams/{liveStream:slug}/moderators/{user}', [LegacyLiveStreamController::class, 'removeModerator'])->name('live.moderators.destroy');
                    Route::put('/live-streams/{liveStream:slug}/engagement', [LegacyLiveStreamController::class, 'engagement'])->name('live.engagement');
                    Route::put('/live-streams/{liveStream:slug}/chat-settings', [LegacyLiveStreamController::class, 'chatSettings'])->name('live.chat-settings');
                    Route::post('/live-streams/{liveStream:slug}/start', [LegacyLiveStreamController::class, 'start'])->name('live.start');
                    Route::post('/live-streams/{liveStream:slug}/activate', [LegacyLiveStreamController::class, 'activate'])->name('live.activate');
                    Route::post('/live-streams/{liveStream:slug}/end', [LegacyLiveStreamController::class, 'end'])->name('live.end');
                });

            Route::middleware(RequireTokenAbility::class.':live:view')->group(function (): void {
                Route::get('/live-streams/{liveStream:slug}/control-room', [LegacyLiveStreamController::class, 'controlRoom'])->name('live.control-room');
                Route::delete('/live-streams/{liveStream:slug}/messages/{message}', [LegacyLiveStreamController::class, 'deleteMessage'])->middleware('throttle:api-write')->name('live.messages.destroy');
                Route::post('/live-streams/{liveStream:slug}/users/{user}/restrictions', [LegacyLiveStreamController::class, 'restrictViewer'])->middleware('throttle:api-write')->name('live.users.restrict');
                Route::delete('/live-streams/{liveStream:slug}/users/{user}/restrictions/{type}', [LegacyLiveStreamController::class, 'removeViewerRestriction'])->middleware('throttle:api-write')->name('live.users.unrestrict');
                Route::post('/live-streams/{liveStream:slug}/participant-requests/{participantRequest}', [LegacyLiveStreamController::class, 'respondParticipantRequest'])->middleware('throttle:api-write')->name('live.participant-requests.respond');
                Route::post('/live-streams/{liveStream:slug}/participants/action', [LegacyLiveStreamController::class, 'participantAction'])->middleware('throttle:api-write')->name('live.participants.action');
                Route::post('/live-streams/{liveStream:slug}/contributions', [LegacyLiveStreamController::class, 'contribute'])->middleware('throttle:api-write')->name('live.contributions');
                Route::post('/live-streams/{liveStream:slug}/ads', [LegacyLiveStreamController::class, 'serveAd'])->middleware('throttle:live-ad-impressions')->name('live.ads');
                Route::post('/live-streams/{liveStream:slug}/ads/{impression:uuid}/complete', [LegacyLiveStreamController::class, 'completeAd'])->middleware('throttle:api-write')->name('live.ads.complete');
                Route::post('/live-streams/{liveStream:slug}/messages', [LegacyLiveStreamController::class, 'sendMessage'])
                    ->middleware('throttle:api-write')->name('live.messages.store');
                Route::post('/live-streams/{liveStream:slug}/polls/{poll}/vote', [LegacyLiveStreamController::class, 'vote'])
                    ->middleware('throttle:api-write')->name('live.polls.vote');
                Route::post('/live-streams/{liveStream:slug}/participant-request', [LegacyLiveStreamController::class, 'requestParticipation'])
                    ->middleware('throttle:api-write')->name('live.participant-request.store');
                Route::delete('/live-streams/{liveStream:slug}/participant-request', [LegacyLiveStreamController::class, 'leaveParticipation'])
                    ->middleware('throttle:api-write')->name('live.participant-request.destroy');
            });

            Route::middleware([RequireTokenAbility::class.':uploads:write', RejectTvCreator::class])
                ->prefix('creator')->name('creator.')->group(function (): void {
                    Route::get('/options', [CreatorController::class, 'options'])->name('options');
                    Route::get('/dashboard', [CreatorController::class, 'dashboard'])->name('dashboard');
                    Route::get('/live', [CreatorController::class, 'live'])->name('live');
                    Route::get('/videos', [CreatorController::class, 'index'])->name('videos.index');
                    Route::get('/videos/{video}', [CreatorController::class, 'show'])->name('videos.show');
                    Route::get('/analytics', [CreatorAnalyticsController::class, 'show'])->name('analytics');
                    Route::get('/videos/{video}/analytics', [CreatorAnalyticsController::class, 'show'])->name('videos.analytics');
                    Route::get('/channel', [CreatorChannelController::class, 'show'])->name('channel.show');
                    Route::middleware('throttle:api-write')->group(function (): void {
                        Route::patch('/videos/{video}', [CreatorController::class, 'update'])->name('videos.update');
                        Route::delete('/videos/{video}', [CreatorController::class, 'destroy'])->name('videos.destroy');
                        Route::post('/videos/{video}/retry', [CreatorController::class, 'retry'])->name('videos.retry');
                        Route::post('/videos/{video}/thumbnail', [CreatorMediaController::class, 'thumbnail'])->name('videos.thumbnail');
                        Route::post('/videos/{video}/caption', [CreatorMediaController::class, 'caption'])->name('videos.caption');
                        Route::delete('/videos/{video}/caption', [CreatorMediaController::class, 'removeCaption'])->name('videos.caption.destroy');
                        Route::patch('/channel', [CreatorChannelController::class, 'update'])->name('channel.update');
                        Route::post('/channel/artwork', [CreatorChannelController::class, 'artwork'])->name('channel.artwork');
                        Route::post('/imports', [CreatorController::class, 'import'])->name('imports');
                    });
                });

            Route::middleware([RequireTokenAbility::class.':uploads:write', 'throttle:uploads'])
                ->prefix('uploads')->name('uploads.')->group(function (): void {
                    Route::get('/capabilities', [UploadController::class, 'capabilities'])->name('capabilities');
                    Route::post('/sessions', [UploadController::class, 'store'])->name('sessions.store');
                    Route::get('/sessions/{uploadSession:uuid}', [UploadController::class, 'show'])->name('sessions.show');
                    Route::post('/sessions/{uploadSession:uuid}/chunks', [UploadController::class, 'chunk'])->name('sessions.chunks.store');
                    Route::post('/sessions/{uploadSession:uuid}/complete', [UploadController::class, 'complete'])->name('sessions.complete');
                    Route::delete('/sessions/{uploadSession:uuid}', [UploadController::class, 'destroy'])->name('sessions.destroy');
                });
        });
    });
