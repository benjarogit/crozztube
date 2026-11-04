<?php

namespace App\Http\Middleware;

use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\LiveStream;
use App\Models\Video;
use App\Support\UserBlocks;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUgcSafety
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! in_array($request->method(), ['GET', 'HEAD', 'DELETE'], true) && ! UserBlocks::bypass($request)) {
            foreach ((array) $request->route()?->parameters() as $target) {
                if ($target instanceof Video || $target instanceof CommunityPost || $target instanceof Comment || $target instanceof Channel || $target instanceof LiveStream) {
                    UserBlocks::assertInteraction($request->user(), (int) $target->user_id);
                }
            }
            if ($request->filled('parent_id')) {
                $parent = Comment::withoutGlobalScope('blocked_authors')->find($request->integer('parent_id'));
                foreach ((array) $request->route()?->parameters() as $target) {
                    $sameThread = $parent && (($target instanceof Video && (int) $parent->video_id === (int) $target->id)
                        || ($target instanceof CommunityPost && $parent->commentable_type === CommunityPost::class && (int) $parent->commentable_id === (int) $target->id));
                    if ($sameThread) {
                        UserBlocks::assertInteraction($request->user(), (int) $parent->user_id);
                    }
                }
            }
        }

        return $next($request);
    }
}
