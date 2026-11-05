<?php

namespace App\Services\Api\V1;

use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\Like;
use App\Models\UploadSession;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Video;
use App\Support\MediaStorage;
use App\Support\Images\ImageUploads;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AccountDeletionService
{
    public function permanentlyDelete(User $user): void
    {
        $paths = collect();
        $uploadSessionUuids = collect();

        DB::transaction(function () use ($user, &$paths, &$uploadSessionUuids): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $lockedUser->loadMissing(['channel', 'videos', 'liveStreams']);

            $videoIds = $lockedUser->videos->pluck('id');
            $communityPosts = CommunityPost::withoutGlobalScopes()
                ->where('user_id', $lockedUser->id)
                ->get(['id', 'attachments']);
            $communityPostIds = $communityPosts->pluck('id');

            $paths->push($lockedUser->avatar_path, $lockedUser->channel?->avatar_path, $lockedUser->channel?->cover_path);
            foreach ($lockedUser->videos as $video) {
                $paths->push($video->video_path, $video->thumbnail_path, $video->captions_path);
                $paths->push(...collect($video->renditions ?: [])->pluck('path')->all());
            }
            foreach ($lockedUser->liveStreams as $stream) {
                $recording = (array) data_get($stream->stream_settings, '_recording', []);
                $paths->push($stream->thumbnail_path, $recording['local_path'] ?? null, $recording['media_path'] ?? null);
            }
            foreach ($communityPosts as $post) {
                app(ImageUploads::class)->detach($post->attachments ?: []);
                $paths->push(...collect($post->attachments ?: [])->pluck('path')->all());
            }

            $comments = $this->commentsRemovedWith($lockedUser, $videoIds, $communityPostIds);
            foreach ($comments as $comment) {
                app(ImageUploads::class)->detach($comment->attachments ?: []);
                $paths->push(...collect($comment->attachments ?: [])->pluck('path')->all());
            }

            $likedTargets = Like::query()
                ->where('user_id', $lockedUser->id)
                ->get(['likeable_type', 'likeable_id']);
            $subscribedChannelIds = DB::table('subscriptions')
                ->where('user_id', $lockedUser->id)
                ->pluck('channel_id');
            $uploadSessionUuids = UploadSession::query()
                ->where('user_id', $lockedUser->id)
                ->pluck('uuid');

            $this->collectPrivateMessagePaths($lockedUser->id, $paths);

            // Retain the database references if the cloud refuses deletion, so
            // the operation can be retried instead of orphaning private media.
            MediaStorage::delete($paths
                ->filter(fn ($path): bool => $this->isManagedPath($path))
                ->unique()->values()->all());

            $this->deleteLikesFor(Video::class, $videoIds);
            $this->deleteLikesFor(CommunityPost::class, $communityPostIds);
            $this->deleteLikesFor(Comment::class, $comments->pluck('id'));
            if ($comments->isNotEmpty()) {
                Comment::withoutGlobalScopes()->whereKey($comments->pluck('id'))->delete();
            }

            // Notifications can retain actor-authored excerpts after actor_id is nulled by its FK.
            UserNotification::query()->where('actor_id', $lockedUser->id)->delete();
            DB::table('contact_messages')->where('user_id', $lockedUser->id)->delete();
            DB::table('tv_device_authorizations')->where('approved_by_user_id', $lockedUser->id)->delete();
            $lockedUser->tokens()->delete();
            DB::table('sessions')->where('user_id', $lockedUser->id)->delete();
            DB::table('password_reset_tokens')->where('email', $lockedUser->email)->delete();
            $lockedUser->delete();

            $this->recountLikes($likedTargets);
            $this->recountComments($comments);
            $this->recountSubscriptions($subscribedChannelIds);
        }, 3);

        foreach ($uploadSessionUuids as $uuid) {
            foreach (array_unique([config('playhub.video_disk', 'public'), 'public', 'local']) as $disk) {
                try {
                    Storage::disk($disk)->deleteDirectory('videos/chunks/'.$uuid);
                } catch (\Throwable) {
                    // The account is deleted even when a stale storage backend is unavailable.
                }
            }
        }
    }

    private function commentsRemovedWith(User $user, Collection $videoIds, Collection $communityPostIds): Collection
    {
        $comments = Comment::withoutGlobalScopes()
            ->where(function ($query) use ($user, $videoIds, $communityPostIds): void {
                $query->where('user_id', $user->id)
                    ->when($videoIds->isNotEmpty(), fn ($inner) => $inner->orWhereIn('video_id', $videoIds))
                    ->when($communityPostIds->isNotEmpty(), fn ($inner) => $inner
                        ->orWhere(fn ($postComments) => $postComments
                            ->where('commentable_type', CommunityPost::class)
                            ->whereIn('commentable_id', $communityPostIds)));
            })
            ->get(['id', 'user_id', 'video_id', 'commentable_type', 'commentable_id', 'parent_id', 'attachments']);

        $knownIds = $comments->pluck('id')->flip();
        $frontier = $comments->pluck('id');
        while ($frontier->isNotEmpty()) {
            $children = Comment::withoutGlobalScopes()
                ->whereIn('parent_id', $frontier)
                ->get(['id', 'user_id', 'video_id', 'commentable_type', 'commentable_id', 'parent_id', 'attachments'])
                ->reject(fn (Comment $comment): bool => $knownIds->has($comment->id));
            if ($children->isEmpty()) {
                break;
            }

            foreach ($children as $child) {
                $knownIds->put($child->id, true);
            }
            $comments = $comments->concat($children);
            $frontier = $children->pluck('id');
        }

        return $comments->unique('id')->values();
    }

    private function collectPrivateMessagePaths(int $userId, Collection $paths): void
    {
        $conversationIds = DB::table('private_conversations')
            ->where('user_one_id', $userId)
            ->orWhere('user_two_id', $userId)
            ->pluck('id');
        if ($conversationIds->isEmpty()) {
            return;
        }

        DB::table('private_messages')->whereIn('conversation_id', $conversationIds)
            ->orderBy('id')
            ->select(['id', 'attachments'])
            ->chunkById(200, function ($messages) use ($paths): void {
                foreach ($messages as $message) {
                    $attachments = is_string($message->attachments)
                        ? json_decode($message->attachments, true)
                        : $message->attachments;
                    app(ImageUploads::class)->detach(is_array($attachments) ? $attachments : []);
                    $paths->push(...collect(is_array($attachments) ? $attachments : [])->pluck('path')->all());
                }
            });
    }

    private function deleteLikesFor(string $type, Collection $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        Like::query()
            ->where('likeable_type', $type)
            ->whereIn('likeable_id', $ids)
            ->delete();
    }

    private function recountLikes(Collection $targets): void
    {
        $models = [
            Video::class => [Video::class, 'likes_count'],
            Comment::class => [Comment::class, 'likes_count'],
            CommunityPost::class => [CommunityPost::class, 'likes_count'],
        ];

        foreach ($targets->groupBy('likeable_type') as $type => $items) {
            if (! isset($models[$type])) {
                continue;
            }
            [$modelClass, $column] = $models[$type];
            foreach ($items->pluck('likeable_id')->unique() as $id) {
                $target = $modelClass::query()->lockForUpdate()->find($id);
                if ($target) {
                    $target->forceFill([$column => Like::query()
                        ->where('likeable_type', $type)
                        ->where('likeable_id', $id)
                        ->count()])->save();
                }
            }
        }
    }

    private function recountComments(Collection $comments): void
    {
        foreach ($comments->pluck('video_id')->filter()->unique() as $videoId) {
            $video = Video::query()->lockForUpdate()->find($videoId);
            if ($video) {
                $video->forceFill(['comments_count' => Comment::query()->where('video_id', $videoId)->count()])->save();
            }
        }

        $postIds = $comments
            ->where('commentable_type', CommunityPost::class)
            ->pluck('commentable_id')
            ->filter()
            ->unique();
        foreach ($postIds as $postId) {
            $post = CommunityPost::query()->lockForUpdate()->find($postId);
            if ($post) {
                $post->forceFill(['comments_count' => Comment::query()
                    ->where('commentable_type', CommunityPost::class)
                    ->where('commentable_id', $postId)
                    ->count()])->save();
            }
        }
    }

    private function recountSubscriptions(Collection $channelIds): void
    {
        foreach ($channelIds->unique() as $channelId) {
            $channel = Channel::query()->lockForUpdate()->find($channelId);
            if ($channel) {
                $channel->forceFill(['subscribers_count' => DB::table('subscriptions')
                    ->where('channel_id', $channelId)
                    ->count()])->save();
            }
        }
    }

    private function isManagedPath(mixed $path): bool
    {
        return is_string($path)
            && trim($path) !== ''
            && ! str_starts_with($path, '/')
            && ! str_starts_with($path, 'external:')
            && ! preg_match('/^(?:https?:)?\/\//i', $path)
            && ! preg_match('#(?:^|/)\.\.(?:/|$)#', str_replace('\\', '/', $path));
    }
}
