<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\PluginAssetController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SpaController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', SpaController::class)->name('home');
Route::get('/social-preview', [SpaController::class, 'socialImage'])->name('social-preview');
Route::withoutMiddleware([
    PreventRequestForgery::class,
    StartSession::class,
    ShareErrorsFromSession::class,
])->group(function (): void {
    Route::get('/embed/{video:slug}', [SpaController::class, 'embed'])->name('video.embed');
    Route::get('/social-preview/videos/{video}', [SpaController::class, 'videoSocialImage'])
        ->whereNumber('video')
        ->name('social-preview.video');
    Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
    Route::get('/sitemap.xsl', [SitemapController::class, 'stylesheet'])->name('sitemap.stylesheet');
    Route::get('/sitemaps/pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');
    Route::get('/sitemaps/{section}-{page}.xml', [SitemapController::class, 'section'])
        ->where(['section' => 'videos|shorts|channels|playlists|live', 'page' => '[1-9][0-9]*'])
        ->name('sitemap.section');
});
Route::view('/livekit-recording-template', 'livekit-recording-template', [], 200, [
    'Cache-Control' => 'no-store',
    'Referrer-Policy' => 'no-referrer',
])->name('livekit.recording-template');

Route::get('/media/videos/{video}/stream/{quality?}', [MediaController::class, 'video'])->name('media.video');
Route::get('/media/images', [MediaController::class, 'image'])->name('media.image');
Route::get('/media/videos/{video}/thumbnail', [MediaController::class, 'thumbnail'])->name('media.thumbnail');
Route::get('/media/videos/{video}/captions', [MediaController::class, 'captions'])->name('media.video-captions');
Route::get('/media/live-streams/{liveStream}/thumbnail', [MediaController::class, 'liveStreamThumbnail'])->name('media.live-stream-thumbnail');
Route::get('/media/community-posts/{communityPost}/photos/{index}', [MediaController::class, 'communityPhoto'])->whereNumber('index')->name('media.community-photo');
Route::get('/media/comments/{comment}/attachments/{index}', [MediaController::class, 'commentAttachment'])->whereNumber('index')->name('media.comment-attachment');
Route::get('/media/private-messages/{message}/attachments/{index}', [MediaController::class, 'privateMessageAttachment'])
    ->whereNumber(['message', 'index'])->name('media.private-message-attachment');
Route::get('/storage/{path}', [MediaController::class, 'legacyStorage'])->where('path', '.*')->name('media.legacy-storage');
Route::get('/plugin-assets/{plugin}', PluginAssetController::class)
    ->where('plugin', '[a-z][a-z0-9._-]*')
    ->name('plugins.asset');
Route::get('/plugin-assets/{plugin}/{path}', PluginAssetController::class)
    ->where(['plugin' => '[a-z][a-z0-9._-]*', 'path' => '.*'])
    ->name('plugins.asset.legacy');

Route::get('/{path}', SpaController::class)
    ->where('path', '^(?!api|build|storage|media|install\.php).*$')
    ->name('spa');
