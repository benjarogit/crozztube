<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\Video;
use App\Services\Api\V1\EngagementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function __construct(private readonly EngagementService $engagement) {}

    public function toggleVideo(Request $request, Video $video): JsonResponse
    {
        return $this->toggle($request, $video, 'likes_count');
    }

    public function toggleComment(Request $request, Comment $comment): JsonResponse
    {
        return $this->toggle($request, $comment, 'likes_count');
    }

    public function toggleCommunityPost(Request $request, CommunityPost $communityPost): JsonResponse
    {
        return $this->toggle($request, $communityPost, 'likes_count');
    }

    private function toggle(Request $request, Model $likeable, string $counterColumn): JsonResponse
    {
        $result = $this->engagement->toggleLike($request->user(), $likeable, $counterColumn);
        unset($result['changed']);

        return response()->json($result);
    }
}
