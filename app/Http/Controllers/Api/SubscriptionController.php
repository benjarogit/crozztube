<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Subscription;
use App\Models\UserNotification;
use App\Models\Video;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\VideoResource;
use App\Services\Api\V1\EngagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function __construct(private readonly EngagementService $engagement) {}

    public function index(Request $request): JsonResponse
    {
        $channels = Channel::query()
            ->whereIn('id', Subscription::query()
                ->where('user_id', $request->user()->id)
                ->select('channel_id'))
            ->withCount(['videos' => fn ($query) => $query->public()])
            ->latest('updated_at')
            ->get();
        $channels->each(function (Channel $channel): void {
            $channel->is_subscribed = true;
        });

        $videos = Video::query()
            ->whereIn('channel_id', $channels->pluck('id'))
            ->public()
            ->with(['channel.user', 'user', 'category', 'language'])
            ->latest('published_at')
            ->latest()
            ->take(24)
            ->get();
        $progress = DB::table('video_watch_progress')
            ->where('user_id', $request->user()->id)
            ->whereIn('video_id', $videos->pluck('id'))
            ->get()
            ->keyBy('video_id');
        $videos->each(function (Video $video) use ($progress): void {
            $row = $progress->get($video->id);
            if (! $row) {
                return;
            }

            $video->progress = (int) $row->progress;
            $video->current_time = (int) $row->current_time_seconds;
            $video->progress_updated_at = $row->updated_at;
        });

        return response()->json([
            'channels' => ChannelResource::collection($channels)->resolve($request),
            'videos' => VideoResource::collection($videos)->resolve($request),
        ]);
    }

    public function favorites(Request $request): JsonResponse
    {
        $channels = Channel::query()
            ->join('channel_favorites', 'channel_favorites.channel_id', '=', 'channels.id')
            ->where('channel_favorites.user_id', $request->user()->id)
            ->select('channels.*')
            ->with('user')
            ->withCount(['videos' => fn ($query) => $query->public()])
            ->orderByDesc('channel_favorites.created_at')
            ->get();
        $subscribedIds = DB::table('subscriptions')
            ->where('user_id', $request->user()->id)
            ->whereIn('channel_id', $channels->pluck('id'))
            ->pluck('channel_id')
            ->flip();
        $channels->each(function (Channel $channel) use ($subscribedIds): void {
            $channel->is_favorite = true;
            $channel->is_subscribed = $subscribedIds->has($channel->id);
        });

        $videos = Video::query()
            ->whereIn('channel_id', $channels->pluck('id'))
            ->public()
            ->with(['channel.user', 'user', 'category', 'language'])
            ->latest('published_at')
            ->latest()
            ->take(30)
            ->get();

        return response()->json([
            'channels' => ChannelResource::collection($channels)->resolve($request),
            'videos' => VideoResource::collection($videos)->resolve($request),
        ]);
    }

    public function toggleFavorite(Request $request, Channel $channel): JsonResponse
    {
        abort_if($channel->user_id === $request->user()->id, 422, 'You cannot favorite your own channel.');

        $favorite = DB::transaction(function () use ($request, $channel): bool {
            $query = DB::table('channel_favorites')
                ->where('user_id', $request->user()->id)
                ->where('channel_id', $channel->id);

            if ($query->lockForUpdate()->exists()) {
                $query->delete();

                return false;
            }

            DB::table('channel_favorites')->insert([
                'user_id' => $request->user()->id,
                'channel_id' => $channel->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });

        return response()->json(['favorite' => $favorite]);
    }

    public function toggle(Request $request, Channel $channel): JsonResponse
    {
        $result = $this->engagement->toggleSubscription($request->user(), $channel);
        unset($result['changed']);

        return response()->json($result);
    }

    private function notificationsMuted(int $recipientId, int $actorId): bool
    {
        return DB::table('user_mutes')
            ->where('user_id', $recipientId)
            ->where('muted_user_id', $actorId)
            ->exists();
    }
}
