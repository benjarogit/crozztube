<?php

namespace App\Support;

use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Personal shared playlists: Owner / Editor / Viewer.
 * Manual sort_order stays authoritative; votes never reorder rows.
 */
final class SharedPlaylists
{
    public const ROLE_EDITOR = 'editor';

    public const ROLE_VIEWER = 'viewer';

    public static function role(?User $user, Playlist $playlist): ?string
    {
        if (! $user) {
            return null;
        }
        if ((int) $playlist->user_id === (int) $user->id) {
            return 'owner';
        }

        $row = DB::table('playlist_members')
            ->where('playlist_id', $playlist->id)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->first();

        return $row?->role;
    }

    public static function hasPendingInvite(?User $user, Playlist $playlist): bool
    {
        if (! $user) {
            return false;
        }

        return DB::table('playlist_members')
            ->where('playlist_id', $playlist->id)
            ->where('user_id', $user->id)
            ->whereNull('accepted_at')
            ->exists();
    }

    public static function isMember(?User $user, Playlist $playlist): bool
    {
        return self::role($user, $playlist) !== null;
    }

    public static function canView(?User $user, Playlist $playlist): bool
    {
        return self::isMember($user, $playlist) || self::hasPendingInvite($user, $playlist);
    }

    public static function invite(User $owner, Playlist $playlist, User $invitee, string $role): array
    {
        self::assertOwner($owner, $playlist);
        if (! in_array($role, [self::ROLE_EDITOR, self::ROLE_VIEWER], true)) {
            throw ValidationException::withMessages(['role' => 'Choose editor or viewer.']);
        }
        if ((int) $invitee->id === (int) $owner->id) {
            throw ValidationException::withMessages(['user' => 'You already own this playlist.']);
        }

        return DB::transaction(function () use ($owner, $playlist, $invitee, $role): array {
            Playlist::query()->lockForUpdate()->findOrFail($playlist->id);
            DB::table('playlist_members')->updateOrInsert(
                [
                    'playlist_id' => $playlist->id,
                    'user_id' => $invitee->id,
                ],
                [
                    'role' => $role,
                    'invited_by' => $owner->id,
                    'accepted_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            return self::memberPayload(
                DB::table('playlist_members')
                    ->where('playlist_id', $playlist->id)
                    ->where('user_id', $invitee->id)
                    ->first()
            );
        });
    }

    public static function accept(User $user, Playlist $playlist): array
    {
        return DB::transaction(function () use ($user, $playlist): array {
            $row = DB::table('playlist_members')
                ->where('playlist_id', $playlist->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $row) {
                throw ValidationException::withMessages(['playlist' => 'Invitation not found.']);
            }
            DB::table('playlist_members')->where('id', $row->id)->update([
                'accepted_at' => $row->accepted_at ?? now(),
                'updated_at' => now(),
            ]);

            return self::memberPayload(
                DB::table('playlist_members')->where('id', $row->id)->first()
            );
        });
    }

    public static function revoke(User $owner, Playlist $playlist, User $member): array
    {
        self::assertOwner($owner, $playlist);
        if ((int) $member->id === (int) $owner->id) {
            throw ValidationException::withMessages(['user' => 'The owner cannot be removed.']);
        }

        return DB::transaction(function () use ($playlist, $member): array {
            Playlist::query()->lockForUpdate()->findOrFail($playlist->id);
            $deleted = DB::table('playlist_members')
                ->where('playlist_id', $playlist->id)
                ->where('user_id', $member->id)
                ->delete();
            if ($deleted < 1) {
                throw ValidationException::withMessages(['user' => 'Member not found.']);
            }

            return ['removed' => true, 'user_id' => (int) $member->id];
        });
    }

    public static function addVideo(User $user, Playlist $playlist, Video $video): array
    {
        $role = self::role($user, $playlist);
        if (! in_array($role, ['owner', self::ROLE_EDITOR], true)) {
            throw ValidationException::withMessages(['playlist' => 'Only the owner or an editor can add videos.']);
        }

        return DB::transaction(function () use ($user, $playlist, $video): array {
            $locked = Playlist::query()->lockForUpdate()->findOrFail($playlist->id);
            $maximum = (int) DB::table('playlist_video')->where('playlist_id', $locked->id)->max('sort_order');
            $inserted = DB::table('playlist_video')->insertOrIgnore([
                'playlist_id' => $locked->id,
                'video_id' => $video->id,
                'added_by_user_id' => $user->id,
                'sort_order' => $maximum + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted > 0) {
                $locked->touch();
            }

            return [
                'playlist_id' => (int) $locked->id,
                'video_id' => (int) $video->id,
                'included' => true,
                'added_by_user_id' => (int) $user->id,
            ];
        });
    }

    public static function removeVideo(User $user, Playlist $playlist, int $videoId): array
    {
        $role = self::role($user, $playlist);
        if (! in_array($role, ['owner', self::ROLE_EDITOR], true)) {
            throw ValidationException::withMessages(['playlist' => 'Only the owner or an editor can remove videos.']);
        }

        return DB::transaction(function () use ($user, $playlist, $videoId, $role): array {
            $locked = Playlist::query()->lockForUpdate()->findOrFail($playlist->id);
            $row = DB::table('playlist_video')
                ->where('playlist_id', $locked->id)
                ->where('video_id', $videoId)
                ->lockForUpdate()
                ->first();
            if (! $row) {
                return ['playlist_id' => (int) $locked->id, 'video_id' => $videoId, 'included' => false];
            }
            if ($role === self::ROLE_EDITOR && (int) ($row->added_by_user_id ?? 0) !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'video' => 'Editors can only remove videos they added.',
                ]);
            }

            DB::table('playlist_video')->where('id', $row->id)->delete();
            DB::table('playlist_video_votes')
                ->where('playlist_id', $locked->id)
                ->where('video_id', $videoId)
                ->delete();
            $locked->touch();

            return ['playlist_id' => (int) $locked->id, 'video_id' => $videoId, 'included' => false];
        });
    }

    /**
     * @param  list<int>  $orderedVideoIds
     */
    public static function reorder(User $owner, Playlist $playlist, array $orderedVideoIds): array
    {
        self::assertOwner($owner, $playlist);
        $orderedVideoIds = array_values(array_unique(array_map('intval', $orderedVideoIds)));

        return DB::transaction(function () use ($playlist, $orderedVideoIds): array {
            $locked = Playlist::query()->lockForUpdate()->findOrFail($playlist->id);
            $existing = DB::table('playlist_video')
                ->where('playlist_id', $locked->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('video_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            sort($existing);
            $sortedIncoming = $orderedVideoIds;
            sort($sortedIncoming);
            if ($existing !== $sortedIncoming) {
                throw ValidationException::withMessages([
                    'video_ids' => 'Provide every playlist video exactly once.',
                ]);
            }

            foreach ($orderedVideoIds as $index => $videoId) {
                DB::table('playlist_video')
                    ->where('playlist_id', $locked->id)
                    ->where('video_id', $videoId)
                    ->update(['sort_order' => $index, 'updated_at' => now()]);
            }
            $locked->touch();

            return [
                'playlist_id' => (int) $locked->id,
                'video_ids' => $orderedVideoIds,
                'sort_preserved' => true,
            ];
        });
    }

    public static function vote(User $user, Playlist $playlist, int $videoId): array
    {
        if (! self::isMember($user, $playlist)) {
            throw ValidationException::withMessages(['playlist' => 'Only members can vote on playlist videos.']);
        }

        return DB::transaction(function () use ($user, $playlist, $videoId): array {
            Playlist::query()->lockForUpdate()->findOrFail($playlist->id);
            $membership = DB::table('playlist_video')
                ->where('playlist_id', $playlist->id)
                ->where('video_id', $videoId)
                ->lockForUpdate()
                ->first();
            if (! $membership) {
                throw ValidationException::withMessages(['video' => 'Video is not in this playlist.']);
            }

            $beforeOrder = (int) $membership->sort_order;
            DB::table('playlist_video_votes')->updateOrInsert(
                [
                    'playlist_id' => $playlist->id,
                    'video_id' => $videoId,
                    'user_id' => $user->id,
                ],
                [
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $afterOrder = (int) DB::table('playlist_video')
                ->where('id', $membership->id)
                ->value('sort_order');

            return [
                'playlist_id' => (int) $playlist->id,
                'video_id' => $videoId,
                'votes' => (int) DB::table('playlist_video_votes')
                    ->where('playlist_id', $playlist->id)
                    ->where('video_id', $videoId)
                    ->count(),
                'voted' => true,
                'sort_order' => $afterOrder,
                'sort_unchanged' => $beforeOrder === $afterOrder,
            ];
        });
    }

    public static function members(Playlist $playlist): array
    {
        $rows = DB::table('playlist_members')
            ->join('users', 'users.id', '=', 'playlist_members.user_id')
            ->where('playlist_members.playlist_id', $playlist->id)
            ->orderBy('playlist_members.id')
            ->get([
                'playlist_members.id',
                'playlist_members.user_id',
                'playlist_members.role',
                'playlist_members.accepted_at',
                'users.name',
                'users.username',
            ]);

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'role' => (string) $row->role,
            'accepted' => $row->accepted_at !== null,
            'name' => (string) $row->name,
            'username' => (string) $row->username,
        ])->all();
    }

    public static function voteCounts(Playlist $playlist): array
    {
        return DB::table('playlist_video_votes')
            ->where('playlist_id', $playlist->id)
            ->selectRaw('video_id, count(*) as votes')
            ->groupBy('video_id')
            ->pluck('votes', 'video_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private static function assertOwner(User $user, Playlist $playlist): void
    {
        if ((int) $playlist->user_id !== (int) $user->id) {
            throw ValidationException::withMessages(['playlist' => 'Only the owner can manage members and order.']);
        }
    }

    private static function memberPayload(object $row): array
    {
        return [
            'playlist_id' => (int) $row->playlist_id,
            'user_id' => (int) $row->user_id,
            'role' => (string) $row->role,
            'accepted' => $row->accepted_at !== null,
            'accepted_at' => $row->accepted_at,
        ];
    }
}
