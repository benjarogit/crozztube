<?php

namespace App\Policies;

use App\Models\Playlist;
use App\Models\User;
use App\Support\SharedPlaylists;

class PlaylistPolicy
{
    public function view(?User $user, Playlist $playlist): bool
    {
        if (! \App\Support\UserBlocks::canSee($user, (int) $playlist->user_id)) {
            return false;
        }
        if (SharedPlaylists::canView($user, $playlist)) {
            return true;
        }

        $playlist->loadMissing('user');

        return in_array($playlist->privacy, ['public', 'unlisted'], true)
            && $playlist->user?->hasPublicProfileEnabled();
    }

    public function update(User $user, Playlist $playlist): bool
    {
        return SharedPlaylists::role($user, $playlist) === 'owner';
    }

    public function delete(User $user, Playlist $playlist): bool
    {
        return $this->update($user, $playlist);
    }

    public function manageMembers(User $user, Playlist $playlist): bool
    {
        return $this->update($user, $playlist);
    }

    public function reorder(User $user, Playlist $playlist): bool
    {
        return $this->update($user, $playlist);
    }

    public function manageVideos(User $user, Playlist $playlist): bool
    {
        return in_array(SharedPlaylists::role($user, $playlist), ['owner', SharedPlaylists::ROLE_EDITOR], true);
    }

    public function vote(User $user, Playlist $playlist): bool
    {
        return SharedPlaylists::isMember($user, $playlist);
    }
}
