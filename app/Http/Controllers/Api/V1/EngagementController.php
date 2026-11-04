<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\Video;
use App\Services\Api\V1\CommunityAccessService;
use App\Services\Api\V1\EngagementService;
use App\Services\Api\V1\VideoAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EngagementController extends Controller
{
    public function likeVideo(
        Request $request,
        Video $video,
        VideoAccessService $access,
        EngagementService $engagement,
    ): JsonResponse {
        $access->assertViewable($video, $request->user());

        return response()->json($engagement->setLike($request->user(), $video, 'likes_count', true));
    }

    public function unlikeVideo(
        Request $request,
        Video $video,
        VideoAccessService $access,
        EngagementService $engagement,
    ): JsonResponse {
        $access->assertViewable($video, $request->user());

        return response()->json($engagement->setLike($request->user(), $video, 'likes_count', false));
    }

    public function likeComment(
        Request $request,
        Comment $comment,
        VideoAccessService $access,
        CommunityAccessService $communityAccess,
        EngagementService $engagement,
    ): JsonResponse {
        $this->assertCommentVisible($comment, $request, $access, $communityAccess);

        return response()->json($engagement->setLike($request->user(), $comment, 'likes_count', true));
    }

    public function unlikeComment(
        Request $request,
        Comment $comment,
        VideoAccessService $access,
        CommunityAccessService $communityAccess,
        EngagementService $engagement,
    ): JsonResponse {
        $this->assertCommentVisible($comment, $request, $access, $communityAccess);

        return response()->json($engagement->setLike($request->user(), $comment, 'likes_count', false));
    }

    public function subscribe(Request $request, Channel $channel, EngagementService $engagement): JsonResponse
    {
        $this->assertChannelVisible($channel, $request);

        return response()->json($engagement->setSubscription($request->user(), $channel, true));
    }

    public function unsubscribe(Request $request, Channel $channel, EngagementService $engagement): JsonResponse
    {
        $subscribed = DB::table('subscriptions')
            ->where('user_id', $request->user()->id)
            ->where('channel_id', $channel->id)
            ->exists();
        if (! $subscribed) {
            $this->assertChannelVisible($channel, $request);
        }

        return response()->json($engagement->setSubscription($request->user(), $channel, false));
    }

    public function reportVideo(Request $request, Video $video, VideoAccessService $access): JsonResponse
    {
        $access->assertViewable($video, $request->user());
        abort_if((int) $video->user_id === (int) $request->user()->id, 403, 'You cannot report your own video.');

        DB::table('video_reports')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'video_id' => $video->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['video_id' => (int) $video->id, 'reported' => true]);
    }

    public function reportProfile(Request $request, Channel $channel): JsonResponse
    {
        $this->assertChannelVisible($channel, $request);
        abort_if((int) $channel->user_id === (int) $request->user()->id, 403, 'You cannot report your own profile.');

        DB::table('user_reports')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'reported_user_id' => $channel->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['profile_id' => (int) $channel->id, 'reported' => true]);
    }

    private function assertCommentVisible(Comment $comment, Request $request, VideoAccessService $access, CommunityAccessService $communityAccess): void
    {
        if ($comment->video_id) {
            $access->assertViewable($comment->video()->firstOrFail(), $request->user());

            return;
        }
        abort_unless($comment->commentable_type === CommunityPost::class && $comment->commentable_id, 404, 'Comment not found.');
        $post = CommunityPost::query()->findOrFail($comment->commentable_id);
        $communityAccess->assertVisible($post, $request->user());
    }

    private function assertChannelVisible(Channel $channel, Request $request): void
    {
        $channel->loadMissing('user');
        abort_unless($channel->user, 404, 'Profile not found.');
        $isOwner = (int) $channel->user_id === (int) $request->user()->id;
        $status = strtolower(str_replace(['_', '-'], ' ', trim((string) data_get($channel->user->settings, 'status', 'active'))));
        $public = (bool) data_get($channel->user->settings, 'preferences.publicProfile', true);

        abort_unless($isOwner || (! in_array($status, ['suspended', 'deleted'], true) && $public), 404, 'Profile not found.');
    }
}
