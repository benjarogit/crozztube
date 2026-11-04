<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserPublicResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MuteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userIds = DB::table('user_mutes')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')->pluck('muted_user_id');
        $users = User::query()->with('channel.user')->whereIn('id', $userIds)->get()
            ->sortBy(fn (User $user) => $userIds->search($user->id))->values();

        return response()->json([
            'users' => UserPublicResource::collection($users)->resolve($request),
            'count' => $users->count(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, User $user): JsonResponse
    {
        abort_if((int) $user->id === (int) $request->user()->id, 422, 'You cannot mute yourself.');
        DB::table('user_mutes')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'muted_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'user_id' => (int) $user->id,
            'muted' => true,
            'count' => $this->count($request),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        DB::table('user_mutes')
            ->where('user_id', $request->user()->id)->where('muted_user_id', $user->id)->delete();

        return response()->json([
            'user_id' => (int) $user->id,
            'muted' => false,
            'count' => $this->count($request),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function count(Request $request): int
    {
        return DB::table('user_mutes')->where('user_id', $request->user()->id)->count();
    }
}
