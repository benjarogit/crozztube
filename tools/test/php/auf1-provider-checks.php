<?php

// Maintained plugin sources, fake upstream and queue, transactional database checks.
require __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/../../../docker/plugin-src/crozztube.auf1/src/Auf1Provider.php';
require_once __DIR__.'/../../../docker/plugin-src/crozztube.source-resolver/src/Resolver.php';
require_once __DIR__.'/../../../docker/plugin-src/crozztube.source-resolver/src/Support/Mirror.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\VideoController;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Plugins\PluginContext;
use App\Plugins\PluginHooks;
use App\Plugins\PluginManifest;
use App\Support\MediaSourceMetadata;
use App\Support\ResolverFailure;
use App\Support\VideoImportIdentity;
use App\Support\VideoImportSettings;
use CrzAuf1\Auf1Provider;
use CrzSourceResolver\Models\CrozztubeImportSource;
use CrzSourceResolver\Resolver;
use CrzSourceResolver\Support\Mirror;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error."\n"); exit(1); });
config(['cache.default' => 'array']);
Bus::fake(); Queue::fake(); Http::preventStrayRequests();
$pass = $fail = 0;
function check(bool $ok, string $name): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}
function rejects(callable $call, string $name): void {
    try { $call(); check(false, $name); } catch (RuntimeException) { check(true, $name); }
}

$hooks = app(PluginHooks::class);
$hooks->removePlugin('crozztube.auf1');
$hooks->addFilter('playhub.video_import.providers', static function (array $providers): array {
    $providers['auf1'] = Auf1Provider::definition(); return $providers;
}, 10, 'auf1-checks');
$manifest = PluginManifest::fromFile(__DIR__.'/../../../docker/plugin-src/crozztube.auf1/plugin.json');
check($manifest->id() === 'crozztube.auf1', 'AUF1 package manifest validates');

$slug = 'fixture-video';
$url = 'https://auf1.tv/nachrichten-auf1/'.$slug.'/';
$uuid = 'b70c747f-7500-4f49-a44d-4719239d2377';
$bunnyUuid = '8906d9b9-53f7-4003-8935-db9b46ac7401';
$primary = 'https://gegenstimme.tv/static/streaming-playlists/hls/'.$uuid.'/9dd1ea54-834d-4f7a-b7d2-38000264a3ba-master.m3u8';
$backup = 'https://vz-0b49e78d-475.b-cdn.net/'.$bunnyUuid.'/playlist.m3u8';
$mp4 = 'https://gegenstimme.tv/download/streaming-playlists/hls/videos/'.$uuid.'-1080-fragmented.mp4';
$fixture = [
    'id' => 6428, 'public_id' => $slug, 'show' => ['public_id' => 'nachrichten-auf1'],
    'title' => 'Fixture video', 'text' => '<p>First &amp; second.</p><p>Next paragraph.</p>',
    'additional_text' => '<p>Details.</p>', 'preview_text' => 'Short summary',
    'thumbnail_url' => 'https://auf1.tv/storage/fixture.png', 'duration' => '25:59', 'is_live' => 0,
    'tags' => ['News', 'news', ['name' => 'Interview']],
    'videoData' => [
        'id' => 11204, 'is_live' => 0,
        'urls' => ['force_fallback' => 0, 'peertube' => [['src' => $primary, 'label' => 'auto', 'type' => 'application/x-mpegURL']],
            'bunny' => ['src' => $backup],
            'peertube_download' => [['src' => str_replace('-1080-', '-0-', $mp4), 'label' => 'Audio'], ['src' => $mp4, 'label' => '1080p']]],
        'embeddableUrls' => ['peertube' => 'https://gegenstimme.tv/videos/embed/'.$uuid,
            'bunny' => 'https://iframe.mediadelivery.net/play/171363/'.$bunnyUuid],
        'chapters' => ['0' => 'Intro', '51' => 'Main topic', '129' => 'Interview'],
    ],
];
$fake = static function (mixed $data, int $status = 200): void {
    Cache::flush();
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://auf1.tv/api/getContent/*' => Http::response($data, $status)]);
};
$fake($fixture);
foreach (['https://auf1.tv/nachrichten-auf1/'.$slug, 'http://www.auf1.tv/nachrichten-auf1/'.$slug.'/?utm_source=fixture&t=51#player'] as $variant) {
    check(Auf1Provider::canonicalUrl($variant) === $url, 'normalizes public page variants');
    check(VideoImportSettings::detectProvider($variant) === 'auf1', 'server detects AUF1 automatically');
    check(Auf1Provider::idFromUrl($variant) === null, 'URL slug is not treated as numeric content ID');
}
foreach (['https://auf1.tv.evil.test/nachrichten-auf1/'.$slug, 'https://user@auf1.tv/nachrichten-auf1/'.$slug,
    'https://auf1.tv:8443/nachrichten-auf1/'.$slug, 'https://auf1.tv/api/getContent', 'https://auf1.tv/eilt/'.$slug,
    'https://auf1.tv/suche?q=test', 'https://auf1.tv/nachrichten-auf1', 'https://auf1.tv/live/stream',
    'https://auf1.tv/nachrichten-auf1/%2e%2e', 'https://auf1.tv/nachrichten-auf1/'.$slug.'/extra', 'file:///nachrichten-auf1/'.$slug] as $bad) {
    rejects(fn () => Auf1Provider::metadata($bad), 'rejects unsafe or non-video page before network');
}
check(Http::recorded()->isEmpty(), 'invalid import URLs never contact upstream');
$metadata = Auf1Provider::metadata($url);
check($metadata['provider_id'] === '6428' && $metadata['provider_id'] !== '11204', 'identity is content ID, never videoData ID or hosting UUID');
check($metadata['description'] === "First & second.\nNext paragraph.\n\nDetails.", 'full description preserves paragraphs and additional text');
check($metadata['tags'] === ['News', 'Interview'], 'tags retain supplied values and deduplicate');
check($metadata['duration_seconds'] === 1559 && $metadata['thumbnail_url'] === $fixture['thumbnail_url'], 'duration and provider thumbnail import');
check(count($metadata['chapters']) === 3 && $metadata['chapters_source'] === 'provider', 'actual provider chapter map imports');
check($metadata['captions'] === [] && $metadata['language_code'] === null && $metadata['original_language_code'] === null, 'does not invent captions or audio language');
check(Auf1Provider::sourceCandidates($url)['playback_candidates'] === [$primary, $backup, $mp4], 'native HLS candidates precede bounded video MP4 fallback');
check(str_contains((string) Auf1Provider::embedUrl('6428', '', $url), '/videos/embed/'.$uuid), 'official embed is resolved from verified source context');
check(Auf1Provider::embedUrl('11204', '', $url) === null && Auf1Provider::embedUrl('6428') === null, 'embed never guesses from an ID or mismatched record');
check(Http::recorded()->count() === 1, 'metadata, candidate discovery and embed share one bounded cache');

// Fake transport captures the actual Guzzle options so both transfer guards are
// exercised independently of the final body-size fallback used by HTTP fakes.
Cache::flush();
Http::swap(new Illuminate\Http\Client\Factory);
Http::preventStrayRequests();
$transferOptions = []; $acceptEncoding = [];
Http::fake(static function ($request, array $options) use (&$transferOptions, &$acceptEncoding, $fixture) {
    $transferOptions = $options;
    $acceptEncoding = $request->header('Accept-Encoding');
    return Http::response($fixture);
});
Auf1Provider::metadata($url);
$limit = 2 * 1024 * 1024;
check(($transferOptions['decode_content'] ?? true) === false && $acceptEncoding === ['identity'], 'compressed response expansion is disabled before byte checks');
rejects(fn () => $transferOptions['on_headers'](new GuzzleHttp\Psr7\Response(200, ['Content-Length' => (string) ($limit + 1)])), 'oversized declared response aborts before downloading its body');
$transferOptions['on_headers'](new GuzzleHttp\Psr7\Response(200, ['Content-Length' => (string) $limit]));
$transferOptions['on_headers'](new GuzzleHttp\Psr7\Response(200, ['Transfer-Encoding' => 'chunked']));
$transferOptions['progress'](0, $limit, 0, 0);
check(true, 'exact byte boundary and absent Content-Length remain valid');
rejects(fn () => $transferOptions['progress'](0, $limit + 1, 0, 0), 'chunked or missing-length response aborts during download at byte limit');
rejects(fn () => $transferOptions['progress'](10, $limit + 1, 0, 0), 'misleading short Content-Length cannot bypass transfer limit');
rejects(fn () => $transferOptions['progress']($limit + 1, 0, 0, 0), 'oversized reported transfer total aborts immediately');
$fake(str_repeat('x', $limit + 1));
rejects(fn () => Auf1Provider::metadata($url), 'overlarge body is rejected even when a fake transport skips transfer callbacks');
$fake($fixture);

$long = $fixture; $long['duration'] = '1:07:33'; $long['videoData']['chapters'] = [];
check(Auf1Provider::parseContent($long, 'nachrichten-auf1', $slug)['metadata']['duration_seconds'] === 4053, 'long-form hour duration parses');
$short = $long; $short['duration'] = '12:05';
check(Auf1Provider::parseContent($short, 'nachrichten-auf1', $slug)['metadata']['chapters'] === [], 'video without chapters does not acquire invented sections');
foreach (['not-an-array', [], [...$fixture, 'id' => 'not-numeric'], [...$fixture, 'public_id' => 'other'],
    [...$fixture, 'is_live' => 1], [...$fixture, 'show' => ['public_id' => 'other']], [...$fixture, 'videoData' => []]] as $bad) {
    rejects(fn () => Auf1Provider::parseContent($bad, 'nachrichten-auf1', $slug), 'changed schema, non-video or mismatched identity is rejected');
}
$untrusted = $fixture;
$untrusted['videoData']['urls']['peertube'][0]['src'] = 'https://gegenstimme.tv@127.0.0.1/private.m3u8';
$untrusted['videoData']['urls']['bunny']['src'] = 'https://other.b-cdn.net/'.$bunnyUuid.'/playlist.m3u8';
$untrusted['videoData']['urls']['peertube_download'] = [['src' => str_replace('-1080-', '-0-', $mp4), 'label' => 'Audio']];
check(Auf1Provider::parseContent($untrusted, 'nachrichten-auf1', $slug)['playback_candidates'] === [], 'unsafe CDN URLs and audio-only downloads are excluded');
$untrusted['videoData']['embeddableUrls'] = ['peertube' => 'https://gegenstimme.tv.evil.test/videos/embed/'.$uuid,
    'bunny' => 'https://iframe.mediadelivery.net/play/999/'.$bunnyUuid];
rejects(fn () => Auf1Provider::parseContent($untrusted, 'nachrichten-auf1', $slug), 'untrusted embeds and unrelated Bunny libraries are rejected');
$fake([], 200);
rejects(fn () => Auf1Provider::metadata($url), 'HTTP 200 empty API result is not a video');
check(Auf1Provider::embedUrl('6428', '', $url) === null, 'API failure falls back to original page, never a guessed embed');
$fake($fixture);

$method = new ReflectionMethod(VideoController::class, 'providerImportMetadata');
$import = $method->invoke(app(VideoController::class), 'auf1', $url);
$direct = $method->invoke(app(VideoController::class), 'direct', $url);
check($import['video_path'] === 'external:auf1:6428' && $direct['video_path'] === $import['video_path'], 'manual direct import retains canonical AUF1 identity and mirror origin');
check($direct['provider'] === 'auf1' && $direct['description'] === $import['description'], 'manual direct imports use official metadata instead of unsupported page extraction');
$identity = VideoImportIdentity::keys('auf1', $url, $import['video_path']);
check($identity['import_source_key'] === VideoImportIdentity::keys('direct', 'http://www.auf1.tv/nachrichten-auf1/'.$slug.'?t=5', $direct['video_path'])['import_source_key'], 'manual direct and URL variants have one global source key');
check($identity['import_source_key'] === hash('sha256', "auf1\0".'6428'), 'source key uses numeric AUF1 content identity');

class FixtureAuf1Resolver extends Resolver
{
    public array $seen = [];
    public array $responses = [];
    protected function extract(string $url, float $timeout = 180.0): array
    {
        $this->seen[] = $url;
        $result = $this->responses[$url] ?? new ResolverFailure('permanent', 'Missing fixture');
        if ($result instanceof Throwable) throw $result;
        return $result;
    }
}
$context = app(App\Plugins\PluginManager::class)->context('crozztube.source-resolver');
$extractor = ['id' => 'hosting-master', 'title' => 'hosting filename', 'duration' => 1559, 'extractor' => 'generic',
    'manifest_url' => $primary, 'formats' => [['url' => $primary, 'protocol' => 'm3u8_native', 'height' => 1080, 'width' => 1920, 'vcodec' => 'avc1.640028', 'acodec' => 'mp4a.40.2']]];
$resolver = new FixtureAuf1Resolver($context);
$resolver->responses = [$primary => new ResolverFailure('transient', 'CDN unavailable'), $backup => $extractor];
$result = $resolver->resolve($url);
check($result['ttl_seconds'] === max(1, (int) $context->setting('stale_fallback_minutes', 180)) * 60, 'unsigned AUF1 HLS uses the configured internal recheck interval, not a proven provider expiry');
$signed = $extractor;
$signed['manifest_url'] = $primary.'?expire='.(time() + 3600);
$signed['formats'][0]['url'] = $signed['manifest_url'];
$signedTtl = $resolver->fromMetadata($signed)['ttl_seconds'];
check($signedTtl >= 3295 && $signedTtl <= 3300, 'real Unix-second CDN expiry keeps the five-minute safety margin instead of the three-hour fallback');
$signed['manifest_url'] = $primary.'?expire='.(time() - 600);
$signed['formats'][0]['url'] = $signed['manifest_url'];
rejects(fn () => $resolver->fromMetadata($signed), 'already expired provider URLs are not published as fresh playback sources');
check($resolver->seen === [$primary, $backup], 'resolver attempts provider primary then backup HLS');
check($result['metadata']['provider_id'] === '6428' && $result['metadata']['title'] === 'Fixture video'
    && count($result['metadata']['chapters']) === 3, 'source resolution preserves official identity, title and chapters');
check($result['audio_tracks'] === [], 'muxed source does not invent alternate audio tracks');
$muxedExtractor = $extractor;
unset($muxedExtractor['manifest_url']);
$muxedExtractor['formats'][] = ['url' => $primary.'/audio', 'protocol' => 'm3u8_native', 'height' => 0,
    'vcodec' => 'none', 'acodec' => 'mp4a.40.2'];
$native = new FixtureAuf1Resolver($context);
$native->responses = [$primary => $muxedExtractor];
$muxedResult = $native->resolve($url);
check($muxedResult['kind'] === 'hls' && $muxedResult['media_url'] === $primary,
    'muxed provider master stays native rather than entering the Googlevideo split-stream proxy');
check(count($muxedResult['audio_tracks']) === 1 && ! $muxedResult['audio_tracks'][0]['original'],
    'audio-only bitrate does not invent an original-language claim');
$splitExtractor = $muxedExtractor;
$splitExtractor['formats'][0]['acodec'] = 'none';
$native->responses = [$primary => $splitExtractor];
check($native->resolve($url)['kind'] === 'hls-synth', 'truly separate video and audio retain composite playback');
$multiExtractor = $muxedExtractor;
$multiExtractor['formats'][1]['language'] = 'de';
$multiExtractor['formats'][] = [...$multiExtractor['formats'][1], 'url' => $primary.'/audio-fr', 'language' => 'fr'];
$native->responses = [$primary => $multiExtractor];
check($native->resolve($url)['kind'] === 'hls-synth', 'multiple distinct audio languages retain selectable composite playback');
$resolver = new FixtureAuf1Resolver($context);
$resolver->responses = [$primary => new ResolverFailure('transient', 'CDN unavailable'), $backup => new ResolverFailure('permanent', 'gone'), $mp4 => new ResolverFailure('permanent', 'gone')];
try { $resolver->resolve($url); check(false, 'source failure category'); }
catch (ResolverFailure $error) { check($error->category === 'transient' && count($resolver->seen) === 3, 'all candidates fail with bounded attempts and retryable category'); }
$legacy = new FixtureAuf1Resolver($context);
$legacy->responses = ['https://rumble.com/embed/vfixture/' => $extractor];
$legacy->resolve('https://rumble.com/embed/vfixture/');
check($legacy->seen === ['https://rumble.com/embed/vfixture/'], 'existing provider without candidates retains original extraction path');

DB::beginTransaction();
try {
    $video = Video::query()->firstOrFail()->replicate();
    $video->forceFill(['slug' => 'auf1-check-'.bin2hex(random_bytes(6)), 'source_type' => 'import',
        'import_provider' => 'auf1', 'import_url' => $url, 'video_path' => $import['video_path'],
        'title' => 'AUF1 fixture', 'description' => null, 'caption_metadata' => null,
        'status' => 'ready', 'visibility' => 'public', 'approval_status' => 'approved']);
    $video->save();
    check(VideoImportIdentity::duplicate($identity)?->id === $video->id, 'global duplicate lookup finds AUF1 record');
    check(MediaSourceMetadata::providerIdForVideo($video) === '6428', 'stored AUF1 numeric reference is retained');
    $mirror = Mirror::forVideo($video, 'crozztube.local');
    check($mirror['provider'] === 'auf1' && $mirror['mode'] === 'iframe' && str_contains($mirror['embed_url'], $uuid), 'watch mirror uses verified official provider embed');
    $request = Request::create('https://crozztube.local:8443/api/videos/fixture');
    $resource = (new VideoResource($video))->toArray($request);
    check($resource['embed_url'] === null && $resource['video_sources'] === [] && $resource['playback_pending']
        && str_contains((string) Mirror::forVideo($video, 'crozztube.local')['embed_url'], $uuid), 'pending playback keeps native player with official embed available through Mirror');
    CrozztubeImportSource::query()->create(['video_id' => $video->id, 'state' => 'fresh', 'kind' => 'hls',
        'media_url' => $primary, 'audio_tracks' => [], 'expires_at' => now()->addHour(), 'resolved_at' => now()]);
    $resource = (new VideoResource($video))->toArray($request);
    check(($resource['video_sources'][0]['src'] ?? null) === $primary, 'fresh native HLS is available ahead of embed fallback');
    $source = CrozztubeImportSource::where('video_id', $video->id)->firstOrFail();
    $source->update(['expires_at' => now()->subMinute()]);
    $resource = (new VideoResource($video))->toArray($request);
    check($resource['video_sources'] === [], 'elapsed internal recheck interval requests renewal before source reuse');
    $freshResolver = new FixtureAuf1Resolver($context);
    $freshResolver->responses = [$primary => $extractor];
    app()->instance(Resolver::class, $freshResolver);
    $job = new CrzSourceResolver\Jobs\RefreshSource($video->id, true, now()->getTimestamp());
    $job->handle();
    $resource = (new VideoResource($video->fresh()))->toArray($request);
    check($source->fresh()->isFresh() && ($resource['video_sources'][0]['src'] ?? null) === $primary,
        'an actual refresh job restores expired AUF1 native playback with mocked upstream only');
    $calls = count($freshResolver->seen);
    $job->handle();
    check(count($freshResolver->seen) === $calls, 'queued duplicate renewal skips extraction after a successful refresh');
    CrozztubeImportSource::query()->where('video_id', $video->id)->update(['state' => 'error']);
    $resource = (new VideoResource($video))->toArray($request);
    check($resource['video_sources'] === [] && str_contains((string) Mirror::forVideo($video, 'crozztube.local')['embed_url'], $uuid), 'failed native source leaves official embed playable through Mirror');
    $fake([], 503);
    $mirror = Mirror::forVideo($video, 'crozztube.local');
    check($mirror['mode'] === 'link' && $mirror['external_url'] === $url, 'metadata outage retains original AUF1 page fallback');
} finally { DB::rollBack(); $hooks->removePlugin('auf1-checks'); }
check(! isset(VideoImportSettings::providers()['auf1']), 'disabling plugin removes capability without deleting video records');
echo "AUF1 checks: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
