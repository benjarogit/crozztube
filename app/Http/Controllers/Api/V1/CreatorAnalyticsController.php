<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CreatorVideoResource;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CreatorAnalyticsController extends Controller
{
    public function show(Request $request, ?Video $video = null): JsonResponse
    {
        if ($video) {
            abort_unless((int) $video->user_id === (int) $request->user()->id, 404);
        }
        $data = $request->validate(['range' => ['nullable', 'integer', Rule::in([7, 28, 90, 365])]]);
        $range = (int) ($data['range'] ?? 28);
        $end = now();
        $start = $end->copy()->startOfDay()->subDays($range - 1);
        $videos = Video::query()->where('user_id', $request->user()->id)->when($video, fn ($query) => $query->whereKey($video->id));
        $ids = (clone $videos)->select('id');
        $likes = DB::table('likes')->where('likeable_type', (new Video)->getMorphClass())->whereIn('likeable_id', clone $ids)->whereBetween('created_at', [$start, $end]);
        $comments = DB::table('comments')->whereIn('video_id', clone $ids)->whereBetween('created_at', [$start, $end]);
        $watch = DB::table('video_watch_progress')->whereIn('video_id', clone $ids)->whereBetween('updated_at', [$start, $end]);
        $subscriptions = DB::table('subscriptions')->where('channel_id', $request->user()->channel?->id)->whereBetween('created_at', [$start, $end]);
        $previousStart = $start->copy()->subDays($range);
        $previousWatch = DB::table('video_watch_progress')->whereIn('video_id', clone $ids)->whereBetween('updated_at', [$previousStart, $start->copy()->subSecond()]);
        $previousLikes = DB::table('likes')->where('likeable_type', (new Video)->getMorphClass())->whereIn('likeable_id', clone $ids)->whereBetween('created_at', [$previousStart, $start->copy()->subSecond()])->count();
        $previousComments = DB::table('comments')->whereIn('video_id', clone $ids)->whereBetween('created_at', [$previousStart, $start->copy()->subSecond()])->count();
        $watchSeconds = (int) (clone $watch)->sum('current_time_seconds');
        $engagements = (clone $likes)->count() + (clone $comments)->count();
        $subscribers = $video ? null : (clone $subscriptions)->count();
        $change = static fn (int $current, int $previous): ?float => $previous === 0 ? ($current === 0 ? 0.0 : null) : round(($current - $previous) / $previous * 100, 1);
        $dailyLikes = (clone $likes)->selectRaw('DATE(created_at) as day, count(*) as count')->groupByRaw('DATE(created_at)')->pluck('count', 'day');
        $dailyComments = (clone $comments)->selectRaw('DATE(created_at) as day, count(*) as count')->groupByRaw('DATE(created_at)')->pluck('count', 'day');
        $dailyWatch = (clone $watch)->selectRaw('DATE(updated_at) as day, sum(current_time_seconds) as seconds')->groupByRaw('DATE(updated_at)')->pluck('seconds', 'day');
        $dailySubscribers = (clone $subscriptions)->selectRaw('DATE(created_at) as day, count(*) as count')->groupByRaw('DATE(created_at)')->pluck('count', 'day');
        $series = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $day = $date->toDateString();
            $series[] = ['date' => $day, 'likes' => (int) ($dailyLikes[$day] ?? 0), 'comments' => (int) ($dailyComments[$day] ?? 0),
                'engagements' => (int) ($dailyLikes[$day] ?? 0) + (int) ($dailyComments[$day] ?? 0),
                'recorded_progress_seconds' => (int) ($dailyWatch[$day] ?? 0), 'subscribers' => $video ? null : (int) ($dailySubscribers[$day] ?? 0)];
        }
        $top = (clone $videos)->with('language')->orderByDesc('views_count')->take(10)->get();
        $topIds = $top->modelKeys();
        $topWatch = (clone $watch)->whereIn('video_id', $topIds)
            ->selectRaw('video_id, sum(current_time_seconds) as seconds, avg(progress) as average_percentage')->groupBy('video_id')->get()->keyBy('video_id');
        $topLikes = (clone $likes)->whereIn('likeable_id', $topIds)->selectRaw('likeable_id, count(*) as total')->groupBy('likeable_id')->pluck('total', 'likeable_id');
        $topComments = (clone $comments)->whereIn('video_id', $topIds)->selectRaw('video_id, count(*) as total')->groupBy('video_id')->pluck('total', 'video_id');
        $topMetrics = $top->map(fn (Video $item): array => [
            'video_id' => (int) $item->id,
            'recorded_progress_seconds' => (int) ($topWatch->get($item->id)?->seconds ?? 0),
            'average_percentage' => round((float) ($topWatch->get($item->id)?->average_percentage ?? 0), 1),
            'engagements' => (int) ($topLikes[$item->id] ?? 0) + (int) ($topComments[$item->id] ?? 0),
        ])->all();
        $lifetimeViews = (int) (clone $videos)->sum('views_count');
        $lifetimeEngagements = (int) (clone $videos)->sum('likes_count') + (int) (clone $videos)->sum('comments_count');

        return response()->json([
            'range_days' => $range,
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'summary' => [
                'lifetime_views' => $lifetimeViews,
                'likes' => (clone $likes)->count(), 'comments' => (clone $comments)->count(),
                'recorded_progress_seconds' => $watchSeconds,
                'average_progress_seconds' => (int) round((float) (clone $watch)->avg('current_time_seconds')),
                'unique_viewers' => (clone $watch)->distinct()->count('user_id'),
                'subscribers_gained' => $subscribers,
                'total_subscribers' => $video ? null : (int) ($request->user()->channel?->subscribers_count ?? 0),
                'average_percentage' => round((float) (clone $watch)->avg('progress'), 1),
                'engagements' => $engagements,
                'engagement_rate' => $lifetimeViews > 0 ? round($lifetimeEngagements / $lifetimeViews * 100, 2) : 0,
                'progress_change_percent' => $change($watchSeconds, (int) (clone $previousWatch)->sum('current_time_seconds')),
                'engagements_change_percent' => $change($engagements, $previousLikes + $previousComments),
                'subscribers_change_percent' => $video ? null : $change($subscribers, DB::table('subscriptions')->where('channel_id', $request->user()->channel?->id)->whereBetween('created_at', [$previousStart, $start->copy()->subSecond()])->count()),
            ],
            'series' => $series,
            'top_content' => CreatorVideoResource::collection($top)->resolve($request),
            'top_content_metrics' => $topMetrics,
            'content_mix' => [
                ['id' => 'videos', 'label' => 'Videos', 'count' => (clone $videos)->standardVideos()->count()],
                ['id' => 'shorts', 'label' => 'Shorts', 'count' => (clone $videos)->shorts()->count()],
                ['id' => 'live_replays', 'label' => 'Live replays', 'count' => (clone $videos)->where('source_type', 'live_replay')->count()],
            ],
            'publishing' => ['public' => (clone $videos)->where('visibility', 'public')->count(), 'unlisted' => (clone $videos)->where('visibility', 'unlisted')->count(), 'private' => (clone $videos)->where('visibility', 'private')->count()],
            'measurement_note' => 'Viewing progress is the latest saved position per signed-in viewer, not cumulative watch time. Views are lifetime totals; historical view and revenue series are not tracked.',
        ])->header('Cache-Control', 'private, no-store');
    }
}
