<?php

require __DIR__.'/../../../vendor/autoload.php';
// Exercise maintained plugin sources before installing a release.
require_once __DIR__.'/../../../docker/plugin-src/crozztube.odysee/src/OdyseeProvider.php';
require_once __DIR__.'/../../../docker/plugin-src/crozztube.source-resolver/src/Support/AudioTracks.php';
require_once __DIR__.'/../../../docker/plugin-src/crozztube.source-resolver/src/Resolver.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Plugins\PluginManifest;
use App\Support\MediaSourceMetadata;
use App\Support\VideoImportSettings;
use App\Support\YtDlp;
use CrzOdysee\OdyseeProvider;
use CrzSourceResolver\Support\AudioTracks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error."\n"); exit(1); });
config(['cache.default' => 'array']);
Http::preventStrayRequests();
$pass = $fail = 0;
function check(bool $condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
$claimId = str_repeat('a', 40);
$uri = 'lbry://@garden#b/garden.video#c';
$url = 'https://odysee.com/@garden:b/garden.video:c?src=tracking';
$claim = ['name' => 'garden.video', 'claim_id' => $claimId,
    'value' => ['stream_type' => 'video', 'title' => 'Garden video', 'description' => "Full description\nwith another paragraph", 'tags' => ['garden', 'plants'],
        'video' => ['duration' => 554, 'width' => 1920, 'height' => 1080], 'languages' => ['de'], 'thumbnail' => ['url' => 'https://thumbs.odycdn.com/garden.webp']],
    'signing_channel' => ['name' => '@garden', 'value' => ['title' => 'Gardening']]];
Http::fake(['https://api.lbry.tv/api/v1/proxy' => Http::response(['result' => [$uri => $claim]])]);
check(PluginManifest::fromFile(__DIR__.'/../../../docker/plugin-src/crozztube.odysee/plugin.json')->id() === 'crozztube.odysee', 'Odysee manifest validates');
check(version_compare(PluginManifest::fromFile(__DIR__.'/../../../docker/plugin-src/crozztube.source-resolver/plugin.json')->version(), '0.8.1', '>='), 'audio resolver package manifest validates');
app(App\Plugins\PluginHooks::class)->addFilter('playhub.video_import.providers', static function (array $providers): array {
    $providers['odysee'] = OdyseeProvider::definition(false); return $providers;
}, 10, 'odysee-checks');
check(VideoImportSettings::detectProvider($url) === 'odysee', 'server automatically detects Odysee');
check(VideoImportSettings::detectProvider('https://lbry.tv/garden.video:abc') === 'odysee', 'legacy LBRY URLs detect Odysee');
check(OdyseeProvider::idFromUrl($url) === null, 'abbreviated video identities require resolution');
foreach (['https://odysee.com/garden.video:'.$claimId, 'https://odysee.com/$/download/garden.video/'.$claimId,
    'https://odysee.com/$/embed/'.rawurlencode('garden.video:'.$claimId), 'https://odysee.com/garden.video#'.$claimId] as $permanent) {
    check(OdyseeProvider::idFromUrl($permanent) === 'garden.video:'.$claimId, 'permanent and encoded embed links retain full identity');
}
foreach (['https://odysee.com.evil.test/garden:abc', 'https://user@odysee.com/garden:abc', 'https://odysee.com:8443/garden:abc',
    'file:///garden:abc', 'https://odysee.com/@channel:abc', 'https://odysee.com/$/wallet', 'https://odysee.com/search',
    'https://odysee.com/%0agarden:abc', 'https://odysee.com/garden:abc/extra/path', 'https://odysee.com/garden:xyz',
    'https://odysee.com/%2e%2e', 'https://odysee.com/$/embed/%3Ciframe%3E:'.$claimId] as $bad) {
    try { OdyseeProvider::metadata($bad, false); check(false, 'reject invalid or unsafe Odysee URL'); }
    catch (RuntimeException) { check(true, 'reject invalid or unsafe Odysee URL'); }
}
check(OdyseeProvider::embedUrl('garden.video:'.$claimId) === 'https://odysee.com/$/embed/garden.video%3A'.$claimId, 'official embed uses permanent URI encoding');
check(OdyseeProvider::embedUrl('garden:abc') === null, 'embed never guesses an abbreviated identity');
$metadata = OdyseeProvider::metadata($url, false);
check($metadata['title'] === 'Garden video' && strlen($metadata['description']) > 20 && $metadata['tags'] === ['garden', 'plants'], 'claim descriptions and tags survive import');
check($metadata['provider_id'] === 'garden.video:'.$claimId && $metadata['author_name'] === 'Gardening', 'resolved identity and creator are stored');
check($metadata['language_code'] === 'de' && $metadata['duration_seconds'] === 554, 'Odysee language and duration are retained');
check($metadata['captions'] === [], 'unavailable captions are not fabricated');
$method = new ReflectionMethod(App\Http\Controllers\Api\VideoController::class, 'providerImportMetadata');
$import = $method->invoke(app(App\Http\Controllers\Api\VideoController::class), 'odysee', $url);
check($import['video_path'] === 'external:odysee:garden.video:'.$claimId, 'import persists permanent Odysee reference');
check($import['language_code'] === 'de' && str_contains($import['embed_url'], 'garden.video%3A'), 'core metadata includes language and official Odysee fallback');
$video = new App\Models\Video(['source_type' => 'import', 'import_provider' => 'odysee', 'import_url' => $url, 'video_path' => $import['video_path']]);
check(MediaSourceMetadata::providerIdForVideo($video) === 'garden.video:'.$claimId, 'stored identity preserves colon-delimited claim name');

$audio = static fn (string $language, int $preference, string $note, int $bitrate = 128): array => [
    'url' => 'https://media.googlevideo.com/'.$language.'/'.$bitrate.'/audio.m3u8', 'protocol' => 'm3u8_native', 'vcodec' => 'none',
    'acodec' => 'mp4a.40.2', 'language' => $language, 'language_preference' => $preference, 'format_note' => $note, 'abr' => $bitrate,
];
$formats = [$audio('en', 5, 'English (default)'), $audio('de', 10, 'German (original)', 64), $audio('de', 10, 'German (original)', 160),
    $audio('fr', -1, 'French'), $audio('en-desc', -10, 'English descriptive')];
$tracks = AudioTracks::hls($formats);
check(count($tracks) === 4, 'alternate audio languages and description remain selectable');
check($tracks[0]['language'] === 'de' && $tracks[0]['original'] && $tracks[0]['default'], 'German original wins over regional English default');
check(str_contains($tracks[0]['url'], '/160/'), 'best bitrate retained within the original language');
check(str_contains($tracks[0]['label'], 'Original') && ! $tracks[1]['original'], 'only confirmed original is labeled Original');
$variants = [['url' => 'https://media.googlevideo.com/video.m3u8', 'width' => 1920, 'height' => 1080, 'bandwidth' => 2000000, 'codecs' => 'avc1.640028']];
$master = AudioTracks::master($variants, $tracks, 'https://crozztube.local/api/source-resolver/playlist?url=', '&video_id=1');
check(substr_count($master, 'TYPE=AUDIO') === 4 && substr_count($master, 'DEFAULT=YES') === 1, 'master exposes all tracks and exactly one default');
check(str_contains($master, 'LANGUAGE="de",DEFAULT=YES') && str_contains($master, 'LANGUAGE="en",DEFAULT=NO'), 'HLS default follows original language');
check(str_contains($master, 'public.accessibility.describes-video'), 'audio description remains marked as accessibility track');
check(str_contains($master, '&video_id=1') && str_contains($master, 'AUDIO="audio"'), 'video variants reference the switchable audio group');
$unknown = AudioTracks::hls([$audio('fr', 0, 'French'), $audio('en', 0, 'English')]);
check($unknown[0]['language'] === 'fr' && ! $unknown[0]['original'], 'unknown original never defaults to English or gains a false Original label');
check(YtDlp::normalize(['formats' => $formats, 'language' => 'en'])['language_code'] === 'de', 'video metadata uses confirmed original instead of regional default');
check(YtDlp::normalize(['formats' => [$audio('fr', 0, ''), $audio('en', 0, '')], 'language' => 'en'])['language_code'] === null, 'ambiguous multilingual metadata remains unknown');
check(YtDlp::normalize([])['language_code'] === null, 'missing language never becomes English');
$resolver = app(CrzSourceResolver\Resolver::class);
$resolved = $resolver->fromMetadata(['title' => 'Languages', 'duration' => 60, 'formats' => [...$formats,
    ['url' => 'https://media.googlevideo.com/video.m3u8', 'protocol' => 'm3u8_native', 'vcodec' => 'avc1.640028', 'acodec' => 'none', 'height' => 1080, 'width' => 1920, 'tbr' => 2000]]]);
check($resolved['kind'] === 'hls-synth' && count($resolved['audio_tracks']) === 4, 'resolver preserves tracks in synthesized native source');
check($resolved['audio_url'] === $tracks[0]['url'], 'legacy default URL also points at original');
$progressive = $resolver->fromMetadata(['title' => 'Muxed', 'duration' => 60, 'formats' => [
    ['url' => 'https://cdn.example.com/en.mp4', 'protocol' => 'https', 'ext' => 'mp4', 'vcodec' => 'h264', 'acodec' => 'aac', 'height' => 2160, 'language' => 'en', 'language_preference' => 5],
    ['url' => 'https://cdn.example.com/de.mp4', 'protocol' => 'https', 'ext' => 'mp4', 'vcodec' => 'h264', 'acodec' => 'aac', 'height' => 1080, 'width' => 1920, 'language' => 'de', 'language_preference' => 10],
]]);
check(str_ends_with($progressive['media_url'], '/de.mp4'), 'progressive fallback prefers original over higher-resolution dub');
try {
    $resolver->fromMetadata(['formats' => [$audio('en', 0, 'English dub'),
        ['url' => 'https://media.googlevideo.com/de.m4a', 'protocol' => 'https', 'vcodec' => 'none', 'acodec' => 'aac', 'language' => 'de', 'language_preference' => 10],
        ['url' => 'https://media.googlevideo.com/video.m3u8', 'protocol' => 'm3u8_native', 'vcodec' => 'avc1.640028', 'acodec' => 'none', 'height' => 1080]]]);
    check(false, 'an available dub never silently replaces an unavailable original');
} catch (App\Support\ResolverFailure) { check(true, 'an available dub never silently replaces an unavailable original'); }
echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);
