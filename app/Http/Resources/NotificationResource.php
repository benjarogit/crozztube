<?php

namespace App\Http\Resources;

use App\Models\LiveStream;
use App\Plugins\PluginHooks;
use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $this->actor;
        $video = $this->video;
        $comment = $this->comment;
        $communityPost = $this->communityPost;
        $actorName = $actor?->channel?->name ?: $actor?->name ?: config('app.name', 'PlayHub');
        $actorAvatar = $actor?->channel?->avatar_path ?: $actor?->avatar_path;
        $replayAudienceRole = $this->type === 'live_replay_ready' ? $this->liveReplayAudienceRole($video) : null;

        $payload = [
            'id' => $this->id,
            'type' => $this->type,
            'actor' => $this->actorText($actorName, $replayAudienceRole),
            'actor_avatar_url' => $this->assetUrl($actorAvatar),
            'action' => $this->actionText(),
            'detail' => $this->detailText($video, $comment, $communityPost, $replayAudienceRole),
            'badge' => $this->badgeIcon(),
            'badge_class' => $this->badgeClass(),
            'unread' => $this->read_at === null,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'to' => $this->targetUrl($video, $comment, $actor, $communityPost),
            'media' => $this->media($video, $communityPost),
        ];

        $filtered = app(PluginHooks::class)->filter('playhub.notification.payload', $payload, $this->resource, $request);

        return is_array($filtered) ? $filtered : $payload;
    }

    private function actionText(): string
    {
        return match ($this->type) {
            'clip_ready' => '',
            'video_ready' => 'is ready to watch',
            'live_replay_ready' => 'is ready to replay',
            'video_comment' => 'commented on your video',
            'comment_mention' => 'mentioned you in a comment',
            'community_mention' => 'mentioned you in a community post',
            'video_like' => 'liked your video',
            'comment_like' => 'liked your comment',
            'channel_subscribe' => 'subscribed to your channel',
            'creator_tip' => 'sent you a tip',
            'video_purchase' => 'purchased your video',
            'community_photo_purchase' => 'purchased your photos',
            'withdrawal_approved' => 'was approved',
            'withdrawal_rejected' => 'was rejected',
            'community_like' => 'liked your community post',
            'community_comment' => 'commented on your community post',
            'community_poll_vote' => 'voted in your poll',
            'live_moderator' => 'made you a stream moderator',
            'admin_role_moderator' => 'made you a platform moderator',
            'account_restricted' => 'restricted your account',
            default => 'sent you a notification',
        };
    }

    private function actorText(string $actorName, ?string $replayAudienceRole): string
    {
        return match ($this->type) {
            'clip_ready' => 'Your clip is ready to watch.',
            'video_ready' => 'Your video',
            'live_replay_ready' => match ($replayAudienceRole) {
                'streamer' => 'Your live stream',
                'participant' => 'A live stream you participated in',
                default => 'A live stream you interacted with',
            },
            'withdrawal_approved', 'withdrawal_rejected' => 'Your withdrawal request',
            default => $actorName,
        };
    }

    private function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (preg_match('/^(https?:)?\/\//', $path) || str_starts_with($path, '/')) {
            return url($path);
        }

        return MediaStorage::url($path);
    }

    private function targetUrl($video, $comment, $actor, $communityPost): ?string
    {
        if ($this->type === 'clip_ready') {
            $id = (string) data_get($this->data, 'clip_id', '');

            return \Illuminate\Support\Str::isUuid($id) ? '/clip-uploads?clip='.$id : '/clip-uploads';
        }
        if (filled(data_get($this->data, 'to'))) {
            return (string) data_get($this->data, 'to');
        }

        if ($communityPost?->id) {
            return $communityPost->channel?->slug ? '/channel/'.$communityPost->channel->slug.'/community#post-'.$communityPost->id.($comment?->id ? '-comment-'.$comment->id : '') : null;
        }

        if ($video?->slug) {
            if ($video->source_type === 'live_replay') {
                $liveStreamSlug = LiveStream::query()->where('replay_video_id', $video->id)->value('slug');
                if ($liveStreamSlug) return '/live/'.$liveStreamSlug;
            }
            $isShort = $video->isShort();
            $path = ($isShort ? '/shorts/' : '/watch/').$video->slug;
            $hashPrefix = $isShort ? '#short-'.$video->slug.'-comment-' : '#comment-';

            return $path.($comment?->id ? $hashPrefix.$comment->id : '');
        }

        return $actor?->channel?->slug ? '/channel/'.$actor->channel->slug : null;
    }

    private function detailText($video, $comment, $communityPost, ?string $replayAudienceRole): string
    {
        return match ($this->type) {
            'clip_ready' => (string) data_get($this->data, 'title', ''),
            'video_ready' => $video?->title ? 'Your upload "'.$video->title.'" has finished processing.' : 'Your upload has finished processing.',
            'live_replay_ready' => $video?->title
                ? ($replayAudienceRole === 'streamer' ? 'The replay "'.$video->title.'" has finished processing.' : 'The replay "'.$video->title.'" is now available to watch.')
                : 'The live stream replay is ready.',
            'video_comment', 'comment_mention' => (string) ($this->data['body'] ?? $comment?->body ?? $video?->title ?? ''),
            'video_like' => (string) ($video?->title ?? ''),
            'comment_like' => (string) ($this->data['body'] ?? $comment?->body ?? ''),
            'channel_subscribe' => 'New subscriber',
            'creator_tip' => (string) ($this->data['detail'] ?? ''),
            'video_purchase' => (string) ($this->data['detail'] ?? $video?->title ?? ''),
            'community_photo_purchase' => (string) ($this->data['detail'] ?? 'Paid photo post'),
            'community_comment', 'community_mention' => (string) ($this->data['body'] ?? $comment?->body ?? $communityPost?->body ?? ''),
            'community_like' => (string) ($communityPost?->body ?: 'Community post'),
            'community_poll_vote' => (string) ($communityPost?->body ?: 'Community poll'),
            default => (string) ($this->data['detail'] ?? ''),
        };
    }

    private function liveReplayAudienceRole($video): string
    {
        $storedRole = (string) data_get($this->data, 'audience_role', '');
        if (in_array($storedRole, ['streamer', 'participant', 'viewer'], true)) return $storedRole;
        if ($video && (int) $this->user_id === (int) $video->user_id) return 'streamer';

        $liveStreamId = (int) data_get($this->data, 'live_stream_id', 0);
        if (! $liveStreamId && $video?->id) {
            $liveStreamId = (int) LiveStream::query()->where('replay_video_id', $video->id)->value('id');
        }
        if ($liveStreamId && DB::table('live_stream_invites')
            ->where('live_stream_id', $liveStreamId)
            ->where('user_id', $this->user_id)
            ->whereNotNull('accepted_at')
            ->whereIn('role', ['guest', 'cohost'])
            ->exists()) {
            return 'participant';
        }

        return 'viewer';
    }

    private function badgeIcon(): string
    {
        return match ($this->type) {
            'video_ready', 'live_replay_ready', 'clip_ready' => 'play',
            'video_comment', 'comment_mention', 'community_mention' => 'comment',
            'video_like', 'comment_like', 'community_like' => 'heart',
            'community_comment' => 'comment',
            'community_poll_vote' => 'chart',
            'channel_subscribe' => 'users',
            'creator_tip', 'video_purchase', 'community_photo_purchase', 'withdrawal_approved', 'withdrawal_rejected' => 'wallet',
            'live_moderator', 'admin_role_moderator' => 'shield',
            'account_restricted' => 'ban',
            default => 'bell',
        };
    }

    private function badgeClass(): string
    {
        return match ($this->type) {
            'video_ready', 'live_replay_ready', 'clip_ready' => 'playlist',
            'video_comment', 'comment_mention', 'community_mention', 'community_comment', 'community_poll_vote' => 'comment',
            'video_like', 'comment_like', 'community_like' => 'like',
            'channel_subscribe' => 'follow',
            'creator_tip', 'video_purchase', 'community_photo_purchase', 'withdrawal_approved' => 'follow',
            'withdrawal_rejected' => 'like',
            'live_moderator', 'admin_role_moderator' => 'follow',
            'account_restricted' => 'like',
            default => 'comment',
        };
    }

    private function media($video, $communityPost): ?array
    {
        if ($video) {
            return [
                'title' => $video->title,
                'thumbnail_url' => $video->thumbnail_url,
            ];
        }

        if (! $communityPost) {
            return null;
        }

        $image = collect($communityPost->attachments ?: [])->firstWhere('type', 'image');

        return [
            'title' => mb_substr((string) ($communityPost->body ?: 'Community post'), 0, 80),
            'thumbnail_url' => $communityPost->video?->thumbnail_url ?: ($image['url'] ?? null),
        ];
    }
}
