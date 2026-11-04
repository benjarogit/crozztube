<?php

namespace App\Policies;

use App\Models\Playlist;
use App\Models\User;

class PlaylistPolicy
{
    public function view(?User $user, Playlist $playlist): bool
    {
        if (! \App\Support\UserBlocks::canSee($user, (int) $playlist->user_id)) return false;
        if ($user && (int) $playlist->user_id === (int) $user->id) {
            return true;
        }

        $playlist->loadMissing('user');

        return in_array($playlist->privacy, ['public', 'unlisted'], true)
            && $playlist->user?->hasPublicProfileEnabled();
    }

    public function update(User $user, Playlist $playlist): bool
    {
        return (int) $playlist->user_id === (int) $user->id;
    }

    public function delete(User $user, Playlist $playlist): bool
    {
        return $this->update($user, $playlist);
    }

    public function manageVideos(User $user, Playlist $playlist): bool
    {
        return $this->update($user, $playlist);
    }
}
