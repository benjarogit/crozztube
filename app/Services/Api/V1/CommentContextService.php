<?php

namespace App\Services\Api\V1;

use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\Video;

class CommentContextService
{
    public function resolve(Comment $comment, Video|CommunityPost $target): array
    {
        $this->assertBelongsToTarget($comment, $target);

        $ancestors = [];
        $seen = [(int) $comment->id => true];
        $parentId = $comment->parent_id;

        // A valid thread is finite and entirely contained within the visible target.
        for ($depth = 0; $parentId !== null; $depth++) {
            abort_if($depth >= 32 || isset($seen[(int) $parentId]), 404, 'Comment not found.');
            $parent = Comment::query()->findOrFail($parentId);
            $this->assertBelongsToTarget($parent, $target);
            $seen[(int) $parent->id] = true;
            $ancestors[] = (int) $parent->id;
            $parentId = $parent->parent_id;
        }

        return [
            'comment_id' => (int) $comment->id,
            'ancestor_ids' => array_reverse($ancestors),
        ];
    }

    private function assertBelongsToTarget(Comment $comment, Video|CommunityPost $target): void
    {
        abort_unless(\App\Support\UserBlocks::canSee(\App\Support\UserBlocks::currentViewer(), (int) $comment->user_id), 404, 'Comment not found.');
        $belongs = $target instanceof Video
            ? (int) $comment->video_id === (int) $target->id
                && $comment->commentable_type === null
                && $comment->commentable_id === null
            : $comment->video_id === null
                && $comment->commentable_type === CommunityPost::class
                && (int) $comment->commentable_id === (int) $target->id;

        abort_unless($belongs, 404, 'Comment not found.');
    }
}
