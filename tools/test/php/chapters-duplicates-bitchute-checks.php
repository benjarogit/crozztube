<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error."\n"); exit(1); });
require_once __DIR__.'/../../../docker/plugin-src/crozztube.bitchute/src/BitChuteProvider.php';

use App\Http\Controllers\Api\VideoController;
use App\Models\User;
use App\Models\Video;
use App\Plugins\PluginHooks;
use App\Plugins\PluginManifest;
use App\Support\ImportMetadataBackfill;
use App\Support\VideoChapters;
use App\Support\VideoImportIdentity;
use App\Support\VideoImportSettings;
use App\Support\YtDlp;
use CrzBitChute\BitChuteProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

config(['cache.default' => 'array']);
Bus::fake(); Queue::fake(); Http::preventStrayRequests();
$pass = $fail = 0;
function check(bool $ok, string $name): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}
function sameSource(string $a, string $b, string $name, string $provider = 'auto'): void {
    check(VideoImportIdentity::keys($provider === 'auto' ? VideoImportSettings::detectProvider($a) ?? 'direct' : $provider, $a)['import_source_key']
        === VideoImportIdentity::keys($provider === 'auto' ? VideoImportSettings::detectProvider($b) ?? 'direct' : $provider, $b)['import_source_key'], $name);
}
function conflict(callable $action, string $name): void {
    try { $action(); check(false, $name); }
    catch (HttpResponseException $error) { check($error->getResponse()->getStatusCode() === 409 && $error->getResponse()->getData(true)['code'] === 'duplicate_video', $name); }
}

check(PluginManifest::fromFile(__DIR__.'/../../../docker/plugin-src/crozztube.bitchute/plugin.json')->id() === 'crozztube.bitchute', 'BitChute package manifest validates');
app(PluginHooks::class)->addFilter('playhub.video_import.providers', static function (array $providers): array {
    $providers['bitchute'] = BitChuteProvider::definition(); return $providers;
}, 10, 'bitchute-checks');
$id = 'chaptertest1';
foreach (['bitchute.com', 'www.bitchute.com', 'old.bitchute.com'] as $host) {
    check(VideoImportSettings::detectProvider("https://$host/video/$id/") === 'bitchute', 'automatic BitChute provider detection '.$host);
    check(BitChuteProvider::idFromUrl("https://$host/embed/$id/?t=5") === $id, 'BitChute embed identity '.$host);
}
foreach (['https://bitchute.com.evil.test/video/chaptertest1', 'https://user@bitchute.com/video/chaptertest1',
    'https://bitchute.com:8443/video/chaptertest1', 'https://bitchute.com/channel/channel1/',
    'https://bitchute.com/playlist/list123', 'https://bitchute.com/search?q=test',
    'https://bitchute.com/video/chaptertest1/more', 'https://bitchute.com/video/%0Atest123',
    'file:///video/chaptertest1', 'https://bitchute.com/video/../'] as $url) {
    check(BitChuteProvider::idFromUrl($url) === null, 'reject unsafe or non-video BitChute URL');
    try { BitChuteProvider::metadata($url); check(false, 'reject invalid BitChute import before network'); }
    catch (RuntimeException) { check(true, 'reject invalid BitChute import before network'); }
}
check(BitChuteProvider::embedUrl($id) === "https://www.bitchute.com/embed/$id/", 'official BitChute fallback URL');
check(BitChuteProvider::embedUrl('../bad') === null, 'embed rejects invalid identity');

$native = [['start_time' => 20, 'title' => 'Main'], ['start_time' => 0, 'title' => 'Intro'],
    ['start_time' => 5.9, 'title' => 'Short section'], ['start_time' => 20, 'title' => 'Duplicate'],
    ['start_time' => -1, 'title' => 'Bad'], ['start_time' => 9999, 'title' => 'Past end'], ['start_time' => 30, 'title' => '']];
$chapters = VideoChapters::discover($native, "0:00 Other intro\n0:30 Other part", 120);
check($chapters['chapters_source'] === 'provider' && array_column($chapters['chapters'], 'start_time') === [0, 5, 20], 'native chapters sort, deduplicate and keep short sections');
check($chapters['chapters'][2]['title'] === 'Main', 'first native chapter title wins over duplicate and description');
check(count(VideoChapters::attributes(['chapters_enabled' => true, ...$chapters], 120)['chapters']) === 3, 'short native sections persist without rejecting the video');
check(count(VideoChapters::discover([['start_time' => 5, 'title' => 'First'], ['start_time' => 8, 'title' => 'Second']], null, 10)['chapters']) === 2, 'provider boundaries do not invent an introduction');
$description = "## Video chapters\n[0:00] Einführung\n00:20 - Überblick\n• 01:00 Ergebnisse\n";
$fromDescription = VideoChapters::discover([], $description, 120);
check($fromDescription['chapters_source'] === 'timestamps' && array_column($fromDescription['chapters'], 'start_time') === [0, 20, 60], 'description heading, brackets and bullet timestamp list imports');
check($fromDescription['chapters'][0]['title'] === 'Einführung' && $fromDescription['chapters'][1]['title'] === 'Überblick', 'UTF-8 chapter titles stay intact');
check(count(VideoChapters::discover([], "Intro 00:00\nMain [00:20]", 120)['chapters']) === 2, 'two trailing timestamps are sufficient');
check(count(VideoChapters::discover([], "[0:00](https://youtu.be/example?t=0) Intro\n[0:20](https://youtu.be/example?t=20) Main", 120)['chapters']) === 2, 'linked YouTube timestamps import as chapters');
foreach (["The time is 12:30 today", "https://example.test/0:00\nhttps://example.test/0:20", "0:00 Intro\n00:99 Invalid", "0:15 First\n0:30 Second", "0:00 Intro", "0:00 Intro\n0:05 Too short"] as $bad) {
    check(VideoChapters::discover([], $bad, 120)['chapters'] === [], 'prose, URLs and incomplete/ambiguous lists are ignored');
}
check(count(VideoChapters::discover([], "0:00 Intro\n0:20 Main\n0:15 Backwards\n0:40 Later", 120)['chapters']) === 2, 'description parsing stops at contradictory timestamps');
$normalized = YtDlp::normalize(['duration' => 120, 'description' => $description]);
check(count($normalized['chapters']) === 3 && $normalized['chapters_source'] === 'timestamps', 'shared yt-dlp normalization discovers description chapters');

sameSource('https://youtu.be/CaseTest1234?t=2', 'https://www.youtube.com/watch?v=CaseTest1234&list=abc', 'YouTube short/watch/start/playlist links share identity');
sameSource('https://youtube.com/shorts/CaseTest1234', 'https://youtube-nocookie.com/embed/CaseTest1234', 'YouTube shorts and embeds share identity');
sameSource('https://youtu.be/CaseTest1234', 'https://youtube.com/watch?v=CaseTest1234', 'manual direct cannot bypass YouTube identity', 'direct');
check(VideoImportIdentity::keys('youtube', 'https://youtube.com/watch?v=CaseTest1234')['import_source_key'] !== VideoImportIdentity::keys('youtube', 'https://youtube.com/watch?v=casetest1234')['import_source_key'], 'case-sensitive video IDs are not merged');
sameSource("https://old.bitchute.com/video/$id/?t=5", "https://www.bitchute.com/embed/$id/", 'BitChute aliases and embeds share identity');
sameSource('https://vimeo.com/123456?h=secret', 'https://player.vimeo.com/video/123456', 'Vimeo privacy parameters do not create another video');
sameSource('https://cdn.example.test/movie.mp4?b=2&a=1&utm_source=test#t=4', 'https://cdn.example.test/movie.mp4?a=1&b=2', 'direct query order, fragments and tracking normalize');
check(VideoImportIdentity::keys('direct', 'https://cdn.example.test/movie.mp4?id=1')['import_source_key'] !== VideoImportIdentity::keys('direct', 'https://cdn.example.test/movie.mp4?id=2')['import_source_key'], 'functional direct query parameters identify different assets');
$claim = str_repeat('f', 40);
check(VideoImportIdentity::keys('odysee', 'https://odysee.com/@garden:b/video:c', 'external:odysee:video:'.$claim)['import_source_key']
    === VideoImportIdentity::keys('direct', 'https://odysee.com/@garden:b/video:c', 'external:direct:'.$claim)['import_source_key'], 'Odysee name/full-claim and direct identities match');
check(VideoImportIdentity::keys('rumble', 'https://rumble.com/v7hpvpi-any-slug.html', 'external:rumble:v7fjis0')['import_source_key']
    === VideoImportIdentity::keys('rumble', 'https://rumble.com/embed/v7fjis0/')['import_source_key'], 'Rumble watch and actual player identities match');
sameSource('https://rumble.com/embed/V7FJIS0/', 'https://rumble.com/embed/v7fjis0/', 'Rumble base-36 identity is case insensitive');

$url = "https://www.bitchute.com/video/$id/";
Http::fake(['https://api.bitchute.com/api/beta/video' => Http::response(['video_id' => $id, 'video_name' => 'Chapter integration',
    'description' => "<p>Full description</p>0:00 Intro<br>0:20 Main", 'hashtags' => ['Testing', 'Chapters'], 'duration' => '2:00',
    'channel' => ['channel_name' => 'Test channel'], 'thumbnail_url' => null])]);
Cache::put('ytdlp:meta:v4:'.sha1($url), YtDlp::normalize(['id' => $id, 'extractor_key' => 'BitChute', 'duration' => 120,
    'width' => 1920, 'height' => 1080, 'chapters' => $native]), 600);
$metadata = BitChuteProvider::metadata($url);
check($metadata['provider_id'] === $id && $metadata['title'] === 'Chapter integration' && $metadata['duration_seconds'] === 120, 'BitChute metadata and duration normalize');
check(str_contains($metadata['description'], "\n") && $metadata['tags'] === ['Testing', 'Chapters'], 'BitChute description paragraphs and tags are retained');
check($metadata['author_name'] === 'Test channel' && count($metadata['chapters']) === 3, 'BitChute creator and yt-dlp chapters are retained');

DB::beginTransaction();
try {
    $base = Video::query()->withoutEagerLoads()->firstOrFail();
    $owner = User::query()->with('channel')->findOrFail($base->user_id);
    $requestFor = static function (array $data) use ($app, $owner): Request {
        $request = Request::create('https://crozztube.local:8443/api/video-imports', 'POST', $data);
        $app->instance('request', $request); $request->setUserResolver(fn () => $owner); return $request;
    };
    $body = ['provider' => 'auto', 'url' => $url, 'category_id' => DB::table('video_categories')->value('id'), 'visibility' => 'private'];
    $response = app(VideoController::class)->import($requestFor($body));
    $saved = Video::query()->findOrFail($response->getData(true)['video']['id']);
    check($response->getStatusCode() === 201 && $saved->import_provider === 'bitchute', 'full BitChute import chooses provider automatically');
    check($saved->chapters_source === 'provider' && array_column($saved->chapters, 'start_time') === [0, 5, 20], 'full endpoint persists short native chapters');
    check($saved->import_source_key && $saved->import_url_key, 'database stores both unique import keys');
    conflict(fn () => app(VideoController::class)->import($requestFor([...$body, 'url' => "https://old.bitchute.com/embed/$id/?t=20"])), 'duplicate alternate embed import is HTTP 409');
    conflict(fn () => app(VideoController::class)->import($requestFor([...$body, 'provider' => 'direct'])), 'manual direct duplicate is HTTP 409');
    $keys = VideoImportIdentity::keys('bitchute', $url);
    $stranger = new User; $stranger->id = $owner->id + 100000;
    $private = VideoImportIdentity::duplicatePayload($keys, $stranger);
    check($private['existing_video'] === null, 'global duplicate conflict hides private video link and title');
    check(VideoImportIdentity::duplicatePayload($keys, $owner)['existing_video']['slug'] === $saved->slug, 'owner receives existing video link');
    $before = Video::count();
    $copy = $saved->replicate(); $copy->slug = 'duplicate-race-'.bin2hex(random_bytes(4));
    try { $copy->save(); check(false, 'database unique constraint blocks a racing insertion'); }
    catch (UniqueConstraintViolationException) { check(Video::count() === $before, 'database unique constraint blocks a racing insertion'); }
    $preview = app(VideoController::class)->importMetadata($requestFor(['url' => "https://bitchute.com/embed/$id", 'provider' => 'auto']))->getData(true)['metadata'];
    check($preview['duplicate']['code'] === 'duplicate_video' && count($preview['chapters']) === 3, 'metadata preview supplies duplicate warning and chapter count');
    $sourceRow = \CrzSourceResolver\Models\CrozztubeImportSource::query()->updateOrCreate(['video_id' => $saved->id], [
        'state' => 'pending', 'media_url' => null, 'kind' => 'pending', 'audio_tracks' => [],
    ]);
    foreach (['youtube' => 'https://youtube.com/watch?v=CaseTest1234', 'rumble' => 'https://rumble.com/embed/v7fjis0/',
        'odysee' => 'https://odysee.com/video:'.$claim, 'bitchute' => $url] as $provider => $providerUrl) {
        $example = clone $saved;
        $example->import_provider = $provider;
        $example->import_url = $providerUrl;
        $example->video_path = null;
        foreach (['pending', 'refreshing', 'error'] as $state) {
            $sourceRow->forceFill(['state' => $state, 'media_url' => null, 'kind' => 'pending'])->save();
            $payload = (new \App\Http\Resources\VideoResource($example))->toArray($requestFor([]));
            check($payload['video_sources'] === [] && $payload['embed_url'] === null && $payload['playback_pending'], "$provider $state never silently opens the provider embed");
        }
        $sourceRow->forceFill(['state' => 'fresh', 'kind' => 'mp4', 'media_url' => 'https://cdn.example.test/native.mp4', 'expires_at' => now()->addHour()])->save();
        $payload = (new \App\Http\Resources\VideoResource($example))->toArray($requestFor([]));
        check($payload['video_sources'][0]['src'] === 'https://cdn.example.test/native.mp4' && $payload['embed_url'] === null && ! $payload['playback_pending'], "$provider uses native playback when resolution finishes");
        $mirror = \CrzSourceResolver\Support\Mirror::forVideo($example, 'crozztube.local');
        check(($mirror['mode'] ?? null) === 'iframe' && str_contains($mirror['embed_url'], '/embed/'), "$provider original embed remains available through Mirror");
    }
    $sourceRow->forceFill(['audio_tracks' => null])->save();
    $state = app(\CrzSourceResolver\ResolverController::class)->status($requestFor([]), $saved->id)->getData(true)['source'];
    check(! $state['fresh'] && $state['can_refresh'], 'legacy source status waits for language-aware resolution instead of reloading an unusable source');
    $saved->forceFill(['chapters' => null, 'chapters_source' => null, 'metadata_overrides' => [], 'thumbnail_is_custom' => true])->save();
    ImportMetadataBackfill::apply($saved, ['description' => $description]);
    check(count($saved->fresh()->chapters) === 3 && $saved->fresh()->chapters_source === 'timestamps', 'existing imports receive description chapters on refresh');
    $saved->forceFill(['chapters' => null, 'chapters_enabled' => false, 'metadata_overrides' => []])->save();
    ImportMetadataBackfill::apply($saved, ['description' => $description]);
    check($saved->fresh()->chapters_enabled && count($saved->fresh()->chapters) === 3, 'legacy implicit disabled default is repaired when chapters are discovered');
    $manual = $saved->fresh()->chapters;
    $saved->forceFill(['metadata_overrides' => ['chapters']])->save();
    ImportMetadataBackfill::apply($saved, ['chapters' => [['start_time' => 0, 'title' => 'New'], ['start_time' => 10, 'title' => 'Other']]]);
    check($saved->fresh()->chapters === $manual, 'provider refresh preserves existing/manual chapters');
    $saved->forceFill(['chapters' => null, 'chapters_enabled' => false, 'metadata_overrides' => ['chapters_enabled']])->save();
    ImportMetadataBackfill::apply($saved, ['description' => $description]);
    check($saved->fresh()->chapters === null && ! $saved->fresh()->chapters_enabled, 'disabled chapters remain disabled');
    $disabledId = 'disabledtest1';
    Cache::put('bitchute:metadata:v1:'.sha1($disabledId.':1'), [...$metadata, 'provider_id' => $disabledId], 600);
    $disabledResponse = app(VideoController::class)->import($requestFor([...$body, 'url' => 'https://bitchute.com/video/'.$disabledId, 'chapters_enabled' => false]));
    $disabled = Video::query()->findOrFail($disabledResponse->getData(true)['video']['id']);
    check(! $disabled->chapters_enabled && in_array('chapters_enabled', $disabled->metadata_overrides, true), 'explicit disable during import records a protected manual choice');
    $seen = new ReflectionMethod(App\Http\Controllers\Api\AdminController::class, 'massImportCandidateSeen');
    check($seen->invoke(app(App\Http\Controllers\Api\AdminController::class), "https://bitchute.com/embed/$id/?t=1", []), 'mass-import discovery skips existing video identities');
    $saved->delete();
    check(VideoImportIdentity::duplicate($keys) === null, 'deleting the video releases its import identity');
} finally { DB::rollBack(); }

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
