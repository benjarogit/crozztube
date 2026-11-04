<?php

namespace App\Http\Resources\Api\V1;

use App\Support\VideoSales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actorName = $this->actor?->channel?->name ?: $this->actor?->name ?: config('app.name', 'PlayHub');

        return [
            'id' => (int) $this->id,
            'type' => (string) $this->type,
            'title' => $this->title($actorName),
            'body' => $this->body(),
            'unread' => $this->read_at === null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'actor' => $this->relationLoaded('actor') && $this->actor
                ? (new UserPublicResource($this->actor))->resolve($request)
                : null,
            'media' => $this->video ? [
                'video_id' => (int) $this->video->id,
                'title' => (string) $this->video->title,
                'thumbnail_url' => VideoSales::thumbnailUrl($this->video),
            ] : null,
            'action' => $this->action(),
        ];
    }

    private function title(string $actorName): string
    {
        return match ($this->type) {
            'video_ready' => 'Your video is ready',
            'live_replay_ready' => 'Live replay ready',
            'video_comment' => $actorName.' commented on your video',
            'comment_reply' => $actorName.' replied to your comment',
            'comment_mention' => $actorName.' mentioned you',
            'community_mention' => $actorName.' mentioned you',
            'video_like' => $actorName.' liked your video',
            'comment_like' => $actorName.' liked your comment',
            'channel_subscribe' => $actorName.' subscribed to your channel',
            'creator_tip' => $actorName.' sent you a tip',
            'video_purchase' => $actorName.' purchased your video',
            'community_photo_purchase' => $actorName.' purchased your photos',
            'withdrawal_approved' => 'Withdrawal approved',
            'withdrawal_rejected' => 'Withdrawal rejected',
            'community_like' => $actorName.' liked your community post',
            'community_comment' => $actorName.' commented on your community post',
            'community_poll_vote' => $actorName.' voted in your poll',
            'live_moderator' => 'You are a stream moderator',
            'admin_role_moderator' => 'You are a platform moderator',
            'account_restricted' => 'Account restriction updated',
            default => 'New notification',
        };
    }

    private function body(): string
    {
        return mb_substr((string) match ($this->type) {
            'video_ready' => $this->video?->title ?: 'Your upload has finished processing.',
            'live_replay_ready' => $this->video?->title ?: 'Your replay is available.',
            'video_comment', 'comment_reply', 'comment_mention', 'community_comment', 'community_mention' => data_get($this->data, 'body', $this->comment?->body ?? ''),
            'video_like' => $this->video?->title ?? '',
            'comment_like' => data_get($this->data, 'body', $this->comment?->body ?? ''),
            'channel_subscribe' => 'New subscriber',
            default => data_get($this->data, 'detail', ''),
        }, 0, 500);
    }

    private function action(): ?array
    {
        if (in_array($this->type, ['account_restricted', 'withdrawal_approved', 'withdrawal_rejected', 'admin_role_moderator'], true)) {
            return ['type' => 'account'];
        }

        if (in_array($this->type, ['live_moderator', 'creator_tip'], true)) {
            $target = (string) data_get($this->data, 'to', '');
            if (preg_match('~^/live/([A-Za-z0-9-]+)$~', $target, $matches)) {
                return ['type' => 'live_stream', 'slug' => $matches[1]];
            }

            return null;
        }

        if ($this->communityPost) {
            return array_filter([
                'type' => 'community_post',
                'channel_slug' => $this->communityPost->channel?->slug,
                'post_id' => (int) $this->communityPost->id,
                'comment_id' => $this->comment_id ? (int) $this->comment_id : null,
            ], static fn ($value): bool => $value !== null);
        }

        if ($this->video) {
            if ($this->video->source_type === 'live_replay' && $this->video->relationLoaded('liveStream') && $this->video->liveStream) {
                return ['type' => 'live_stream', 'slug' => (string) $this->video->liveStream->slug];
            }

            return array_filter([
                'type' => $this->video->isShort() ? 'short' : 'video',
                'slug' => (string) $this->video->slug,
                'comment_id' => $this->comment_id ? (int) $this->comment_id : null,
            ], static fn ($value): bool => $value !== null);
        }

        if ($this->type === 'channel_subscribe' && $this->actor?->channel?->slug) {
            return ['type' => 'channel', 'slug' => (string) $this->actor->channel->slug];
        }

        return null;
    }
}
