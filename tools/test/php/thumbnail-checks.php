<?php

// Integration assertions run inside one rolled-back transaction against the local stack.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\MaintainThumbnails;
use App\Models\ThumbnailMirror;
use App\Models\ThumbnailProvider;
use App\Models\Video;
use App\Support\ImportMetadataBackfill;
use App\Support\ResolverFailure;
use App\Support\ShortVideos;
use App\Support\Thumbnails\Hosters;
use App\Support\Thumbnails\NightWindow;
use App\Support\Thumbnails\RemoteImages;
use App\Support\Thumbnails\ThumbnailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MemoryImages extends RemoteImages
{
    public array $calls = [];
    public array $responses = [];
    public bool $sourceFails = false;
    public ?float $lastDeadline = null;
    public mixed $onRequest = null;

    public function request(string $url, string $method, float $deadline, int $limit, array $headers = [], ?string $body = null, bool $partial = false): array
    {
        $this->calls[] = [$url, $method];
        $this->lastDeadline = $deadline;
        if ($this->onRequest) ($this->onRequest)();
        return ($this->responses[$method.' '.$url] ?? ['code' => 200, 'body' => '', 'type' => 'image/png', 'location' => $url, 'error' => ''])
            + ['code' => 200, 'body' => '', 'type' => 'image/png', 'location' => $url, 'error' => ''];
    }

    public function image(string $url, float $deadline): array
    {
        if ($this->sourceFails) throw new RuntimeException('source-unavailable');
        return self::inspect(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }
}

$pass = 0;
$fail = 0;
function check(bool $ok, string $name): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
function rejects(callable $fn, string $name): void
{
    try { $fn(); check(false, $name); } catch (Throwable) { check(true, $name); }
}

config(['cache.default' => 'array', 'thumbnails.pause_file' => '/proc/crozztube-test-no-pause',
    'thumbnails.providers.fastpic.key' => 'test-only', 'thumbnails.providers.postimages.key' => 'test-only']);
DB::beginTransaction();
try {
    $template = Video::query()->withoutEagerLoads()->firstOrFail();
    $make = function (string $suffix) use ($template): Video {
        $video = $template->replicate();
        $video->forceFill(['slug' => 'crz-thumbnail-test-'.$suffix.'-'.bin2hex(random_bytes(3)), 'thumbnail_path' => null,
            'original_thumbnail_url' => 'https://i.ytimg.com/test-'.$suffix.'.jpg', 'thumbnail_source_url' => 'https://i.ytimg.com/test-'.$suffix.'.jpg',
            'thumbnail_source_version' => hash('sha256', 'https://i.ytimg.com/test-'.$suffix.'.jpg'), 'thumbnail_is_custom' => false,
            'metadata_overrides' => [], 'video_width' => 1080, 'video_height' => 1920, 'duration_seconds' => 180, 'source_type' => 'import']);
        $video->save();
        return $video;
    };
    foreach ([[1, 1080, 1920, true], [60, 1080, 1920, true], [180, 1080, 1920, true], [181, 1080, 1920, false],
        [180.01, 1080, 1920, false], [180, 1080, 1080, true], [60, 1920, 1080, false], [0, 1080, 1920, false], [60, null, null, false]] as [$d, $w, $h, $expected]) {
        check(ShortVideos::qualifies($d, $w, $h) === $expected, "short rule $d/$w/$h");
    }
    check(! ShortVideos::qualifies(30, 1080, 1920, 'live_replay'), 'live replay stays outside Shorts');
    foreach (['127.0.0.1', '10.1.2.3', '169.254.169.254', '100.64.0.1', '224.0.0.1', '192.0.2.1', '::1', '::ffff:127.0.0.1', 'fe80::1', '2001:db8::1'] as $ip) {
        check(! RemoteImages::publicIp($ip), 'reject non-public '.$ip);
    }
    check(RemoteImages::publicIp('8.8.8.8') && RemoteImages::publicIp('2606:4700:4700::1111'), 'allow public IPv4 and IPv6');
    $real = new RemoteImages;
    rejects(fn () => $real->address('http://127.0.0.1/image.jpg'), 'reject SSRF before transfer');
    rejects(fn () => $real->address('file:///etc/passwd'), 'reject file protocol');
    rejects(fn () => $real->address('https://user:password@example.com/a.jpg'), 'reject URL credentials');
    rejects(fn () => $real->address('http://example.com:3306/a'), 'reject unexpected port');
    rejects(fn () => RemoteImages::inspect('<svg><script/></svg>'), 'reject SVG and non-image bytes');
    rejects(fn () => RemoteImages::inspect(str_repeat('x', 5242881)), 'reject oversized image');

    $http = new MemoryImages;
    $hosters = new Hosters($http);
    $service = new ThumbnailService($http, $hosters);
    $app->instance(ThumbnailService::class, $service);
    $fast = 'https://i.fastpic.org/big/test.png';
    $post = 'https://i.postimg.cc/test/image.png';
    check($hosters->parse('fastpic', json_encode(['hotlinked' => true, 'big_link' => $fast])) === $fast, 'FastPic hotlink parser');
    check($hosters->parse('fastpic', json_encode(['hotlinked' => false, 'big_link' => $fast, 'thumb_link' => 'https://i.fastpic.org/thumb/test.png'])) === 'https://i.fastpic.org/thumb/test.png', 'FastPic respects hotlink restriction');
    check($hosters->parse('postimages', '<data success="1"><links><hotlink>'.$post.'</hotlink></links></data>') === $post, 'Postimages own-key XML hotlink parser');
    rejects(fn () => $hosters->parse('postimages', '<!DOCTYPE data [<!ENTITY x SYSTEM "file:///etc/passwd">]><data success="1"><links><hotlink>&x;</hotlink></links></data>'), 'reject XML entities');
    rejects(fn () => $hosters->parse('postimages', '<data success="0"><links><hotlink>'.$post.'</hotlink></links></data>'), 'reject API failure despite HTTP 200');
    rejects(fn () => $hosters->parse('fastpic', '{"hotlinked":true,"big_link":"https://127.0.0.1/test.png"}'), 'reject unexpected provider URL');

    $video = $make('fallback');
    $original = $video->original_thumbnail_url;
    $service->ensureSource($video);
    $http->responses['POST https://new.fastpic.org/upload'] = ['code' => 503];
    $http->responses['POST https://api.postimage.org/1/upload'] = ['body' => '<data success="1"><links><hotlink>'.$post.'</hotlink></links></data>'];
    $service->initial($video);
    check($video->fresh()->original_thumbnail_url === $original, 'original survives upload fallback');
    check(ThumbnailMirror::where('video_id', $video->id)->where('provider_id', 'postimages')->value('status') === 'available', 'next provider succeeds after primary failure');
    check(ThumbnailMirror::where('video_id', $video->id)->where('provider_id', 'fastpic')->value('status') === 'failed', 'failed first copy remains retryable');
    check(count(array_filter($http->calls, fn ($c) => $c[1] === 'POST')) === 2, 'one attempt per provider, no immediate repeats');
    check(($http->lastDeadline - microtime(true)) <= 10.1, 'all requests share import deadline');
    $candidates = $video->fresh()->thumbnail_candidates;
    check($candidates[0] === $post && end($candidates) === $original, 'ordered browser candidates end with original');
    $http->calls = [];
    $video->fresh()->thumbnail_candidates;
    check($http->calls === [], 'display performs no remote checks');

    $http->responses['POST https://new.fastpic.org/upload'] = ['body' => json_encode(['hotlinked' => true, 'big_link' => $fast])];
    $second = $make('stop');
    $service->initial($second);
    check(count(array_filter($http->calls, fn ($c) => $c[1] === 'POST')) === 1, 'stop after first successful daytime copy');
    check(ThumbnailMirror::where('video_id', $second->id)->where('provider_id', 'postimages')->value('status') === 'pending', 'secondary copy waits for night');
    $before = count($http->calls);
    $service->initial($second->fresh());
    check(count($http->calls) === $before, 'do not upload functioning image twice');
    $third = $make('stop');
    $service->initial($third);
    check(count($http->calls) === $before, 'reuse identical verified source across videos');
    $hold = Cache::lock('thumbnails:transfer', 90); $hold->get();
    $busy = $make('busy'); $service->initial($busy); $hold->release();
    check(count($http->calls) === $before, 'global lock prevents concurrent transfers without blocking import');

    $contentTwin = $make('different-source-url');
    $beforeTwin = count($http->calls);
    $service->initial($contentTwin);
    check(count(array_filter(array_slice($http->calls, $beforeTwin), fn ($c) => $c[1] === 'POST')) === 0, 'changed source URL with identical bytes does not re-upload');
    $noBudget = $make('no-budget');
    config(['thumbnails.import_budget' => 0]);
    $beforeBudget = count($http->calls);
    $service->initial($noBudget);
    check(count($http->calls) === $beforeBudget && $noBudget->fresh()->exists, 'exhausted import budget leaves video and original usable');
    config(['thumbnails.import_budget' => 10]);
    $long = $make('long');
    $longUrl = 'https://i.ytimg.com/image.jpg?signature='.str_repeat('x', 2048);
    $long->forceFill(['original_thumbnail_url' => null])->save();
    $service->ensureSource($long, $longUrl);
    check($long->fresh()->original_thumbnail_url === $longUrl && $long->fresh()->thumbnail_source_url === $longUrl, 'thumbnail source URL over 255 characters is stored intact');
    $http->sourceFails = true;
    $missing = $make('missing'); $service->initial($missing);
    check($missing->fresh()->thumbnail_candidates === [$missing->original_thumbnail_url], 'missing source keeps original fallback');
    $http->sourceFails = false;
    $http->responses['POST https://new.fastpic.org/upload'] = ['code' => 503];
    $http->responses['POST https://api.postimage.org/1/upload'] = ['code' => 503];
    $oldVersion = $second->fresh()->thumbnail_source_version;
    check(! $service->replace($second, $http->image($original, microtime(true) + 10)['bytes'].'different'), 'manual thumbnail may fail without losing video');
    check($second->fresh()->thumbnail_source_version === $oldVersion, 'failed custom replacement keeps previous image');

    $http->responses['POST https://new.fastpic.org/upload'] = ['body' => json_encode(['hotlinked' => true, 'big_link' => 'https://i.fastpic.org/big/custom.png'])];
    $customBytes = $http->image($original, microtime(true) + 10)['bytes'].'custom-content';
    $secondOriginal = $second->original_thumbnail_url;
    check($service->replace($second, $customBytes), 'manual image is hosted from RAM');
    check($second->fresh()->thumbnail_is_custom && $second->fresh()->thumbnail_source_version === hash('sha256', $customBytes), 'manual replacement changes active source version');
    check($second->fresh()->original_thumbnail_url === $secondOriginal && $second->fresh()->thumbnail_path === null, 'manual replacement preserves original and creates no local storage path');
    $obsolete = ThumbnailMirror::where('video_id', $second->id)->where('source_version', $oldVersion)->first();
    $beforeObsolete = count($http->calls);
    check(! $service->copy($obsolete, microtime(true) + 10) && count($http->calls) === $beforeObsolete, 'obsolete source jobs do not upload after a manual image change');
    $legacy = $make('legacy');
    $legacy->forceFill(['thumbnail_path' => 'videos/thumbnails/test-legacy-reference.jpg'])->save();
    $service->ensureSource($legacy);
    $http->responses['HEAD https://i.fastpic.org/big/test.png'] = ['code' => 503];
    $http->responses['HEAD https://i.postimg.cc/test/image.png'] = ['code' => 503];
    $service->initial($legacy);
    check($legacy->fresh()->thumbnail_path === 'videos/thumbnails/test-legacy-reference.jpg', 'local migration retains old path until successful verification');
    $http->responses['HEAD https://i.fastpic.org/big/test.png'] = ['code' => 200];
    $http->responses['HEAD https://i.postimg.cc/test/image.png'] = ['code' => 200];
    $unverified = ThumbnailMirror::where('video_id', $legacy->id)->where('provider_id', 'fastpic')->first();
    $service->check($unverified, microtime(true) + 10);
    check($legacy->fresh()->thumbnail_path === null, 'verified night check completes local migration safely');
    $legacy->forceFill(['thumbnail_path' => '../outside-thumbnail.jpg'])->save();
    $service->check($unverified, microtime(true) + 10);
    check($legacy->fresh()->thumbnail_path === '../outside-thumbnail.jpg' && $unverified->fresh()->last_result === 'cleanup-pending', 'failed local cleanup keeps its reference for a later retry');
    $legacy->forceFill(['thumbnail_path' => null])->save();


    $provider = ThumbnailProvider::find('fastpic');
    $provider->forceFill(['failed_video_ids' => [], 'paused_until' => null, 'circuit_open' => false])->save();
    foreach ([101, 102, 103] as $id) $service->providerResult($provider, $id, ['url' => null, 'server_failure' => true, 'result' => 'http:503']);
    check($provider->fresh()->circuit_open && $provider->paused_until->isFuture(), 'three distinct failures pause whole hoster');
    $service->providerResult($provider, 104, ['url' => $fast, 'server_failure' => false, 'result' => 'ok']);
    check(! $provider->fresh()->circuit_open && ! $provider->paused_until, 'successful recovery closes circuit');
    $service->providerResult($provider, 105, ['url' => null, 'server_failure' => false, 'result' => 'http:404']);
    check(! $provider->fresh()->circuit_open && ! $provider->paused_until, 'single missing image never disables hoster');

    Carbon::setTestNow(Carbon::parse('2026-11-05 00:30', 'Europe/Berlin'));
    check(NightWindow::next()->format('Y-m-d H:i') === '2026-11-05 02:00', 'retry before night targets the upcoming window');
    Carbon::setTestNow(Carbon::parse('2026-11-05 02:00', 'Europe/Berlin'));
    check(NightWindow::contains(), 'night includes 02:00 Berlin');
    Carbon::setTestNow(Carbon::parse('2026-11-05 05:00', 'Europe/Berlin'));
    check(! NightWindow::contains(), 'night excludes 05:00 Berlin');
    $before = count($http->calls);
    (new MaintainThumbnails)->handle($service);
    check(count($http->calls) === $before, 'queued maintenance cannot execute at daytime boundary');
    Carbon::setTestNow(Carbon::parse('2026-11-05 03:00', 'Europe/Berlin'));
    $http->responses['POST https://new.fastpic.org/upload'] = ['body' => json_encode(['hotlinked' => true, 'big_link' => $fast])];
    $http->responses['POST https://api.postimage.org/1/upload'] = ['body' => '<data success="1"><links><hotlink>'.$post.'</hotlink></links></data>'];
    ThumbnailMirror::where('video_id', $second->id)->where('source_version', $second->fresh()->thumbnail_source_version)->where('status', 'available')->update(['next_check_at' => now()->subDay()]);
    ThumbnailMirror::where('video_id', $second->id)->where('source_version', $second->fresh()->thumbnail_source_version)->where('status', 'pending')->update(['next_attempt_at' => now()->subDay()]);
    config(['thumbnails.check_limit' => 2, 'thumbnails.upload_limit' => 2]);
    (new MaintainThumbnails)->handle($service);
    $stats = Cache::get('thumbnails:last-run');
    check($stats['checks'] > 0 && $stats['checks'] <= 2 && $stats['attempts'] > 0 && $stats['attempts'] <= 2 && $stats['seconds'] <= 60, 'night actually processes bounded checks and copies');
    Carbon::setTestNow(Carbon::parse('2026-11-05 04:59:59', 'Europe/Berlin'));
    ThumbnailMirror::where('video_id', $second->id)->where('source_version', $second->fresh()->thumbnail_source_version)->where('status', 'available')->update(['next_check_at' => now()->subDay()]);
    $http->onRequest = fn () => Carbon::setTestNow(Carbon::parse('2026-11-05 05:00', 'Europe/Berlin'));
    (new MaintainThumbnails)->handle($service);
    check(Cache::get('thumbnails:last-run')['checks'] === 1 && Cache::get('thumbnails:last-run')['attempts'] === 0, 'running batch stops at 05:00 instead of draining backlog');
    $http->onRequest = null;
    Carbon::setTestNow();

    $meta = $make('metadata');
    $meta->forceFill(['title' => 'My title', 'description' => '', 'tags' => [], 'chapters_enabled' => false, 'metadata_overrides' => ['title', 'description']])->save();
    ImportMetadataBackfill::apply($meta, ['title' => 'Upstream', 'description' => 'Do not restore', 'tags' => ['new'], 'chapters' => [['start_time' => 0, 'title' => 'One'], ['start_time' => 20, 'title' => 'Two']]]);
    check($meta->title === 'My title' && $meta->description === '', 'backfill preserves deliberate edits and cleared fields');
    $stale = $meta->replicate();
    $stale->id = $meta->id;
    $stale->exists = true;
    Video::whereKey($meta->id)->update(['description' => 'Concurrent edit', 'metadata_overrides' => json_encode(['title', 'description'])]);
    ImportMetadataBackfill::apply($stale, ['description' => 'Stale upstream']);
    check($meta->fresh()->description === 'Concurrent edit', 'refresh rechecks current edits under a database lock');
    $app->instance(ThumbnailService::class, new class($http, $hosters) extends ThumbnailService {
        public function ensureSource(Video $video, ?string $url = null): void { throw new RuntimeException('simulated thumbnail store failure'); }
    });
    ImportMetadataBackfill::apply($meta, ['thumbnail_url' => 'https://i.ytimg.com/new-source.jpg']);
    check($meta->fresh()->description === 'Concurrent edit', 'thumbnail subsystem failure cannot fail playback metadata refresh');
    $app->instance(ThumbnailService::class, $service);

    check($meta->tags === ['new'] && ! $meta->chapters_enabled && empty($meta->chapters), 'backfill fills missing tags and preserves disabled chapters');
    check(Video::shorts()->whereKey($meta->id)->exists() && $meta->isShort(), 'Shorts SQL agrees with API classification');
    check(! Video::standardVideos()->whereKey($meta->id)->exists(), 'Shorts and standard feeds do not overlap');
    check(ResolverFailure::fromOutput('ERROR: Video unavailable')->category === 'permanent', 'permanent resolver error classification');
    check(ResolverFailure::fromOutput('HTTP Error 503 Service unavailable')->category === 'transient', 'transient resolver error classification');
    check(ResolverFailure::fromOutput('unexpected extractor issue')->category === 'unknown', 'unknown errors remain bounded');
    $source = \CrzSourceResolver\Models\CrozztubeImportSource::create(['video_id' => $meta->id, 'state' => 'error', 'kind' => 'mp4',
        'media_url' => 'https://example.com/video.mp4', 'expires_at' => now()->subDay(), 'error_category' => 'permanent']);
    check(! \CrzSourceResolver\Jobs\RefreshSource::request($meta->id, force: true), 'automatic refresh cannot restart a permanent failure');
    \Illuminate\Support\Facades\Artisan::call('crozztube:sources:refresh-expiring', ['--dry-run' => true]);
    check(! str_contains(\Illuminate\Support\Facades\Artisan::output(), 'video='.$meta->id.' '), 'expiry scheduler excludes permanent failures');
    \Illuminate\Support\Facades\Queue::fake();
    check(\CrzSourceResolver\Jobs\RefreshSource::request($meta->id, force: true, allowFailed: true), 'explicit user retry can restart after a permanent failure');
    check($source->fresh()->error_category === null, 'explicit retry clears automatic stop flag');
    (new \CrzSourceResolver\Jobs\RefreshSource($meta->id))->failed(ResolverFailure::fromOutput('unexpected extractor issue'));
    check($source->fresh()->error_category === 'unknown_exhausted', 'unknown exhausted failure persists across scheduler runs');
    $deletedId = $meta->id;
    $meta->delete();
    check(! \CrzSourceResolver\Models\CrozztubeImportSource::where('video_id', $deletedId)->exists(), 'deleting video also removes its resolver row');
    (new \CrzSourceResolver\Jobs\RefreshSource($deletedId, true))->handle();
    check(! \CrzSourceResolver\Models\CrozztubeImportSource::where('video_id', $deletedId)->exists(), 'queued job cannot recreate a deleted video source');


} finally {
    Carbon::setTestNow();
    DB::rollBack();
}
echo "Thumbnail integration checks: $pass pass, $fail fail\n";
exit($fail ? 1 : 0);
