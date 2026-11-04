<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVideoRequest;
use App\Http\Requests\UpdateVideoRequest;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\VideoResource;
use App\Jobs\ProcessVideoUpload;
use App\Models\Channel;
use App\Models\Comment;
use App\Models\LiveStream;
use App\Models\Playlist;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;
use App\Support\LiveStreamingSettings;
use App\Support\MediaSourceMetadata;
use App\Support\MediaStorage;
use App\Support\MonetizationSettings;
use App\Support\PlatformFeatureSettings;
use App\Support\PlayerAdSettings;
use App\Support\PublishingAccess;
use App\Support\Slugs;
use App\Support\VideoChapters;
use App\Support\VideoImportSettings;
use App\Support\VideoSales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

class VideoController extends Controller
{
    private function applyWatchProgress($videos, int $userId): void
    {
        if ($videos->isEmpty()) {
            return;
        }

        $progress = DB::table('video_watch_progress')
            ->where('user_id', $userId)
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
    }

    public function studioIndex(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', $request->query('q', '')));
        $like = '%'.addcslashes($search, '\\%_').'%';
        $visibility = (string) $request->query('visibility', '');
        $status = (string) $request->query('status', '');
        $monetization = (string) $request->query('monetization', '');
        $sort = (string) $request->query('sort', 'latest');
        $tab = (string) $request->query('tab', 'all');
        $now = now();

        $baseQuery = Video::query()
            ->where('user_id', $request->user()->id)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($like): void {
                $query->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('playlist_title', 'like', $like)
                    ->orWhere('tags', 'like', $like)
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'like', $like))
                    ->orWhereHas('language', fn ($language) => $language->where('name', 'like', $like));
            }))
            ->when(in_array($visibility, ['public', 'unlisted', 'private'], true), fn ($query) => $query->where('visibility', $visibility))
            ->when(in_array($monetization, ['on', 'off'], true), fn ($query) => $query->where('monetization_enabled', $monetization === 'on'))
            ->when($status === 'published', fn ($query) => $query->where('status', 'ready')->where(fn ($query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', $now)))
            ->when($status === 'processing', fn ($query) => $query->where('status', 'processing'))
            ->when($status === 'draft', fn ($query) => $query->where('status', 'draft'))
            ->when($status === 'scheduled', fn ($query) => $query->whereNotNull('scheduled_at')->where('scheduled_at', '>', $now))
            ->when($status === 'live', fn ($query) => $query->where(fn ($query) => $query->where('source_type', 'live_replay')->orWhereIn('status', ['live', 'ended', 'replay'])));

        $liveFilter = fn ($query) => $query->where(fn ($query) => $query->where('source_type', 'live_replay')->orWhereIn('status', ['live', 'ended', 'replay']));
        $shortsFilter = fn ($query) => $query->shorts();
        $videoFilter = fn ($query) => $query
            ->standardVideos()
            ->whereNotIn('status', ['live', 'ended', 'replay']);
        $tabFilter = function ($query) use ($tab, $shortsFilter, $videoFilter, $liveFilter, $now): void {
            if ($tab === 'videos') {
                $videoFilter($query);
            } elseif ($tab === 'shorts') {
                $shortsFilter($query);
            } elseif ($tab === 'live') {
                $liveFilter($query);
            } elseif ($tab === 'drafts') {
                $query->where('status', 'draft');
            } elseif ($tab === 'scheduled') {
                $query->whereNotNull('scheduled_at')->where('scheduled_at', '>', $now);
            }
        };
        $contentCounts = [
            'all' => (clone $baseQuery)->count(),
            'videos' => (clone $baseQuery)->where($videoFilter)->count(),
            'shorts' => (clone $baseQuery)->where($shortsFilter)->count(),
            'live' => (clone $baseQuery)->where($liveFilter)->count(),
            'drafts' => (clone $baseQuery)->where('status', 'draft')->count(),
            'scheduled' => (clone $baseQuery)->whereNotNull('scheduled_at')->where('scheduled_at', '>', $now)->count(),
            'published' => (clone $baseQuery)->where('status', 'ready')->where(fn ($query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', $now))->count(),
            'total_views' => (int) (clone $baseQuery)->sum('views_count'),
        ];

        $videos = (clone $baseQuery)
            ->where($tabFilter)
            ->with(['channel.user', 'user', 'category', 'language', 'liveStream'])
            ->when($sort === 'oldest', fn ($query) => $query->oldest())
            ->when($sort === 'views', fn ($query) => $query->orderByDesc('views_count')->latest())
            ->when($sort === 'comments', fn ($query) => $query->orderByDesc('comments_count')->latest())
            ->when($sort === 'likes', fn ($query) => $query->orderByDesc('likes_count')->latest())
            ->when($sort === 'title', fn ($query) => $query->orderBy('title')->latest())
            ->when(! in_array($sort, ['oldest', 'views', 'comments', 'likes', 'title'], true), fn ($query) => $query->latest())
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        $earnings = MonetizationSettings::videoEarningsForUser($request->user()->id, $videos->getCollection()->pluck('id')->all());
        $videos->getCollection()->each(function (Video $video) use ($earnings): void {
            $video->monetization_earnings = $earnings[$video->id] ?? ['total_cents' => 0, 'total' => MonetizationSettings::formatMoney(0), 'events' => []];
        });

        return VideoResource::collection($videos)->additional(['content_counts' => $contentCounts]);
    }

    public function studioOverview(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $videos = Video::query()
            ->where('user_id', $userId)
            ->with(['channel.user', 'user', 'category', 'language'])
            ->latest()
            ->take(12)
            ->get();

        $summary = Video::query()
            ->where('user_id', $userId)
            ->selectRaw('count(*) as total_videos')
            ->selectRaw('coalesce(sum(views_count), 0) as total_views')
            ->selectRaw('coalesce(sum(likes_count), 0) as total_likes')
            ->selectRaw('coalesce(sum(comments_count), 0) as total_comments')
            ->selectRaw("sum(case when status = 'ready' then 1 else 0 end) as ready_videos")
            ->selectRaw("sum(case when status = 'processing' then 1 else 0 end) as processing_videos")
            ->selectRaw("sum(case when visibility = 'public' then 1 else 0 end) as public_videos")
            ->selectRaw('sum(case when monetization_enabled = 1 then 1 else 0 end) as monetized_videos')
            ->first();
        $earnings = MonetizationSettings::userEarnings($userId);
        $vastAdCompletions = Schema::hasTable('video_ad_impressions')
            ? DB::table('video_ad_impressions')->where('creator_id', $userId)->where('status', 'completed')->count()
            : 0;
        $videoEarnings = MonetizationSettings::videoEarningsForUser($userId, $videos->pluck('id')->all());
        $videos->each(function (Video $video) use ($videoEarnings): void {
            $video->monetization_earnings = $videoEarnings[$video->id] ?? ['total_cents' => 0, 'total' => MonetizationSettings::formatMoney(0), 'events' => []];
        });

        $comments = Comment::query()
            ->whereHas('video', fn ($query) => $query->where('user_id', $userId))
            ->with(['user.channel', 'video.channel.user'])
            ->latest()
            ->take(5)
            ->get();
        $reportedIds = DB::table('comment_reports')
            ->where('user_id', $userId)
            ->whereIn('comment_id', $comments->pluck('id'))
            ->pluck('comment_id')
            ->all();
        $comments = $comments
            ->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'user_id' => $comment->user_id,
                'name' => $comment->user?->name ?: 'User',
                'channel' => $comment->user?->channel ? (new ChannelResource($comment->user->channel))->resolve($request) : null,
                'time' => optional($comment->created_at)->diffForHumans(),
                'body' => $comment->body,
                'video' => (new VideoResource($comment->video))->resolve($request),
                'pinned' => (bool) $comment->is_pinned,
                'reported' => in_array($comment->id, $reportedIds, true),
                'can_delete' => true,
                'can_pin' => true,
                'can_report' => (int) $comment->user_id !== (int) $userId,
                'can_mute' => (int) $comment->user_id !== (int) $userId,
                'replyCount' => $comment->replies()->count(),
            ])
            ->values();

        return response()->json([
            'videos' => VideoResource::collection($videos)->resolve($request),
            'comments' => $comments,
            'summary' => [
                'total_videos' => (int) ($summary->total_videos ?? 0),
                'total_views' => (int) ($summary->total_views ?? 0),
                'total_likes' => (int) ($summary->total_likes ?? 0),
                'total_comments' => (int) ($summary->total_comments ?? 0),
                'ready_videos' => (int) ($summary->ready_videos ?? 0),
                'processing_videos' => (int) ($summary->processing_videos ?? 0),
                'public_videos' => (int) ($summary->public_videos ?? 0),
                'monetized_videos' => (int) ($summary->monetized_videos ?? 0),
                'earnings_cents' => $earnings['total_cents'],
                'earnings' => $earnings['total'],
                'earning_events' => $earnings['events'],
                'vast_ad_completions' => $vastAdCompletions,
            ],
        ]);
    }

    public function studioAnalytics(Request $request): JsonResponse
    {
        $data = $request->validate([
            'range' => ['nullable', 'integer', Rule::in([7, 28, 90, 365])],
        ]);
        $range = (int) ($data['range'] ?? 28);
        $userId = (int) $request->user()->id;
        $channelId = Channel::query()->where('user_id', $userId)->value('id');
        $end = now();
        $start = $end->copy()->startOfDay()->subDays($range - 1);
        $previousEnd = $start->copy()->subSecond();
        $previousStart = $start->copy()->subDays($range);
        $videoMorph = (new Video)->getMorphClass();

        $periodTotals = static function (Carbon $from, Carbon $to) use ($userId, $channelId, $videoMorph): array {
            $likes = (int) DB::table('likes')
                ->join('videos', 'videos.id', '=', 'likes.likeable_id')
                ->where('likes.likeable_type', $videoMorph)
                ->where('videos.user_id', $userId)
                ->whereBetween('likes.created_at', [$from, $to])
                ->count();
            $comments = (int) DB::table('comments')
                ->join('videos', 'videos.id', '=', 'comments.video_id')
                ->where('videos.user_id', $userId)
                ->whereBetween('comments.created_at', [$from, $to])
                ->count();
            $watch = DB::table('video_watch_progress')
                ->join('videos', 'videos.id', '=', 'video_watch_progress.video_id')
                ->where('videos.user_id', $userId)
                ->whereBetween('video_watch_progress.updated_at', [$from, $to]);

            return [
                'likes' => $likes,
                'comments' => $comments,
                'engagements' => $likes + $comments,
                'subscribers' => $channelId ? (int) DB::table('subscriptions')->where('channel_id', $channelId)->whereBetween('created_at', [$from, $to])->count() : 0,
                'watch_time_seconds' => (int) (clone $watch)->sum('video_watch_progress.current_time_seconds'),
                'unique_viewers' => (int) (clone $watch)->distinct()->count('video_watch_progress.user_id'),
                'average_percentage' => round((float) ((clone $watch)->avg('video_watch_progress.progress') ?? 0), 1),
                'average_view_seconds' => (int) round((float) ((clone $watch)->avg('video_watch_progress.current_time_seconds') ?? 0)),
            ];
        };
        $current = $periodTotals($start, $end);
        $previous = $periodTotals($previousStart, $previousEnd);
        $changePercent = static function (int $currentValue, int $previousValue): ?float {
            if ($previousValue === 0) {
                return $currentValue > 0 ? null : 0.0;
            }

            return round((($currentValue - $previousValue) / $previousValue) * 100, 1);
        };

        $dailyLikes = DB::table('likes')
            ->join('videos', 'videos.id', '=', 'likes.likeable_id')
            ->where('likes.likeable_type', $videoMorph)
            ->where('videos.user_id', $userId)
            ->whereBetween('likes.created_at', [$start, $end])
            ->selectRaw('DATE(likes.created_at) as activity_date, count(*) as total')
            ->groupByRaw('DATE(likes.created_at)')
            ->pluck('total', 'activity_date');
        $dailyComments = DB::table('comments')
            ->join('videos', 'videos.id', '=', 'comments.video_id')
            ->where('videos.user_id', $userId)
            ->whereBetween('comments.created_at', [$start, $end])
            ->selectRaw('DATE(comments.created_at) as activity_date, count(*) as total')
            ->groupByRaw('DATE(comments.created_at)')
            ->pluck('total', 'activity_date');
        $dailyWatch = DB::table('video_watch_progress')
            ->join('videos', 'videos.id', '=', 'video_watch_progress.video_id')
            ->where('videos.user_id', $userId)
            ->whereBetween('video_watch_progress.updated_at', [$start, $end])
            ->selectRaw('DATE(video_watch_progress.updated_at) as activity_date, coalesce(sum(video_watch_progress.current_time_seconds), 0) as total')
            ->groupByRaw('DATE(video_watch_progress.updated_at)')
            ->pluck('total', 'activity_date');
        $dailySubscribers = $channelId
            ? DB::table('subscriptions')
                ->where('channel_id', $channelId)
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('DATE(created_at) as activity_date, count(*) as total')
                ->groupByRaw('DATE(created_at)')
                ->pluck('total', 'activity_date')
            : collect();
        $series = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            $likes = (int) ($dailyLikes[$key] ?? 0);
            $comments = (int) ($dailyComments[$key] ?? 0);
            $series[] = [
                'date' => $key,
                'label' => $date->format('M j'),
                'watch_time_seconds' => (int) ($dailyWatch[$key] ?? 0),
                'likes' => $likes,
                'comments' => $comments,
                'engagements' => $likes + $comments,
                'subscribers' => (int) ($dailySubscribers[$key] ?? 0),
            ];
        }

        $videoQuery = Video::query()->where('user_id', $userId);
        $lifetimeViews = (int) (clone $videoQuery)->sum('views_count');
        $lifetimeEngagements = (int) (clone $videoQuery)->sum('likes_count') + (int) (clone $videoQuery)->sum('comments_count');
        $topVideos = (clone $videoQuery)->orderByDesc('views_count')->latest()->take(10)->get();
        $topIds = $topVideos->pluck('id');
        $topWatch = $topIds->isEmpty() ? collect() : DB::table('video_watch_progress')
            ->whereIn('video_id', $topIds)
            ->whereBetween('updated_at', [$start, $end])
            ->selectRaw('video_id, coalesce(sum(current_time_seconds), 0) as watch_time_seconds, coalesce(avg(progress), 0) as average_percentage')
            ->groupBy('video_id')
            ->get()->keyBy('video_id');
        $topLikes = $topIds->isEmpty() ? collect() : DB::table('likes')
            ->where('likeable_type', $videoMorph)
            ->whereIn('likeable_id', $topIds)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('likeable_id, count(*) as total')
            ->groupBy('likeable_id')
            ->pluck('total', 'likeable_id');
        $topComments = $topIds->isEmpty() ? collect() : DB::table('comments')
            ->whereIn('video_id', $topIds)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('video_id, count(*) as total')
            ->groupBy('video_id')
            ->pluck('total', 'video_id');

        return response()->json([
            'range_days' => $range,
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'summary' => [
                'lifetime_views' => $lifetimeViews,
                'watch_time_seconds' => $current['watch_time_seconds'],
                'watch_time_change_percent' => $changePercent($current['watch_time_seconds'], $previous['watch_time_seconds']),
                'subscribers_gained' => $current['subscribers'],
                'subscribers_change_percent' => $changePercent($current['subscribers'], $previous['subscribers']),
                'engagements' => $current['engagements'],
                'engagements_change_percent' => $changePercent($current['engagements'], $previous['engagements']),
                'likes' => $current['likes'],
                'comments' => $current['comments'],
                'unique_viewers' => $current['unique_viewers'],
                'average_percentage' => $current['average_percentage'],
                'average_view_seconds' => $current['average_view_seconds'],
                'engagement_rate' => $lifetimeViews > 0 ? round(($lifetimeEngagements / $lifetimeViews) * 100, 2) : 0,
                'total_subscribers' => $channelId ? (int) Channel::query()->whereKey($channelId)->value('subscribers_count') : 0,
            ],
            'series' => $series,
            'top_content' => $topVideos->map(function (Video $video) use ($topWatch, $topLikes, $topComments): array {
                $watch = $topWatch->get($video->id);

                return [
                    'id' => (int) $video->id,
                    'title' => $video->title,
                    'slug' => $video->slug,
                    'thumbnail_url' => $video->thumbnail_url,
                    'source_type' => $video->source_type ?: 'upload',
                    'is_short' => $video->isShort(),
                    'content_type' => $video->source_type === 'live_replay' ? 'Live replay' : ($video->isShort() ? 'Short' : 'Video'),
                    'views_count' => (int) $video->views_count,
                    'engagements' => (int) ($topLikes[$video->id] ?? 0) + (int) ($topComments[$video->id] ?? 0),
                    'watch_time_seconds' => (int) ($watch->watch_time_seconds ?? 0),
                    'average_percentage' => round((float) ($watch->average_percentage ?? 0), 1),
                    'published_at' => optional($video->published_at ?: $video->created_at)->toIso8601String(),
                ];
            })->values(),
            'content_mix' => [
                ['id' => 'videos', 'label' => 'Videos', 'count' => (int) (clone $videoQuery)->standardVideos()->count()],
                ['id' => 'shorts', 'label' => 'Shorts', 'count' => (int) (clone $videoQuery)->shorts()->count()],
                ['id' => 'live_replays', 'label' => 'Live replays', 'count' => (int) (clone $videoQuery)->where('source_type', 'live_replay')->count()],
            ],
            'publishing' => [
                'public' => (int) (clone $videoQuery)->where('visibility', 'public')->count(),
                'unlisted' => (int) (clone $videoQuery)->where('visibility', 'unlisted')->count(),
                'private' => (int) (clone $videoQuery)->where('visibility', 'private')->count(),
            ],
        ]);
    }

    public function watchLater(Request $request): AnonymousResourceCollection
    {
        $videos = Video::query()
            ->select('videos.*')
            ->join('watch_laters', 'watch_laters.video_id', '=', 'videos.id')
            ->where('watch_laters.user_id', $request->user()->id)
            ->where(function ($query) use ($request): void {
                $query->where(function ($public): void {
                    $public->where('videos.visibility', 'public')->where('videos.status', 'ready');
                })->orWhere('videos.user_id', $request->user()->id);
            })
            ->with(['channel.user', 'user', 'category', 'language'])
            ->orderByDesc('watch_laters.created_at')
            ->paginate(min(max((int) $request->integer('per_page', 24), 1), 50));

        $videos->getCollection()->each(function (Video $video): void {
            $video->is_watch_later = true;
        });
        $this->applyWatchProgress($videos->getCollection(), $request->user()->id);

        return VideoResource::collection($videos);
    }

    public function continueWatching(Request $request): AnonymousResourceCollection
    {
        $videos = Video::query()
            ->select('videos.*', 'video_watch_progress.progress', 'video_watch_progress.current_time_seconds as current_time', 'video_watch_progress.updated_at as progress_updated_at')
            ->join('video_watch_progress', 'video_watch_progress.video_id', '=', 'videos.id')
            ->where('video_watch_progress.user_id', $request->user()->id)
            ->whereBetween('video_watch_progress.progress', [1, 94])
            ->where(function ($query) use ($request): void {
                $query->where(function ($public): void {
                    $public->where('videos.visibility', 'public')->where('videos.status', 'ready');
                })->orWhere('videos.user_id', $request->user()->id);
            })
            ->with(['channel.user', 'user', 'category', 'language'])
            ->orderByDesc('video_watch_progress.updated_at')
            ->paginate(min(max((int) $request->integer('per_page', $request->integer('limit', 5)), 1), 50));

        return VideoResource::collection($videos);
    }

    public function likedVideos(Request $request): AnonymousResourceCollection
    {
        $videos = Video::query()
            ->select('videos.*')
            ->join('likes', function ($join): void {
                $join->on('likes.likeable_id', '=', 'videos.id')
                    ->where('likes.likeable_type', Video::class);
            })
            ->where('likes.user_id', $request->user()->id)
            ->where(function ($query) use ($request): void {
                $query->where(function ($public): void {
                    $public->where('videos.visibility', 'public')->where('videos.status', 'ready');
                })->orWhere('videos.user_id', $request->user()->id);
            })
            ->with(['channel.user', 'user', 'category', 'language'])
            ->orderByDesc('likes.created_at')
            ->paginate(min(max((int) $request->integer('per_page', 24), 1), 50));

        $videos->getCollection()->each(function (Video $video): void {
            $video->is_liked = true;
        });

        return VideoResource::collection($videos);
    }

    public function library(Request $request): JsonResponse
    {
        $user = $request->user();
        $videoRelations = ['channel.user', 'user', 'category', 'language'];

        $watchLater = Video::query()
            ->select('videos.*')
            ->join('watch_laters', 'watch_laters.video_id', '=', 'videos.id')
            ->where('watch_laters.user_id', $user->id)
            ->where(function ($query) use ($user): void {
                $query->where(function ($public): void {
                    $public->where('videos.visibility', 'public')->where('videos.status', 'ready');
                })->orWhere('videos.user_id', $user->id);
            })
            ->with($videoRelations)
            ->orderByDesc('watch_laters.created_at')
            ->take(12)
            ->get();

        $watchLater->each(fn (Video $video): bool => $video->is_watch_later = true);
        $this->applyWatchProgress($watchLater, $user->id);

        $likedVideos = Video::query()
            ->select('videos.*')
            ->join('likes', function ($join): void {
                $join->on('likes.likeable_id', '=', 'videos.id')
                    ->where('likes.likeable_type', Video::class);
            })
            ->where('likes.user_id', $user->id)
            ->where(function ($query) use ($user): void {
                $query->where(function ($public): void {
                    $public->where('videos.visibility', 'public')->where('videos.status', 'ready');
                })->orWhere('videos.user_id', $user->id);
            })
            ->with($videoRelations)
            ->orderByDesc('likes.created_at')
            ->take(12)
            ->get();

        $likedVideos->each(fn (Video $video): bool => $video->is_liked = true);

        $subscriptions = Channel::query()
            ->select('channels.*')
            ->join('subscriptions', 'subscriptions.channel_id', '=', 'channels.id')
            ->where('subscriptions.user_id', $user->id)
            ->withCount(['videos' => fn ($query) => $query->public()])
            ->orderByDesc('subscriptions.created_at')
            ->take(12)
            ->get();

        $subscriptions->each(fn (Channel $channel): bool => $channel->is_subscribed = true);

        $subscriptionVideos = Video::query()
            ->whereIn('channel_id', $subscriptions->pluck('id'))
            ->public()
            ->with($videoRelations)
            ->latest('published_at')
            ->take(12)
            ->get();

        $uploads = Video::query()
            ->where('user_id', $user->id)
            ->with($videoRelations)
            ->latest()
            ->take(12)
            ->get();
        $this->applyWatchProgress($uploads, $user->id);

        $purchasedVideos = Schema::hasTable('video_purchases')
            ? Video::query()
                ->select('videos.*')
                ->join('video_purchases', 'video_purchases.video_id', '=', 'videos.id')
                ->where('video_purchases.buyer_id', $user->id)
                ->where('video_purchases.status', 'completed')
                ->where('videos.visibility', 'public')
                ->where('videos.status', 'ready')
                ->with($videoRelations)
                ->latest('video_purchases.created_at')
                ->take(24)
                ->get()
            : collect();
        $this->applyWatchProgress($purchasedVideos, $user->id);

        return response()->json([
            'watch_later' => VideoResource::collection($watchLater)->resolve($request),
            'liked_videos' => VideoResource::collection($likedVideos)->resolve($request),
            'subscriptions' => ChannelResource::collection($subscriptions)->resolve($request),
            'subscription_videos' => VideoResource::collection($subscriptionVideos)->resolve($request),
            'uploads' => VideoResource::collection($uploads)->resolve($request),
            'purchased_videos' => VideoResource::collection($purchasedVideos)->resolve($request),
        ]);
    }

    public function show(Request $request, Video $video, VideoAccessService $access): JsonResponse
    {
        $user = VideoSales::viewer($request);
        $access->assertViewable($video, $user);

        $video->load(['channel.user', 'user', 'category', 'language', 'liveStream']);
        $hasSaleAccess = VideoSales::hasAccess($video, $user);
        if ($hasSaleAccess) {
            $video->increment('views_count');
        }

        if ($user) {
            $video->is_liked = $video->likes()->where('user_id', $user->id)->exists();
            $video->is_watch_later = DB::table('watch_laters')
                ->where('user_id', $user->id)
                ->where('video_id', $video->id)
                ->exists();
            $video->reported = DB::table('video_reports')
                ->where('user_id', $user->id)
                ->where('video_id', $video->id)
                ->exists();
            $video->can_report = (int) $video->user_id !== (int) $user->id;
            $video->channel->is_subscribed = $video->channel->subscriptions()->where('user_id', $user->id)->exists();
            if ($hasSaleAccess) {
                $this->applyWatchProgress(collect([$video]), $user->id);
            }
        }

        return response()->json([
            'video' => new VideoResource($video),
            'player_ads' => $hasSaleAccess ? PlayerAdSettings::publicSettings() : ['enabled' => false, 'ads' => []],
            'tips' => LiveStreamingSettings::creatorTipSettings($video->user),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function playbackSession(Request $request, Video $video, VideoAccessService $access): JsonResponse
    {
        $user = VideoSales::viewer($request);
        $access->assertViewable($video, $user);

        $video->load(['channel.user', 'user', 'category', 'language', 'liveStream']);
        if ($user && VideoSales::hasAccess($video, $user)) {
            $this->applyWatchProgress(collect([$video]), $user->id);
        }

        return response()->json([
            'video' => new VideoResource($video),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, Video $video): JsonResponse
    {
        app(\App\Services\Api\V1\VideoAccessService::class)->assertViewable($video, $request->user());
        abort_unless($request->user() && $request->user()->id !== $video->user_id, 403);

        DB::table('video_reports')->updateOrInsert([
            'user_id' => $request->user()->id,
            'video_id' => $video->id,
        ], [
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $video->reported = true;
        $video->can_report = false;

        return response()->json([
            'message' => 'Video reported for review.',
            'video' => new VideoResource($video->load(['channel.user', 'user', 'category', 'language'])),
        ]);
    }

    public function viewerState(Request $request, Video $video): JsonResponse
    {
        abort_if($video->visibility === 'private' && $request->user()?->id !== $video->user_id, 404);

        $userId = $request->user()->id;

        return response()->json([
            'is_watch_later' => DB::table('watch_laters')->where('user_id', $userId)->where('video_id', $video->id)->exists(),
            'reported' => DB::table('video_reports')->where('user_id', $userId)->where('video_id', $video->id)->exists(),
            'can_report' => (int) $video->user_id !== (int) $userId,
        ]);
    }

    public function updateWatchProgress(Request $request, Video $video): JsonResponse
    {
        abort_if($video->visibility === 'private' && $request->user()->id !== $video->user_id, 404);
        abort_unless(VideoSales::hasAccess($video, $request->user()), 403, 'Purchase this video to watch it.');

        $data = $request->validate([
            'current_time' => ['required', 'numeric', 'min:0'],
            'duration_seconds' => ['required', 'numeric', 'min:1'],
        ]);

        $currentTime = max(0, (int) floor((float) $data['current_time']));
        $duration = max(1, (int) floor((float) $data['duration_seconds']));
        $progress = max(0, min(100, (int) round(($currentTime / $duration) * 100)));

        if ($currentTime < 3) {
            DB::table('video_watch_progress')
                ->where('user_id', $request->user()->id)
                ->where('video_id', $video->id)
                ->delete();

            return response()->json(['progress' => 0, 'current_time' => 0]);
        }

        if ($progress >= 95) {
            $progress = 100;
            $currentTime = $duration;
        }

        DB::table('video_watch_progress')->updateOrInsert([
            'user_id' => $request->user()->id,
            'video_id' => $video->id,
        ], [
            'progress' => $progress,
            'current_time_seconds' => $currentTime,
            'duration_seconds' => $duration,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['progress' => $progress, 'current_time' => $currentTime]);
    }

    public function store(StoreVideoRequest $request): JsonResponse
    {
        PublishingAccess::authorizeUpload($request->user());
        $user = $request->user()->loadMissing('channel');
        $playlist = $request->filled('playlist_id')
            ? Playlist::query()->where('user_id', $user->id)->findOrFail((int) $request->validated('playlist_id'))
            : null;
        $pricing = VideoSales::pricing(
            $request->boolean('sale_enabled'),
            $request->validated('sale_price'),
            $request->validated('content_type', 'video') !== 'short'
        );
        $validated = $request->validated();
        $chapterData = VideoChapters::attributes(
            ($validated['content_type'] ?? 'video') === 'short' ? [...$validated, 'chapters_enabled' => false] : $validated,
            isset($validated['duration_seconds']) ? (int) $validated['duration_seconds'] : null
        );
        $disk = MediaStorage::diskName();
        $originalDirectory = 'videos/originals/'.now()->format('Y/m');
        $thumbnailDirectory = 'videos/thumbnails/'.now()->format('Y/m');
        $captionsDirectory = 'videos/captions/'.now()->format('Y/m');
        if (MediaStorage::isLocal()) {
            MediaStorage::ensureLocalDirectory($originalDirectory);
            MediaStorage::ensureLocalDirectory($thumbnailDirectory);
            MediaStorage::ensureLocalDirectory($captionsDirectory);
        }
        $videoPath = $thumbnailPath = $captionsPath = null;
        try {
            $videoPath = $request->file('video')->store($originalDirectory, $disk);
            abort_unless($videoPath && Storage::disk($disk)->exists($videoPath), 500, 'Cannot store uploaded video.');
            $thumbnailPath = $request->file('thumbnail')?->store($thumbnailDirectory, $disk);
            abort_unless(! $request->hasFile('thumbnail') || ($thumbnailPath && MediaStorage::exists($thumbnailPath)), 500, 'Cannot store uploaded thumbnail.');
            $captionsPath = $request->file('captions')?->store($captionsDirectory, $disk);
            abort_unless(! $request->hasFile('captions') || ($captionsPath && MediaStorage::exists($captionsPath)), 500, 'Cannot store uploaded captions.');
            $tags = collect(explode(',', (string) $request->validated('tags')))
                ->map(fn (string $tag): string => trim($tag))
                ->filter()
                ->take(10)
                ->values()
                ->all();
            $requestedVisibility = $request->validated('visibility', 'public');
            $scheduledAt = $request->validated('scheduled_at');
            if ($request->validated('content_type') === 'short') {
                $width = (int) $request->validated('video_width');
                $height = (int) $request->validated('video_height');
                $duration = (int) $request->validated('duration_seconds');
                $ratio = $height > 0 ? $width / $height : 0;

                abort_if($duration <= 0 || $duration > 60, 422, 'Shorts must be 60 seconds or less.');
                abort_if($width <= 0 || $height <= 0 || $height <= $width || $ratio < 0.45 || $ratio > 0.8, 422, 'Shorts must use a vertical portrait aspect ratio.');
            }

            $approvalStatus = PlatformFeatureSettings::requiresUploadApproval() ? 'pending' : 'approved';
            $video = DB::transaction(function () use ($request, $user, $playlist, $videoPath, $thumbnailPath, $captionsPath, $tags, $requestedVisibility, $approvalStatus, $scheduledAt, $pricing, $chapterData): Video {
                $video = Video::query()->create([
                    'user_id' => $user->id,
                    'channel_id' => $user->channel->id,
                    'title' => $request->validated('title'),
                    'slug' => Slugs::unique($request->validated('title'), Video::class),
                    'is_short' => $request->validated('content_type') === 'short' ? true : ($request->boolean('sale_enabled') ? false : null),
                    'description' => $request->validated('description'),
                    'tags' => $tags,
                    'category_id' => $request->validated('category_id'),
                    'language_id' => $request->validated('language_id'),
                    'playlist_title' => $playlist?->title ?? $request->validated('playlist_title'),
                    'scheduled_at' => $scheduledAt,
                    'made_for_kids' => $request->boolean('made_for_kids'),
                    'monetization_enabled' => $request->boolean('monetization_enabled'),
                    ...$pricing,
                    'visibility' => 'private',
                    'requested_visibility' => $requestedVisibility,
                    'status' => 'processing',
                    'approval_status' => $approvalStatus,
                    'processing_progress' => 0,
                    'processing_stage' => 'Queued for processing',
                    'video_path' => $videoPath,
                    'thumbnail_path' => $thumbnailPath,
                    'captions_path' => $captionsPath,
                    ...$chapterData,
                    'published_at' => $approvalStatus === 'pending' ? null : now(),
                ]);

                if ($playlist) {
                    $playlist->videos()->attach($video->id, [
                        'sort_order' => ((int) DB::table('playlist_video')->where('playlist_id', $playlist->id)->max('sort_order')) + 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $playlist->touch();
                }

                $user->channel()->increment('videos_count');

                return $video;
            });
        } catch (\Throwable $exception) {
            try {
                MediaStorage::delete(array_filter([$videoPath, $thumbnailPath, $captionsPath]));
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }
            throw $exception;
        }

        ProcessVideoUpload::dispatch($video->id)->onQueue('videos');

        return response()->json([
            'message' => 'Video uploaded. Processing has started.',
            'video' => new VideoResource($video->load(['channel.user', 'user', 'category', 'language'])),
        ], 201);
    }

    public function import(Request $request): JsonResponse
    {
        PublishingAccess::authorizeUpload($request->user());
        $providers = array_keys(VideoImportSettings::providers());
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in($providers)],
            'url' => ['required', 'url', 'max:2000'],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['required', 'integer', Rule::exists('video_categories', 'id')],
            'language_id' => ['nullable', 'integer', Rule::exists('video_languages', 'id')],
            'playlist_id' => ['nullable', 'integer', Rule::exists('playlists', 'id')->where(fn ($query) => $query->where('user_id', $request->user()->id))],
            'playlist_title' => ['nullable', 'string', 'max:160'],
            'scheduled_at' => ['nullable', 'date'],
            'made_for_kids' => ['nullable', 'boolean'],
            'monetization_enabled' => ['nullable', 'boolean'],
            'sale_enabled' => ['nullable', 'boolean'],
            'sale_price' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted', 'private'])],
            'mass_import' => ['nullable', 'boolean'],
            ...VideoChapters::rules(),
        ]);

        abort_if($request->boolean('sale_enabled'), 422, 'Imported videos cannot be sold.');

        abort_unless(VideoImportSettings::providerEnabled($data['provider']), 403, 'This video import provider is disabled.');

        try {
            $metadata = $this->providerImportMetadata($data['provider'], $data['url']);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        $user = $request->user()->loadMissing('channel');
        $playlist = ! empty($data['playlist_id'])
            ? Playlist::query()->where('user_id', $user->id)->findOrFail((int) $data['playlist_id'])
            : null;
        $requestedVisibility = $data['visibility'] ?? 'public';
        $approvalStatus = PlatformFeatureSettings::requiresUploadApproval() ? 'pending' : 'approved';
        $scheduledAt = ! empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;
        $scheduledForFuture = $scheduledAt?->isFuture() ?? false;
        $title = trim((string) ($data['title'] ?? '')) ?: ($metadata['title'] ?: 'Imported video');
        $title = mb_substr($title, 0, 160);
        $description = trim((string) ($data['description'] ?? '')) ?: ($metadata['description'] ?: null);
        $thumbnailPath = strlen((string) $metadata['thumbnail_url']) <= 255 ? $metadata['thumbnail_url'] : null;
        $providerTags = collect($metadata['tags'] ?? [])
            ->map(fn ($tag): string => trim((string) $tag))
            ->filter();
        $extraTags = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn (string $tag): string => trim($tag))
            ->filter();
        $tags = $providerTags
            ->merge($extraTags)
            ->unique(fn (string $tag): string => mb_strtolower($tag))
            ->take(10)
            ->values()
            ->all();
        $chapterInput = $data;
        if (($metadata['is_short'] ?? false) === true) {
            $chapterInput['chapters_enabled'] = false;
        } elseif (($chapterInput['chapters_enabled'] ?? true) && empty($chapterInput['chapters']) && ! empty($metadata['chapters'])) {
            $chapterInput['chapters_enabled'] = true;
            $chapterInput['chapters_source'] = $metadata['chapters_source'] ?? 'timestamps';
            $chapterInput['chapters'] = $metadata['chapters'];
        }
        $chapterData = VideoChapters::attributes($chapterInput, isset($metadata['duration_seconds']) ? (int) $metadata['duration_seconds'] : null);

        $video = DB::transaction(function () use ($data, $user, $playlist, $requestedVisibility, $approvalStatus, $scheduledAt, $scheduledForFuture, $title, $description, $tags, $metadata, $thumbnailPath, $request, $chapterData): Video {
            $video = Video::query()->create([
                'user_id' => $user->id,
                'channel_id' => $user->channel->id,
                'title' => $title,
                'slug' => Slugs::unique($title, Video::class),
                'description' => $description,
                'tags' => $tags,
                'category_id' => $data['category_id'] ?? null,
                'language_id' => $data['language_id'] ?? null,
                'playlist_title' => $playlist?->title ?? ($data['playlist_title'] ?? null),
                'scheduled_at' => $scheduledAt,
                'made_for_kids' => $request->boolean('made_for_kids'),
                'monetization_enabled' => $request->boolean('monetization_enabled'),
                'visibility' => ($scheduledForFuture || $approvalStatus === 'pending') ? 'private' : $requestedVisibility,
                'requested_visibility' => $requestedVisibility,
                'status' => 'ready',
                'approval_status' => $approvalStatus,
                'processing_progress' => 100,
                'processing_stage' => 'External provider video',
                'video_path' => $metadata['video_path'],
                'thumbnail_path' => $thumbnailPath,
                'duration_seconds' => $metadata['duration_seconds'],
                'source_type' => 'import',
                'import_provider' => $data['provider'],
                'import_url' => $data['url'],
                'is_short' => $metadata['is_short'] ?? null,
                ...$chapterData,
                'published_at' => ($scheduledForFuture || $approvalStatus === 'pending') ? null : now(),
            ]);

            if ($playlist) {
                $playlist->videos()->attach($video->id, [
                    'sort_order' => ((int) DB::table('playlist_video')->where('playlist_id', $playlist->id)->max('sort_order')) + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $playlist->touch();
            }

            $user->channel()->increment('videos_count');

            return $video;
        });

        // CrozzTube-Patch: Direkt-URL via yt-dlp Resolver beim Import auflösen
        // (Pflicht-Primärpfad). Embed-Fallback bleibt aktiv, bis die URL steht.
        if ($data['provider'] !== 'direct' && class_exists(\CrzSourceResolver\Jobs\ResolveImportSource::class)) {
            \CrzSourceResolver\Jobs\ResolveImportSource::dispatch($video->id);
        }

        return response()->json([
            'message' => 'Video import saved.',
            'video' => new VideoResource($video->load(['channel.user', 'user', 'category', 'language'])),
        ], 201);
    }

    public function importMetadata(Request $request): JsonResponse
    {
        PublishingAccess::authorizeUpload($request->user());
        $providers = array_keys(VideoImportSettings::providers());
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in($providers)],
            'url' => ['required', 'url', 'max:2000'],
        ]);

        abort_unless(VideoImportSettings::providerEnabled($data['provider']), 403, 'This video import provider is disabled.');

        try {
            $metadata = $this->providerImportMetadata($data['provider'], $data['url']);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json(['metadata' => $metadata]);
    }

    private function providerImportMetadata(string $provider, string $url): array
    {
        $providers = VideoImportSettings::providers();
        $this->validateImportUrl($url, $providers[$provider]['domains'] ?? []);

        $id = $this->providerVideoId($provider, $url);
        $api = $this->fetchProviderApiMetadata($provider, $id, $url);
        $oembed = $provider === 'direct' ? [] : $this->fetchOembed($provider, $url);
        $title = trim((string) ($api['title'] ?? $oembed['title'] ?? '')) ?: $this->providerFallbackTitle($provider, $id, $url);
        $thumbnail = trim((string) ($api['thumbnail_url'] ?? $oembed['thumbnail_url'] ?? ''));

        if ($provider === 'youtube' && $id && $thumbnail === '') {
            $thumbnail = 'https://img.youtube.com/vi/'.$id.'/hqdefault.jpg';
        }
        $duration = $api['duration_seconds'] ?? (isset($oembed['duration']) ? (int) $oembed['duration'] : null);
        $isShort = $this->providerImportIsShort($provider, $id, $url, $duration, $api, $oembed);
        $chapters = array_values($api['chapters'] ?? []);
        [$description, $tags] = $this->providerDescriptionAndTags(
            $provider,
            trim((string) ($api['description'] ?? $oembed['description'] ?? '')) ?: null,
            (array) ($api['tags'] ?? []),
        );

        return [
            'provider' => $provider,
            'source_url' => $url,
            'embed_url' => $this->providerEmbedUrl($provider, $id),
            'thumbnail_url' => $thumbnail ?: null,
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'author_name' => $api['author_name'] ?? $oembed['author_name'] ?? null,
            'duration_seconds' => $duration,
            'is_short' => $isShort,
            'chapters_source' => $chapters ? 'timestamps' : null,
            'chapters' => $chapters,
            'video_path' => 'external:'.$provider.($id ? ':'.$id : ''),
        ];
    }

    private function providerImportIsShort(string $provider, ?string $id, string $url, mixed $durationSeconds, array $api, array $oembed): ?bool
    {
        if ($provider === 'youtube' && $id) {
            $isShort = VideoImportSettings::youtubeVideoIsShort($id);
            if ($isShort !== null) {
                return $isShort;
            }

            return preg_match('#^/shorts/[^/]+#i', (string) parse_url($url, PHP_URL_PATH)) ? true : null;
        }

        $duration = (int) $durationSeconds;
        if ($duration <= 0) {
            return null;
        }
        if ($duration > 60) {
            return false;
        }

        $width = (int) ($api['width'] ?? $oembed['width'] ?? 0);
        $height = (int) ($api['height'] ?? $oembed['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $aspectRatio = $width / $height;

        return $height > $width && $aspectRatio >= 0.45 && $aspectRatio <= 0.8;
    }

    private function providerDescriptionAndTags(string $provider, ?string $description, array $tags): array
    {
        $description = trim((string) $description) ?: null;
        $providerTags = collect($tags)->map(fn ($tag): string => trim((string) $tag))->filter();
        if ($provider !== 'youtube' || ! $description) {
            return [$description, $providerTags->unique(fn (string $tag): string => mb_strtolower($tag))->take(10)->values()->all()];
        }

        $pattern = '/(^|[\s(\[{"\'])#([\p{L}\p{N}][\p{L}\p{N}_-]*)/u';
        preg_match_all($pattern, $description, $matches);
        $descriptionTags = collect($matches[2] ?? [])->map(fn ($tag): string => trim((string) $tag))->filter();
        $description = collect(preg_split('/\R/u', $description) ?: [])
            ->map(function (string $line) use ($pattern): ?string {
                $filtered = preg_replace($pattern, '$1', $line, -1, $removed);
                if ($removed && trim((string) $filtered) === '') {
                    return null;
                }

                return $removed ? rtrim((string) preg_replace('/[ \t]{2,}/u', ' ', (string) $filtered)) : $line;
            })
            ->filter(fn (?string $line): bool => $line !== null)
            ->implode("\n");

        return [
            trim($description) ?: null,
            $descriptionTags->merge($providerTags)->unique(fn (string $tag): string => mb_strtolower($tag))->take(10)->values()->all(),
        ];
    }

    private function fetchProviderApiMetadata(string $provider, ?string $id, string $url): array
    {
        if (! $id) {
            return [];
        }

        $settings = VideoImportSettings::settings();
        $apiKey = trim((string) data_get($settings, "providers.{$provider}.apiKey", ''));

        if ($provider === 'youtube') {
            if ($apiKey !== '') {
                $data = $this->fetchJson('https://www.googleapis.com/youtube/v3/videos?'.http_build_query([
                    'part' => 'snippet,contentDetails',
                    'id' => $id,
                    'key' => $apiKey,
                ]));
                $item = $data['items'][0] ?? null;
                $snippet = $item['snippet'] ?? [];

                if ($item) {
                    $duration = $this->parseIso8601Duration($item['contentDetails']['duration'] ?? null);
                    $chapters = $this->youtubeDescriptionChapters($snippet['description'] ?? null, $duration);
                    if ($chapters === []) {
                        $chapters = $this->fetchYoutubePageMetadata($id)['chapters'] ?? [];
                    }

                    return [
                        'title' => $snippet['title'] ?? null,
                        'description' => $snippet['description'] ?? null,
                        'tags' => $snippet['tags'] ?? [],
                        'thumbnail_url' => $this->bestYoutubeThumbnail($snippet['thumbnails'] ?? []),
                        'author_name' => $snippet['channelTitle'] ?? null,
                        'duration_seconds' => $duration,
                        'chapters' => $chapters,
                    ];
                }
            }

            return $this->fetchYoutubePageMetadata($id);
        }

        if ($provider === 'vimeo') {
            if ($apiKey === '') {
                return [];
            }

            $data = $this->fetchJson('https://api.vimeo.com/videos/'.rawurlencode($id), [
                'Authorization: Bearer '.$apiKey,
                'Accept: application/vnd.vimeo.*+json;version=3.4',
            ]);
            $thumbnail = collect($data['pictures']['sizes'] ?? [])->sortByDesc('width')->first();

            return $data ? [
                'title' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'tags' => collect($data['tags'] ?? [])->map(fn ($tag) => is_array($tag) ? ($tag['name'] ?? '') : $tag)->all(),
                'thumbnail_url' => is_array($thumbnail) ? ($thumbnail['link'] ?? null) : null,
                'author_name' => $data['user']['name'] ?? null,
                'duration_seconds' => isset($data['duration']) ? (int) $data['duration'] : null,
                'width' => isset($data['width']) ? (int) $data['width'] : null,
                'height' => isset($data['height']) ? (int) $data['height'] : null,
            ] : [];
        }

        if ($provider === 'dailymotion') {
            $data = $this->fetchJson('https://api.dailymotion.com/video/'.rawurlencode($id).'?'.http_build_query([
                'fields' => 'title,description,tags,thumbnail_720_url,thumbnail_url,duration,width,height,owner.screenname',
            ]));

            return $data ? [
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'tags' => is_array($data['tags'] ?? null) ? $data['tags'] : [],
                'thumbnail_url' => $data['thumbnail_720_url'] ?? $data['thumbnail_url'] ?? null,
                'author_name' => $data['owner.screenname'] ?? null,
                'duration_seconds' => isset($data['duration']) ? (int) $data['duration'] : null,
                'width' => isset($data['width']) ? (int) $data['width'] : null,
                'height' => isset($data['height']) ? (int) $data['height'] : null,
            ] : [];
        }

        return [];
    }

    private function fetchOembed(string $provider, string $url): array
    {
        $endpoint = match ($provider) {
            'youtube' => 'https://www.youtube.com/oembed?'.http_build_query(['url' => $url, 'format' => 'json']),
            'vimeo' => 'https://vimeo.com/api/oembed.json?'.http_build_query(['url' => $url]),
            'dailymotion' => 'https://www.dailymotion.com/services/oembed?'.http_build_query(['url' => $url, 'format' => 'json']),
            'twitch' => 'https://api.twitch.tv/v5/oembed?'.http_build_query(['url' => $url]),
            default => null,
        };

        if (! $endpoint) {
            return [];
        }

        $data = $this->fetchJson($endpoint);

        return is_array($data) ? $data : [];
    }

    private function fetchJson(string $url, array $headers = []): array
    {
        $json = $this->fetchText($url, $headers);
        $data = $json !== '' ? json_decode($json, true) : null;

        return is_array($data) ? $data : [];
    }

    private function fetchText(string $url, array $headers = []): string
    {
        $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131 Safari/537.36';

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
                return $body;
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 12,
                'ignore_errors' => true,
                'user_agent' => $userAgent,
                'header' => implode("\r\n", $headers),
            ],
        ]);
        $body = @file_get_contents($url, false, $context);

        if (is_string($body) && $body !== '') {
            return $body;
        }

        if (function_exists('shell_exec')) {
            $headersCommand = collect($headers)
                ->map(fn (string $header): string => '-H '.escapeshellarg($header))
                ->implode(' ');
            $command = trim('curl -L --silent --show-error --max-time 12 --user-agent '.escapeshellarg($userAgent).' '.$headersCommand.' '.escapeshellarg($url));
            $body = shell_exec($command);
        }

        return is_string($body) ? $body : '';
    }

    private function fetchYoutubePageMetadata(string $id): array
    {
        $html = $this->fetchText('https://www.youtube.com/watch?'.http_build_query([
            'v' => $id,
            'hl' => 'en',
        ]), [
            'Accept-Language: en-US,en;q=0.9',
            'Cookie: SOCS=CAI',
        ]);

        $metadata = $html !== '' ? $this->parseYoutubePageMetadata($html, $id) : [];
        if (empty($metadata['chapters']) || blank($metadata['title'] ?? null) || blank($metadata['description'] ?? null)) {
            $fallback = $this->fetchYoutubePlayerMetadata($id);
            foreach (['title', 'description', 'tags', 'thumbnail_url', 'author_name', 'duration_seconds', 'chapters'] as $key) {
                if (blank($metadata[$key] ?? null) && filled($fallback[$key] ?? null)) {
                    $metadata[$key] = $fallback[$key];
                }
            }
        }

        return $metadata;
    }

    private function fetchYoutubePlayerMetadata(string $id): array
    {
        try {
            $embed = Http::connectTimeout(8)
                ->timeout(12)
                ->withUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131 Safari/537.36')
                ->get('https://www.youtube-nocookie.com/embed/'.rawurlencode($id));
            if (! $embed->successful()
                || ! preg_match('/"INNERTUBE_API_KEY":"([^"]+)"/', $embed->body(), $keyMatch)) {
                return [];
            }

            $response = Http::connectTimeout(8)
                ->timeout(12)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-YouTube-Client-Name' => '3',
                    'X-YouTube-Client-Version' => '20.10.38',
                ])
                ->withUserAgent('com.google.android.youtube/20.10.38 (Linux; U; Android 11) gzip')
                ->post('https://www.youtube.com/youtubei/v1/player?'.http_build_query([
                    'prettyPrint' => 'false',
                    'key' => $keyMatch[1],
                ]), [
                    'context' => ['client' => ['clientName' => 'ANDROID', 'clientVersion' => '20.10.38', 'androidSdkVersion' => 30, 'hl' => 'en', 'gl' => 'US']],
                    'videoId' => $id,
                    'contentCheckOk' => true,
                    'racyCheckOk' => true,
                ]);
        } catch (\Throwable) {
            return [];
        }

        return $response->successful() ? $this->parseYoutubePlayerMetadata((array) $response->json(), $id) : [];
    }

    private function parseYoutubePlayerMetadata(array $player, string $id): array
    {
        $details = $player['videoDetails'] ?? [];
        if ((string) ($details['videoId'] ?? '') !== $id) {
            return [];
        }

        $description = $details['shortDescription'] ?? data_get($player, 'microformat.playerMicroformatRenderer.description.simpleText');
        $duration = isset($details['lengthSeconds']) ? (int) $details['lengthSeconds'] : null;
        $chapters = $this->youtubePageChapters($player, $duration);
        if ($chapters === []) {
            $chapters = $this->youtubeDescriptionChapters($description, $duration);
        }
        $thumbnail = collect($details['thumbnail']['thumbnails'] ?? [])->sortByDesc('width')->first();

        return [
            'title' => $details['title'] ?? null,
            'description' => $description,
            'tags' => $details['keywords'] ?? [],
            'thumbnail_url' => is_array($thumbnail) ? ($thumbnail['url'] ?? null) : null,
            'author_name' => $details['author'] ?? null,
            'duration_seconds' => $duration,
            'chapters' => $chapters,
        ];
    }

    private function parseYoutubePageMetadata(string $html, string $id): array
    {
        $player = $this->extractJsonAfter($html, 'ytInitialPlayerResponse = ');
        $initialData = $this->extractJsonAfter($html, 'ytInitialData = ');
        $details = $player['videoDetails'] ?? [];
        $jsonLd = [];

        $document = new \DOMDocument;
        $previousLibxmlState = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);
        $xpath = $loaded ? new \DOMXPath($document) : null;
        $metaContent = static function (string $query) use ($xpath): ?string {
            if (! $xpath) {
                return null;
            }
            $value = trim((string) $xpath->evaluate('string(('.$query.')[1]/@content)'));

            return $value !== '' ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5) : null;
        };

        if ($xpath) {
            foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
                $decoded = json_decode(trim((string) $node->textContent), true);
                if (! is_array($decoded)) {
                    continue;
                }
                $candidates = isset($decoded['@graph']) && is_array($decoded['@graph']) ? $decoded['@graph'] : [$decoded];
                foreach ($candidates as $candidate) {
                    if (! is_array($candidate)) {
                        continue;
                    }
                    if (in_array('VideoObject', (array) ($candidate['@type'] ?? []), true)) {
                        $jsonLd = $candidate;
                        break 2;
                    }
                }
            }
        }

        $pageUrl = $metaContent('//meta[@property="og:url"]');
        parse_str((string) parse_url((string) $pageUrl, PHP_URL_QUERY), $pageQuery);
        $isVideoPage = (string) ($details['videoId'] ?? '') === $id
            || (string) ($pageQuery['v'] ?? '') === $id
            || (string) data_get($initialData, 'currentVideoEndpoint.watchEndpoint.videoId', '') === $id;
        $metaDescription = $isVideoPage ? ($metaContent('//meta[@itemprop="description"]') ?: $metaContent('//meta[@name="description"]') ?: $metaContent('//meta[@property="og:description"]')) : null;
        $metaKeywords = $isVideoPage ? ($metaContent('//meta[@itemprop="keywords"]') ?: $metaContent('//meta[@name="keywords"]')) : null;
        $jsonLdKeywords = $jsonLd['keywords'] ?? [];
        $fallbackKeywords = $jsonLdKeywords ?: $metaKeywords;
        $fallbackTags = is_array($fallbackKeywords) ? $fallbackKeywords : explode(',', (string) $fallbackKeywords);
        $thumbnail = collect($details['thumbnail']['thumbnails'] ?? [])->sortByDesc('width')->first();
        $jsonLdThumbnail = $jsonLd['thumbnailUrl'] ?? ($isVideoPage ? $metaContent('//meta[@property="og:image"]') : null);
        if (is_array($jsonLdThumbnail)) {
            $jsonLdThumbnail = $jsonLdThumbnail[0] ?? null;
        }
        $description = $details['shortDescription'] ?? $jsonLd['description'] ?? $metaDescription;
        $duration = isset($details['lengthSeconds']) ? (int) $details['lengthSeconds'] : $this->parseIso8601Duration($jsonLd['duration'] ?? null);
        $chapters = $isVideoPage ? $this->youtubePageChapters([$player, $initialData], $duration) : [];
        if ($isVideoPage && $chapters === []) {
            $chapters = $this->youtubeDescriptionChapters($description, $duration);
        }

        return [
            'title' => $details['title'] ?? $jsonLd['name'] ?? ($isVideoPage ? $metaContent('//meta[@property="og:title"]') : null),
            'description' => $description,
            'tags' => is_array($details['keywords'] ?? null) && $details['keywords'] ? $details['keywords'] : $fallbackTags,
            'thumbnail_url' => is_array($thumbnail) ? ($thumbnail['url'] ?? null) : $jsonLdThumbnail,
            'author_name' => $details['author'] ?? data_get($jsonLd, 'author.name'),
            'duration_seconds' => $duration,
            'chapters' => $chapters,
        ];
    }

    private function youtubePageChapters(array $initialData, ?int $durationSeconds): array
    {
        $items = $this->findYoutubeChapterItems($initialData);
        $chapters = [];

        foreach ($items as $item) {
            $renderer = $item['chapterRenderer'] ?? null;
            if (! is_array($renderer) || ! is_numeric($renderer['timeRangeStartMillis'] ?? null)) {
                continue;
            }
            $title = trim((string) data_get($renderer, 'title.simpleText', ''));
            if ($title === '') {
                $title = trim(collect(data_get($renderer, 'title.runs', []))->pluck('text')->implode(''));
            }
            $chapters[] = [
                'start_time' => (int) floor(((int) $renderer['timeRangeStartMillis']) / 1000),
                'title' => $title,
            ];
        }

        return $this->normalizeYoutubeChapters($chapters, $durationSeconds);
    }

    private function findYoutubeChapterItems(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($key !== 'chapters' || ! is_array($value)) {
                continue;
            }
            $items = array_values(array_filter($value, fn ($item): bool => is_array($item) && isset($item['chapterRenderer'])));
            if (count($items) >= 2) {
                return $items;
            }
        }
        foreach ($data as $value) {
            if (! is_array($value)) {
                continue;
            }
            $items = $this->findYoutubeChapterItems($value);
            if ($items !== []) {
                return $items;
            }
        }

        return [];
    }

    private function youtubeDescriptionChapters(?string $description, ?int $durationSeconds): array
    {
        $chapters = [];
        foreach (preg_split('/\R/u', (string) $description) ?: [] as $line) {
            if (! preg_match_all('/(?<![\d:])\d{1,5}:\d{2}(?::\d{2})?(?![\d:])/u', $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $selected = null;
            foreach ($matches[0] as $match) {
                $prefix = preg_replace('/^\s*\d{1,3}[.)-]?\s*/u', '', substr($line, 0, $match[1]));
                if (trim((string) $prefix) === '') {
                    $selected = $match;
                    break;
                }
            }
            if ($selected === null) {
                foreach (array_reverse($matches[0]) as $match) {
                    if (trim(substr($line, $match[1] + strlen($match[0]))) === '') {
                        $selected = $match;
                        break;
                    }
                }
            }
            if ($selected === null) {
                continue;
            }
            $startTime = $this->youtubeTimestampSeconds($selected[0]);
            if ($startTime === null) {
                continue;
            }
            $title = substr($line, 0, $selected[1]).' '.substr($line, $selected[1] + strlen($selected[0]));
            $title = preg_replace('/^\s*\d{1,3}[.)-]?\s+/u', '', $title);
            $title = trim((string) preg_replace('/\s{2,}/u', ' ', (string) $title), " \t\n\r\0\x0B|:.-");
            if ($title !== '') {
                $chapters[] = ['start_time' => $startTime, 'title' => $title];
            }
        }

        return $this->normalizeYoutubeChapters($chapters, $durationSeconds);
    }

    private function youtubeTimestampSeconds(string $timestamp): ?int
    {
        $parts = explode(':', $timestamp);
        if (count($parts) < 2 || count($parts) > 3 || collect($parts)->contains(fn (string $part): bool => ! ctype_digit($part))) {
            return null;
        }
        $values = array_map('intval', $parts);
        if ($values[array_key_last($values)] > 59 || (count($values) === 3 && $values[1] > 59)) {
            return null;
        }

        return array_reduce($values, fn (int $total, int $part): int => ($total * 60) + $part, 0);
    }

    private function normalizeYoutubeChapters(array $chapters, ?int $durationSeconds): array
    {
        $duration = (int) $durationSeconds;
        $normalized = collect($chapters)
            ->map(fn (array $chapter): array => [
                'start_time' => max(0, (int) ($chapter['start_time'] ?? 0)),
                'title' => mb_substr(trim((string) ($chapter['title'] ?? '')), 0, 160),
            ])
            ->filter(fn (array $chapter): bool => $chapter['title'] !== '' && ($duration <= 0 || $chapter['start_time'] < $duration))
            ->sortBy('start_time')
            ->unique('start_time')
            ->take(VideoChapters::MAX_CHAPTERS)
            ->values();

        if ($normalized->count() < 2 || (int) $normalized->first()['start_time'] !== 0) {
            return [];
        }
        $accepted = [];
        foreach ($normalized as $chapter) {
            $previous = $accepted ? $accepted[array_key_last($accepted)]['start_time'] : null;
            if ($previous !== null && $chapter['start_time'] - $previous < VideoChapters::MIN_CHAPTER_SECONDS) {
                continue;
            }
            $accepted[] = $chapter;
        }

        return count($accepted) >= 2 ? $accepted : [];
    }

    private function extractJsonAfter(string $text, string $marker): array
    {
        $markerPosition = strpos($text, $marker);
        $start = $markerPosition === false ? false : strpos($text, '{', $markerPosition);
        if ($start === false) {
            return [];
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($escaped) {
                $escaped = false;

                continue;
            }
            if ($char === '\\') {
                $escaped = true;

                continue;
            }
            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }
            if ($inString) {
                continue;
            }
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $data = json_decode(substr($text, $start, $i - $start + 1), true);

                    return is_array($data) ? $data : [];
                }
            }
        }

        return [];
    }

    private function bestYoutubeThumbnail(array $thumbnails): ?string
    {
        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $quality) {
            if (! empty($thumbnails[$quality]['url'])) {
                return $thumbnails[$quality]['url'];
            }
        }

        return null;
    }

    private function parseIso8601Duration(?string $duration): ?int
    {
        if (! $duration) {
            return null;
        }

        try {
            $interval = new \DateInterval($duration);

            return ($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
        } catch (\Throwable) {
            return null;
        }
    }

    private function providerVideoId(string $provider, string $url): ?string
    {
        if ($provider === 'direct') {
            return null;
        }

        $id = MediaSourceMetadata::providerId($provider, $url);
        if ($id) {
            return $id;
        }

        throw new RuntimeException('Could not read the provider video ID from this URL.');
    }

    private function providerEmbedUrl(string $provider, ?string $id): ?string
    {
        $parent = parse_url((string) config('app.url'), PHP_URL_HOST) ?: request()->getHost();

        return MediaSourceMetadata::embedUrl($provider, $id, (string) $parent);
    }

    private function titleFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $name = pathinfo($path, PATHINFO_FILENAME) ?: parse_url($url, PHP_URL_HOST) ?: 'Imported video';

        return mb_substr(trim(preg_replace('/[_-]+/', ' ', $name)) ?: 'Imported video', 0, 160);
    }

    private function providerFallbackTitle(string $provider, ?string $id, string $url): string
    {
        if ($provider === 'direct') {
            return $this->titleFromUrl($url);
        }

        $label = VideoImportSettings::providers()[$provider]['label'] ?? 'Imported';

        return mb_substr($label.' video'.($id ? ' '.$id : ''), 0, 160);
    }

    private function validateImportUrl(string $url, array $allowedDomains): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Import URL is invalid.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Import URL must use HTTP or HTTPS.');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new RuntimeException('Import URL host is invalid.');
        }

        if ($allowedDomains) {
            if (! collect($allowedDomains)->contains(fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain))) {
                throw new RuntimeException('Import URL does not match the selected provider.');
            }

            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        if (! $ips) {
            throw new RuntimeException('Import URL host cannot be resolved.');
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Import URL must resolve to a public host.');
            }
        }
    }

    public function status(Request $request, Video $video): JsonResponse
    {
        abort_unless($request->user()?->id === $video->user_id, 403);

        return response()->json([
            'video' => new VideoResource($video->fresh(['channel.user', 'user', 'category', 'language'])),
        ]);
    }

    public function toggleWatchLater(Request $request, Video $video): JsonResponse
    {
        abort_if($video->visibility === 'private' && $request->user()->id !== $video->user_id, 404);

        $saved = DB::transaction(function () use ($request, $video): bool {
            $query = DB::table('watch_laters')
                ->where('user_id', $request->user()->id)
                ->where('video_id', $video->id);

            if ($query->lockForUpdate()->exists()) {
                $query->delete();

                return false;
            }

            DB::table('watch_laters')->insert([
                'user_id' => $request->user()->id,
                'video_id' => $video->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });

        return response()->json([
            'watch_later' => $saved,
        ]);
    }

    public function update(UpdateVideoRequest $request, Video $video): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('sale_enabled', $data) || array_key_exists('sale_price', $data)) {
            $saleEnabled = array_key_exists('sale_enabled', $data) ? $request->boolean('sale_enabled') : (bool) $video->sale_enabled;
            $salePrice = $data['sale_price'] ?? ((int) $video->price_cents / 100);
            $preservingDisabledSale = $saleEnabled
                && (bool) $video->sale_enabled
                && ! MonetizationSettings::paidVideoSettings()['enabled']
                && (int) round(((float) $salePrice) * 100) === (int) $video->price_cents;
            if (! $preservingDisabledSale) {
                $data = array_merge($data, VideoSales::pricing(
                    $saleEnabled,
                    $salePrice,
                    $video->source_type !== 'import' && $video->source_type !== 'live_replay' && ! $video->isShort()
                ));
            }
            unset($data['sale_price']);
        }
        $oldThumbnailPath = null;
        if ($request->hasFile('thumbnail')) {
            $oldThumbnailPath = $video->thumbnail_path;
            $thumbnailDirectory = 'videos/thumbnails/'.now()->format('Y/m');
            if (MediaStorage::isLocal()) {
                MediaStorage::ensureLocalDirectory($thumbnailDirectory);
            }
            $data['thumbnail_path'] = $request->file('thumbnail')->store($thumbnailDirectory, MediaStorage::diskName());
            abort_unless($data['thumbnail_path'] && MediaStorage::exists($data['thumbnail_path']), 500, 'Cannot store uploaded thumbnail.');
        }
        if (array_key_exists('tags', $data)) {
            $data['tags'] = collect(explode(',', (string) $data['tags']))
                ->map(fn (string $tag): string => trim($tag))
                ->filter()
                ->take(10)
                ->values()
                ->all();
        }
        if (array_key_exists('visibility', $data)) {
            $data['requested_visibility'] = $data['visibility'];
            if ($video->status !== 'ready' || ($video->approval_status ?: 'approved') === 'pending') {
                $data['visibility'] = 'private';
            }
        }

        if (isset($data['title']) && $data['title'] !== $video->title) {
            $data['slug'] = Slugs::unique($data['title'], Video::class, 'slug', $video->id);
        }

        $video->update($data);
        if ($oldThumbnailPath && ! preg_match('/^(https?:)?\/\//', $oldThumbnailPath)) {
            MediaStorage::delete($oldThumbnailPath);
        }

        return response()->json([
            'message' => 'Video updated.',
            'video' => new VideoResource($video->fresh(['channel.user', 'user', 'category', 'language'])),
        ]);
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        abort_unless($this->canDeleteVideo($request->user(), $video), 403);

        $liveStream = LiveStream::query()->where('replay_video_id', $video->id)->first();
        $streamThumbnailPath = $liveStream?->thumbnail_path;
        $streamSettings = (array) $liveStream?->stream_settings;
        $recording = (array) ($streamSettings['_recording'] ?? []);
        unset($streamSettings['_recording']);

        // Keep the database entry available for retry if the provider refuses
        // deletion. Deleting it first would leave inaccessible cloud orphans.
        MediaStorage::delete(array_filter([
            $video->video_path,
            $video->thumbnail_path,
            $video->captions_path,
            ...collect($video->renditions ?: [])->pluck('path')->all(),
            $streamThumbnailPath,
            $recording['local_path'] ?? null,
            $recording['media_path'] ?? null,
        ]));

        DB::transaction(function () use ($video, $liveStream, $streamSettings): void {
            $liveStream?->forceFill([
                'replay_video_id' => null,
                'replay_expires_at' => null,
                'thumbnail_path' => null,
                'stream_settings' => $streamSettings,
            ])->save();
            $video->channel()->decrement('videos_count');
            $video->delete();
        });

        return response()->json([
            'message' => 'Video deleted.',
        ]);
    }

    private function canDeleteVideo(?object $user, Video $video): bool
    {
        if (! $user) {
            return false;
        }
        if ((int) $user->id === (int) $video->user_id) {
            return true;
        }

        $settings = is_array($user->settings ?? null) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;

        return $adminEnabled || (bool) ($settings['is_admin'] ?? false) || in_array($role, ['admin', 'super admin', 'owner', 'moderator'], true);
    }
}
