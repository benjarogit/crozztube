<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;

class VideoPolicy
{
    public function view(?User $user, Video $video): bool
    {
        return app(VideoAccessService::class)->canView($video, $user);
    }

    public function play(?User $user, Video $video): bool
    {
        return $this->view($user, $video) && \App\Support\VideoSales::hasAccess($video, $user);
    }

    public function update(User $user, Video $video): bool
    {
        return (int) $video->user_id === (int) $user->id;
    }

    public function delete(User $user, Video $video): bool
    {
        return $this->update($user, $video);
    }
}
