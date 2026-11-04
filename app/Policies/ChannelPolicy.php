<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    public function update(User $user, Channel $channel): bool
    {
        return (int) $channel->user_id === (int) $user->id;
    }

    public function delete(User $user, Channel $channel): bool
    {
        return $this->update($user, $channel);
    }
}
