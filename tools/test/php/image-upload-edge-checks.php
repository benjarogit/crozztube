<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use App\Models\ImageUpload;
use App\Models\ImageUploadMirror;
use App\Models\ThumbnailProvider;
use App\Models\User;
use App\Models\Video;
use App\Support\Images\ImageUploads;
use App\Support\Thumbnails\Hosters;
use App\Support\Thumbnails\RemoteImages;
use App\Support\Thumbnails\ThumbnailService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

final class EdgeImageTransport extends RemoteImages
{
    public array $calls = [];
    public mixed $onPost = null;
    public bool $failGet = false;
    public bool $failDirectPost = false;
    public string $mime = 'image/png';
    public string $bytes;

    public function __construct()
    {
        $this->bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    }

    public function request(string $url, string $method, float $deadline, int $limit, array $headers = [], ?string $body = null, bool $partial = false): array
    {
        $this->calls[] = ['url' => $url, 'method' => $method, 'deadline' => $deadline, 'limit' => $limit];
        if ($method === 'POST' && $this->onPost) {
            $callback = $this->onPost;
            $this->onPost = null;
            $callback();
        }
        $direct = str_contains($url, 'directupload');
        return ['code' => ($method === 'GET' && $this->failGet) || ($method === 'POST' && $direct && $this->failDirectPost) ? 503 : 200,
            'body' => $method === 'POST' ? ($direct ? '<input value="https://s1.directupload.eu/images/261105/edge.png">'
                : json_encode(['hotlinked' => false, 'big_link' => 'https://i.fastpic.org/full/edge.png'])) : $this->bytes,
            'type' => $method === 'POST' ? 'text/html' : $this->mime, 'location' => $url, 'error' => ''];
    }

    public function posts(): int
    {
        return count(array_filter($this->calls, fn ($call) => $call['method'] === 'POST'));
    }
}

$passed = 0; $failed = 0;
function edgeCheck(bool $condition, string $name): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo 'PASS '.$name."\n"; }
    else { $failed++; echo 'FAIL '.$name."\n"; }
}
function edgeReject(callable $action, string $name): void
{
    try { $action(); edgeCheck(false, $name); }
    catch (Throwable) { edgeCheck(true, $name); }
}
function edgeProviders(): void
{
    ThumbnailProvider::query()->update(['paused_until' => null, 'circuit_open' => false, 'failed_video_ids' => '[]']);
}

config(['cache.default' => 'array', 'thumbnails.pause_file' => '/proc/no-image-edge-pause',
    'media_providers.settings_path' => sys_get_temp_dir().'/crz-image-edge-'.bin2hex(random_bytes(8)).'/settings.enc',
    'thumbnails.providers.fastpic.supported' => false, 'thumbnails.providers.postimages.supported' => false,
    'thumbnails.providers.directupload.supported' => true, 'thumbnails.providers.directupload.minute_limit' => 1000,
    'thumbnails.providers.directupload.day_limit' => 1000]);
Bus::fake(); Queue::fake();
DB::beginTransaction();
try {
    $owner = User::with('channel')->findOrFail(1);
    $recipient = User::forceCreate(['name' => 'Image edge recipient', 'username' => 'edge'.bin2hex(random_bytes(5)),
        'email' => Str::uuid().'@example.invalid', 'password' => bcrypt(Str::random(24)),
        'settings' => ['preferences' => ['privateMessages' => 'anyone']]]);
    $video = Video::withoutEagerLoads()->where('user_id', $owner->id)->firstOrFail();
    $request = Request::create('/api/image-upload-edge-checks');
    $app->instance('request', $request);
    Auth::guard('sanctum')->setUser($owner);
    $http = new EdgeImageTransport;
    $app->instance(RemoteImages::class, $http);
    $app->instance(Hosters::class, new Hosters($http));
    $app->forgetInstance(ThumbnailService::class);
    $images = new ImageUploads($http, new Hosters($http));

    $first = $images->receive($owner, (string) Str::uuid(), 'video_comment', $video->id, $http->bytes, 'first.png');
    $posts = $http->posts();
    $same = $images->receive($owner, (string) Str::uuid(), 'video_comment', $video->id, $http->bytes, 'same.png');
    edgeCheck($same->status === 'ready' && $http->posts() === $posts, 'same owner reuses an existing verified content hash');
    $foreign = $images->receive($recipient, (string) Str::uuid(), 'private_message', $owner->id, $http->bytes, 'foreign.png');
    edgeCheck($foreign->status === 'ready' && $http->posts() === $posts + 1, 'identical bytes from another owner do not share private upload identity');

    $cancelId = (string) Str::uuid();
    $http->onPost = fn () => $images->abandon($owner, $cancelId);
    $cancelled = $images->receive($owner, $cancelId, 'video_comment', $video->id, $http->bytes.'cancel');
    edgeCheck($cancelled->status === 'abandoned', 'DELETE during host transfer cannot be resurrected by its successful response');
    edgeReject(fn () => $images->validateIds($owner, 'video_comment', $video->id, [$cancelId]), 'cancelled in-flight image cannot be published');

    $raceId = (string) Str::uuid(); $innerStatus = null; $duringStatus = null;
    $http->onPost = function () use ($images, $owner, $video, $raceId, &$innerStatus, &$duringStatus): void {
        try { $images->receive($owner, $raceId, 'video_comment', $video->id, 'not-an-image'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) { $innerStatus = $exception->getStatusCode(); }
        catch (Throwable) { $innerStatus = 422; }
        $duringStatus = ImageUpload::find($raceId)->status;
    };
    $race = $images->receive($owner, $raceId, 'video_comment', $video->id, $http->bytes.'race');
    edgeCheck($innerStatus === 409 && $duringStatus === 'uploading' && $race->status === 'ready', 'malformed concurrent PUT cannot overwrite the in-flight upload state');

    $caseId = (string) Str::uuid(); $caseStatus = null;
    $http->onPost = function () use ($images, $owner, $video, $caseId, $http, &$caseStatus): void {
        try { $images->receive($owner, strtoupper($caseId), 'video_comment', $video->id, $http->bytes.'uuid-case'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) { $caseStatus = $exception->getStatusCode(); }
    };
    $caseRace = $images->receive($owner, $caseId, 'video_comment', $video->id, $http->bytes.'uuid-case');
    edgeCheck($caseStatus === 409 && $caseRace->status === 'ready', 'UUID casing cannot bypass the in-flight upload lock');

    $oversizeId = (string) Str::uuid();
    $oversizeRequest = Request::create('/api/image-uploads/'.$oversizeId.'?purpose=video_comment&target_id='.$video->id,
        'PUT', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'image/png'], $http->bytes.str_repeat('x', 5 * 1048576));
    $oversizeRequest->headers->remove('Content-Length');
    $app->instance('request', $oversizeRequest); Auth::guard('sanctum')->setUser($owner);
    $oversizeResponse = $kernel->handle($oversizeRequest);
    edgeCheck($oversizeResponse->getStatusCode() === 413 && ImageUpload::find($oversizeId)?->status === 'failed', 'oversized body without Content-Length leaves a failed draft instead of a stuck uploading state');

    edgeProviders();
    $pendingId = (string) Str::uuid(); $http->failGet = true;
    $pending = $images->receive($owner, $pendingId, 'video_comment', $video->id, $http->bytes.'verify');
    $posts = $http->posts();
    edgeCheck($pending->status === 'failed' && ImageUploadMirror::where('image_upload_id', $pendingId)->whereNotNull('image_url')->exists(), 'verification failure keeps the returned URL without exposing it as ready');
    $http->failGet = false;
    $recovered = $images->receive($owner, $pendingId, 'video_comment', $video->id, $http->bytes.'verify');
    edgeCheck($recovered->status === 'ready' && $http->posts() === $posts, 'retry verifies the previous upload instead of creating a second host copy');

    edgeProviders(); $http->mime = 'text/html';
    $invalidType = $images->receive($owner, (string) Str::uuid(), 'video_comment', $video->id, $http->bytes.'mime');
    edgeCheck($invalidType->status === 'failed' && ! ThumbnailProvider::find('directupload')->circuit_open, 'non-image response MIME is rejected without treating it as a global server outage');
    $http->mime = 'image/png';
    $original = $http->bytes;
    // A PNG IHDR with different dimensions models a downsized returned image.
    $larger = substr_replace($original, pack('NN', 2, 2), 16, 8);
    $badSize = $images->receive($owner, (string) Str::uuid(), 'video_comment', $video->id, $larger);
    edgeCheck($badSize->status === 'failed', 'a smaller returned image cannot become an original attachment');

    $banned = clone $owner;
    $banned->settings = array_replace($owner->settings ?? [], ['bans' => ['comments' => ['forever' => true]]]);
    $posts = $http->posts();
    edgeReject(fn () => $images->receive($banned, (string) Str::uuid(), 'video_comment', $video->id, $original), 'comment ban is enforced before external upload');
    edgeCheck($http->posts() === $posts, 'comment ban makes no transfer');
    $settings = $recipient->settings;
    $recipient->forceFill(['settings' => ['preferences' => ['privateMessages' => 'disabled']]])->save();
    edgeReject(fn () => $images->receive($owner, (string) Str::uuid(), 'private_message', $recipient->id, $original), 'recipient disabled-message preference is enforced before upload');
    $recipient->forceFill(['settings' => ['bans' => ['account' => ['forever' => true]]]])->save();
    edgeReject(fn () => $images->assertTarget($owner, 'private_message', $recipient->id), 'account-banned recipient cannot receive uploads');
    $recipient->forceFill(['settings' => $settings])->save();

    $heldId = (string) Str::uuid();
    $images->reserve($owner, $heldId, 'video_comment', $video->id);
    $lock = Cache::lock('image-uploads:'.$heldId, 90); $lock->get();
    $posts = $http->posts();
    edgeReject(fn () => $images->receive($owner, $heldId, 'video_comment', $video->id, $original), 'concurrent same-UUID request is rejected by its lock');
    $lock->release();
    edgeCheck($http->posts() === $posts, 'same-UUID contention does not reach the hoster');

    try {
        DB::transaction(function () use ($images, $owner, $video, $first): void {
            $comment = $video->comments()->create(['user_id' => $owner->id, 'body' => 'Rollback image claim']);
            $images->bind($owner, 'video_comment', $video->id, [$first->id], $comment);
            throw new RuntimeException('rollback fixture');
        });
    } catch (RuntimeException) {}
    edgeCheck(ImageUpload::find($first->id)->status === 'ready', 'content transaction rollback also rolls back its attachment claim');

    $same->forceFill(['expires_at' => now()->subMinute()])->save();
    $expiredRequest = Request::create('/api/image-uploads/'.$same->id, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $app->instance('request', $expiredRequest); Auth::forgetGuards(); Auth::guard('sanctum')->setUser($owner);
    $expiredResponse = $kernel->handle($expiredRequest);
    $expiredData = json_decode($expiredResponse->getContent(), true);
    edgeCheck($expiredResponse->getStatusCode() === 410 && ($expiredData['status'] ?? null) === 'failed'
        && ($expiredData['code'] ?? null) === 'image_upload_expired' && empty($expiredData['attachment']),
        'expired draft recovery returns select-again status without exposing image URLs');
    $same->forceFill(['expires_at' => now()->addDay()])->save();

    $comment = $video->comments()->create(['user_id' => $owner->id, 'body' => 'Cache image claim']);
    $bound = $images->bind($owner, 'video_comment', $video->id, [$first->id, $same->id], $comment);
    $same->fresh()->forceFill(['expires_at' => now()->subMinute()])->save();
    $attachedRequest = Request::create('/api/image-uploads/'.$same->id, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $app->instance('request', $attachedRequest); Auth::forgetGuards(); Auth::guard('sanctum')->setUser($owner);
    $attachedResponse = $kernel->handle($attachedRequest);
    $attachedData = json_decode($attachedResponse->getContent(), true);
    edgeCheck($attachedResponse->getStatusCode() === 200 && ($attachedData['status'] ?? null) === 'attached'
        && ! empty($attachedData['attachment']['url']), 'published image status remains readable after its former draft deadline');
    $app->instance('request', Request::create('/api/comments'));
    DB::enableQueryLog(); DB::flushQueryLog();
    ImageUploads::prefetch($bound);
    foreach ([...$bound, ...$bound] as $attachment) ImageUploads::payload($attachment);
    $queries = count(DB::getQueryLog());
    edgeCheck($queries <= 3, 'a batch of attachment payloads uses at most three metadata queries');
    $app->instance('request', Request::create('/api/comments-next-request'));
    DB::flushQueryLog(); ImageUploads::payload($bound[0]);
    edgeCheck(count(DB::getQueryLog()) > 0, 'metadata cache is not reused across HTTP request objects');
    $images->detach($bound);
    edgeCheck(ImageUploads::payload($bound[0]) === [], 'detach invalidates an already cached attachment payload');
    DB::disableQueryLog();
    edgeCheck(collect($http->calls)->every(fn ($call) => $call['limit'] <= 8 * 1048576), 'every HTTP request keeps its bounded response body');
    edgeProviders(); config(['thumbnails.providers.fastpic.supported' => true]);
    $http->calls = []; $http->failDirectPost = true;
    $started = microtime(true);
    $budget = $images->receive($owner, (string) Str::uuid(), 'video_comment', $video->id, $original.'budget');
    $deadlines = array_column($http->calls, 'deadline');
    edgeCheck($budget->status === 'ready' && $http->posts() === 2 && count(array_unique($deadlines, SORT_REGULAR)) === 1
        && max($deadlines) <= $started + 10.05, 'fallback uploads and hotlink verification share the same overall deadline');
} catch (Throwable $exception) {
    $failed++; echo 'FAIL unexpected '.get_class($exception).': '.$exception->getMessage().' at '.$exception->getLine()."\n";
} finally {
    DB::disableQueryLog(); Carbon::setTestNow();
    while (DB::transactionLevel() > 0) DB::rollBack();
}
echo "Image upload edge checks: $passed pass, $failed fail\n";
exit($failed ? 1 : 0);
