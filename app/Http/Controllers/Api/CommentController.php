<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;
use App\Support\Images\ImageUploads;
use App\Support\KlipyStickers;
use App\Support\PlatformFeatureSettings;
use App\Support\VideoSales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    public function index(Request $request, Video $video, VideoAccessService $access): AnonymousResourceCollection
    {
        $user = $request->user() ?: $this->userFromBearerToken($request);
        $access->assertViewable($video, $user);
        $comments = $video->comments()
            ->with(['user.channel', 'sticker'])
            ->withCount('replies')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        $this->hydrateCommentState($comments->getCollection(), $user, $video->user_id);

        return CommentResource::collection($comments);
    }

    public function replies(Request $request, Comment $comment, VideoAccessService $access): JsonResponse
    {
        $user = $request->user() ?: $this->userFromBearerToken($request);
        $this->assertCommentParentVisible($comment, $user);
        $limit = min(20, max(1, (int) $request->integer('limit', 10)));
        $offset = max(0, (int) $request->integer('offset', 0));
        $total = $comment->replies()->count();
        $replies = $comment->replies()
            ->with(['user.channel', 'sticker'])
            ->skip($offset)
            ->take($limit)
            ->get();

        $this->hydrateCommentState($replies, $user, $this->commentTargetOwnerId($comment));

        return response()->json([
            'replies' => CommentResource::collection($replies)->resolve($request),
            'total' => $total,
            'next_offset' => $offset + $replies->count(),
            'has_more' => $offset + $replies->count() < $total,
        ]);
    }

    private function userFromBearerToken(Request $request): ?object
    {
        return VideoSales::viewer($request);
    }

    public function store(StoreCommentRequest $request, Video $video): JsonResponse
    {
        app(VideoAccessService::class)->assertViewable($video, $request->user());
        \App\Support\UserBlocks::assertInteraction($request->user(), (int) $video->user_id);
        abort_if($request->user()->isBannedFrom('comments'), 403, $request->user()->banMessage('comments'));
        $data = $request->validated();
        $body = PlatformFeatureSettings::filterProfanity(trim((string) ($data['body'] ?? '')));
        $sticker = app(KlipyStickers::class)->resolveSelection($data['sticker_id'] ?? null, $request);

        if (! empty($data['parent_id'])) {
            $parent = Comment::query()->whereKey($data['parent_id'])->where('video_id', $video->id)->firstOrFail();
            \App\Support\UserBlocks::assertInteraction($request->user(), (int) $parent->user_id);
            $data['parent_id'] = $parent->id;
        }

        $imageIds = $data['image_ids'] ?? [];
        app(ImageUploads::class)->validateIds($request->user(), 'video_comment', $video->id, $imageIds);

        $comment = DB::transaction(function () use ($request, $video, $data, $imageIds, $body, $sticker): Comment {
            $comment = $video->comments()->create([
                'user_id' => $request->user()->id,
                'parent_id' => $data['parent_id'] ?? null,
                'sticker_id' => $sticker?->id,
                'body' => $body,
                'attachments' => null,
            ]);
            if ($imageIds) {
                $attachments = app(ImageUploads::class)->bind($request->user(), 'video_comment', $video->id, $imageIds, $comment);
                abort_unless(count($attachments) === count($imageIds), 422, 'An image is no longer ready to publish.');
                $comment->update(['attachments' => $attachments]);
            }

            $video->increment('comments_count');

            return $comment;
        });

        $parent = $comment->parent_id ? Comment::query()->find($comment->parent_id) : null;
        if ($parent && (int) $parent->user_id !== (int) $request->user()->id
            && ! $this->notificationsMuted((int) $parent->user_id, (int) $request->user()->id)) {
            UserNotification::query()->create([
                'user_id' => $parent->user_id,
                'actor_id' => $request->user()->id,
                'video_id' => $video->id,
                'comment_id' => $comment->id,
                'type' => 'comment_reply',
                'data' => [
                    'body' => mb_substr($comment->body ?: ($sticker ? 'Sent a sticker: '.$sticker->name : ''), 0, 180),
                    'parent_id' => $parent->id,
                ],
            ]);
        }

        if ($video->user_id !== $request->user()->id
            && (int) $video->user_id !== (int) ($parent?->user_id ?? 0)
            && ! $this->notificationsMuted($video->user_id, $request->user()->id)) {
            UserNotification::query()->create([
                'user_id' => $video->user_id,
                'actor_id' => $request->user()->id,
                'video_id' => $video->id,
                'comment_id' => $comment->id,
                'type' => 'video_comment',
                'data' => ['body' => mb_substr($comment->body ?: ($sticker ? 'Sent a sticker: '.$sticker->name : ''), 0, 180)],
            ]);
        }

        $this->notifyMentionedUsers($request, $comment, $video);

        $this->setCommentPermissions($comment, $request->user(), $video->user_id);

        return response()->json([
            'message' => 'Comment published.',
            'comment' => new CommentResource($comment->load(['user.channel', 'sticker'])),
        ], 201);
    }

    public function update(UpdateCommentRequest $request, Comment $comment): JsonResponse
    {
        abort_if($request->user()->isBannedFrom('comments'), 403, $request->user()->banMessage('comments'));
        $data = $request->validated();
        $data['body'] = PlatformFeatureSettings::filterProfanity(trim((string) $data['body']));
        $comment->update($data);
        $fresh = $comment->fresh(['user.channel', 'sticker'])->loadCount('replies');
        if ($fresh->video_id) {
            $this->notifyMentionedUsers($request, $fresh, $fresh->video);
        } elseif ($fresh->commentable_type === CommunityPost::class) {
            $this->notifyMentionedUsers($request, $fresh, null, CommunityPost::query()->find($fresh->commentable_id));
        }
        $this->setCommentPermissions($fresh, $request->user(), $this->commentTargetOwnerId($fresh));

        return response()->json([
            'message' => 'Comment updated.',
            'comment' => new CommentResource($fresh),
        ]);
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->id === $comment->user_id || $this->ownsCommentTarget($user->id, $comment) || $this->canModerateComments($user)), 403);

        $thread = $comment->threadModels();
        DB::transaction(function () use ($comment, $thread): void {
            app(ImageUploads::class)->detach($thread->flatMap(fn (Comment $item) => $item->attachments ?? [])->values()->all());
            $comment->loadMissing('commentable');
            $deletedCount = $thread->count();

            if ($comment->video_id) {
                $video = $comment->video()->lockForUpdate()->first();
                $video?->update(['comments_count' => max(0, (int) $video->comments_count - $deletedCount)]);
            } elseif ($comment->commentable instanceof CommunityPost) {
                $post = CommunityPost::query()->lockForUpdate()->find($comment->commentable_id);
                $post?->update(['comments_count' => max(0, (int) $post->comments_count - $deletedCount)]);
            }

            $comment->delete();
        });

        return response()->json([
            'message' => 'Comment deleted.',
        ]);
    }

    public function togglePin(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($request->user() && $this->ownsCommentTarget($request->user()->id, $comment), 403);

        $comment->update([
            'is_pinned' => ! $comment->is_pinned,
            'pinned_at' => $comment->is_pinned ? null : now(),
            'pinned_by' => $comment->is_pinned ? null : $request->user()->id,
        ]);

        $fresh = $comment->fresh(['user.channel', 'sticker'])->loadCount('replies');
        $this->setCommentPermissions($fresh, $request->user(), $this->commentTargetOwnerId($fresh));

        return response()->json([
            'message' => $fresh->is_pinned ? 'Comment pinned.' : 'Comment unpinned.',
            'comment' => new CommentResource($fresh),
        ]);
    }

    public function report(Request $request, Comment $comment): JsonResponse
    {
        $this->assertCommentParentVisible($comment, $request->user());
        abort_unless($request->user() && $request->user()->id !== $comment->user_id, 403);

        $data = $request->validate(\App\Support\ReportReasons::rules(\App\Support\ReportReasons::COMMENT));

        DB::table('comment_reports')->updateOrInsert([
            'user_id' => $request->user()->id,
            'comment_id' => $comment->id,
        ], [
            'reason' => $data['reason'],
            'details' => \App\Support\ReportReasons::details($data['details'] ?? null),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $comment = $comment->load(['user.channel', 'sticker'])->loadCount('replies');
        $comment->reported = true;
        $this->setCommentPermissions($comment, $request->user(), $this->commentTargetOwnerId($comment));

        return response()->json([
            'message' => 'Comment reported for review.',
            'comment' => new CommentResource($comment),
        ]);
    }

    public function muteUser(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($request->user() && $request->user()->id !== $comment->user_id, 403);

        DB::table('user_mutes')->updateOrInsert([
            'user_id' => $request->user()->id,
            'muted_user_id' => $comment->user_id,
        ], [
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Notifications muted for this user.',
            'muted_user_id' => $comment->user_id,
        ]);
    }

    private function hydrateCommentState(Collection $comments, ?object $user, ?int $targetOwnerId): void
    {
        if (! $user || $comments->isEmpty()) {
            return;
        }

        $commentIds = $comments->pluck('id')->merge(
            $comments->flatMap(fn ($comment) => $comment->relationLoaded('replies') ? $comment->replies->pluck('id') : collect())
        )->values();
        $likedIds = Comment::query()
            ->whereIn('id', $commentIds)
            ->whereHas('likes', fn ($query) => $query->where('user_id', $user->id))
            ->pluck('id')
            ->all();
        $reportedIds = DB::table('comment_reports')
            ->where('user_id', $user->id)
            ->whereIn('comment_id', $commentIds)
            ->pluck('comment_id')
            ->all();

        $comments->each(function ($comment) use ($user, $targetOwnerId, $likedIds, $reportedIds): void {
            $comment->is_liked = in_array($comment->id, $likedIds, true);
            $comment->reported = in_array($comment->id, $reportedIds, true);
            $this->setCommentPermissions($comment, $user, $targetOwnerId);
            if (! $comment->relationLoaded('replies')) {
                return;
            }

            $comment->replies->each(function ($reply) use ($user, $targetOwnerId, $likedIds, $reportedIds): void {
                $reply->is_liked = in_array($reply->id, $likedIds, true);
                $reply->reported = in_array($reply->id, $reportedIds, true);
                $this->setCommentPermissions($reply, $user, $targetOwnerId);
            });
        });
    }

    private function setCommentPermissions(Comment $comment, ?object $user, ?int $targetOwnerId): void
    {
        if (! $user) {
            return;
        }

        $isCommentAuthor = (int) $comment->user_id === (int) $user->id;
        $isTargetOwner = $targetOwnerId && (int) $targetOwnerId === (int) $user->id;
        $comment->can_delete = $isCommentAuthor || $isTargetOwner || $this->canModerateComments($user);
        $comment->can_pin = $isTargetOwner;
        $comment->can_report = ! $isCommentAuthor;
        $comment->can_mute = ! $isCommentAuthor;
    }

    private function canModerateComments(object $user): bool
    {
        $settings = is_array($user->settings ?? null) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;

        return $adminEnabled || (bool) ($settings['is_admin'] ?? false) || in_array($role, ['admin', 'super admin', 'owner', 'moderator'], true);
    }

    private function commentTargetOwnerId(Comment $comment): ?int
    {
        $comment->loadMissing(['video', 'commentable', 'parent.video', 'parent.commentable']);

        if ($comment->video?->user_id) {
            return $comment->video->user_id;
        }

        if ($comment->commentable instanceof CommunityPost) {
            return $comment->commentable->user_id;
        }

        if ($comment->parent) {
            return $this->commentTargetOwnerId($comment->parent);
        }

        return null;
    }

    private function ownsCommentTarget(int $userId, Comment $comment): bool
    {
        return (int) $this->commentTargetOwnerId($comment) === $userId;
    }

    private function assertCommentParentVisible(Comment $comment, ?User $viewer): void
    {
        abort_unless(\App\Support\UserBlocks::canSee($viewer, (int) $comment->user_id), 404, 'Comment not found.');
        if ($comment->video_id) {
            app(VideoAccessService::class)->assertViewable($comment->video()->firstOrFail(), $viewer);
            return;
        }
        abort_unless($comment->commentable_type === CommunityPost::class && $comment->commentable_id, 404, 'Comment not found.');
        $post = CommunityPost::query()->findOrFail($comment->commentable_id);
        app(\App\Services\Api\V1\CommunityAccessService::class)->assertVisible($post, $viewer);
    }

    private function notifyMentionedUsers(Request $request, Comment $comment, ?Video $video = null, ?CommunityPost $communityPost = null): void
    {
        $handles = collect($this->mentionedHandles($comment->body));
        if ($handles->isEmpty() || (! $video && ! $communityPost)) {
            return;
        }

        $actorId = (int) $request->user()->id;
        $targetOwnerId = (int) ($video?->user_id ?: $communityPost?->user_id);
        User::query()
            ->with('channel')
            ->where(function ($query) use ($handles): void {
                $query->whereIn(DB::raw('LOWER(username)'), $handles->all())
                    ->orWhereHas('channel', fn ($channelQuery) => $channelQuery->whereIn(DB::raw('LOWER(slug)'), $handles->all()));
            })
            ->where('id', '!=', $actorId)
            ->when($targetOwnerId > 0, fn ($query) => $query->where('id', '!=', $targetOwnerId))
            ->get()
            ->each(function (User $user) use ($request, $video, $communityPost, $comment, $actorId): void {
                if (! $this->acceptsMentionNotifications($user) || $this->notificationsMuted((int) $user->id, $actorId)) {
                    return;
                }

                UserNotification::query()->updateOrCreate([
                    'user_id' => $user->id,
                    'actor_id' => $request->user()->id,
                    'video_id' => $video?->id,
                    'community_post_id' => $communityPost?->id,
                    'comment_id' => $comment->id,
                    'type' => 'comment_mention',
                ], [
                    'data' => ['body' => mb_substr($comment->body, 0, 180)],
                ]);
            });
    }

    private function mentionedHandles(?string $body): array
    {
        preg_match_all('/(^|[^\pL\pN_])@([A-Za-z0-9_.-]{2,40})/u', $body, $matches);

        return collect($matches[2] ?? [])
            ->map(fn ($handle) => strtolower(trim((string) $handle, '.-_')))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    private function acceptsMentionNotifications(User $user): bool
    {
        return (bool) data_get(is_array($user->settings) ? $user->settings : [], 'preferences.mentions', true);
    }

    private function notificationsMuted(int $recipientId, int $actorId): bool
    {
        return DB::table('user_mutes')
            ->where('user_id', $recipientId)
            ->where('muted_user_id', $actorId)
            ->exists();
    }
}
