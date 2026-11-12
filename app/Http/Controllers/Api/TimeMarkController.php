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
    public function index(Video $video): JsonResponse
    {
        return response()->json([
            'time_marks' => TimeMarks::visible($video, null, CarbonImmutable::now()),
        ]);
    }

    public function mine(Request $request, Video $video): JsonResponse
    {
        return response()->json([
            'time_marks' => TimeMarks::visible($video, $request->user(), CarbonImmutable::now()),
        ]);
    }

    public function share(Video $video): JsonResponse
    {
        return response()->json(TimeMarks::share($video, CarbonImmutable::now()));
    }

    public function store(Request $request, Video $video): JsonResponse
    {
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
        $vote = TimeMarks::vote($request->user(), $timeMark);

        return response()->json(['vote' => ['id' => $vote->id, 'time_mark_id' => $timeMark->id]]);
    }
}
