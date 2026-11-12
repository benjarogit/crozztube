<?php

// Fake upstream responses and queues; database changes are always rolled back.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/../../../docker/plugin-src/crozztube.rumble/src/RumbleProvider.php';
require_once __DIR__.'/../../../docker/plugin-src/crozztube.source-resolver/src/Support/Mirror.php';

use App\Http\Controllers\Api\VideoController;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Plugins\PluginHooks;
use App\Plugins\PluginManifest;
use App\Services\Api\V1\PlaybackSessionService;
use App\Support\Client\ClientContext;
use App\Support\MediaSourceMetadata;
use App\Support\VideoImportSettings;
use App\Support\YtDlp;
use CrzRumble\RumbleProvider;
use CrzSourceResolver\Models\CrozztubeImportSource;
use CrzSourceResolver\Support\Mirror;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

config(['cache.default' => 'array']);
Bus::fake();
Queue::fake();
Http::preventStrayRequests();
$pass = 0;
$fail = 0;
function check(bool $condition, string $name): void
{
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
function rejects(callable $callback, string $name): void
{
    try { $callback(); check(false, $name); }
    catch (RuntimeException $error) { check(true, $name); }
}

$hooks = app(PluginHooks::class);
$hooks->removePlugin('crozztube.rumble');
check(! isset(VideoImportSettings::providers()['rumble']), 'disabled plugin removes provider definition');
$hooks->addFilter('playhub.video_import.providers', static function (array $providers): array {
    $providers['rumble'] = RumbleProvider::definition();

    return $providers;
}, 10, 'rumble-checks');

$url = 'https://rumble.com/vdmum1-moose-the-dog-helps-girls-dig-a-snow-fort.html';
$oembed = [
    'type' => 'video', 'title' => 'Winter-loving dog', 'author_name' => 'LovingMontana',
    'html' => '<iframe src="https://rumble.com/embed/ufe9n.vb0ofn/?pub=test"></iframe>',
    'thumbnail_url' => 'https://cdn.rumble.cloud/dog.jpg', 'duration' => 103,
    'width' => 1920, 'height' => 1080,
];
$stubYtdlp = static function (string $source, array $data): void {
    Cache::put('ytdlp:meta:v4:'.sha1($source), $data, 600);
};
$fakeOembed = static function (array $data, int $status = 200): void {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://rumble.com/api/Media/oembed.json*' => Http::response($data, $status)]);
};

DB::beginTransaction();
try {
    $manifest = PluginManifest::fromFile(__DIR__.'/../../../docker/plugin-src/crozztube.rumble/plugin.json');
    check($manifest->id() === 'crozztube.rumble', 'plugin manifest validates');
    $public = collect(VideoImportSettings::publicSettings()['providers'])->firstWhere('key', 'rumble');
    check($public !== null && $public['label'] === 'Rumble', 'public settings expose Rumble');
    check(! isset($public['metadata'], $public['embed_url'], $public['id_from_url']), 'public settings never expose callbacks');
    $admin = VideoImportSettings::adminSettings();
    check(isset($admin['providers']['rumble']['enabled']), 'admin settings include Rumble switch');

    foreach (['rumble.com', 'www.rumble.com'] as $host) {
        check(MediaSourceMetadata::providerForUrl("https://$host/vdmum1-test.html") === 'rumble', "provider detection $host");
    }
    foreach (['https://rumble.com.evil.test/embed/vb0ofn/', 'https://evilrumble.com/embed/vb0ofn/',
        'https://rumble.com@evil.test/embed/vb0ofn/', 'https://user@rumble.com/embed/vb0ofn/',
        'https://rumble.com:8443/embed/vb0ofn/', 'file:///embed/vb0ofn/'] as $badUrl) {
        check(RumbleProvider::idFromUrl($badUrl) === null, 'reject unsafe player URL '.$badUrl);
        rejects(fn () => RumbleProvider::metadata($badUrl, false), 'reject unsafe import before network');
    }
    foreach (['/c/creator', '/user/creator', '/search/video?q=dogs', '/', '/videos', '/embedJS/u3/'] as $path) {
        rejects(fn () => RumbleProvider::metadata('https://rumble.com'.$path, false), 'reject non-video '.$path);
    }
    check(Http::recorded()->isEmpty(), 'invalid URLs are rejected before upstream requests');
    check(RumbleProvider::idFromUrl($url) === null, 'watch slug is never treated as player ID');
    check(MediaSourceMetadata::providerId('rumble', 'https://rumble.com/embed/ufe9n.vb0ofn/') === 'vb0ofn', 'publisher embed maps actual player ID');
    check(RumbleProvider::embedUrl('../evil') === null, 'embed builder rejects invalid ID');
    check(MediaSourceMetadata::providerId('youtube', 'https://youtu.be/dQw4w9WgXcQ') === 'dQw4w9WgXcQ', 'existing YouTube ID parsing remains intact');
    check(MediaSourceMetadata::embedUrl('vimeo', '12345', 'crozztube.local') === 'https://player.vimeo.com/video/12345', 'existing Vimeo adapter remains intact');
    check(str_contains(MediaSourceMetadata::embedUrl('twitch', 'clip:Example', 'crozztube.local'), 'parent=crozztube.local'), 'Twitch retains parent hostname');

    $normalized = YtDlp::normalize(['id' => 'vb0ofn', 'extractor_key' => 'RumbleEmbed', 'title' => 'Yt-dlp title',
        'description' => 'Dog in the snow', 'tags' => ['Dog', 'Snow', 'dog'], 'duration' => 103,
        'chapters' => [['start_time' => 0, 'title' => 'Snow fort'], ['start_time' => 20, 'title' => 'Playing']], 'width' => 1920, 'height' => 1080]);
    check($normalized['provider_id'] === 'vb0ofn', 'yt-dlp retains player identity');
    check($normalized['tags'] === ['Dog', 'Snow'], 'yt-dlp preserves distinct tags and removes duplicates');
    $stubYtdlp($url, $normalized);
    $fakeOembed($oembed);
    $metadata = RumbleProvider::metadata($url);
    check($metadata['provider_id'] === 'vb0ofn' && $metadata['title'] === 'Winter-loving dog', 'oEmbed player ID and title win');
    check($metadata['description'] === 'Dog in the snow' && $metadata['tags'] === ['Dog', 'Snow'], 'yt-dlp enriches description and tags');
    check(count($metadata['chapters']) === 2 && $metadata['duration_seconds'] === 103, 'chapters and duration survive import');
    $method = new ReflectionMethod(VideoController::class, 'providerImportMetadata');
    $import = $method->invoke(app(VideoController::class), 'rumble', $url);
    check($import['video_path'] === 'external:rumble:vb0ofn', 'import stores actual player reference');
    check($import['embed_url'] === 'https://rumble.com/embed/vb0ofn/', 'import preview builds correct official embed');
    check($import['is_short'] === false && $import['thumbnail_url'] === $oembed['thumbnail_url'], 'landscape metadata and thumbnail are preserved');

    Cache::forget('rumble:metadata:v2:'.sha1($url.':1'));
    $stubYtdlp($url, []);
    $fallback = RumbleProvider::metadata($url);
    check($fallback['provider_id'] === 'vb0ofn', 'oEmbed imports successfully when yt-dlp fails');
    $fallback = RumbleProvider::metadata('http://www.rumble.com/vdmum1-moose-the-dog-helps-girls-dig-a-snow-fort.html', false);
    check($fallback['provider_id'] === 'vb0ofn', 'HTTP and www links normalize to HTTPS');

    Cache::forget('rumble:metadata:v2:'.sha1($url.':1'));
    $fakeOembed([], 503);
    $stubYtdlp($url, $normalized);
    check(RumbleProvider::metadata($url)['provider_id'] === 'vb0ofn', 'yt-dlp imports successfully when oEmbed fails');
    Cache::forget('rumble:metadata:v2:'.sha1($url.':1'));
    $stubYtdlp($url, []);
    rejects(fn () => RumbleProvider::metadata($url), 'unavailable watch page returns an error instead of a guessed ID');
    $embed = 'https://rumble.com/embed/vb0ofn/';
    $stubYtdlp($embed, []);
    check(RumbleProvider::metadata($embed, false)['provider_id'] === 'vb0ofn', 'explicit embed works without metadata services');
    $fakeOembed(array_replace($oembed, ['html' => '<iframe src="https://evil.test/embed/vb0ofn/"></iframe>']));
    rejects(fn () => RumbleProvider::metadata($url), 'untrusted upstream iframe is rejected');

    $video = Video::query()->withoutEagerLoads()->firstOrFail()->replicate();
    $video->forceFill(['slug' => 'rumble-check-'.bin2hex(random_bytes(4)), 'source_type' => 'import',
        'import_provider' => 'rumble', 'import_url' => $url, 'video_path' => 'external:rumble:vb0ofn',
        'visibility' => 'public', 'status' => 'ready', 'approval_status' => 'approved', 'renditions' => [],
        'sale_enabled' => false]);
    $video->save();
    check(MediaSourceMetadata::originProvider($video) === 'rumble', 'stored video retains provider');
    check(MediaSourceMetadata::providerIdForVideo($video) === 'vb0ofn', 'stored video retains actual player identity');
    $mirror = Mirror::forVideo($video, 'crozztube.local');
    check($mirror['provider'] === 'rumble' && $mirror['mode'] === 'iframe'
        && $mirror['embed_url'] === 'https://rumble.com/embed/vb0ofn/', 'mirror uses official Rumble iframe');
    $request = Request::create('https://crozztube.local:8443/watch/'.$video->slug);
    $app->instance('request', $request);
    $resource = (new VideoResource($video))->resolve($request);
    check($resource['embed_url'] === null && $resource['video_sources'] === [] && $resource['playback_pending']
        && Mirror::forVideo($video, 'crozztube.local')['embed_url'] === 'https://rumble.com/embed/vb0ofn/', 'pending native playback retains official embed through explicit Mirror');

    $source = CrozztubeImportSource::query()->create(['video_id' => $video->id,
        'kind' => 'mp4', 'media_url' => 'https://cdn.rumble.cloud/video/dog.mp4',
        'state' => 'fresh', 'audio_tracks' => [], 'expires_at' => now()->addHour(), 'resolved_at' => now()]);
    $resource = (new VideoResource($video))->resolve($request);
    check($resource['embed_url'] === null && $resource['video_sources'][0]['src'] === $source->media_url, 'fresh native stream takes precedence over iframe');
    $source->update(['state' => 'error']);
    $resource = (new VideoResource($video))->resolve($request);
    check($resource['embed_url'] === null && $resource['video_sources'] === []
        && Mirror::forVideo($video, 'crozztube.local')['embed_url'] === 'https://rumble.com/embed/vb0ofn/', 'failed native source keeps official embed available through Mirror');
    $source->update(['state' => 'fresh', 'expires_at' => now()->subMinute()]);
    $resource = (new VideoResource($video))->resolve($request);
    check($resource['embed_url'] === null && $resource['video_sources'] === []
        && Mirror::forVideo($video, 'crozztube.local')['embed_url'] === 'https://rumble.com/embed/vb0ofn/', 'expired native source keeps official embed available through Mirror');
    $locked = clone $video;
    $locked->forceFill(['sale_enabled' => true, 'price_cents' => 100, 'is_short' => false]);
    $resource = (new VideoResource($locked))->resolve($request);
    check($resource['embed_url'] === null && $resource['video_sources'] === [] && $resource['import_url'] === null, 'playback access gate protects provider URLs');

    $sessionSources = new ReflectionMethod(PlaybackSessionService::class, 'externalSources');
    $sources = $sessionSources->invoke(app(PlaybackSessionService::class), $video, ClientContext::fromRequest($request), false);
    $media = MediaSourceMetadata::forVideo($video, $sources);
    check($sources[0]['provider'] === 'rumble' && $media['provider'] === 'rumble' && $media['source_type'] === 'embed', 'versioned playback API recognizes Rumble provider');

    $hooks->removePlugin('rumble-checks');
    check(! isset(VideoImportSettings::providers()['rumble']) && ! VideoImportSettings::providerEnabled('rumble'), 'disabling plugin removes import capability');
    check($video->fresh()->import_provider === 'rumble', 'disabling plugin retains imported records');
} finally {
    DB::rollBack();
    $hooks->removePlugin('rumble-checks');
}

echo "$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
