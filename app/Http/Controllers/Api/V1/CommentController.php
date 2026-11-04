<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\CommentController as LegacyCommentController;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Http\Resources\Api\V1\CommentResource;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\Video;
use App\Services\Api\V1\CommentContextService;
use App\Services\Api\V1\CommunityAccessService;
use App\Services\Api\V1\VideoAccessService;
use App\Support\Api\V1\PaginationPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    public function index(Request $request, Video $video, VideoAccessService $access): JsonResponse
    {
        $access->assertViewable($video, $request->user());
        $comments = $video->comments()
            ->with(['user.channel', 'sticker'])
            ->withCount('replies')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));
        $this->hydrateViewer($comments->getCollection(), $request, $video);

        return PaginationPayload::lengthAware(
            $comments,
            CommentResource::collection($comments->getCollection())->resolve($request),
        );
    }

    public function context(
        Request $request,
        Video $video,
        Comment $comment,
        VideoAccessService $access,
        CommentContextService $context,
    ): JsonResponse {
        $access->assertViewable($video, $request->user());

        return response()->json($context->resolve($comment, $video));
    }

    public function replies(Request $request, Comment $comment, VideoAccessService $access, CommunityAccessService $communityAccess): JsonResponse
    {
        $target = $this->assertTargetVisible($comment, $request, $access, $communityAccess);
        $replies = $comment->replies()
            ->with(['user.channel', 'sticker'])
            ->withCount('replies')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));
        $this->hydrateViewer($replies->getCollection(), $request, $target);

        return PaginationPayload::lengthAware(
            $replies,
            CommentResource::collection($replies->getCollection())->resolve($request),
        );
    }

    public function store(
        StoreCommentRequest $request,
        Video $video,
        VideoAccessService $access,
        LegacyCommentController $legacy,
    ): JsonResponse {
        $access->assertViewable($video, $request->user());
        $legacyResponse = $legacy->store($request, $video);
        $commentId = (int) data_get($legacyResponse->getData(true), 'comment.id');
        abort_unless($commentId > 0, 500);
        $comment = Comment::query()->with(['user.channel', 'sticker'])->withCount('replies')->findOrFail($commentId);
        $this->hydrateViewer(collect([$comment]), $request, $video);

        return response()->json(['comment' => (new CommentResource($comment))->resolve($request)], 201);
    }

    public function update(
        UpdateCommentRequest $request,
        Comment $comment,
        VideoAccessService $access,
        CommunityAccessService $communityAccess,
        LegacyCommentController $legacy,
    ): JsonResponse {
        $target = $this->assertTargetVisible($comment, $request, $access, $communityAccess);
        $legacy->update($request, $comment);
        $fresh = $comment->fresh(['user.channel', 'sticker'])->loadCount('replies');
        $this->hydrateViewer(collect([$fresh]), $request, $target);

        return response()->json(['comment' => (new CommentResource($fresh))->resolve($request)]);
    }

    public function destroy(
        Request $request,
        Comment $comment,
        VideoAccessService $access,
        CommunityAccessService $communityAccess,
        LegacyCommentController $legacy,
    ): JsonResponse {
        $this->assertTargetVisible($comment, $request, $access, $communityAccess);
        $legacy->destroy($request, $comment);

        return response()->json([], 204);
    }

    public function report(
        Request $request,
        Comment $comment,
        VideoAccessService $access,
        CommunityAccessService $communityAccess,
        LegacyCommentController $legacy,
    ): JsonResponse {
        $this->assertTargetVisible($comment, $request, $access, $communityAccess);
        $legacy->report($request, $comment);

        return response()->json(['comment_id' => (int) $comment->id, 'reported' => true]);
    }

    public function pin(
        Request $request,
        Comment $comment,
        VideoAccessService $access,
        CommunityAccessService $communityAccess,
        LegacyCommentController $legacy,
    ): JsonResponse {
        $target = $this->assertTargetVisible($comment, $request, $access, $communityAccess);
        $legacy->togglePin($request, $comment);
        $fresh = $comment->fresh(['user.channel', 'sticker'])->loadCount('replies');
        $this->hydrateViewer(collect([$fresh]), $request, $target);

        return response()->json(['comment' => (new CommentResource($fresh))->resolve($request)]);
    }

    private function hydrateViewer(Collection $comments, Request $request, Video|CommunityPost $target): void
    {
        $viewer = $request->user();
        $liked = collect();
        $reported = collect();

        if ($viewer && $comments->isNotEmpty()) {
            $ids = $comments->pluck('id');
            $liked = DB::table('likes')
                ->where('user_id', $viewer->id)
                ->where('likeable_type', Comment::class)
                ->whereIn('likeable_id', $ids)
                ->pluck('likeable_id')->flip();
            $reported = DB::table('comment_reports')
                ->where('user_id', $viewer->id)
                ->whereIn('comment_id', $ids)
                ->pluck('comment_id')->flip();
        }

        $comments->each(function (Comment $comment) use ($viewer, $target, $liked, $reported): void {
            $comment->setRelation($target instanceof Video ? 'video' : 'commentable', $target);
            $comment->setAttribute('viewer_liked', $liked->has($comment->id));
            $comment->setAttribute('viewer_reported', $reported->has($comment->id));
            $comment->setAttribute('viewer_can_update', $viewer && (int) $viewer->id === (int) $comment->user_id);
            $comment->setAttribute('viewer_can_delete', $viewer && ((int) $viewer->id === (int) $target->user_id
                || Gate::forUser($viewer)->allows('delete', $comment)));
            $comment->setAttribute('viewer_can_pin', $viewer && (int) $viewer->id === (int) $target->user_id);
            $comment->setAttribute('viewer_can_report', $viewer && (int) $viewer->id !== (int) $comment->user_id);
        });
    }

    private function assertTargetVisible(
        Comment $comment,
        Request $request,
        VideoAccessService $videoAccess,
        CommunityAccessService $communityAccess,
    ): Video|CommunityPost {
        abort_unless(\App\Support\UserBlocks::canSee($request->user(), (int) $comment->user_id), 404, 'Comment not found.');
        if ($comment->video_id) {
            $video = $comment->video()->firstOrFail();
            $videoAccess->assertViewable($video, $request->user());

            return $video;
        }
        abort_unless($comment->commentable_type === CommunityPost::class && $comment->commentable_id, 404, 'Comment not found.');
        $post = CommunityPost::query()->findOrFail($comment->commentable_id);
        $communityAccess->assertVisible($post, $request->user());

        return $post;
    }
}
