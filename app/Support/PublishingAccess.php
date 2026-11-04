<?php

namespace App\Support;

use App\Models\User;

class PublishingAccess
{
    public static function status(User $user): array
    {
        $user->loadMissing('channel');
        $role = self::role($user);
        $requirements = self::profileRequirements($user);
        $allowlistActive = self::allowedUsernames((string) data_get(PlatformFeatureSettings::settings(), 'publishing.allowedUsers', '')) !== [];

        return [
            'role' => $role,
            'upload' => self::capabilityStatus($user, 'upload', $role),
            'live' => self::capabilityStatus($user, 'live', $role),
            'profile_complete' => collect($requirements)->every(fn (array $item): bool => $item['complete']),
            'profile_requirements' => $requirements,
            'upgrade_required' => $role === 'user' && ! $allowlistActive,
            'allowlist_active' => $allowlistActive,
        ];
    }

    public static function authorizeUpload(User $user): void
    {
        $status = self::status($user)['upload'];
        abort_unless($status['allowed'], 403, $status['reason']);
    }

    public static function authorizeLive(User $user): void
    {
        $status = self::status($user)['live'];
        abort_unless($status['allowed'], 403, $status['reason']);
    }

    public static function role(User $user): string
    {
        $settings = is_array($user->settings) ? $user->settings : [];
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;
        if ($adminEnabled || (bool) ($settings['is_admin'] ?? false)) {
            return 'admin';
        }

        $storedRole = (string) ($settings['role'] ?? $settings['account_role'] ?? '');
        if (trim($storedRole) === '' && ($user->videos()->exists() || $user->liveStreams()->exists())) {
            return 'creator';
        }
        $role = strtolower(trim(str_replace(['_', '-'], ' ', $storedRole ?: 'user')));

        return match ($role) {
            'owner', 'super admin', 'administrator', 'admin' => 'admin',
            'moderator' => 'moderator',
            'creator' => 'creator',
            default => 'user',
        };
    }

    public static function profileRequirements(User $user): array
    {
        $user->loadMissing('channel');
        $channel = $user->channel;

        return [
            ['key' => 'name', 'label' => 'Display name', 'complete' => filled($user->name), 'section' => 'profile'],
            ['key' => 'username', 'label' => 'Username', 'complete' => filled($user->username), 'section' => 'profile'],
            ['key' => 'email', 'label' => 'Email address', 'complete' => filled($user->email), 'section' => 'profile'],
            ['key' => 'date_of_birth', 'label' => 'Date of birth', 'complete' => filled($user->date_of_birth), 'section' => 'profile'],
            ['key' => 'avatar', 'label' => 'Profile avatar', 'complete' => filled($channel?->avatar_path ?: $user->avatar_path), 'section' => 'profile'],
            ['key' => 'cover', 'label' => 'Channel cover', 'complete' => filled($channel?->cover_path), 'section' => 'channel'],
        ];
    }

    private static function capabilityStatus(User $user, string $capability, string $role): array
    {
        $scope = $capability === 'live' ? 'live' : 'uploads';
        if ($user->isBannedFrom($scope)) {
            return ['allowed' => false, 'reason' => $user->banMessage($scope)];
        }

        $settings = PlatformFeatureSettings::settings();
        $allowlist = self::allowedUsernames((string) data_get($settings, 'publishing.allowedUsers', ''));
        if ($allowlist) {
            $allowed = in_array(strtolower((string) $user->username), $allowlist, true);

            return [
                'allowed' => $allowed,
                'reason' => $allowed ? null : 'Your account is not included in the publishing allowlist.',
            ];
        }

        $roles = collect(data_get($settings, 'publishing.'.($capability === 'live' ? 'liveRoles' : 'uploadRoles'), []))
            ->map(fn ($item): string => strtolower(trim((string) $item)))
            ->all();
        $allowed = in_array($role, $roles, true);

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? null : ($role === 'user'
                ? 'Upgrade to a creator account to '.($capability === 'live' ? 'go live' : 'upload videos').'.'
                : 'Your role is not allowed to '.($capability === 'live' ? 'go live' : 'upload videos').'.'),
        ];
    }

    private static function allowedUsernames(string $value): array
    {
        return collect(preg_split('/[\s,]+/', $value) ?: [])
            ->map(fn (string $username): string => strtolower(ltrim(trim($username), '@')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
