<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\SharedPlaylists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SharedPlaylistController extends Controller
{
    public function members(Request $request, Playlist $playlist): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $playlist), 404, 'Playlist not found.');

        return response()->json([
            'role' => SharedPlaylists::role($request->user(), $playlist),
            'members' => SharedPlaylists::members($playlist),
        ]);
    }

    public function invite(Request $request, Playlist $playlist): JsonResponse
    {
        abort_unless(Gate::allows('manageMembers', $playlist), 404, 'Playlist not found.');
        $data = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'role' => ['required', Rule::in([SharedPlaylists::ROLE_EDITOR, SharedPlaylists::ROLE_VIEWER])],
        ]);
        $invitee = User::query()->where('username', ltrim($data['username'], '@'))->firstOrFail();

        return response()->json(SharedPlaylists::invite($request->user(), $playlist, $invitee, $data['role']), 201);
    }

    public function accept(Request $request, Playlist $playlist): JsonResponse
    {
        return response()->json(SharedPlaylists::accept($request->user(), $playlist));
    }

    public function revoke(Request $request, Playlist $playlist, User $user): JsonResponse
    {
        abort_unless(Gate::allows('manageMembers', $playlist), 404, 'Playlist not found.');

        return response()->json(SharedPlaylists::revoke($request->user(), $playlist, $user));
    }

    public function reorder(Request $request, Playlist $playlist): JsonResponse
    {
        abort_unless(Gate::allows('reorder', $playlist), 404, 'Playlist not found.');
        $data = $request->validate([
            'video_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'video_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return response()->json(SharedPlaylists::reorder($request->user(), $playlist, $data['video_ids']));
    }

    public function vote(Request $request, Playlist $playlist, Video $video): JsonResponse
    {
        abort_unless(Gate::allows('vote', $playlist), 404, 'Playlist not found.');

        return response()->json(SharedPlaylists::vote($request->user(), $playlist, (int) $video->id));
    }
}
