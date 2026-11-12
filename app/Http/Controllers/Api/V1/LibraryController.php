<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChannelSummaryResource;
use App\Http\Resources\Api\V1\PlaylistSummaryResource;
use App\Http\Resources\Api\V1\VideoCardResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;
use App\Services\Api\V1\ViewerStateService;
use App\Support\Api\V1\PaginationPayload;
use App\Support\WatchProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class LibraryController extends Controller
{
    public function __construct(private readonly VideoAccessService $videoAccess) {}

    public function overview(Request $request, ViewerStateService $states): JsonResponse
    {
        $limit = min(max((int) $request->integer('row_limit', 10), 4), 12);
        $watchLater = $this->watchLaterQuery($request)->take($limit)->get();
        $liked = $this->likedQuery($request)->take($limit)->get();
        $continue = WatchProgress::applyContinueScope($this->progressQuery($request))->take($limit)->get();
        $history = $this->progressQuery($request)->take($limit)->get();
        // Hydrate every row instance: the same video is loaded as separate models
        // for Watch Later, Liked, Continue Watching, and History.
        $all = $watchLater->concat($liked)->concat($continue)->concat($history)->values();
        $states->hydrate($all, $request->user());
        $playlists = Playlist::query()->where('user_id', $request->user()->id)
            ->with(['channel.user', 'coverVideo' => fn ($query) => $this->videoAccess->constrainViewable($query, $request->user())])
            ->withCount(['videos' => fn ($query) => $this->videoAccess->constrainViewable($query, $request->user())])
            ->orderByDesc('updated_at')->orderByDesc('id')->take($limit)->get();
        $channels = $this->subscribedChannelsQuery($request)->take($limit)->get();
        $channels->each->setAttribute('viewer_subscribed', true);

        return response()->json([
            'counts' => [
                'history' => $this->progressQuery($request)->count(),
                'continue_watching' => WatchProgress::applyContinueScope($this->progressQuery($request))->count(),
                'watch_later' => $this->watchLaterQuery($request)->count(),
                'liked_videos' => $this->likedQuery($request)->count(),
                'playlists' => Playlist::query()->where('user_id', $request->user()->id)->count(),
            ],
            'rows' => [
                $this->row('continue-watching', 'Continue watching', VideoCardResource::collection($continue)->resolve($request)),
                $this->row('history', 'History', VideoCardResource::collection($history)->resolve($request)),
                $this->row('watch-later', 'Watch later', VideoCardResource::collection($watchLater)->resolve($request)),
                $this->row('liked-videos', 'Liked videos', VideoCardResource::collection($liked)->resolve($request)),
                $this->row('playlists', 'My playlists', PlaylistSummaryResource::collection($playlists)->resolve($request)),
                $this->row('subscriptions', 'Subscriptions', ChannelSummaryResource::collection($channels)->resolve($request)),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function watchLater(Request $request, ViewerStateService $states): JsonResponse
    {
        $filters = $this->libraryVideoFilters($request);

        return $this->videoPage($request, $this->applyVideoType(
            $this->watchLaterQuery($request, $filters['sort']), $filters['type'],
        ), $states);
    }

    public function playlistVideos(Request $request, ViewerStateService $states): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        // Library editing remains available when new uploads are disabled.
        $query = Video::query()->where('user_id', $request->user()->id)->with('channel.user');
        if ($search = trim($filters['q'] ?? '')) {
            $query->where(fn ($query) => $query->where('title', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%'));
        }
        $videos = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 20);
        $states->hydrate($videos->getCollection(), $request->user());

        return PaginationPayload::lengthAware($videos, VideoCardResource::collection($videos->getCollection())->resolve($request))
            ->header('Cache-Control', 'private, no-store');
    }

    public function likedVideos(Request $request, ViewerStateService $states): JsonResponse
    {
        $filters = $this->libraryVideoFilters($request);

        return $this->videoPage($request, $this->applyVideoType(
            $this->likedQuery($request, $filters['sort']), $filters['type'],
        ), $states);
    }

    public function purchasedVideos(Request $request, ViewerStateService $states): JsonResponse
    {
        abort_unless(Schema::hasTable('video_purchases'), 404, 'Purchased videos are unavailable.');
        $query = Video::query()->select('videos.*')
            ->join('video_purchases', 'video_purchases.video_id', '=', 'videos.id')
            ->where('video_purchases.buyer_id', $request->user()->id)
            ->where('video_purchases.status', 'completed')
            ->whereIn('videos.visibility', ['public', 'unlisted'])
            ->where('videos.status', 'ready')
            ->where(fn ($approval) => $approval->whereNull('videos.approval_status')->orWhere('videos.approval_status', 'approved'))
            ->where(fn ($schedule) => $schedule->whereNull('videos.scheduled_at')->orWhere('videos.scheduled_at', '<=', now()))
            ->with(['channel.user'])->orderByDesc('video_purchases.created_at');

        return $this->videoPage($request, $query, $states);
    }

    public function subscribedChannels(Request $request): JsonResponse
    {
        $channels = $this->subscribedChannelsQuery($request)
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));
        $channels->getCollection()->each->setAttribute('viewer_subscribed', true);

        return $this->privateResponse(PaginationPayload::lengthAware(
            $channels,
            ChannelSummaryResource::collection($channels->getCollection())->resolve($request),
        ));
    }

    public function subscriptionVideos(Request $request, ViewerStateService $states): JsonResponse
    {
        $query = $this->strictPublicVideos()
            ->whereIn('channel_id', DB::table('subscriptions')->where('user_id', $request->user()->id)->select('channel_id'))
            ->with(['channel.user'])->latest('published_at');

        return $this->videoPage($request, $query, $states);
    }

    public function playlists(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'sort' => ['nullable', Rule::in(['updated', 'newest', 'title'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $sort = $filters['sort'] ?? 'updated';
        $playlists = Playlist::query()->where('user_id', $request->user()->id)
            ->with(['channel.user', 'coverVideo' => fn ($query) => $this->videoAccess->constrainViewable($query, $request->user())])
            ->withCount(['videos' => fn ($query) => $this->videoAccess->constrainViewable($query, $request->user())])
            ->when($sort === 'updated', fn ($query) => $query->orderByDesc('updated_at'))
            ->when($sort === 'newest', fn ($query) => $query->orderByDesc('created_at'))
            ->when($sort === 'title', fn ($query) => $query->orderBy('title'))
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));

        return $this->privateResponse(PaginationPayload::lengthAware(
            $playlists,
            PlaylistSummaryResource::collection($playlists->getCollection())->resolve($request),
        ));
    }

    public function saveWatchLater(Request $request, Video $video, VideoAccessService $access): JsonResponse
    {
        $access->assertViewable($video, $request->user());
        DB::table('watch_laters')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'video_id' => $video->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['video_id' => $video->id, 'watch_later' => true])
            ->header('Cache-Control', 'private, no-store');
    }

    public function removeWatchLater(Request $request, Video $video, VideoAccessService $access): JsonResponse
    {
        $saved = DB::table('watch_laters')
            ->where('user_id', $request->user()->id)
            ->where('video_id', $video->id)
            ->exists();
        if (! $saved) {
            $access->assertViewable($video, $request->user());
        }

        DB::table('watch_laters')->where('user_id', $request->user()->id)->where('video_id', $video->id)->delete();

        return response()->json(['video_id' => $video->id, 'watch_later' => false])
            ->header('Cache-Control', 'private, no-store');
    }

    private function videoPage(Request $request, $query, ViewerStateService $states): JsonResponse
    {
        $videos = $query->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));
        $states->hydrate($videos->getCollection(), $request->user());

        return $this->privateResponse(PaginationPayload::lengthAware(
            $videos,
            VideoCardResource::collection($videos->getCollection())->resolve($request),
        ));
    }

    private function watchLaterQuery(Request $request, string $sort = 'recent')
    {
        return $this->visibleOwnedQuery($request)
            ->join('watch_laters', 'watch_laters.video_id', '=', 'videos.id')
            ->where('watch_laters.user_id', $request->user()->id)
            ->when($sort === 'recent', fn ($query) => $query->orderByDesc('watch_laters.created_at'))
            ->when($sort === 'oldest', fn ($query) => $query->orderBy('watch_laters.created_at'))
            ->when($sort === 'published', fn ($query) => $query->orderByDesc('videos.published_at'))
            ->orderByDesc('videos.id');
    }

    private function likedQuery(Request $request, string $sort = 'recent')
    {
        return $this->visibleOwnedQuery($request)
            ->join('likes', fn ($join) => $join->on('likes.likeable_id', '=', 'videos.id')->where('likes.likeable_type', Video::class))
            ->where('likes.user_id', $request->user()->id)
            ->when($sort === 'recent', fn ($query) => $query->orderByDesc('likes.created_at'))
            ->when($sort === 'oldest', fn ($query) => $query->orderBy('likes.created_at'))
            ->when($sort === 'published', fn ($query) => $query->orderByDesc('videos.published_at'))
            ->orderByDesc('videos.id');
    }

    private function progressQuery(Request $request)
    {
        return $this->visibleOwnedQuery($request)
            ->join('video_watch_progress', 'video_watch_progress.video_id', '=', 'videos.id')
            ->where('video_watch_progress.user_id', $request->user()->id)
            ->orderByDesc('video_watch_progress.updated_at')->orderByDesc('videos.id');
    }

    private function visibleOwnedQuery(Request $request)
    {
        return $this->videoAccess->viewableQuery($request->user())->select('videos.*')
            ->with(['channel.user']);
    }

    private function subscribedChannelsQuery(Request $request)
    {
        $query = Channel::query()->select('channels.*')
            ->join('subscriptions', 'subscriptions.channel_id', '=', 'channels.id')
            ->where('subscriptions.user_id', $request->user()->id)
            ->with('user')->withCount(['videos' => fn ($query) => $this->applyStrictPublicConstraints($query)])
            ->orderByDesc('subscriptions.created_at');

        return $query;
    }

    private function strictPublicVideos()
    {
        return $this->applyStrictPublicConstraints(Video::query());
    }

    private function applyStrictPublicConstraints($query)
    {
        return $query
            ->where('videos.visibility', 'public')
            ->where('videos.status', 'ready')
            ->where(fn ($approval) => $approval->whereNull('videos.approval_status')->orWhere('videos.approval_status', 'approved'))
            ->where(fn ($schedule) => $schedule->whereNull('videos.scheduled_at')->orWhere('videos.scheduled_at', '<=', now()));
    }

    private function libraryVideoFilters(Request $request): array
    {
        $filters = $request->validate([
            'sort' => ['nullable', Rule::in(['recent', 'oldest', 'published'])],
            'type' => ['nullable', Rule::in(['all', 'videos', 'shorts', 'replays'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return ['sort' => $filters['sort'] ?? 'recent', 'type' => $filters['type'] ?? 'all'];
    }

    private function applyVideoType($query, string $type)
    {
        return match ($type) {
            'videos' => $query->standardVideos(),
            'shorts' => $query->shorts(),
            'replays' => $query->where('videos.source_type', 'live_replay'),
            default => $query,
        };
    }

    private function row(string $id, string $title, array $items): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'items' => $items,
            'next_url' => route(match ($id) {
                'continue-watching' => 'api.v1.history.continue',
                'history' => 'api.v1.history.index',
                'watch-later' => 'api.v1.library.watch-later',
                'liked-videos' => 'api.v1.library.liked-videos',
                'playlists' => 'api.v1.library.playlists',
                'subscriptions' => 'api.v1.library.subscriptions',
            }),
        ];
    }

    private function privateResponse(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'private, no-store');
    }
}
