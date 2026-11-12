<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\SharedPlaylists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$check(Schema::hasTable('playlist_members') && Schema::hasTable('playlist_video_votes'), 'Shared playlist tables must exist.');
$check(Schema::hasColumn('playlist_video', 'added_by_user_id'), 'playlist_video.added_by_user_id must exist.');

$source = Video::query()->whereNotNull('slug')->first();
$check($source !== null, 'Shared playlist checks require one video.');

DB::beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $owner = User::query()->create([
        'name' => 'Playlist owner',
        'username' => 'pl-owner-'.$suffix,
        'email' => 'pl-owner-'.$suffix.'@example.invalid',
        'password' => 'secret',
    ]);
    $editor = User::query()->create([
        'name' => 'Playlist editor',
        'username' => 'pl-editor-'.$suffix,
        'email' => 'pl-editor-'.$suffix.'@example.invalid',
        'password' => 'secret',
    ]);
    $viewer = User::query()->create([
        'name' => 'Playlist viewer',
        'username' => 'pl-viewer-'.$suffix,
        'email' => 'pl-viewer-'.$suffix.'@example.invalid',
        'password' => 'secret',
    ]);
    $outsider = User::query()->create([
        'name' => 'Playlist outsider',
        'username' => 'pl-out-'.$suffix,
        'email' => 'pl-out-'.$suffix.'@example.invalid',
        'password' => 'secret',
    ]);

    $channel = $owner->channel()->firstOrCreate(
        ['user_id' => $owner->id],
        ['name' => $owner->name, 'slug' => 'pl-owner-'.$suffix, 'bio' => null],
    );

    $playlist = Playlist::query()->create([
        'user_id' => $owner->id,
        'channel_id' => $channel->id,
        'title' => 'Shared check '.$suffix,
        'description' => null,
        'privacy' => 'private',
        'color' => '#112233',
    ]);

    $videoA = $source->replicate();
    $videoA->forceFill([
        'slug' => 'pl-a-'.$suffix,
        'user_id' => $owner->id,
        'channel_id' => $channel->id,
        'visibility' => 'public',
        'status' => 'ready',
        'approval_status' => 'approved',
        'sale_enabled' => false,
        'import_url' => 'https://example.invalid/pl-a-'.$suffix,
        'video_path' => 'external:test:pla'.$suffix,
    ])->save();
    $videoB = $source->replicate();
    $videoB->forceFill([
        'slug' => 'pl-b-'.$suffix,
        'user_id' => $owner->id,
        'channel_id' => $channel->id,
        'visibility' => 'public',
        'status' => 'ready',
        'approval_status' => 'approved',
        'sale_enabled' => false,
        'import_url' => 'https://example.invalid/pl-b-'.$suffix,
        'video_path' => 'external:test:plb'.$suffix,
    ])->save();

    $invite = SharedPlaylists::invite($owner, $playlist, $editor, SharedPlaylists::ROLE_EDITOR);
    $check($invite['accepted'] === false && $invite['role'] === 'editor', 'Owner can invite an editor.');
    $check(SharedPlaylists::hasPendingInvite($editor, $playlist) && SharedPlaylists::canView($editor, $playlist), 'Pending invitees can open the playlist to accept.');
    $accepted = SharedPlaylists::accept($editor, $playlist);
    $check($accepted['accepted'] === true, 'Invitee can accept.');
    SharedPlaylists::invite($owner, $playlist, $viewer, SharedPlaylists::ROLE_VIEWER);
    SharedPlaylists::accept($viewer, $playlist);

    $rejected = false;
    try {
        SharedPlaylists::invite($editor, $playlist, $outsider, SharedPlaylists::ROLE_VIEWER);
    } catch (ValidationException) {
        $rejected = true;
    }
    $check($rejected, 'Only the owner invites members.');

    SharedPlaylists::addVideo($owner, $playlist, $videoA);
    SharedPlaylists::addVideo($editor, $playlist, $videoB);
    $check(
        (int) DB::table('playlist_video')->where('playlist_id', $playlist->id)->where('video_id', $videoB->id)->value('added_by_user_id') === (int) $editor->id,
        'Editor additions record the contributor.'
    );

    $editorBlocked = false;
    try {
        SharedPlaylists::removeVideo($editor, $playlist, (int) $videoA->id);
    } catch (ValidationException) {
        $editorBlocked = true;
    }
    $check($editorBlocked, 'Editors cannot remove the owner\'s entries.');
    SharedPlaylists::removeVideo($editor, $playlist, (int) $videoB->id);
    $check(
        ! DB::table('playlist_video')->where('playlist_id', $playlist->id)->where('video_id', $videoB->id)->exists(),
        'Editors can remove their own entries.'
    );
    SharedPlaylists::addVideo($editor, $playlist, $videoB);

    $order = SharedPlaylists::reorder($owner, $playlist, [(int) $videoB->id, (int) $videoA->id]);
    $check($order['video_ids'] === [(int) $videoB->id, (int) $videoA->id], 'Owner can reorder every entry.');
    $sorted = DB::table('playlist_video')->where('playlist_id', $playlist->id)->orderBy('sort_order')->pluck('video_id')->map(fn ($id) => (int) $id)->all();
    $check($sorted === [(int) $videoB->id, (int) $videoA->id], 'Manual order is stored.');

    $beforeVotes = $sorted;
    $vote = SharedPlaylists::vote($viewer, $playlist, (int) $videoA->id);
    $check($vote['sort_unchanged'] === true && $vote['votes'] === 1, 'Votes do not change manual order.');
    $afterVotes = DB::table('playlist_video')->where('playlist_id', $playlist->id)->orderBy('sort_order')->pluck('video_id')->map(fn ($id) => (int) $id)->all();
    $check($beforeVotes === $afterVotes, 'Voting leaves sort_order untouched.');

    $outsiderVote = false;
    try {
        SharedPlaylists::vote($outsider, $playlist, (int) $videoA->id);
    } catch (ValidationException) {
        $outsiderVote = true;
    }
    $check($outsiderVote, 'Non-members cannot vote.');

    SharedPlaylists::revoke($owner, $playlist, $editor);
    $check(SharedPlaylists::role($editor, $playlist) === null, 'Owner can revoke rights.');
    $editorAddBlocked = false;
    try {
        SharedPlaylists::addVideo($editor, $playlist, $videoA);
    } catch (ValidationException) {
        $editorAddBlocked = true;
    }
    $check($editorAddBlocked, 'Revoked editors lose write access.');

    // Concurrent-style: lock playlist, reorder, ensure a second reorder with stale ids fails.
    $raceRejected = false;
    try {
        SharedPlaylists::reorder($owner, $playlist, [(int) $videoA->id]);
    } catch (ValidationException) {
        $raceRejected = true;
    }
    $check($raceRejected, 'Partial reorder payloads are rejected under the locked playlist.');
} finally {
    DB::rollBack();
}

echo "Shared playlist checks passed: {$checks}\n";
