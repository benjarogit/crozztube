<?php

// Real API/controller integration; all users/content/assets/tokens roll back.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Resources\CommentResource;
use App\Http\Resources\CommunityPostResource;
use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\ImageUpload;
use App\Models\ImageUploadMirror;
use App\Models\NativeDeviceSession;
use App\Models\User;
use App\Models\Video;
use App\Services\Api\V1\AccountDeletionService;
use App\Support\Images\ImageUploads;
use App\Support\Thumbnails\Hosters;
use App\Support\Thumbnails\RemoteImages;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class AttachmentApiNoNetwork extends RemoteImages
{
    public array $calls = [];
    public function request(string $url, string $method, float $deadline, int $limit, array $headers = [], ?string $body = null, bool $partial = false): array
    {
        $this->calls[] = [$method, $url];
        throw new RuntimeException('Publishing must not contact an image hoster.');
    }
}

class AttachmentApiRace extends ImageUploads
{
    public mixed $afterValidation = null;
    public function validateIds(User $user, string $purpose, int|string $targetId, array $ids): array
    {
        $result = parent::validateIds($user, $purpose, $targetId, $ids);
        if ($this->afterValidation) ($this->afterValidation)();
        return $result;
    }
}

$passed = 0;
$failed = 0;
function apiCheck(bool $ok, string $label): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS $label\n"; }
    else { $failed++; echo "FAIL $label\n"; }
}

config(['cache.default' => 'array', 'cache.limiter' => 'array']);
Bus::fake();
Queue::fake();
Http::preventStrayRequests();
$network = new AttachmentApiNoNetwork;
$app->instance(RemoteImages::class, $network);
$app->instance(Hosters::class, new Hosters($network));
$tokens = [];
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

$request = function (string $method, string $path, ?User $user = null, array $body = [], bool $native = false, ?string $raw = null) use ($app, $kernel, &$tokens): array {
    $req = Request::create($path, $method, [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'crozztube.local',
    ], $raw ?? json_encode($body));
    if ($raw !== null) {
        $req->headers->set('Content-Type', 'image/png');
        $req->headers->set('Content-Length', (string) strlen($raw));
    }
    if ($user) $req->headers->set('Authorization', 'Bearer '.$tokens[$user->id][$native ? 'native' : 'web']);
    if ($native) $req->headers->set('X-PlayHub-Client', 'android');
    Auth::forgetGuards();
    $app->instance('request', $req);
    // Bypass rate limits only in this process. Authentication, scopes and gates run.
    $app['router']->getRoutes()->match($req)->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);
    $response = $kernel->handle($req);
    $json = json_decode($response->getContent(), true) ?: [];
    return [$response->getStatusCode(), $json['data'] ?? $json, $response];
};

$viewerRequest = function (?User $user) use ($app): Request {
    Auth::forgetGuards();
    $req = Request::create('/api/community-posts');
    $req->setUserResolver(fn () => $user);
    $app->instance('request', $req);
    if ($user) Auth::guard('sanctum')->setUser($user);
    return $req;
};

DB::beginTransaction();
try {
    $template = User::query()->firstOrFail();
    $channelTemplate = Channel::query()->firstOrFail();
    $makeUser = function (string $name) use ($template, $channelTemplate, &$tokens): User {
        $suffix = strtolower($name).'-'.bin2hex(random_bytes(4));
        $user = $template->replicate();
        $user->forceFill(['name' => 'Image API '.$name, 'username' => 'image-api-'.$suffix, 'password' => 'image-api-fixture-not-a-real-password', 'remember_token' => null,
            'email' => $suffix.'@example.invalid', 'avatar_path' => null,
            'settings' => ['role' => 'creator', 'status' => 'active', 'preferences' => ['publicProfile' => true, 'privateMessages' => 'anyone']]])->save();
        $channel = $channelTemplate->replicate();
        $channel->forceFill(['user_id' => $user->id, 'slug' => 'image-api-'.$suffix, 'name' => $user->name,
            'avatar_path' => null, 'cover_path' => null])->save();
        $web = $user->createToken('image-api-fixture', ['*'], now()->addHour());
        $native = $user->createToken('playhub-native', ['*'], now()->addHour());
        NativeDeviceSession::query()->create(['user_id' => $user->id, 'personal_access_token_id' => $native->accessToken->id,
            'client_type' => 'mobile', 'platform' => 'android', 'device_name' => 'Image API fixture',
            'device_identifier_hash' => hash('sha256', $suffix), 'last_seen_at' => now(), 'expires_at' => now()->addHour()]);
        $tokens[$user->id] = ['web' => $web->plainTextToken, 'native' => $native->plainTextToken];
        return $user->fresh('channel');
    };
    $author = $makeUser('Author');
    $recipient = $makeUser('Recipient');
    $outsider = $makeUser('Outsider');
    $video = Video::query()->firstOrFail()->replicate();
    $video->forceFill(['user_id' => $author->id, 'channel_id' => $author->channel->id, 'slug' => 'image-api-'.Str::uuid(),
        'visibility' => 'public', 'status' => 'ready', 'approval_status' => 'approved', 'scheduled_at' => null,
        'video_path' => 'external:image-api-fixture', 'thumbnail_path' => null, 'captions_path' => null, 'renditions' => null,
        'original_thumbnail_url' => null, 'thumbnail_source_url' => null, 'thumbnail_source_version' => null, 'comments_count' => 0])->save();
    $asset = function (User $owner, string $purpose, int $target, string $status = 'ready'): ImageUpload {
        $id = (string) Str::uuid();
        $upload = ImageUpload::query()->create(['id' => $id, 'user_id' => $owner->id, 'purpose' => $purpose,
            'target_id' => $target, 'status' => $status, 'content_hash' => hash('sha256', $id),
            'name' => 'fixture.png', 'mime' => 'image/png', 'size' => 1200, 'width' => 640, 'height' => 360,
            'expires_at' => now()->addDay()]);
        foreach (['directupload' => ['https://s1.directupload.eu/images/261105/'.$id.'.png', 1], 'fastpic' => ['https://i.fastpic.org/'.$id.'.png', 2]] as $provider => [$url, $priority]) {
            ImageUploadMirror::query()->create(['image_upload_id' => $id, 'provider_id' => $provider,
                'source_version' => $upload->content_hash, 'image_url' => $url, 'priority' => $priority, 'status' => 'available']);
        }
        return $upload;
    };

    $postImage = $asset($author, 'community_post', $author->channel->id);
    [$status, $json] = $request('POST', '/api/channels/'.$author->channel->slug.'/community-posts', $author, ['image_ids' => [$postImage->id]]);
    apiCheck($status === 201 && ($json['post']['type'] ?? null) === 'image', 'image-only community post publishes from ready IDs');
    $post = CommunityPost::query()->findOrFail($json['post']['id']);
    apiCheck($postImage->fresh()->status === 'attached' && $postImage->fresh()->attachable_type === CommunityPost::class, 'post row and asset claim share the transaction');
    apiCheck(count($json['post']['attachments'][0]['image_candidates'] ?? []) === 2 && ! isset($json['post']['attachments'][0]['path']), 'community payload returns external candidates without local paths');
    $eventImage = $asset($author, 'community_post', $author->channel->id);
    [$status, $json] = $request('POST', '/api/channels/'.$author->channel->slug.'/community-posts', $author, ['type' => 'event', 'event_title' => 'Image fixture event', 'event_starts_at' => now()->addDay()->toIso8601String(), 'image_ids' => [$eventImage->id]]);
    apiCheck($status === 201 && ($json['post']['type'] ?? null) === 'event' && count($json['post']['attachments'] ?? []) === 1, 'event cover accepts the same ready image reference');

    $commentImage = $asset($author, 'video_comment', $video->id);
    [$status, $json] = $request('POST', '/api/videos/'.$video->slug.'/comments', $author, ['image_ids' => [$commentImage->id]]);
    apiCheck($status === 201 && ($json['comment']['body'] ?? null) === '', 'image-only video comment is valid');
    apiCheck(count($json['comment']['attachments'][0]['image_candidates'] ?? []) === 2, 'video comment preserves candidate metadata');
    $rootComment = Comment::query()->findOrFail($json['comment']['id']);
    $count = Comment::withoutGlobalScopes()->count();
    [$status] = $request('POST', '/api/videos/'.$video->slug.'/comments', $author, ['image_ids' => [$commentImage->id]]);
    apiCheck($status === 422 && Comment::withoutGlobalScopes()->count() === $count, 'attached ID cannot create a second comment');
    foreach (['uploading', 'failed', 'abandoned'] as $badStatus) {
        $bad = $asset($author, 'video_comment', $video->id, $badStatus);
        [$status] = $request('POST', '/api/videos/'.$video->slug.'/comments', $author, ['body' => 'Keep this draft', 'image_ids' => [$bad->id]]);
        apiCheck($status === 422 && Comment::withoutGlobalScopes()->count() === $count, $badStatus.' image prevents partial comment publication');
    }
    $foreign = $asset($recipient, 'video_comment', $video->id);
    [$status] = $request('POST', '/api/videos/'.$video->slug.'/comments', $author, ['image_ids' => [$foreign->id]]);
    apiCheck($status === 422 && $foreign->fresh()->status === 'ready', 'foreign owner cannot claim an image ID');
    $wrongPurpose = $asset($author, 'community_post', $author->channel->id);
    [$status] = $request('POST', '/api/videos/'.$video->slug.'/comments', $author, ['image_ids' => [$wrongPurpose->id]]);
    apiCheck($status === 422, 'ready ID for another purpose is rejected');
    $expired = $asset($author, 'video_comment', $video->id);
    $expired->update(['expires_at' => now()->subSecond()]);
    [$status] = $request('POST', '/api/videos/'.$video->slug.'/comments', $author, ['image_ids' => [$expired->id]]);
    apiCheck($status === 422, 'expired ready ID is rejected');
    $duplicate = $asset($author, 'video_comment', $video->id);
    [$status] = $request('POST', '/api/videos/'.$video->slug.'/comments', $author, ['image_ids' => [$duplicate->id, $duplicate->id]]);
    apiCheck($status === 422 && $duplicate->fresh()->status === 'ready', 'duplicate IDs do not claim assets');
    [$status, $json] = $request('POST', '/api/videos/'.$video->slug.'/comments', $author, ['body' => 'Legacy request', 'images' => ['not-a-file']]);
    apiCheck($status === 422 && str_contains(json_encode($json), 'image_ids'), 'legacy images field has a clear migration error');

    $racing = $asset($author, 'video_comment', $video->id);
    $race = new AttachmentApiRace($network, app(Hosters::class));
    $race->afterValidation = fn () => $racing->update(['status' => 'abandoned']);
    $app->instance(ImageUploads::class, $race);
    [$status] = $request('POST', '/api/videos/'.$video->slug.'/comments', $author, ['image_ids' => [$racing->id]]);
    apiCheck($status === 422 && Comment::withoutGlobalScopes()->count() === $count, 'cancellation between validation and binding rolls back the new row');
    $app->forgetInstance(ImageUploads::class);

    $communityCommentImage = $asset($recipient, 'community_comment', $post->id);
    [$status, $json] = $request('POST', '/api/community-posts/'.$post->id.'/comments', $recipient, ['image_ids' => [$communityCommentImage->id]]);
    apiCheck($status === 201 && count($json['comment']['attachments'][0]['image_candidates'] ?? []) === 2, 'image-only community comment uses post-bound IDs');

    $private = $asset($author, 'private_message', $recipient->id);
    [$status, $json] = $request('POST', '/api/messages', $author, ['recipient_id' => $recipient->id, 'image_ids' => [$private->id]]);
    apiCheck($status === 201 && count($json['chat_message']['attachments'][0]['image_candidates'] ?? []) === 2, 'legacy image-only private message publishes from IDs');
    $conversation = $json['conversation']['id'];
    [$status, $json] = $request('GET', '/api/messages/'.$conversation, $recipient);
    apiCheck($status === 200 && count($json['conversation']['messages'][0]['attachments'][0]['image_candidates'] ?? []) === 2, 'recipient sees private external image candidates');
    [$status, $json] = $request('GET', '/api/messages/'.$conversation, $outsider);
    apiCheck($status === 404 && ! str_contains(json_encode($json), $private->id), 'outsider cannot read conversation image references');
    [$status, $json] = $request('GET', '/api/messages/'.$conversation);
    apiCheck($status === 401 && ! str_contains(json_encode($json), $private->id), 'anonymous message read exposes no references');
    $refused = $asset($author, 'private_message', $recipient->id);
    $recipientSettings = $recipient->settings;
    $disabledSettings = $recipientSettings;
    $disabledSettings['preferences']['privateMessages'] = 'disabled';
    $recipient->update(['settings' => $disabledSettings]);
    $messageCount = DB::table('private_messages')->count();
    [$status] = $request('POST', '/api/messages/'.$conversation.'/messages', $author, ['image_ids' => [$refused->id]]);
    apiCheck($status === 403 && $refused->fresh()->status === 'ready' && DB::table('private_messages')->count() === $messageCount, 'recipient refusal prevents image claim and message creation');
    $recipient->update(['settings' => $recipientSettings]);

    $nativeImage = $asset($author, 'private_message', $recipient->id);
    [$status, $json] = $request('POST', '/api/v1/messages', $author, ['recipient_id' => $recipient->id, 'image_ids' => [$nativeImage->id]], true);
    apiCheck($status === 201 && ($json['chat_message']['attachments'][0]['mime_type'] ?? '') === 'image/png', 'native private messages preserve metadata aliases');
    apiCheck(count($json['chat_message']['attachments'][0]['image_candidates'] ?? []) === 2, 'native private message serializer keeps pathless attachments');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $request('GET', '/api/messages/'.$conversation, $recipient);
    $assetQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from `image_uploads`'))->count();
    DB::disableQueryLog();
    apiCheck($assetQueries === 1, 'legacy message collection batches image metadata in one asset query');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $request('GET', '/api/v1/messages/'.$conversation.'/messages', $recipient, [], true);
    $assetQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from `image_uploads`'))->count();
    DB::disableQueryLog();
    apiCheck($assetQueries === 1, 'native message collection batches image metadata in one asset query');
    [$status, $json] = $request('GET', '/api/v1/image-uploads/'.$nativeImage->id, $author, [], true);
    apiCheck($status === 200 && ($json['status'] ?? '') === 'attached', 'native image route inherits authenticated publishing scope');
    [$status] = $request('GET', '/api/v1/image-uploads/'.$nativeImage->id, $outsider, [], true);
    apiCheck($status === 404, 'native image status is owner-only');
    $nativeRaw = '/api/v1/image-uploads/'.Str::uuid().'?purpose=community_post&target_id='.$author->channel->id;
    [$status] = $request('PUT', $nativeRaw, $author, [], true, 'invalid image bytes');
    apiCheck($status === 422, 'authenticated native raw uploads validate image content before hoster access');
    [$status] = $request('PUT', $nativeRaw, $author, [], true, str_repeat('x', 8 * 1048576 + 1));
    apiCheck($status === 413, 'authenticated native uploads enforce the eight MiB limit before hoster access');
    $nativeComment = $asset($recipient, 'video_comment', $video->id);
    [$status, $json] = $request('POST', '/api/v1/videos/'.$video->slug.'/comments', $recipient, ['image_ids' => [$nativeComment->id]], true);
    apiCheck($status === 201 && count($json['comment']['attachments'][0]['image_candidates'] ?? []) === 2 && ($json['comment']['attachments'][0]['size_bytes'] ?? 0) === 1200, 'native comment delegates atomic claim and preserves aliases');

    $post->update(['sale_enabled' => true, 'price_cents' => 100]);
    $locked = (new CommunityPostResource($post->fresh(['user', 'channel'])))->resolve($viewerRequest($recipient));
    apiCheck(($locked['attachments'][0]['url'] ?? null) === null && ($locked['attachments'][0]['image_candidates'] ?? null) === [], 'unpaid viewer receives no paid image URLs or candidates');
    $allowed = (new CommunityPostResource($post->fresh(['user', 'channel'])))->resolve($viewerRequest($author));
    apiCheck(count($allowed['attachments'][0]['image_candidates'] ?? []) === 2, 'paid post owner still receives image candidates');

    $childImage = $asset($recipient, 'video_comment', $video->id);
    [$status, $json] = $request('POST', '/api/videos/'.$video->slug.'/comments', $recipient, ['parent_id' => $rootComment->id, 'image_ids' => [$childImage->id]]);
    $childId = $json['comment']['id'];
    $grandImage = $asset($outsider, 'video_comment', $video->id);
    [$status, $json] = $request('POST', '/api/videos/'.$video->slug.'/comments', $outsider, ['parent_id' => $childId, 'image_ids' => [$grandImage->id]]);
    $grandId = $json['comment']['id'];
    $loadedThread = $rootComment->fresh(['user.channel', 'replies.user.channel', 'replies.replies.user.channel']);
    $renderRequest = $viewerRequest($author);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $threadPayload = CommentResource::collection(collect([$loadedThread]))->resolve($renderRequest);
    $assetQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from `image_uploads`'))->count();
    DB::disableQueryLog();
    apiCheck($assetQueries === 1 && count($threadPayload[0]['replies'][0]['replies'][0]['attachments'][0]['image_candidates'] ?? []) === 2, 'nested loaded replies share a single batched image lookup');
    $postsPage = CommunityPost::query()->whereKey([$post->id, $eventImage->fresh()->attachable_id])->with(['user', 'channel'])->paginate(10);
    $renderRequest = $viewerRequest($author);
    DB::enableQueryLog();
    DB::flushQueryLog();
    CommunityPostResource::collection($postsPage)->resolve($renderRequest);
    $assetQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from `image_uploads`'))->count();
    DB::disableQueryLog();
    apiCheck($assetQueries === 1, 'paginated community resources batch image lookups rather than serializing the paginator early');
    DB::table('user_blocks')->insert(['user_id' => $author->id, 'blocked_user_id' => $outsider->id, 'created_at' => now(), 'updated_at' => now()]);
    [$status] = $request('DELETE', '/api/comments/'.$rootComment->id, $author);
    apiCheck($status === 200 && ! Comment::withoutGlobalScopes()->find($grandId), 'thread deletion includes a blocked nested reply');
    apiCheck($commentImage->fresh()->status === 'detached' && $childImage->fresh()->status === 'detached' && $grandImage->fresh()->status === 'detached', 'recursive deletion detaches all descendant image references');
    [$status] = $request('DELETE', '/api/community-posts/'.$post->id, $author);
    apiCheck($status === 200 && $postImage->fresh()->status === 'detached' && $communityCommentImage->fresh()->status === 'detached', 'post deletion detaches post and comment image references');

    $toDelete = $makeUser('DeletedAccount');
    $accountPost = CommunityPost::query()->create(['user_id' => $toDelete->id, 'channel_id' => $toDelete->channel->id, 'type' => 'text', 'body' => 'Deletion fixture']);
    $accountImage = $asset($recipient, 'community_comment', $accountPost->id);
    [$status] = $request('POST', '/api/community-posts/'.$accountPost->id.'/comments', $recipient, ['image_ids' => [$accountImage->id]]);
    apiCheck($status === 201, 'account deletion fixture contains another author image');
    app(AccountDeletionService::class)->permanentlyDelete($toDelete);
    apiCheck(! User::query()->find($toDelete->id) && $accountImage->fresh()->status === 'detached', 'account cascade detaches surviving other-user attachments');
    apiCheck($network->calls === [], 'publishing, reading and detaching use no image network transfers');
} catch (Throwable $exception) {
    $failed++;
    echo 'FAIL unexpected '.get_class($exception).': '.$exception->getMessage()."\n";
} finally {
    DB::rollBack();
}
echo "Image attachment API checks: $passed pass, $failed fail\n";
exit($failed ? 1 : 0);
