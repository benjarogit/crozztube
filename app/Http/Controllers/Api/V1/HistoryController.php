<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateWatchProgressRequest;
use App\Http\Resources\Api\V1\VideoCardResource;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;
use App\Services\Api\V1\ViewerStateService;
use App\Services\Api\V1\WatchProgressService;
use App\Support\Api\V1\PaginationPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HistoryController extends Controller
{
    public function __construct(private readonly VideoAccessService $videoAccess) {}

    public function index(Request $request, ViewerStateService $viewerState): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'in_progress', 'completed'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $status = (string) ($data['status'] ?? 'all');
        $videos = $this->query($request)
            ->when($status === 'in_progress', fn ($query) => $query->whereBetween('video_watch_progress.progress', [1, 94]))
            ->when($status === 'completed', fn ($query) => $query->where('video_watch_progress.progress', '>=', 95))
            ->orderByDesc('video_watch_progress.updated_at')
            ->orderByDesc('videos.id')
            ->paginate((int) ($data['per_page'] ?? 24));
        $viewerState->hydrate($videos->getCollection(), $request->user());

        return PaginationPayload::lengthAware(
            $videos,
            VideoCardResource::collection($videos->getCollection())->resolve($request),
        )->header('Cache-Control', 'private, no-store');
    }

    public function continueWatching(Request $request, ViewerStateService $viewerState): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $videos = $this->query($request)
            ->whereBetween('video_watch_progress.progress', [1, 94])
            ->orderByDesc('video_watch_progress.updated_at')
            ->orderByDesc('videos.id')
            ->paginate(min(max((int) $request->integer('per_page', 12), 1), 50));
        $viewerState->hydrate($videos->getCollection(), $request->user());

        return PaginationPayload::lengthAware(
            $videos,
            VideoCardResource::collection($videos->getCollection())->resolve($request),
        )->header('Cache-Control', 'private, no-store');
    }

    public function update(
        UpdateWatchProgressRequest $request,
        Video $video,
        WatchProgressService $progress,
    ): JsonResponse {
        return response()->json($progress->update($request->user(), $video, $request->validated()));
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        $deleted = DB::table('video_watch_progress')
            ->where('user_id', $request->user()->id)
            ->where('video_id', $video->id)
            ->delete();

        abort_unless($deleted > 0, 404, 'History entry not found.');

        return response()->json(['removed' => true]);
    }

    public function clear(Request $request): JsonResponse
    {
        $deleted = DB::table('video_watch_progress')->where('user_id', $request->user()->id)->delete();

        return response()->json(['cleared' => true, 'removed_count' => $deleted]);
    }

    private function query(Request $request)
    {
        return $this->videoAccess->viewableQuery($request->user())
            ->select('videos.*')
            ->join('video_watch_progress', 'video_watch_progress.video_id', '=', 'videos.id')
            ->where('video_watch_progress.user_id', $request->user()->id)
            ->with(['channel.user']);
    }
}
