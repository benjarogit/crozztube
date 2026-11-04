<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ShortResource;
use App\Models\Video;
use App\Services\Api\V1\PlaybackSessionService;
use App\Services\Api\V1\ReceiverPlaybackService;
use App\Services\Api\V1\VideoAccessService;
use App\Services\Api\V1\ViewerStateService;
use App\Support\Api\V1\PaginationPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShortsController extends Controller
{
    public function index(
        Request $request,
        VideoAccessService $access,
        ViewerStateService $viewerState,
    ): JsonResponse {
        $data = $request->validate([
            'cursor' => ['nullable', 'string', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
            'sort' => ['nullable', Rule::in(['latest', 'trending'])],
        ]);
        $perPage = (int) ($data['per_page'] ?? 8);
        $query = $access->publicQuery()
            ->shorts()
            ->with(['channel.user', 'category', 'language']);

        if (($data['sort'] ?? 'latest') === 'trending') {
            $query->orderByDesc('views_count')->orderByDesc('likes_count');
        } else {
            $query->orderByDesc('published_at');
        }

        $shorts = $query->orderByDesc('id')->cursorPaginate($perPage);
        $viewerState->hydrate($shorts->getCollection(), $request->user());

        return PaginationPayload::cursor(
            $shorts,
            ShortResource::collection($shorts->getCollection())->resolve($request),
        )->header('Cache-Control', 'private, no-store');
    }

    public function show(
        Request $request,
        Video $short,
        VideoAccessService $access,
        ViewerStateService $viewerState,
    ): JsonResponse {
        $access->assertViewable($short, $request->user());
        abort_unless($short->isShort(), 404, 'Short not found.');
        $short->load(['channel.user', 'category', 'language']);
        $viewerState->hydrateOne($short, $request->user(), includeReportState: false);

        return response()->json((new ShortResource($short))->resolve($request))
            ->header('Cache-Control', 'private, no-store');
    }

    public function playback(Request $request, Video $short, PlaybackSessionService $playback): JsonResponse
    {
        abort_unless($short->isShort(), 404, 'Short not found.');

        return response()->json($playback->forVideo($short, $request))
            ->header('Cache-Control', 'private, no-store');
    }

    public function receiverPlayback(
        Request $request,
        Video $short,
        ReceiverPlaybackService $receiverPlayback,
    ): JsonResponse {
        abort_unless($short->isShort(), 404, 'Short not found.');
        $input = $request->validate([
            'target' => ['required', Rule::in(['google_cast'])],
            'source_id' => ['nullable', 'string', 'max:100'],
            'position_seconds' => ['nullable', 'numeric', 'min:0', 'max:604800'],
        ]);

        return response()->json($receiverPlayback->forVideo($short, $request, $input))
            ->header('Cache-Control', 'private, no-store')
            ->header('Pragma', 'no-cache');
    }
}
