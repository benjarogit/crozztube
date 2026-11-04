<?php

namespace App\Policies;

use App\Models\LiveStream;
use App\Models\User;
use App\Services\Api\V1\LiveStreamAccessService;
use Illuminate\Support\Facades\DB;

class LiveStreamPolicy
{
    public function view(?User $user, LiveStream $stream): bool
    {
        return app(LiveStreamAccessService::class)->canView($stream, $user);
    }

    public function publish(User $user, LiveStream $stream): bool
    {
        return (int) $stream->user_id === (int) $user->id
            && ! $user->isBannedFrom('live')
            && ! $user->currentAccessToken()?->cant('live:publish');
    }

    public function moderate(User $user, LiveStream $stream): bool
    {
        return $this->publish($user, $stream) || DB::table('live_stream_moderators')
            ->where('live_stream_id', $stream->id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
