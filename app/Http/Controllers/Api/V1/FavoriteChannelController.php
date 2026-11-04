<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChannelSummaryResource;
use App\Http\Resources\Api\V1\VideoCardResource;
use App\Models\Channel;
use App\Services\Api\V1\VideoAccessService;
use App\Services\Api\V1\ViewerStateService;
use App\Support\Api\V1\PaginationPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FavoriteChannelController extends Controller
{
    public function index(Request $request, VideoAccessService $access): JsonResponse
    {
        $this->pagination($request);
        $channels = Channel::query()->select('channels.*')
            ->join('channel_favorites', 'channel_favorites.channel_id', '=', 'channels.id')
            ->where('channel_favorites.user_id', $request->user()->id)
            ->whereHas('user', fn ($user) => $user->publicProfileEnabled())
            ->with('user')->withCount(['videos' => fn ($videos) => $access->constrainPublic($videos)])
            ->orderByDesc('channel_favorites.created_at')->orderByDesc('channels.id')
            ->paginate($request->integer('per_page', 20));
        $subscribed = DB::table('subscriptions')->where('user_id', $request->user()->id)
            ->whereIn('channel_id', $channels->getCollection()->pluck('id'))->pluck('channel_id')->flip();
        $channels->getCollection()->each(fn (Channel $channel) => $channel->setAttribute('viewer_subscribed', $subscribed->has($channel->id)));

        return PaginationPayload::lengthAware($channels, ChannelSummaryResource::collection($channels->getCollection())->resolve($request))
            ->header('Cache-Control', 'private, no-store');
    }

    public function videos(Request $request, VideoAccessService $access, ViewerStateService $states): JsonResponse
    {
        $this->pagination($request);
        $videos = $access->publicQuery()
            ->whereIn('channel_id', DB::table('channel_favorites')->where('user_id', $request->user()->id)->select('channel_id'))
            ->with('channel.user')->latest('published_at')->latest('id')->paginate($request->integer('per_page', 20));
        $states->hydrate($videos->getCollection(), $request->user());

        return PaginationPayload::lengthAware($videos, VideoCardResource::collection($videos->getCollection())->resolve($request))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Channel $channel): JsonResponse
    {
        abort_if((int) $channel->user_id === (int) $request->user()->id, 422, 'You cannot favorite your own channel.');
        abort_unless(Channel::query()->whereKey($channel->id)->whereHas('user', fn ($user) => $user->publicProfileEnabled())->exists(), 404, 'Channel not found.');
        DB::table('channel_favorites')->insertOrIgnore(['user_id' => $request->user()->id, 'channel_id' => $channel->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['favorite' => true])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, Channel $channel): JsonResponse
    {
        DB::table('channel_favorites')->where('user_id', $request->user()->id)->where('channel_id', $channel->id)->delete();

        return response()->json(['favorite' => false])->header('Cache-Control', 'private, no-store');
    }

    private function pagination(Request $request): void
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:10000'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
    }
}
