<?php

namespace App\Services\Api\V1;

use App\Models\Channel;
use App\Models\CommunityPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CommunityAccessService
{
    public function visibleQuery(?User $viewer): Builder
    {
        return CommunityPost::query()
            ->whereHas('channel.user', function (Builder $query) use ($viewer): void {
                $query->where(function (Builder $status) use ($viewer): void {
                    if ($viewer) {
                        $status->whereKey($viewer->id)->orWhere(function (Builder $public): void {
                            $this->publicUser($public);
                        });
                    } else {
                        $this->publicUser($status);
                    }
                });
            })
            ->where(function (Builder $query) use ($viewer): void {
                $query->whereNull('video_id')
                    ->orWhereHas('video', fn (Builder $video) => app(VideoAccessService::class)->constrainViewable($video, $viewer));
            });
    }

    public function assertVisible(CommunityPost $post, ?User $viewer): void
    {
        abort_unless($this->visibleQuery($viewer)->whereKey($post->id)->exists(), 404, 'Community post not found.');
    }

    public function assertChannelVisible(Channel $channel, ?User $viewer): void
    {
        abort_unless(\App\Support\UserBlocks::canSee($viewer, (int) $channel->user_id), 404, 'Profile not found.');
        $channel->loadMissing('user');
        abort_unless($channel->user, 404, 'Profile not found.');
        if ($viewer && (int) $channel->user_id === (int) $viewer->id) {
            return;
        }
        $status = strtolower(str_replace(['_', '-'], ' ', (string) data_get($channel->user->settings, 'status', 'active')));
        $public = (bool) data_get($channel->user->settings, 'preferences.publicProfile', true);
        abort_unless(! in_array($status, ['suspended', 'deleted'], true) && $public, 404, 'Profile not found.');
    }

    private function publicUser(Builder $query): void
    {
        $query->where(fn (Builder $status) => $status
            ->whereNull('settings->status')->orWhere('settings->status', 'active'))
            ->where(fn (Builder $public) => $public
                ->whereNull('settings->preferences->publicProfile')
                ->orWhere('settings->preferences->publicProfile', true));
    }
}
