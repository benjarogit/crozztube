<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use App\Jobs\MaintainThumbnails;
use App\Models\Comment;
use App\Models\ImageUpload;
use App\Models\ImageUploadMirror;
use App\Models\ThumbnailMirror;
use App\Models\ThumbnailProvider;
use App\Models\User;
use App\Models\Video;
use App\Support\Images\ImageUploads;
use App\Support\MediaStorage;
use App\Support\Thumbnails\Hosters;
use App\Support\Thumbnails\RemoteImages;
use App\Support\Thumbnails\ThumbnailService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class AttachmentMemoryImages extends RemoteImages
{
    public array $calls = [];
    public array $fail = [];
    public bool $fastHotlinked = true;
    public mixed $onRequest = null;
    public string $bytes;

    public function __construct()
    {
        $this->bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    }

    public function request(string $url, string $method, float $deadline, int $limit, array $headers = [], ?string $body = null, bool $partial = false): array
    {
        $this->calls[] = ['url' => $url, 'method' => $method, 'deadline' => $deadline, 'limit' => $limit];
        if ($this->onRequest) ($this->onRequest)();
        $provider = str_contains($url, 'directupload') ? 'directupload' : (str_contains($url, 'fastpic') ? 'fastpic' : 'postimages');
        $code = ($this->fail[$provider] ?? false) ? 503 : 200;
        $data = $this->bytes;
        if ($method === 'POST') {
            $data = match ($provider) {
                'directupload' => '<html><input value="https://s1.directupload.eu/images/261105/attachment.png"></html>',
                'fastpic' => json_encode(['hotlinked' => $this->fastHotlinked, 'big_link' => 'https://i.fastpic.org/full/attachment.png', 'thumb_link' => 'https://i.fastpic.org/thumb/attachment.png']),
                'postimages' => '<data success="1"><links><hotlink>https://i.postimg.cc/test/attachment.png</hotlink></links></data>',
            };
        }
        return ['code' => $code, 'body' => $data, 'type' => $method === 'POST' ? 'text/html' : 'image/png', 'location' => $url, 'error' => ''];
    }

    public function posts(): array
    {
        return array_values(array_filter($this->calls, fn ($call) => $call['method'] === 'POST'));
    }
}

// Exercise authorization and content validation repeatedly without consuming
// the production per-user HTTP quota. Provider budgets/locks stay enabled.
class ImageCheckNoHttpThrottle
{
    public function handle($request, $next, ...$arguments) { return $next($request); }
}
$app->make(\Illuminate\Routing\Router::class)->aliasMiddleware('throttle', ImageCheckNoHttpThrottle::class);

$passed = 0;
$failed = 0;
function imageCheck(bool $ok, string $name): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS $name\n"; }
    else { $failed++; echo "FAIL $name\n"; }
}
function imageRejects(callable $callback, string $name): void
{
    try { $callback(); imageCheck(false, $name); } catch (Throwable) { imageCheck(true, $name); }
}
function imageApi(string $method, string $uri, ?User $user, ?string $bytes = null, array $json = []): array
{
    global $app, $kernel;
    Auth::forgetGuards();
    $server = ['HTTP_ACCEPT' => 'application/json'];
    if ($bytes !== null) {
        $server += ['CONTENT_TYPE' => 'image/png', 'CONTENT_LENGTH' => strlen($bytes), 'HTTP_X_IMAGE_NAME' => rawurlencode('Browser image.png')];
        $content = $bytes;
    } else {
        $server['CONTENT_TYPE'] = 'application/json';
        $content = json_encode($json);
    }
    $request = Request::create($uri, $method, [], [], [], $server, $content);
    $app->instance('request', $request);
    if ($user) Auth::guard('sanctum')->setUser($user);
    $response = $kernel->handle($request);
    return [$response->getStatusCode(), json_decode($response->getContent(), true) ?: []];
}
function imageProvidersReset(): void
{
    ThumbnailProvider::query()->update(['failed_video_ids' => '[]', 'circuit_open' => false, 'paused_until' => null, 'last_result' => null]);
}

config(['cache.default' => 'array', 'thumbnails.pause_file' => '/proc/crozztube-image-test-no-pause',
    'media_providers.settings_path' => sys_get_temp_dir().'/crz-image-fixture-'.bin2hex(random_bytes(8)).'/settings.enc']);
foreach (['directupload', 'fastpic', 'postimages'] as $id) {
    config(['thumbnails.providers.'.$id.'.supported' => true, 'thumbnails.providers.'.$id.'.key' => 'test-only',
        'thumbnails.providers.'.$id.'.minute_limit' => 10000, 'thumbnails.providers.'.$id.'.day_limit' => 10000,
        'thumbnails.providers.'.$id.'.month_limit' => 10000]);
}
Bus::fake(); Queue::fake(); Notification::fake();
DB::beginTransaction();
try {
    $owner = User::query()->with('channel')->findOrFail(1);
    $recipient = User::forceCreate(['name' => 'Image fixture recipient', 'username' => 'img'.bin2hex(random_bytes(5)),
        'email' => 'image-'.Str::uuid().'@example.invalid', 'password' => bcrypt(Str::random(24)),
        'settings' => ['preferences' => ['privateMessages' => 'anyone']]]);
    $video = Video::query()->withoutEagerLoads()->where('user_id', $owner->id)->firstOrFail();
    $http = new AttachmentMemoryImages;
    $app->instance(RemoteImages::class, $http);
    $app->instance(Hosters::class, new Hosters($http));
    $app->forgetInstance(ImageUploads::class);
    $app->forgetInstance(ThumbnailService::class);
    $images = $app->make(ImageUploads::class);
    $thumbs = $app->make(ThumbnailService::class);
    $filesBefore = MediaStorage::isLocal() ? MediaStorage::disk()->allFiles() : null;
    $uuid = (string) Str::uuid();
    $uri = '/api/image-uploads/'.$uuid.'?purpose=private_message&target_id='.$recipient->id;

    [$status] = imageApi('PUT', $uri, null, $http->bytes);
    imageCheck($status === 401 && count($http->posts()) === 0, 'anonymous upload rejected before hoster transfer');
    [$status] = imageApi('PUT', '/api/image-uploads/'.Str::uuid().'?purpose=private_message&target_id='.$owner->id, $owner, $http->bytes);
    imageCheck(in_array($status, [403, 422]) && count($http->posts()) === 0, 'self-message denied before image transfer');
    [$status] = imageApi('PUT', '/api/image-uploads/'.Str::uuid().'?purpose=community_post&target_id='.$owner->channel->id, $recipient, $http->bytes);
    imageCheck(in_array($status, [403, 404]) && count($http->posts()) === 0, 'foreign community upload denied before transfer');
    [$status] = imageApi('PUT', $uri, $owner, 'invalid image');
    imageCheck($status === 422 && count($http->posts()) === 0, 'non-image raw bytes rejected before hoster transfer');

    imageProvidersReset();
    [$status, $payload] = imageApi('PUT', $uri, $owner, $http->bytes);
    imageCheck(in_array($status, [200, 201]) && ($payload['status'] ?? '') === 'ready', 'raw image upload becomes ready');
    imageCheck(count($http->posts()) === 1 && str_contains($http->posts()[0]['url'], 'directupload'), 'first Directupload success stops daytime copies');
    imageCheck(str_contains($payload['attachment']['url'] ?? '', 'directupload.eu') && empty($payload['attachment']['path']), 'response contains direct URL and no local path');
    imageCheck(($payload['attachment']['image_candidates'][0] ?? null) === ($payload['attachment']['url'] ?? null), 'ordered image fallback starts with preferred copy');
    $before = count($http->posts());
    [$status] = imageApi('PUT', $uri, $owner, $http->bytes);
    imageCheck(in_array($status, [200, 201]) && count($http->posts()) === $before, 'same UUID retry never uploads twice');
    [$status] = imageApi('GET', '/api/image-uploads/'.$uuid, $recipient);
    imageCheck(in_array($status, [403, 404]), 'another user cannot inspect an upload UUID');
    [$status] = imageApi('DELETE', '/api/image-uploads/'.$uuid, $recipient);
    imageCheck(in_array($status, [403, 404]) && ImageUpload::find($uuid)?->status === 'ready', 'another user cannot abandon an upload');
    [$status] = imageApi('GET', '/api/image-uploads/'.$uuid, $owner);
    imageCheck($status === 200 && count($http->posts()) === $before, 'owner can recover a ready upload after lost response');
    imageRejects(fn () => $images->validateIds($recipient, 'private_message', $owner->id, [$uuid]), 'foreign upload cannot be attached');
    imageRejects(fn () => $images->validateIds($owner, 'video_comment', $video->id, [$uuid]), 'upload purpose cannot be changed when attaching');
    imageRejects(fn () => $images->validateIds($owner, 'private_message', $recipient->id, array_fill(0, 5, $uuid)), 'attachment cardinality capped at four');

    $cancel = (string) Str::uuid();
    [$status] = imageApi('DELETE', '/api/image-uploads/'.$cancel, $owner);
    $before = count($http->posts());
    [$status] = imageApi('PUT', '/api/image-uploads/'.$cancel.'?purpose=private_message&target_id='.$recipient->id, $owner, $http->bytes);
    imageCheck(in_array($status, [409, 410, 422]) && count($http->posts()) === $before, 'cancel before PUT prevents a late upload resurrection');

    imageProvidersReset(); $http->fail = ['directupload' => true]; $http->fastHotlinked = false;
    $other = (string) Str::uuid();
    [$status, $fallback] = imageApi('PUT', '/api/image-uploads/'.$other.'?purpose=video_comment&target_id='.$video->id, $owner, $http->bytes.'second');
    imageCheck(in_array($status, [200, 201]) && str_contains($fallback['attachment']['url'] ?? '', 'postimg.cc'), 'attachment skips restricted FastPic preview and uses Postimages');
    imageCheck(! ThumbnailProvider::find('fastpic')->paused_until, 'restricted full-size FastPic upload does not globally pause thumbnails');
    $hosters = $app->make(Hosters::class);
    imageCheck($hosters->parse('fastpic', json_encode(['hotlinked' => false, 'thumb_link' => 'https://i.fastpic.org/thumb/test.png'])) === 'https://i.fastpic.org/thumb/test.png', 'existing video thumbnail preview fallback remains supported');

    imageProvidersReset(); $http->fail = ['directupload' => true, 'fastpic' => true, 'postimages' => true];
    [$status] = imageApi('PUT', '/api/image-uploads/'.Str::uuid().'?purpose=video_comment&target_id='.$video->id, $owner, $http->bytes.'failure');
    imageCheck(in_array($status, [422, 502, 503]), 'complete hoster outage returns a recoverable upload failure');
    imageProvidersReset(); $http->fail = []; $http->fastHotlinked = true;

    $bytes = $http->bytes.str_repeat('x', 5 * 1024 * 1024);
    $before = count($http->posts());
    [$status] = imageApi('PUT', '/api/image-uploads/'.Str::uuid().'?purpose=video_comment&target_id='.$video->id, $owner, $bytes);
    imageCheck($status === 413 && count($http->posts()) === $before, 'comment image cap enforced before transfer');
    [$status] = imageApi('PUT', '/api/image-uploads/'.Str::uuid().'?purpose=community_post&target_id='.$owner->channel->id, $owner, $bytes);
    imageCheck(in_array($status, [200, 201]), 'community post retains its existing eight MiB limit');
    unset($bytes);

    $comment = $video->comments()->create(['user_id' => $owner->id, 'body' => 'Image transaction fixture']);
    $bound = DB::transaction(fn () => $images->bind($owner, 'video_comment', $video->id, [$other], $comment));
    $comment->forceFill(['attachments' => $bound])->save();
    imageCheck(ImageUpload::find($other)?->status === 'attached' && count($bound) === 1, 'ready image is atomically associated with its comment');
    imageRejects(fn () => $images->bind($owner, 'video_comment', $video->id, [$other], $comment->replicate()), 'an attached image cannot be reassigned');
    $serialized = ImageUploads::payload($bound[0]);
    imageCheck(! empty($serialized['url']) && ! empty($serialized['image_candidates']) && empty($serialized['path']), 'associated attachment serializes externally with client fallbacks');
    [$status] = imageApi('DELETE', '/api/image-uploads/'.$other, $owner);
    imageCheck(in_array($status, [409, 422]), 'draft cancellation cannot delete a published attachment');

    // Mix thumbnail and attachment tasks and prove they share one budget.
    ThumbnailMirror::query()->update(['next_check_at' => now()->addDays(20), 'next_attempt_at' => now()->addDays(20)]);
    ImageUploadMirror::query()->update(['next_check_at' => now()->addDays(20), 'next_attempt_at' => now()->addDays(20)]);
    Carbon::setTestNow(Carbon::parse('2026-11-06 02:10:00', 'Europe/Berlin'));
    $expired = [];
    for ($index = 0; $index < 23; $index++) {
        $expired[] = ImageUpload::forceCreate(['id' => (string) Str::uuid(), 'user_id' => $owner->id,
            'purpose' => 'video_comment', 'target_id' => $video->id, 'status' => 'abandoned',
            'created_at' => now()->subDays(2), 'expires_at' => now()->subHour()])->id;
    }
    ImageUpload::findOrFail($other)->forceFill(['expires_at' => now()->subDay()])->save();
    $cleaned = $images->cleanupDrafts(1000);
    imageCheck($cleaned === 20 && ImageUpload::whereIn('id', $expired)->count() === 3
        && ImageUpload::find($other)?->status === 'attached', 'expired draft cleanup is capped at twenty and preserves published images');
    config(['thumbnails.check_limit' => 2, 'thumbnails.upload_limit' => 2]);
    $imageMirror = ImageUploadMirror::where('image_upload_id', $other)->where('status', 'available')->firstOrFail();
    $imageMirror->forceFill(['next_check_at' => now()->subMinutes(3)])->save();
    $thumbMirror = ThumbnailMirror::whereHas('video')->whereNotNull('thumbnail_url')->firstOrFail();
    $thumbMirror->forceFill(['next_check_at' => now()->subMinutes(2)])->save();
    $attachmentCopy = ImageUploadMirror::where('image_upload_id', $other)->whereIn('status', ['pending', 'failed'])->firstOrFail();
    $attachmentCopy->forceFill(['next_attempt_at' => now()->subMinutes(3)])->save();
    $thumbnailCopy = ThumbnailMirror::whereHas('video')->whereIn('provider_id', ['directupload', 'fastpic', 'postimages'])->where('status', 'pending')->firstOrFail();
    $thumbnailCopy->forceFill(['next_attempt_at' => now()->subMinutes(2)])->save();
    imageProvidersReset();
    $http->calls = [];
    (new MaintainThumbnails)->handle($thumbs);
    $stats = Cache::get('thumbnails:last-run');
    imageCheck($stats['checks'] === 2 && $stats['attachment_checks'] === 1 && $stats['thumbnail_checks'] === 1, 'night checks use one combined budget across both image families');
    imageCheck($stats['attempts'] === 2 && $stats['attachment_attempts'] === 1 && $stats['thumbnail_attempts'] === 1 && $stats['seconds'] <= 60, 'night copies use one combined budget across both image families');
    // A larger backlog must still use the configured production-wide limits,
    // never one set of budgets for thumbnails and another for attachments.
    config(['thumbnails.check_limit' => 20, 'thumbnails.upload_limit' => 5]);
    ThumbnailMirror::query()->update(['next_check_at' => now()->addDays(20), 'next_attempt_at' => now()->addDays(20)]);
    ImageUploadMirror::query()->update(['next_check_at' => now()->addDays(20), 'next_attempt_at' => now()->addDays(20)]);
    for ($index = 0; $index < 23; $index++) {
        $fixture = ImageUpload::forceCreate(['id' => (string) Str::uuid(), 'user_id' => $owner->id,
            'purpose' => 'video_comment', 'target_id' => $video->id, 'status' => 'attached',
            'content_hash' => hash('sha256', 'budget:'.$index), 'name' => 'night.png', 'mime' => 'image/png',
            'size' => strlen($http->bytes), 'width' => 1, 'height' => 1,
            'attachable_type' => Comment::class, 'attachable_id' => $comment->id, 'expires_at' => now()->addDay()]);
        ImageUploadMirror::forceCreate(['image_upload_id' => $fixture->id, 'provider_id' => 'directupload',
            'source_version' => $fixture->content_hash, 'priority' => 1, 'status' => 'available',
            'image_url' => 'https://s1.directupload.eu/images/261105/budget.png', 'next_check_at' => now()->subMinutes(10)]);
        if ($index < 7) ImageUploadMirror::forceCreate(['image_upload_id' => $fixture->id,
            'provider_id' => 'postimages', 'source_version' => $fixture->content_hash, 'priority' => 3,
            'status' => 'pending', 'next_attempt_at' => now()->subMinutes(10)]);
    }
    $thumbMirror->refresh()->forceFill(['next_check_at' => now()->subMinutes(15)])->save();
    $thumbnailCopy->refresh()->forceFill(['status' => 'pending', 'thumbnail_url' => null, 'next_attempt_at' => now()->subMinutes(15)])->save();
    $http->calls = [];
    $runStarted = microtime(true);
    (new MaintainThumbnails)->handle($thumbs);
    $stats = Cache::get('thumbnails:last-run');
    imageCheck($stats['checks'] === 20 && $stats['attachment_checks'] === 19 && $stats['thumbnail_checks'] === 1,
        'large mixed backlog is capped at twenty checks in total');
    imageCheck($stats['attempts'] === 5 && $stats['attachment_attempts'] === 4 && $stats['thumbnail_attempts'] === 1,
        'large mixed backlog is capped at five copy attempts in total');
    imageCheck(count(array_filter($http->calls, fn ($call) => $call['deadline'] > $runStarted + 60.1)) === 0,
        'all mixed maintenance transfers receive the same sixty-second deadline');
    ThumbnailMirror::query()->update(['next_check_at' => now()->addDays(20), 'next_attempt_at' => now()->addDays(20)]);
    ImageUploadMirror::query()->update(['next_check_at' => now()->addDays(20), 'next_attempt_at' => now()->addDays(20)]);
    $orphan = ImageUpload::findOrFail($other)->replicate();
    $orphan->forceFill(['id' => (string) Str::uuid(), 'attachable_id' => '987654321', 'updated_at' => now()->addSecond()])->save();
    ImageUploadMirror::forceCreate(['image_upload_id' => $orphan->id, 'provider_id' => 'directupload',
        'source_version' => $orphan->content_hash, 'priority' => 1, 'status' => 'pending', 'next_attempt_at' => now()->subMinute()]);
    $http->calls = [];
    (new MaintainThumbnails)->handle($thumbs);
    imageCheck($orphan->fresh()->status === 'detached' && Cache::get('thumbnails:last-run')['detached_orphans'] === 1
        && $http->calls === [], 'orphan cleanup finds deleted parents past twenty healthy attachments and never rehosts them');
    $imageMirror->refresh()->forceFill(['next_check_at' => now()->subMinute()])->save();
    Carbon::setTestNow(Carbon::parse('2026-11-06 04:59:59', 'Europe/Berlin'));
    $http->calls = [];
    $http->onRequest = fn () => Carbon::setTestNow(Carbon::parse('2026-11-06 05:00:00', 'Europe/Berlin'));
    (new MaintainThumbnails)->handle($thumbs);
    $stats = Cache::get('thumbnails:last-run');
    imageCheck($stats['checks'] === 1 && $stats['attempts'] === 0, 'mixed backlog stops at five without starting another transfer');
    $http->onRequest = null;
    imageProvidersReset();
    $provider = ThumbnailProvider::findOrFail('directupload');
    foreach ([$video->id, 'image:'.$uuid, 'image:'.$other] as $identity) {
        $thumbs->providerResult($provider->refresh(), $identity, ['url' => null, 'server_failure' => true, 'result' => 'http:503']);
    }
    imageCheck($provider->refresh()->circuit_open && $provider->paused_until->isFuture(),
        'three mixed thumbnail and attachment failures share one provider circuit');
    imageProvidersReset();
    // Preparation itself belongs to the night budget, not just HTTP transfers.
    $unseededIds = Video::whereNotNull('thumbnail_source_version')->limit(2)->pluck('id');
    ThumbnailMirror::whereIn('video_id', $unseededIds)->delete();
    Carbon::setTestNow(Carbon::parse('2026-11-06 04:59:59', 'Europe/Berlin'));
    $preparing = new class($http, new Hosters($http)) extends ThumbnailService {
        public int $preparations = 0;
        public function ensureSource(Video $video, ?string $url = null): void {
            $this->preparations++;
            Carbon::setTestNow(Carbon::parse('2026-11-06 05:00:00', 'Europe/Berlin'));
        }
    };
    (new MaintainThumbnails)->handle($preparing);
    imageCheck($preparing->preparations === 1, 'night preparation also stops at five before seeding the next video');
    Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00', 'Europe/Berlin'));
    $http->calls = [];
    (new MaintainThumbnails)->handle($thumbs);
    imageCheck($http->calls === [], 'daytime never processes attachment maintenance backlog');
    $lock = Cache::lock('thumbnails:transfer', 90); $lock->get();
    $before = count($http->posts());
    [$status] = imageApi('PUT', '/api/image-uploads/'.Str::uuid().'?purpose=video_comment&target_id='.$video->id, $owner, $http->bytes.'locked');
    imageCheck(in_array($status, [409, 423, 429, 503]) && count($http->posts()) === $before, 'global transfer lock also covers interactive attachment uploads');
    $lock->release();

    $images->detach($bound);
    imageCheck(ImageUpload::find($other)?->status === 'detached', 'deleting content detaches its images from future maintenance');
    if ($filesBefore !== null) imageCheck(MediaStorage::disk()->allFiles() === $filesBefore, 'uploads and maintenance create no local media files');
    imageCheck(DB::table('image_uploads')->whereNotNull('content_hash')->whereRaw('LENGTH(content_hash) != 64')->count() === 0, 'image identity stores hashes instead of image bytes');
    // Exercise the real Laravel throttler using the actual route parameters.
    // GET recovery and cancellation must not consume the ten upload slots.
    $limiter = new \Illuminate\Cache\RateLimiter(Cache::store('array'));
    $throttler = new \Illuminate\Routing\Middleware\ThrottleRequests($limiter);
    $limited = function (string $method, string $prefix = '/api') use ($app, $owner, $uuid, $throttler): void {
        $request = Request::create($prefix.'/image-uploads/'.$uuid, $method);
        $request->setUserResolver(fn () => $owner);
        $route = $app['router']->getRoutes()->match($request);
        $rule = collect($route->gatherMiddleware())->first(fn ($name) => str_starts_with($name, 'throttle:') && str_contains($name, ',1,image-upload-'));
        $parameters = explode(',', substr($rule, strlen('throttle:')));
        $throttler->handle($request, fn () => response('', 204), ...$parameters);
    };
    for ($index = 0; $index < 15; $index++) { $limited('GET'); $limited('DELETE'); }
    for ($index = 0; $index < 10; $index++) $limited('PUT');
    imageCheck(true, 'status recovery and cancellation have independent HTTP budgets from raw uploads');
    $uploadLimited = false;
    try { $limited('PUT', '/api/v1'); }
    catch (\Illuminate\Http\Exceptions\ThrottleRequestsException $exception) { $uploadLimited = $exception->getStatusCode() === 429; }
    $limited('GET'); $limited('DELETE');
    imageCheck($uploadLimited, 'web and native uploads share the ten-per-minute owner budget while status remains usable');
} catch (Throwable $error) {
    $failed++;
    echo 'FAIL unexpected '.get_class($error).': '.$error->getMessage().' at '.$error->getFile().':'.$error->getLine()."\n";
} finally {
    Carbon::setTestNow();
    while (DB::transactionLevel() > 0) DB::rollBack();
}
echo "Image upload integration checks: $passed pass, $failed fail\n";
exit($failed ? 1 : 0);
