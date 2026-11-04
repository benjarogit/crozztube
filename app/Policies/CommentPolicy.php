<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function update(User $user, Comment $comment): bool
    {
        return (int) $comment->user_id === (int) $user->id;
    }

    public function delete(User $user, Comment $comment): bool
    {
        if ($this->update($user, $comment)) {
            return true;
        }

        $ownerId = $comment->video?->user_id ?? $comment->parent?->video?->user_id;
        if ($ownerId && (int) $ownerId === (int) $user->id) {
            return true;
        }

        $settings = is_array($user->settings) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));

        return in_array($role, ['moderator', 'admin', 'super admin', 'owner'], true)
            || (bool) data_get($settings, 'admin.enabled', false)
            || (bool) ($settings['is_admin'] ?? false);
    }
}
