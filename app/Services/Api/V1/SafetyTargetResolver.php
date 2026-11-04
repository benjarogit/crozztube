<?php

namespace App\Services\Api\V1;

use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\LiveStream;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

/** Resolve content namespaces server-side, only after its existing privacy checks. */
class SafetyTargetResolver
{
    public function user(string $type, int $id, User $viewer): User
    {
        $author = match ($type) {
            'user' => $this->publicOrContact($id, $viewer),
            'channel' => $this->channel($id, $viewer),
            'video' => $this->video($id, $viewer),
            'community_post' => $this->post($id, $viewer),
            'comment' => $this->comment($id, $viewer),
            'message' => $this->message($id, $viewer),
            'live_stream' => $this->live($id, $viewer),
            default => abort(404, 'Content not found.'),
        };
        return User::query()->findOrFail($author);
    }

    private function publicOrContact(int $id, User $viewer): int
    {
        $user = User::query()->findOrFail($id);
        $contact = DB::table('private_conversations')->where(fn ($q) => $q->where('user_one_id', $viewer->id)->where('user_two_id', $id))
            ->orWhere(fn ($q) => $q->where('user_one_id', $id)->where('user_two_id', $viewer->id))->exists();
        $active = ! in_array(strtolower((string) data_get($user->settings, 'status', 'active')), ['deleted', 'suspended'], true);
        abort_unless($id === (int) $viewer->id || $contact || ($active && $user->hasPublicProfileEnabled()), 404, 'User not found.');
        return (int) $user->id;
    }

    private function channel(int $id, User $viewer): int
    {
        $channel = Channel::withoutGlobalScope('blocked_authors')->findOrFail($id);
        app(CommunityAccessService::class)->assertChannelVisible($channel, $viewer);
        return (int) $channel->user_id;
    }

    private function video(int $id, User $viewer): int
    {
        $video = Video::withoutGlobalScope('blocked_authors')->findOrFail($id);
        app(VideoAccessService::class)->assertViewable($video, $viewer);
        return (int) $video->user_id;
    }

    private function post(int $id, User $viewer): int
    {
        $post = CommunityPost::withoutGlobalScope('blocked_authors')->findOrFail($id);
        app(CommunityAccessService::class)->assertVisible($post, $viewer);
        return (int) $post->user_id;
    }

    private function comment(int $id, User $viewer): int
    {
        $comment = Comment::withoutGlobalScope('blocked_authors')->findOrFail($id);
        if ($comment->video_id) $this->video((int) $comment->video_id, $viewer);
        elseif ($comment->commentable_type === CommunityPost::class && $comment->commentable_id) $this->post((int) $comment->commentable_id, $viewer);
        else abort(404, 'Comment not found.');
        return (int) $comment->user_id;
    }

    private function message(int $id, User $viewer): int
    {
        $message = DB::table('private_messages as messages')->join('private_conversations as conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('messages.id', $id)->where(fn ($q) => $q->where('conversations.user_one_id', $viewer->id)->orWhere('conversations.user_two_id', $viewer->id))
            ->first(['messages.sender_id']);
        abort_unless($message, 404, 'Message not found.');
        return (int) $message->sender_id;
    }

    private function live(int $id, User $viewer): int
    {
        $stream = LiveStream::withoutGlobalScope('blocked_authors')->findOrFail($id);
        abort_unless(app(LiveStreamAccessService::class)->canView($stream, $viewer), 404, 'Live stream not found.');
        return (int) $stream->user_id;
    }
}
