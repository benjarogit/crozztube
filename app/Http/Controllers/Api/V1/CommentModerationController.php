<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\CommentController as LegacyCommentController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommentResource;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Support\Api\V1\PaginationPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommentModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $userId = (int) $request->user()->id;
        $page = $this->owned($userId)->with(['user.channel', 'sticker', 'video', 'commentable'])
            ->withCount('replies')->latest()->orderByDesc('id')->paginate(min(50, max(1, $request->integer('per_page', 20))));
        $muted = DB::table('user_mutes')->where('user_id', $userId)->whereIn('muted_user_id', $page->getCollection()->pluck('user_id'))->pluck('muted_user_id')->flip();
        $items = $page->getCollection()->map(function (Comment $comment) use ($request, $userId, $muted): array {
            $comment->viewer_can_update = (int) $comment->user_id === $userId;
            $comment->viewer_can_delete = true;
            $comment->viewer_can_pin = true;
            $comment->viewer_can_report = false;
            $target = $comment->video
                ? ['type' => 'video', 'id' => (int) $comment->video_id, 'title' => $comment->video->title, 'video_slug' => $comment->video->slug]
                : ['type' => 'post', 'id' => (int) $comment->commentable_id, 'title' => Str::limit(strip_tags((string) $comment->commentable->body), 80), 'video_slug' => null];

            return [...(new CommentResource($comment))->resolve($request), 'target' => $target,
                'can_mute' => (int) $comment->user_id !== $userId && ! $muted->has($comment->user_id),
                'muted' => $muted->has($comment->user_id)];
        })->all();

        return PaginationPayload::lengthAware($page, $items)->header('Cache-Control', 'private, no-store');
    }

    public function mute(Request $request, Comment $comment, LegacyCommentController $legacy): JsonResponse
    {
        // The native inbox follows the web channel-owner boundary for every target.
        abort_unless($this->owned((int) $request->user()->id)->whereKey($comment->id)->exists(), 404);
        $legacy->muteUser($request, $comment);

        return response()->json(['muted' => true])->header('Cache-Control', 'private, no-store');
    }

    private function owned(int $userId): Builder
    {
        return Comment::query()->where(function (Builder $query) use ($userId): void {
            $query->where(fn (Builder $video) => $video->whereNotNull('video_id')->whereNull('commentable_type')->whereNull('commentable_id')->whereHas('video', fn (Builder $target) => $target->where('user_id', $userId)))
                ->orWhere(fn (Builder $post) => $post->whereNull('video_id')->where('commentable_type', CommunityPost::class)->whereHasMorph('commentable', [CommunityPost::class], fn (Builder $target) => $target->where('user_id', $userId)));
        });
    }
}
