<?php

namespace App\Services\Auth;

use App\Models\User;

class NativeAccountAccess
{
    public function denialMessage(User $user): ?string
    {
        $settings = is_array($user->settings) ? $user->settings : [];
        $status = strtolower(str_replace(['_', '-'], ' ', trim((string) ($settings['status'] ?? 'active'))));

        if (in_array($status, ['suspended', 'deleted'], true)) {
            return 'This account has been suspended. Contact support if you believe this is a mistake.';
        }

        if ($user->isBannedFrom('account')) {
            return $user->banMessage('account');
        }

        return null;
    }

    public function allows(User $user): bool
    {
        return $this->denialMessage($user) === null;
    }
}
