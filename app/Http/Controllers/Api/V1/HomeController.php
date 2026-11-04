<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LiveStreamResource;
use App\Http\Resources\Api\V1\VideoCardResource;
use App\Models\Video;
use App\Services\Api\V1\LiveStreamAccessService;
use App\Services\Api\V1\VideoAccessService;
use App\Services\Api\V1\ViewerStateService;
use App\Support\Client\ClientContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function index(
        Request $request,
        FeedController $feed,
        VideoAccessService $access,
        ViewerStateService $viewerState,
        LiveStreamAccessService $liveAccess,
    ): JsonResponse {
        $input = $request->validate([
            'row_limit' => ['nullable', 'integer', 'min:4', 'max:12'],
            'rotation' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'exclude_ids' => ['nullable', 'string', 'max:500', 'regex:/^[1-9][0-9]*(,[1-9][0-9]*)*$/'],
        ]);
        $client = ClientContext::fromRequest($request);
        $limit = min((int) $request->integer('row_limit', $client->isTv() ? 10 : 8), 12);
        $relations = ['channel.user'];

        $recommendedRequest = clone $request;
        // Opt-in rotation shares the website's hero ranking and recent-history
        // fallback. Older clients retain the original recommended feed.
        $recommendedRequest->query->replace([
            'sort' => isset($input['rotation']) ? 'hero' : 'recommended',
            'type' => 'videos', 'per_page' => $limit, 'page' => 1,
            'rotation' => $input['rotation'] ?? '',
            'exclude_ids' => $input['exclude_ids'] ?? '',
        ]);
        $recommended = $feed->index($recommendedRequest)->resource->getCollection()
            ->map(static fn ($item) => $item instanceof JsonResource ? $item->resource : $item)
            ->filter(fn ($video): bool => $video instanceof Video && $access->canView($video, null, false))
            ->values();
        $latest = $access->publicQuery()->standardVideos()->with($relations)
            ->latest('published_at')->latest('id')->take($limit)->get();
        $trending = $access->publicQuery()->standardVideos()->with($relations)
            ->orderByDesc('views_count')->orderByDesc('likes_count')->orderByDesc('id')->take($limit)->get();
        $shorts = $access->publicQuery()->shorts()->with($relations)
            ->latest('published_at')->latest('id')->take($limit)->get();
        $allVideos = $recommended->concat($latest)->concat($trending)->concat($shorts)->unique('id')->values();

        $continue = collect();
        $subscriptions = collect();
        if ($request->user()) {
            $continue = Video::query()
                ->select('videos.*')
                ->join('video_watch_progress', 'video_watch_progress.video_id', '=', 'videos.id')
                ->where('video_watch_progress.user_id', $request->user()->id)
                ->whereBetween('video_watch_progress.progress', [1, 94])
                ->where(fn ($query) => $query
                    ->where(fn ($public) => $access->constrainPublic($public))
                    ->orWhere('videos.user_id', $request->user()->id))
                ->with($relations)
                ->orderByDesc('video_watch_progress.updated_at')
                ->take($limit)
                ->get();
            $subscriptions = $access->publicQuery()
                ->whereIn('channel_id', DB::table('subscriptions')->where('user_id', $request->user()->id)->select('channel_id'))
                ->with($relations)
                ->latest('published_at')->take($limit)->get();
            $allVideos = $allVideos->concat($continue)->concat($subscriptions)->unique('id')->values();
        }
        $viewerState->hydrate($allVideos, $request->user());
        // A video may occur in multiple rows as distinct Eloquent instances.
        // Reuse the hydrated card for every row, so Continue Watching never
        // loses its viewer position when a recommendation has the same ID.
        $hydrated = $allVideos->keyBy('id');
        $recommended = $recommended->map(fn (Video $video) => $hydrated->get($video->id));
        $latest = $latest->map(fn (Video $video) => $hydrated->get($video->id));
        $trending = $trending->map(fn (Video $video) => $hydrated->get($video->id));
        $shorts = $shorts->map(fn (Video $video) => $hydrated->get($video->id));
        $continue = $continue->map(fn (Video $video) => $hydrated->get($video->id));
        $subscriptions = $subscriptions->map(fn (Video $video) => $hydrated->get($video->id));

        $live = $liveAccess->directoryQuery($request->user())
            ->where('status', 'live')
            ->with(['channel.user', 'category', 'replayVideo.channel.user'])
            ->latest('actual_started_at')
            ->take($limit)
            ->get();

        $rows = collect([
            $this->videoRow('recommended', 'Recommended', $recommended, $request),
            $this->videoRow('trending', 'Trending', $trending, $request),
            $this->videoRow('latest', 'Latest', $latest, $request),
            $this->videoRow('shorts', 'Shorts', $shorts, $request, 'portrait'),
            $live->isNotEmpty() ? [
                'id' => 'live',
                'title' => 'Live now',
                'layout' => 'landscape',
                'items' => LiveStreamResource::collection($live)->resolve($request),
                'next_url' => route('api.v1.home.section', ['section' => 'live']),
            ] : null,
            $continue->isNotEmpty() ? $this->videoRow('continue-watching', 'Continue watching', $continue, $request) : null,
            $subscriptions->isNotEmpty() ? $this->videoRow('subscriptions', 'From your subscriptions', $subscriptions, $request) : null,
        ])->filter()->values();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'presentation' => ['device_family' => $client->platform?->deviceFamily(), 'row_limit' => $limit],
            'rows' => $rows,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function section(Request $request, string $section): JsonResponse
    {
        return match ($section) {
            'recommended', 'trending', 'latest' => $this->videoSection($request, $section),
            'shorts' => app()->call([app(ShortsController::class), 'index'], ['request' => $request]),
            'live', 'upcoming', 'replay' => $this->liveSection($request, $section),
            'continue-watching' => app()->call([app(HistoryController::class), 'continueWatching'], ['request' => $request]),
            'subscriptions' => $this->subscriptionSection($request),
            default => abort(404, 'Home section not found.'),
        };
    }

    private function videoSection(Request $request, string $section): JsonResponse
    {
        $request->query->set('sort', $section === 'latest' ? 'latest' : $section);
        $request->query->set('type', 'videos');

        return app()->call([app(VideoController::class), 'index'], ['request' => $request]);
    }

    private function liveSection(Request $request, string $section): JsonResponse
    {
        $request->query->set('status', $section);

        return app()->call([app(LiveStreamController::class), 'index'], ['request' => $request]);
    }

    private function subscriptionSection(Request $request): JsonResponse
    {
        abort_unless($request->user(), 401);
        $request->query->set('subscribed', '1');

        return app()->call([app(LibraryController::class), 'subscriptionVideos'], ['request' => $request]);
    }

    private function videoRow(string $id, string $title, Collection $videos, Request $request, string $layout = 'landscape'): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'layout' => $layout,
            'items' => VideoCardResource::collection($videos)->resolve($request),
            'next_url' => route('api.v1.home.section', ['section' => $id]),
        ];
    }
}
