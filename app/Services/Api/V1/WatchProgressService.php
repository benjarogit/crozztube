<?php

namespace App\Services\Api\V1;

use App\Models\User;
use App\Models\Video;
use App\Support\VideoSales;
use App\Support\WatchProgress;

class WatchProgressService
{
    public function __construct(private readonly VideoAccessService $access) {}

    public function update(User $user, Video $video, array $data): array
    {
        $this->assertWritable($user, $video);

        return WatchProgress::record($user, $video, $data);
    }

    public function dismiss(User $user, Video $video): array
    {
        $this->assertWritable($user, $video);

        return WatchProgress::dismiss($user, $video);
    }

    public function markWatched(User $user, Video $video): array
    {
        $this->assertWritable($user, $video);

        return WatchProgress::markWatched($user, $video);
    }

    public function restart(User $user, Video $video): array
    {
        $this->assertWritable($user, $video);

        return WatchProgress::restart($user, $video);
    }

    private function assertWritable(User $user, Video $video): void
    {
        $this->access->assertViewable($video, $user);
        abort_unless(VideoSales::hasAccess($video, $user), 403, 'Purchase this video to watch it.');
    }
}
