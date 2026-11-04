<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\VideoCardResource;
use App\Http\Resources\Api\V1\VideoDetailResource;
use App\Models\Video;
use App\Services\Api\V1\PlaybackSessionService;
use App\Services\Api\V1\ReceiverPlaybackService;
use App\Services\Api\V1\VideoAccessService;
use App\Services\Api\V1\ViewerStateService;
use App\Support\Api\V1\PaginationPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class VideoController extends Controller
{
    public function index(Request $request, FeedController $feed, ViewerStateService $viewerState): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'channel' => ['nullable', 'string', 'max:140'],
            'category' => ['nullable', 'string', 'max:140'],
            'tag' => ['nullable', 'string', 'max:120'],
            'upload_date' => ['nullable', Rule::in(['today', 'week', 'month'])],
            'type' => ['nullable', Rule::in(['video', 'videos', 'short', 'shorts'])],
            'sort' => ['nullable', Rule::in(['latest', 'trending', 'recommended', 'hero'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $collection = $feed->index($request);
        $paginator = $collection->resource;
        $paginator->setCollection($paginator->getCollection()->map(
            static fn ($item) => $item instanceof JsonResource ? $item->resource : $item,
        ));
        $viewerState->hydrate($paginator->getCollection(), $request->user());
        $items = VideoCardResource::collection($paginator->getCollection())->resolve($request);

        return PaginationPayload::lengthAware($paginator, $items);
    }

    public function show(
        Request $request,
        Video $video,
        VideoAccessService $access,
        ViewerStateService $viewerState,
    ): JsonResponse {
        $access->assertViewable($video, $request->user());
        $video->load(['channel.user', 'category', 'language']);
        $viewerState->hydrateOne($video, $request->user());

        return response()->json((new VideoDetailResource($video))->resolve($request));
    }

    public function playback(
        Request $request,
        Video $video,
        PlaybackSessionService $playback,
    ): JsonResponse {
        $session = $playback->forVideo($video, $request);
        $this->recordView($video, $request);

        return response()->json($session)
            ->header('Cache-Control', 'private, no-store')
            ->header('Pragma', 'no-cache');
    }

    public function receiverPlayback(
        Request $request,
        Video $video,
        ReceiverPlaybackService $receiverPlayback,
    ): JsonResponse {
        abort_if($video->isShort(), 404, 'Video not found.');
        $input = $request->validate([
            'target' => ['required', Rule::in(['google_cast'])],
            'source_id' => ['nullable', 'string', 'max:100'],
            'position_seconds' => ['nullable', 'numeric', 'min:0', 'max:604800'],
        ]);

        return response()->json($receiverPlayback->forVideo($video, $request, $input))
            ->header('Cache-Control', 'private, no-store')
            ->header('Pragma', 'no-cache');
    }

    public function related(
        Request $request,
        Video $video,
        FeedController $feed,
        VideoAccessService $access,
        ViewerStateService $viewerState,
    ): JsonResponse {
        $access->assertViewable($video, $request->user());
        $request->query->set('sort', 'related');
        $request->query->set('related_to', $video->slug);
        $request->query->set('type', $video->isShort() ? 'shorts' : 'videos');
        $request->query->set('per_page', min(max((int) $request->integer('per_page', 12), 1), 30));

        $collection = $feed->index($request);
        $paginator = $collection->resource;
        $paginator->setCollection($paginator->getCollection()->map(
            static fn ($item) => $item instanceof JsonResource ? $item->resource : $item,
        ));
        $viewerState->hydrate($paginator->getCollection(), $request->user());

        return PaginationPayload::lengthAware(
            $paginator,
            VideoCardResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    private function recordView(Video $video, Request $request): void
    {
        $audience = $request->user()?->id
            ? 'user:'.$request->user()->id
            : 'anon:'.hash('sha256', $request->ip().'|'.substr((string) $request->userAgent(), 0, 200));
        $key = 'playhub:api:v1:view:'.$video->id.':'.hash('sha256', $audience);

        if (Cache::add($key, true, now()->addMinutes(30))) {
            $video->increment('views_count');
        }
    }
}
