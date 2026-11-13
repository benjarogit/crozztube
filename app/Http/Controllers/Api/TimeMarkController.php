<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Models\VideoTimeMark;
use App\Support\TimeMarks;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeMarkController extends Controller
{
    public function index(Request $request, Video $video): JsonResponse
    {
        $this->assertAccess($request, $video);

        return response()->json([
            'time_marks' => TimeMarks::visible($video, null, CarbonImmutable::now()),
        ]);
    }

    public function mine(Request $request, Video $video): JsonResponse
    {
        $this->assertAccess($request, $video);
        return response()->json([
            'time_marks' => TimeMarks::visible($video, $request->user(), CarbonImmutable::now()),
        ]);
    }

    public function share(Request $request, Video $video): JsonResponse
    {
        $this->assertAccess($request, $video);
        return response()->json(TimeMarks::share($video, CarbonImmutable::now()));
    }

    public function rank(Request $request, Video $video): JsonResponse
    {
        $this->assertAccess($request, $video);
        $now = CarbonImmutable::now();

        return response()->json([
            'week' => TimeMarks::ranked($video, 'week', $now),
            'season' => TimeMarks::ranked($video, 'season', $now),
        ]);
    }

    public function store(Request $request, Video $video): JsonResponse
    {
        $this->assertAccess($request, $video);
        $data = $request->validate([
            'start_seconds' => ['required', 'integer', 'min:0'],
            'body' => ['nullable', 'string', 'max:280'],
            'visibility' => ['required', 'in:private,public'],
        ]);
        $mark = TimeMarks::create($request->user(), $video, (int) $data['start_seconds'], $data['body'] ?? null, $data['visibility']);

        return response()->json(['time_mark' => $mark->only(['id', 'start_seconds', 'body', 'visibility'])], 201);
    }

    public function vote(Request $request, VideoTimeMark $timeMark): JsonResponse
    {
        $data = $request->validate(['value' => ['sometimes', 'integer', 'in:-1,0,1']]);
        $this->assertAccess($request, $timeMark->video);
        $vote = TimeMarks::vote($request->user(), $timeMark, (int) ($data['value'] ?? 1));

        return response()->json(['vote' => ['id' => $vote?->id, 'time_mark_id' => $timeMark->id, 'value' => (int) ($vote?->value ?? 0)]]);
    }

    private function assertAccess(Request $request, Video $video): void
    {
        $viewer = \App\Support\VideoSales::viewer($request);
        app(\App\Services\Api\V1\VideoAccessService::class)->assertViewable($video, $viewer);
        abort_unless(\App\Support\VideoSales::hasAccess($video, $viewer), 403, 'Purchase this video to watch it.');
    }
}
