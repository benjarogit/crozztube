<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;

class NotificationPreferencePolicy
{
    public function allows(UserNotification $notification): bool
    {
        if (\App\Support\UserBlocks::between((int) $notification->user_id, (int) $notification->actor_id)) return false;

        $preference = match ($notification->type) {
            'video_comment', 'community_comment' => 'comments',
            'comment_reply' => 'replies',
            'comment_mention', 'community_mention' => 'mentions',
            'channel_subscribe' => 'subscriptions',
            'video_ready', 'clip_ready' => 'uploads',
            'live_started', 'live_reminder', 'live_replay_ready' => 'liveReminders',
            default => null,
        };

        if ($preference === null) {
            return true;
        }

        $recipient = User::query()->find($notification->user_id, ['id', 'settings']);

        return (bool) data_get($recipient?->settings, 'preferences.'.$preference, true);
    }
}
