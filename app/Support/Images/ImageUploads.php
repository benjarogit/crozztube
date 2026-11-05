<?php

namespace App\Support\Images;

use App\Models\Channel;
use App\Models\CommunityPost;
use App\Models\ImageUpload;
use App\Models\ImageUploadMirror;
use App\Models\ThumbnailProvider;
use App\Models\User;
use App\Models\Video;
use App\Services\Api\V1\CommunityAccessService;
use App\Services\Api\V1\VideoAccessService;
use App\Support\Thumbnails\Hosters;
use App\Support\Thumbnails\NightWindow;
use App\Support\Thumbnails\RemoteImages;
use App\Support\Thumbnails\ThumbnailService;
use App\Support\UserBlocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** URL/metadata persistence only. Image bytes exist only in the current bounded transfer. */
class ImageUploads
{
    public const PURPOSES = ['community_post', 'community_comment', 'video_comment', 'private_message'];

    public const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private RemoteImages $http, private Hosters $hosters) {}

    public static function maxBytes(string $purpose): int
    {
        return ($purpose === 'community_post' ? 8 : 5) * 1048576;
    }

    /** Called before reading the HTTP body, and again immediately before publication. */
    public function assertTarget(User $user, string $purpose, int|string $targetId): void
    {
        if (! in_array($purpose, self::PURPOSES, true) || ! ctype_digit((string) $targetId) || (int) $targetId <= 0) {
            throw ValidationException::withMessages(['purpose' => 'Select a valid image destination.']);
        }
        $status = strtolower(str_replace(['_', '-'], ' ', trim((string) data_get($user->settings, 'status', 'active'))));
        abort_if(in_array($status, ['suspended', 'deleted'], true), 403, 'This account is suspended.');
        abort_if($user->isBannedFrom('account'), 403, $user->banMessage('account'));
        if ($purpose === 'community_post') {
            $channel = Channel::findOrFail($targetId);
            abort_unless((int) $channel->user_id === (int) $user->id, 403, 'Only the channel owner can publish its images.');
            return;
        }
        if ($purpose === 'video_comment' || $purpose === 'community_comment') {
            abort_if($user->isBannedFrom('comments'), 403, $user->banMessage('comments'));
            if ($purpose === 'video_comment') {
                $target = Video::findOrFail($targetId);
                app(VideoAccessService::class)->assertViewable($target, $user);
            } else {
                $target = CommunityPost::findOrFail($targetId);
                app(CommunityAccessService::class)->assertVisible($target, $user);
            }
            UserBlocks::assertInteraction($user, (int) $target->user_id);
            return;
        }
        $recipient = User::with('channel')->findOrFail($targetId);
        abort_if((int) $recipient->id === (int) $user->id, 403, 'This user cannot receive private messages.');
        UserBlocks::assertInteraction($user, (int) $recipient->id);
        $recipientStatus = strtolower(str_replace(['_', '-'], ' ', trim((string) data_get($recipient->settings, 'status', 'active'))));
        abort_if(in_array($recipientStatus, ['suspended', 'deleted'], true) || $recipient->isBannedFrom('account'), 403, 'This user cannot receive private messages.');
        $requested = data_get($recipient->settings, 'preferences.privateMessages', 'anyone');
        $policy = in_array($requested, ['anyone', 'subscribers', 'disabled'], true) ? $requested : 'anyone';
        abort_if($policy === 'disabled', 403, 'This user has disabled private messages.');
        if ($policy === 'subscribers') {
            $subscribed = $recipient->channel && DB::table('subscriptions')->where('user_id', $user->id)
                ->where('channel_id', $recipient->channel->id)->exists();
            abort_unless($subscribed, 403, 'This user only accepts private messages from subscribers.');
        }
    }

    /** A reservation makes cancellation effective even while a raw body is still arriving. */
    public function reserve(User $user, string $id, string $purpose, int|string $targetId): ImageUpload
    {
        $id = strtolower($id);
        abort_unless(Str::isUuid($id), 404);
        $this->assertTarget($user, $purpose, $targetId);
        ImageUpload::query()->insertOrIgnore([
            'id' => $id, 'user_id' => $user->id, 'purpose' => $purpose, 'target_id' => $targetId,
            'status' => 'uploading', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $upload = $this->owned($user, $id);
        abort_if(in_array($upload->status, ['abandoned', 'detached'], true), 409, 'This image selection has been cancelled. Select the file again.');
        abort_unless($upload->purpose === $purpose && (int) $upload->target_id === (int) $targetId, 409, 'This image identifier belongs to a different destination.');
        abort_if($upload->status !== 'attached' && $upload->expires_at->isPast(), 410, 'This image selection has expired. Select the file again.');
        return $upload;
    }

    public function owned(User $user, string $id): ImageUpload
    {
        return ImageUpload::where('user_id', $user->id)->whereKey(strtolower($id))->firstOrFail();
    }

    /** Idempotent UUID + content hash, globally serialized against thumbnail transfers. */
    public function receive(User $user, string $id, string $purpose, int|string $targetId, string $bytes, string $name = '', ?string $declaredMime = null): ImageUpload
    {
        $id = strtolower($id);
        $upload = $this->reserve($user, $id, $purpose, $targetId);
        $uuidLock = Cache::lock('image-uploads:'.$id, 90);
        abort_unless($uuidLock->get(), 409, 'This image upload is still in progress.');
        try {
            try {
                $image = RemoteImages::inspect($bytes, self::maxBytes($purpose));
                if (! in_array($image['type'], self::TYPES, true) || ($declaredMime !== null && $declaredMime !== $image['type'])) {
                    throw new RuntimeException('invalid-image');
                }
            } catch (RuntimeException) {
                ImageUpload::whereKey($id)->where('status', 'uploading')->update(['status' => 'failed', 'last_result' => 'invalid-image']);
                throw ValidationException::withMessages(['image' => 'Select a valid JPEG, PNG, WebP or GIF within the image size limit.']);
            }
            $hash = hash('sha256', $bytes);
            $upload = DB::transaction(function () use ($user, $id, $hash, $name, $bytes, $image): ImageUpload {
                $current = ImageUpload::whereKey($id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                abort_if(in_array($current->status, ['abandoned', 'detached'], true), 409, 'This image selection has been cancelled.');
                abort_if($current->content_hash && ! hash_equals($current->content_hash, $hash), 409, 'Use a new image identifier for a different file.');
                if (in_array($current->status, ['ready', 'attached'], true)) return $current;
                $safeName = mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f\\\\\/]/u', '-', mb_convert_encoding($name, 'UTF-8', 'UTF-8'))), 0, 180);
                $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$image['type']];
                $current->forceFill(['content_hash' => $hash, 'name' => $safeName ?: 'image.'.$extension,
                    'mime' => $image['type'], 'size' => strlen($bytes), 'width' => $image['width'], 'height' => $image['height'],
                    'status' => 'uploading', 'last_result' => null])->save();
                return $current;
            });
            if (in_array($upload->status, ['ready', 'attached'], true)) return $upload;
            $transfer = Cache::lock('thumbnails:transfer', 90);
            if (! $transfer->get()) {
                $this->failUpload($upload, 'transfer-busy');
                abort(503, 'Image hosting is busy. Try the same file again shortly.');
            }
            try {
                $deadline = microtime(true) + min(10.0, (float) config('thumbnails.import_budget', 10));
                $this->ensureMirrors($upload);
                foreach (array_keys(ThumbnailService::providers()) as $providerId) {
                    if (microtime(true) >= $deadline - 0.05) break;
                    if ($this->owned($user, $id)->status === 'abandoned') break;
                    $mirror = $upload->mirrors()->where('provider_id', $providerId)->first();
                    if ($mirror && $this->copyBytes($mirror, $image, $deadline)) {
                        // A racing DELETE wins: never resurrect a cancelled draft.
                        ImageUpload::whereKey($id)->where('status', 'uploading')->update(['status' => 'ready', 'last_result' => 'hosted', 'updated_at' => now()]);
                        return $this->owned($user, $id);
                    }
                }
                $this->failUpload($upload, 'hosting-unavailable');
                return $this->owned($user, $id);
            } finally {
                $transfer->release();
            }
        } finally {
            $uuidLock->release();
        }
    }

    public function abandon(User $user, string $id): ImageUpload
    {
        $id = strtolower($id);
        abort_unless(Str::isUuid($id), 404);
        return DB::transaction(function () use ($user, $id): ImageUpload {
            // Unknown IDs are tombstoned for this owner, covering DELETE-before-PUT races.
            ImageUpload::query()->insertOrIgnore(['id' => $id, 'user_id' => $user->id, 'status' => 'abandoned',
                'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
            $upload = ImageUpload::whereKey($id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            abort_if($upload->status === 'attached', 409, 'Published images must be removed from their post, comment or message.');
            $upload->forceFill(['status' => 'abandoned', 'last_result' => 'cancelled', 'expires_at' => now()->addDay()])->save();
            return $upload;
        });
    }

    /** Validation performs no writes or provider requests. */
    public function validateIds(User $user, string $purpose, int|string $targetId, array $ids): array
    {
        $this->assertTarget($user, $purpose, $targetId);
        $this->assertIds($ids);
        $ids = array_map('strtolower', $ids);
        if (! $ids) return [];
        $uploads = ImageUpload::with('mirrors.provider')->where('user_id', $user->id)->whereIn('id', $ids)->get()->keyBy('id');
        $ordered = [];
        foreach ($ids as $id) {
            $upload = $uploads->get($id);
            $this->assertReady($upload, $purpose, $targetId);
            $ordered[] = $upload;
        }
        return $ordered;
    }

    /** The publisher's enclosing transaction covers the content row and these claims together. */
    public function bind(User $user, string $purpose, int|string $targetId, array $ids, Model|string $attachable, int|string|null $attachableId = null): array
    {
        $this->assertIds($ids);
        $ids = array_map('strtolower', $ids);
        $type = $attachable instanceof Model ? $attachable->getMorphClass() : $attachable;
        $parentId = $attachable instanceof Model ? $attachable->getKey() : $attachableId;
        if (! $parentId) throw new RuntimeException('An image can only be attached to a persisted content record.');
        return DB::transaction(function () use ($user, $purpose, $targetId, $ids, $type, $parentId): array {
            if ($purpose === 'private_message') UserBlocks::lockPair((int) $user->id, (int) $targetId);
            $this->assertTarget($user, $purpose, $targetId);
            $uploads = ImageUpload::where('user_id', $user->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $attachments = [];
            foreach ($ids as $id) {
                $upload = $uploads->get($id);
                $sameParent = $upload && $upload->status === 'attached' && $upload->attachable_type === $type && (string) $upload->attachable_id === (string) $parentId;
                if (! $sameParent) {
                    $this->assertReady($upload, $purpose, $targetId);
                    $upload->forceFill(['status' => 'attached', 'attachable_type' => $type, 'attachable_id' => (string) $parentId, 'attached_at' => now()])->save();
                } else {
                    abort_unless($upload->purpose === $purpose && (int) $upload->target_id === (int) $targetId, 422, 'Image destination does not match.');
                }
                $attachments[] = self::attachment($upload);
            }
            self::forgetPayloads($ids);
            return $attachments;
        });
    }

    /** URLs remain at their hosters; detaching only stops local maintenance and future display. */
    public function detach(array $attachments): void
    {
        $ids = array_values(array_filter(array_column(array_filter($attachments, 'is_array'), 'image_id'), fn ($id) => is_string($id) && Str::isUuid($id)));
        $ids = array_map('strtolower', $ids);
        if (! $ids) return;
        ImageUpload::whereIn('id', $ids)->where('status', 'attached')->update(['status' => 'detached',
            'detached_at' => now(), 'expires_at' => now()->addDay(), 'updated_at' => now()]);
        self::forgetPayloads($ids);
    }

    /** Resolve references only after the parent controller has checked visibility. No network or writes. */
    public static function payload(array $attachment): array
    {
        if (empty($attachment['image_id'])) return $attachment;
        if (! is_string($attachment['image_id']) || ! Str::isUuid($attachment['image_id'])) return [];
        $attachment['image_id'] = strtolower($attachment['image_id']);
        self::prefetch([$attachment]);
        $request = self::displayRequest();
        $upload = $request ? ($request->attributes->get('image-upload-display-cache', [])[$attachment['image_id']] ?? null)
            : ImageUpload::with('mirrors.provider')->where('status', 'attached')->find($attachment['image_id']);
        return $upload ? self::attachment($upload) : [];
    }

    /** Batch once per HTTP request. Cache contains URL metadata, never bytes or another request's identity. */
    public static function prefetch(array $attachments): void
    {
        $request = self::displayRequest();
        if (! $request) return;
        $cache = $request->attributes->get('image-upload-display-cache', []);
        $ids = array_values(array_unique(array_map('strtolower', array_filter(array_column(array_filter($attachments, 'is_array'), 'image_id'),
            fn ($id) => is_string($id) && Str::isUuid($id)))));
        $ids = array_values(array_filter($ids, fn ($id) => ! array_key_exists($id, $cache)));
        if (! $ids) return;
        $uploads = ImageUpload::with('mirrors.provider')->where('status', 'attached')->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) $cache[$id] = $uploads->get($id);
        $request->attributes->set('image-upload-display-cache', $cache);
    }

    private static function displayRequest(): ?\Illuminate\Http\Request
    {
        if (! app()->bound('request')) return null;
        $request = request();
        return $request instanceof \Illuminate\Http\Request && $request->is('api/*') ? $request : null;
    }

    private static function forgetPayloads(array $ids): void
    {
        if (! ($request = self::displayRequest())) return;
        $cache = $request->attributes->get('image-upload-display-cache', []);
        foreach ($ids as $id) unset($cache[$id]);
        $request->attributes->set('image-upload-display-cache', $cache);
    }

    public static function attachment(ImageUpload $upload): array
    {
        $urls = self::candidates($upload);
        return ['type' => 'image', 'image_id' => $upload->id, 'url' => $urls[0] ?? null, 'image_candidates' => $urls,
            'name' => $upload->name, 'mime' => $upload->mime, 'size' => $upload->size, 'width' => $upload->width, 'height' => $upload->height];
    }

    public static function candidates(ImageUpload $upload): array
    {
        $upload->loadMissing('mirrors.provider');
        return $upload->mirrors->where('source_version', $upload->content_hash)->where('status', 'available')->sortBy('priority')
            ->filter(fn ($mirror) => ! $mirror->provider?->circuit_open && RemoteImages::urlAllowed($mirror->image_url))
            ->pluck('image_url')->unique()->values()->all();
    }

    /** The caller owns the shared transfer lock and enforces the common nightly budget. */
    public function copy(ImageUploadMirror $mirror, float $deadline): bool
    {
        if (! NightWindow::contains() || NightWindow::paused() || microtime(true) >= $deadline - 0.05) return false;
        $upload = $mirror->upload;
        if (! $upload || $upload->status !== 'attached' || $mirror->source_version !== $upload->content_hash) return false;
        if ($mirror->status === 'available') return true;
        try {
            if ($mirror->image_url) {
                return $this->copyBytes($mirror, [], $deadline);
            }
            foreach (self::candidates($upload) as $source) {
                if (microtime(true) >= $deadline - 0.05) break;
                try {
                    $image = $this->http->imageAtMost($source, $deadline, self::maxBytes($upload->purpose));
                    if (! $this->fullImage($image, $upload)) continue;
                    return $this->copyBytes($mirror, $image, $deadline);
                } catch (Throwable) {
                    // Only bounded metadata is kept, never source bytes or raw upstream errors.
                }
            }
            $this->failure($mirror, 'source-unavailable');
        } catch (Throwable) {
            $this->failure($mirror, 'verification-pending');
        }
        return false;
    }

    public function check(ImageUploadMirror $mirror, float $deadline): void
    {
        if (! NightWindow::contains() || NightWindow::paused() || microtime(true) >= $deadline - 0.05 || $mirror->upload?->status !== 'attached') return;
        try { $result = $this->http->probe($mirror->image_url, $deadline); }
        catch (Throwable) { $result = ['ok' => false, 'server_failure' => true, 'result' => 'network-error']; }
        if ($mirror->provider) app(ThumbnailService::class)->providerResult($mirror->provider, 'image:'.$mirror->image_upload_id,
            ['url' => $result['ok'] ? $mirror->image_url : null, ...$result]);
        if ($result['ok']) $this->success($mirror, $mirror->image_url);
        else {
            $this->failure($mirror, $result['result']);
            if (in_array($result['result'], ['http:404', 'http:410'], true)) $mirror->forceFill(['image_url' => null, 'next_check_at' => null])->save();
        }
    }

    public function cleanupDrafts(int $limit = 20): int
    {
        return DB::transaction(function () use ($limit): int {
            $rows = ImageUpload::where('status', '!=', 'attached')->where('expires_at', '<=', now())
                ->orderBy('expires_at')->limit(min(20, max(0, $limit)))->lockForUpdate()->get();
            foreach ($rows as $upload) $upload->delete();
            return $rows->count();
        });
    }

    private function ensureMirrors(ImageUpload $upload): void
    {
        foreach (config('thumbnails.providers') as $id => $provider) {
            ImageUploadMirror::firstOrCreate(['image_upload_id' => $upload->id, 'provider_id' => $id],
                ['source_version' => $upload->content_hash, 'priority' => $provider['priority'], 'status' => 'pending', 'next_attempt_at' => NightWindow::next()]);
        }
    }

    private function copyBytes(ImageUploadMirror $mirror, array $image, float $deadline): bool
    {
        $upload = ImageUpload::find($mirror->image_upload_id);
        $provider = ThumbnailProvider::find($mirror->provider_id);
        if (microtime(true) >= $deadline - 0.05 || ! $upload || ! in_array($upload->status, ['uploading', 'attached'], true) || ! $provider || ! isset(ThumbnailService::providers()[$provider->id]) || $provider->paused_until?->isFuture()) return false;
        try {
            if ($mirror->image_url) {
                $this->verify($mirror->image_url, $upload, $deadline);
                $this->success($mirror, $mirror->image_url);
                app(ThumbnailService::class)->providerResult($provider, 'image:'.$upload->id, ['url' => $mirror->image_url, 'result' => 'verified', 'server_failure' => false]);
                return true;
            }
            $same = ImageUploadMirror::with('upload')->where('provider_id', $provider->id)->where('source_version', $upload->content_hash)
                ->where('status', 'available')->where('next_check_at', '>', now())->where('image_upload_id', '!=', $upload->id)
                ->whereHas('upload', fn ($q) => $q->where('user_id', $upload->user_id)->where(fn ($s) => $s->where('status', 'attached')
                    ->orWhere(fn ($ready) => $ready->where('status', 'ready')->where('expires_at', '>', now()))))->first();
            if ($same && $same->image_url) {
                $probed = $provider->circuit_open;
                if ($probed) $this->verify($same->image_url, $upload, $deadline);
                $this->success($mirror, $same->image_url, $same->next_check_at);
                if ($probed) app(ThumbnailService::class)->providerResult($provider, 'image:'.$upload->id, ['url' => $same->image_url, 'result' => 'reused', 'server_failure' => false]);
                return true;
            }
            $result = $this->hosters->upload($provider->id, $image, $upload->content_hash, $deadline, original: true);
            if (! $result['url']) {
                if (! ($result['capability_failure'] ?? false)) app(ThumbnailService::class)->providerResult($provider, 'image:'.$upload->id, $result);
                $this->failure($mirror, $result['result']);
                return false;
            }
            // Keep the returned URL before verification: a timeout must not duplicate the upload.
            $mirror->forceFill(['image_url' => $result['url'], 'status' => 'failed', 'last_result' => 'verification-pending',
                'next_check_at' => null, 'next_attempt_at' => NightWindow::next()])->save();
            $this->verify($result['url'], $upload, $deadline);
            $this->success($mirror, $result['url']);
            app(ThumbnailService::class)->providerResult($provider, 'image:'.$upload->id, $result);
            return true;
        } catch (Throwable $exception) {
            $message = in_array($exception->getMessage(), ['timeout', 'dns-failure', 'network-error', 'quota-limit', 'full-size-unavailable', 'size-limit', 'invalid-image', 'source-unavailable'], true)
                || preg_match('/^http:\d{1,3}$/', $exception->getMessage())
                ? $exception->getMessage() : 'provider-error';
            if (in_array($message, ['full-size-unavailable', 'invalid-image', 'http:404', 'http:410'], true)) $mirror->forceFill(['image_url' => null])->save();
            $this->failure($mirror, $mirror->image_url ? 'verification-pending' : $message);
            if (in_array($message, ['timeout', 'dns-failure', 'network-error', 'http:0'], true) || (str_starts_with($message, 'http:') && (int) substr($message, 5) >= 500)) app(ThumbnailService::class)->providerResult($provider, 'image:'.$upload->id,
                ['url' => null, 'server_failure' => true, 'result' => $message]);
            if ($message === 'provider-error') $provider->forceFill(['paused_until' => now()->addMinutes(30), 'last_result' => 'invalid-response'])->save();
            return false;
        }
    }

    private function verify(string $url, ImageUpload $upload, float $deadline): void
    {
        $image = $this->http->imageAtMost($url, $deadline, self::maxBytes($upload->purpose));
        if (! $this->fullImage($image, $upload)) throw new RuntimeException('full-size-unavailable');
    }

    private function fullImage(array $image, ImageUpload $upload): bool
    {
        return in_array($image['type'], self::TYPES, true) && $image['width'] === $upload->width && $image['height'] === $upload->height;
    }

    private function success(ImageUploadMirror $mirror, string $url, mixed $nextCheck = null): void
    {
        $mirror->forceFill(['image_url' => $url, 'status' => 'available', 'last_checked_at' => now(), 'next_check_at' => $nextCheck ?: now()->addDays(7),
            'next_attempt_at' => null, 'failure_count' => 0, 'last_result' => 'ok'])->save();
    }

    private function failure(ImageUploadMirror $mirror, string $result): void
    {
        $failures = min(30, $mirror->failure_count + 1);
        $next = NightWindow::next(min(7, 2 ** min(3, $failures - 1)));
        $mirror->forceFill(['status' => 'failed', 'failure_count' => $failures, 'last_checked_at' => $mirror->image_url ? now() : $mirror->last_checked_at,
            'last_result' => $result, 'next_attempt_at' => $next, 'next_check_at' => $mirror->image_url && $result !== 'verification-pending' ? $next : null])->save();
    }

    private function failUpload(ImageUpload $upload, string $result): void
    {
        ImageUpload::whereKey($upload->id)->where('status', 'uploading')->update(['status' => 'failed', 'last_result' => $result, 'updated_at' => now()]);
    }

    private function assertIds(array $ids): void
    {
        if (count($ids) > 4 || array_filter($ids, fn ($id) => ! is_string($id) || ! Str::isUuid($id))
            || count(array_unique(array_map('strtolower', $ids))) !== count($ids)) {
            throw ValidationException::withMessages(['image_ids' => 'Choose up to four distinct uploaded image identifiers.']);
        }
    }

    private function assertReady(?ImageUpload $upload, string $purpose, int|string $targetId): void
    {
        if (! $upload || $upload->status !== 'ready' || $upload->expires_at->isPast() || $upload->purpose !== $purpose
            || (int) $upload->target_id !== (int) $targetId || ! self::candidates($upload)) {
            throw ValidationException::withMessages(['image_ids' => 'An image is unavailable, expired or belongs to a different destination. Select it again.']);
        }
    }
}
