<?php

namespace App\Services\Api\V1;

use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\Like;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Video;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EngagementService
{
    public function setLike(User $user, Model $likeable, string $counterColumn, bool $liked): array
    {
        $result = $this->changeLike($user, $likeable, $counterColumn, $liked);

        if ($result['changed'] && $result['liked']) {
            $this->notifyLike($user, $likeable);
        }

        return $result;
    }

    public function toggleLike(User $user, Model $likeable, string $counterColumn): array
    {
        $result = $this->changeLike($user, $likeable, $counterColumn, null);

        if ($result['changed'] && $result['liked']) {
            $this->notifyLike($user, $likeable);
        }

        return $result;
    }

    public function setSubscription(User $user, Channel $channel, bool $subscribed): array
    {
        return $this->changeSubscription($user, $channel, $subscribed);
    }

    public function toggleSubscription(User $user, Channel $channel): array
    {
        return $this->changeSubscription($user, $channel, null);
    }

    private function changeLike(User $user, Model $likeable, string $counterColumn, ?bool $desired): array
    {
        return DB::transaction(function () use ($user, $likeable, $counterColumn, $desired): array {
            $locked = $likeable->newQuery()->lockForUpdate()->findOrFail($likeable->getKey());
            $existing = Like::query()
                ->where('user_id', $user->id)
                ->where('likeable_type', $likeable::class)
                ->where('likeable_id', $likeable->getKey())
                ->lockForUpdate()
                ->first();
            $liked = $desired ?? ! $existing;
            $changed = $liked !== (bool) $existing;

            if ($changed && $liked) {
                Like::query()->create([
                    'user_id' => $user->id,
                    'likeable_type' => $likeable::class,
                    'likeable_id' => $likeable->getKey(),
                ]);
            } elseif ($changed) {
                $existing?->delete();
            }

            $count = Like::query()
                ->where('likeable_type', $likeable::class)
                ->where('likeable_id', $likeable->getKey())
                ->count();
            $locked->forceFill([$counterColumn => $count])->save();

            return ['liked' => $liked, 'likes_count' => $count, 'changed' => $changed];
        });
    }

    private function changeSubscription(User $user, Channel $channel, ?bool $desired): array
    {
        abort_if((int) $channel->user_id === (int) $user->id, 422, 'You cannot subscribe to your own channel.');

        $result = DB::transaction(function () use ($user, $channel, $desired): array {
            $locked = Channel::query()->lockForUpdate()->findOrFail($channel->id);
            $existing = Subscription::query()
                ->where('user_id', $user->id)
                ->where('channel_id', $channel->id)
                ->lockForUpdate()
                ->first();
            $subscribed = $desired ?? ! $existing;
            $changed = $subscribed !== (bool) $existing;

            if ($changed && $subscribed) {
                Subscription::query()->create(['user_id' => $user->id, 'channel_id' => $channel->id]);
            } elseif ($changed) {
                $existing?->delete();
            }

            $count = Subscription::query()->where('channel_id', $channel->id)->count();
            $locked->forceFill(['subscribers_count' => $count])->save();

            return ['subscribed' => $subscribed, 'subscribers_count' => $count, 'changed' => $changed];
        });

        if ($result['changed'] && $result['subscribed'] && ! $this->notificationsMuted((int) $channel->user_id, (int) $user->id)) {
            UserNotification::query()->updateOrCreate([
                'user_id' => $channel->user_id,
                'actor_id' => $user->id,
                'channel_id' => $channel->id,
                'type' => 'channel_subscribe',
            ], ['read_at' => null]);
        }

        return $result;
    }

    private function notifyLike(User $actor, Model $likeable): void
    {
        if ($likeable instanceof Video) {
            if ((int) $likeable->user_id === (int) $actor->id || $this->notificationsMuted((int) $likeable->user_id, (int) $actor->id)) {
                return;
            }

            UserNotification::query()->updateOrCreate([
                'user_id' => $likeable->user_id,
                'actor_id' => $actor->id,
                'video_id' => $likeable->id,
                'type' => 'video_like',
            ], [
                'data' => ['title' => mb_substr((string) $likeable->title, 0, 180)],
                'read_at' => null,
            ]);

            return;
        }

        if ($likeable instanceof Comment) {
            $likeable->loadMissing(['video', 'commentable']);
            if ((int) $likeable->user_id === (int) $actor->id || $this->notificationsMuted((int) $likeable->user_id, (int) $actor->id)) {
                return;
            }

            UserNotification::query()->updateOrCreate([
                'user_id' => $likeable->user_id,
                'actor_id' => $actor->id,
                'video_id' => $likeable->video_id,
                'community_post_id' => $likeable->commentable instanceof CommunityPost ? $likeable->commentable_id : null,
                'comment_id' => $likeable->id,
                'type' => 'comment_like',
            ], [
                'data' => ['body' => mb_substr((string) $likeable->body, 0, 180)],
                'read_at' => null,
            ]);

            return;
        }

        if ($likeable instanceof CommunityPost
            && (int) $likeable->user_id !== (int) $actor->id
            && ! $this->notificationsMuted((int) $likeable->user_id, (int) $actor->id)) {
            UserNotification::query()->updateOrCreate([
                'user_id' => $likeable->user_id,
                'actor_id' => $actor->id,
                'channel_id' => $likeable->channel_id,
                'community_post_id' => $likeable->id,
                'type' => 'community_like',
            ], ['read_at' => null]);
        }
    }

    private function notificationsMuted(int $recipientId, int $actorId): bool
    {
        return DB::table('user_mutes')
            ->where('user_id', $recipientId)
            ->where('muted_user_id', $actorId)
            ->exists();
    }
}
