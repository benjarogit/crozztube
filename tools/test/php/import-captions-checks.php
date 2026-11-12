<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error::class.': '.$error->getMessage()."\n"); exit(1); });
require_once __DIR__.'/../../../docker/plugin-src/crozztube.rumble/src/RumbleProvider.php';

use App\Http\Controllers\MediaController;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Services\Api\V1\PlaybackSessionService;
use App\Support\Client\ClientContext;
use App\Support\ImportMetadataBackfill;
use App\Support\Thumbnails\RemoteImages;
use App\Support\VideoCaptions;
use App\Support\VideoImportSettings;
use App\Support\VideoSales;
use App\Support\YtDlp;
use CrzRumble\RumbleProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

config(['cache.default' => 'array']);
Bus::fake();
Queue::fake();
$pass = $fail = 0;
function check(bool $ok, string $name): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}
class CaptionRemote extends RemoteImages {
    public array $responses = [];
    public array $calls = [];
    public function request(string $url, string $method, float $deadline, int $limit, array $headers = [], ?string $body = null, bool $partial = false): array {
        $this->calls[] = $url;
        return ['code' => isset($this->responses[$url]) ? 200 : 404, 'body' => $this->responses[$url] ?? '', 'error' => '', 'type' => 'text/vtt', 'location' => $url];
    }
}
$remote = new CaptionRemote;
$app->instance(RemoteImages::class, $remote);
$vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:03.000\nHello <b>world</b>.\n\n00:00:03.000 --> 00:00:06.000\nSecond line &amp; more.\n";
$remote->responses = ['https://captions.example/en.vtt' => $vtt,
    'https://captions.example/de.srt' => "1\n00:00:00,000 --> 00:00:03,000\nHallo Welt.\n",
    'https://captions.example/invalid.vtt' => '<html>Not captions</html>',
    'https://captions.example/huge.vtt' => $vtt.str_repeat('a', 1024 * 1024)];
$normalized = YtDlp::normalize(['id' => 'youtube-example', 'extractor_key' => 'Youtube', 'language' => 'en',
    'subtitles' => ['en' => [['url' => 'https://captions.example/en.vtt', 'ext' => 'vtt', 'name' => 'English']],
        'de' => [['url' => 'https://captions.example/de.srt', 'ext' => 'srt', 'name' => 'Deutsch']],
        'bad' => [['url' => 'file:///etc/passwd', 'ext' => 'vtt']]],
    'automatic_captions' => ['en' => [['url' => 'https://captions.example/auto.vtt', 'ext' => 'vtt']],
        'fr' => [['url' => 'https://captions.example/fr.vtt', 'ext' => 'vtt', 'name' => 'French (auto)']]]]);
check(count($normalized['captions']) === 3, 'YouTube manual and automatic caption discovery');
check($normalized['captions'][0]['language'] === 'en' && ! $normalized['captions'][0]['automatic'], 'original manual language is preferred');
check(count(array_filter($normalized['captions'], fn ($c) => $c['language'] === 'en')) === 1, 'manual captions win over automatic duplicates');
check($normalized['captions'][2]['automatic'] === true, 'automatic subtitle provenance is retained');
check(VideoCaptions::webvtt('<script>alert(1)</script>') === null, 'non-caption response is rejected');
check(VideoCaptions::webvtt("WEBVTT\n\n<script>x</script>") === null, 'caption header without timed cues is rejected');
check(VideoCaptions::webvtt("WEBVTT\n\0") === null, 'binary caption payload is rejected');
check(VideoCaptions::webvtt($remote->responses['https://captions.example/de.srt'], 'srt') !== null, 'SRT converts to WebVTT');
$imported = VideoCaptions::importMetadata($normalized['captions']);
check(count($imported['imported_tracks']) === 2, 'available caption languages are copied, missing language is skipped');
check(! str_contains(json_encode($imported), 'https://'), 'stored captions do not retain expiring upstream URLs');
check(str_starts_with($imported['imported_tracks'][1]['content'], 'WEBVTT'), 'stored SRT is normalized for browser playback');
$duplicate = [$normalized['captions'][0], $normalized['captions'][0]];
check(count(VideoCaptions::importMetadata($duplicate)['imported_tracks']) === 1, 'duplicate discovered tracks are stored once');
foreach (['invalid', 'huge'] as $name) {
    check(VideoCaptions::importMetadata([['language' => 'en', 'url' => "https://captions.example/$name.vtt", 'format' => 'vtt']]) === null, "reject $name caption payload");
}
$before = count($remote->calls);
check(VideoCaptions::importMetadata([['url' => 'https://user:secret@example.com/cc.vtt']]) === null && count($remote->calls) === $before, 'credentials are rejected before a caption transfer');
try { (new RemoteImages)->address('http://127.0.0.1/cc.vtt'); check(false, 'private subtitle hosts are blocked'); }
catch (RuntimeException) { check(true, 'private subtitle hosts are blocked'); }

$page = RumbleProvider::parsePage('<meta name="description" content="Truncated"><meta name="keywords" content="Faith, Grace">'
    .'<p class="media-description media-description--first">Full description.</p>'
    .'<p class="media-description media-description--more">Second paragraph ✅<br>New line.</p>'
    .'<button>Show more</button><div class="media-description-tags-container"><a>Faith</a><a>Christian Gospel</a></div>'
    .'<iframe src="https://rumble.com/embed/v7fjis0/"></iframe>');
check($page['description'] === "Full description.\n\nSecond paragraph ✅\nNew line.", 'Rumble preserves complete description, paragraphs and UTF-8');
check($page['tags'] === ['Faith', 'Grace', 'Christian Gospel'], 'Rumble combines actual keywords and displayed tags');
check($page['provider_id'] === 'v7fjis0', 'Rumble page fallback retains actual embed ID');
check(VideoImportSettings::detectProvider('https://www.youtube.com/watch?v=123') === 'youtube', 'server automatically detects YouTube');
check(VideoImportSettings::detectProvider('https://rumble.com/v123-example.html') === 'rumble', 'server automatically detects plugin providers');
check(VideoImportSettings::detectProvider('https://other.example/watch/123') === 'direct', 'server routes other supported sites through direct/yt-dlp');
check(VideoImportSettings::resolveProvider('direct', 'https://rumble.com/v123.html') === 'direct', 'server retains explicit direct override');
check(VideoImportSettings::detectProvider('https://user:pass@rumble.com/v123') === null, 'server automatic detection rejects URL credentials');

DB::beginTransaction();
try {
    $video = Video::query()->withoutEagerLoads()->firstOrFail()->replicate();
    $video->forceFill(['slug' => 'caption-check-'.bin2hex(random_bytes(4)), 'source_type' => 'import',
        'import_provider' => 'youtube', 'import_url' => 'https://youtube.com/watch?v=test123', 'video_path' => 'external:youtube:test123',
        'visibility' => 'public', 'status' => 'ready', 'approval_status' => 'approved', 'captions_path' => null,
        'caption_metadata' => $imported, 'sale_enabled' => false, 'metadata_overrides' => [], 'thumbnail_is_custom' => true]);
    $video->save();
    $request = Request::create('https://crozztube.local:8443/watch/'.$video->slug);
    $app->instance('request', $request);
    check(count(VideoCaptions::stored($video)) === 2, 'imported caption copies survive database serialization');
    $tracks = VideoCaptions::forResource($video);
    check(count($tracks) === 2 && str_contains($tracks[1]['url'], 'track=1'), 'all caption languages have stable local endpoints');
    check(VideoCaptions::transcript($video) === "Hello world.\nSecond line & more.", 'readable transcript derives from timed text without markup');
    $resource = (new VideoResource($video))->resolve($request);
    check($resource['has_captions'] && count($resource['caption_tracks']) === 2 && $resource['transcript_url'], 'web resource exposes caption languages and lazy transcript');
    check(! isset($resource['transcript']), 'feed payload does not embed the full transcript');
    $response = app(MediaController::class)->captions(Request::create('https://crozztube.local:8443/media/videos/'.$video->id.'/captions?track=1'), $video);
    check(str_contains($response->getContent(), 'Hallo Welt.') && str_starts_with($response->headers->get('Content-Type'), 'text/vtt'), 'caption endpoint serves the selected normalized language');
    $response = app(MediaController::class)->captions(Request::create('https://crozztube.local:8443/media/videos/'.$video->id.'/captions?transcript=1'), $video);
    check(str_contains($response->getContent(), 'Hello world.') && str_starts_with($response->headers->get('Content-Type'), 'text/plain'), 'transcript endpoint serves text');
    $captionMethod = new ReflectionMethod(PlaybackSessionService::class, 'captions');
    $captions = $captionMethod->invoke(app(PlaybackSessionService::class), $video, ClientContext::fromRequest($request));
    check(count($captions) === 2 && $captions[0]['format'] === 'webvtt', 'versioned playback API exposes every imported caption language');
    $locked = clone $video;
    $locked->forceFill(['sale_enabled' => true, 'price_cents' => 100, 'is_short' => false]);
    $resource = (new VideoResource($locked))->resolve($request);
    check($resource['caption_tracks'] === [] && $resource['transcript_url'] === null, 'caption and transcript resources respect paid access');
    $private = clone $video;
    $private->visibility = 'private';
    $signed = VideoSales::captionsUrl($private, null, 1, true);
    check(str_contains($signed, 'signature=') && str_contains($signed, 'track=1') && str_contains($signed, 'transcript=1'), 'private transcript signs track selection and text mode');
    $before = $video->caption_metadata;
    ImportMetadataBackfill::apply($video, ['captions' => []]);
    check($video->fresh()->caption_metadata == $before, 'empty refresh metadata never clears stored subtitles');
    $video->forceFill(['caption_metadata' => null])->save();
    ImportMetadataBackfill::apply($video, ['captions' => $normalized['captions']]);
    check(count(VideoCaptions::stored($video->fresh())) === 2, 'existing imports receive captions through metadata backfill');
    $video->forceFill(['captions_path' => 'videos/captions/manual.vtt', 'caption_metadata' => ['label' => 'My captions', 'language' => 'en']])->save();
    ImportMetadataBackfill::apply($video, ['captions' => $normalized['captions']]);
    check($video->fresh()->caption_metadata['label'] === 'My captions', 'manual captions are preserved during backfill');
    $german = App\Support\VideoLanguages::idForCode('de-DE');
    $english = App\Support\VideoLanguages::idForCode('en');
    $video->forceFill(['language_id' => $english, 'metadata_overrides' => []])->save();
    ImportMetadataBackfill::apply($video, ['original_language_code' => 'de-DE']);
    check($german && $video->fresh()->language_id === $german, 'confirmed original corrects a previous implicit English language');
    $video->forceFill(['language_id' => $english, 'metadata_overrides' => ['language_id']])->save();
    ImportMetadataBackfill::apply($video, ['original_language_code' => 'de-DE']);
    check($video->fresh()->language_id === $english, 'explicit language override survives provider refresh');

    $url = 'https://rumble.com/vdmum1-moose-the-dog-helps-girls-dig-a-snow-fort.html';
    $metadata = ['provider_id' => 'vb0ofn', 'title' => 'Caption import integration', 'description' => 'Full provider description',
        'tags' => ['Faith', 'Grace'], 'duration_seconds' => 103, 'width' => 1920, 'height' => 1080,
        'captions' => $normalized['captions'], 'language_code' => 'de-DE'];
    foreach ([0, 1] as $enrich) Illuminate\Support\Facades\Cache::put('rumble:metadata:v2:'.sha1($url.':'.$enrich), $metadata, 600);
    $owner = App\Models\User::query()->with('channel')->findOrFail($video->user_id);
    $importRequest = Request::create('https://crozztube.local:8443/api/video-imports', 'POST', [
        'provider' => 'auto', 'url' => $url, 'category_id' => DB::table('video_categories')->value('id'), 'visibility' => 'private',
    ]);
    $app->instance('request', $importRequest);
    $importRequest->setUserResolver(fn () => $owner);
    $response = app(App\Http\Controllers\Api\VideoController::class)->import($importRequest);
    $saved = Video::query()->findOrFail($response->getData(true)['video']['id']);
    check($response->getStatusCode() === 201 && $saved->import_provider === 'rumble', 'full import endpoint automatically chooses and persists provider');
    check($saved->description === 'Full provider description' && $saved->tags === ['Faith', 'Grace'], 'full import endpoint persists provider description and tags');
    check(count(VideoCaptions::stored($saved)) === 2, 'full import endpoint copies discovered subtitle languages');
    check($saved->language_id === $german && ! in_array('language_id', $saved->metadata_overrides, true), 'automatic import records original language without a manual override');
} finally {
    DB::rollBack();
}
echo "$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
