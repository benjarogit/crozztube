<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChannelSummaryResource;
use App\Http\Resources\Api\V1\VideoCardResource;
use App\Models\Channel;
use App\Models\LiveStreamCategory;
use App\Models\VideoCategory;
use App\Services\Api\V1\VideoAccessService;
use App\Services\Api\V1\ViewerStateService;
use App\Support\Api\V1\PaginationPayload;
use App\Support\WatchProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DiscoveryController extends Controller
{
    public function options(Request $request, VideoAccessService $access): JsonResponse
    {
        $categories = VideoCategory::query()->where('enabled', true)
            ->withCount(['videos' => fn ($query) => $access->constrainPublic($query)->standardVideos()])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (VideoCategory $category): array => [
                'id' => (int) $category->id, 'slug' => $category->slug,
                'name' => $category->name, 'videos_count' => (int) $category->videos_count,
            ])->all();
        // Count public tags only: private/draft content must not leak into discovery.
        $tags = $access->publicQuery()->whereNotNull('tags')->pluck('tags')
            ->flatMap(fn ($tags) => is_array($tags) ? $tags : [])
            ->map(fn ($tag) => trim((string) $tag))->filter()->countBy()->sortDesc()->keys()->take(12)->values()->all();
        $creators = Channel::query()->whereHas('user', fn ($owner) => $owner->publicProfileEnabled())
            ->whereHas('videos', fn ($videos) => $access->constrainPublic($videos))
            ->with('user')->withCount(['videos' => fn ($videos) => $access->constrainPublic($videos)])
            ->orderByDesc('subscribers_count')->orderBy('id')->limit(12)->get();
        $subscribed = $request->user()
            ? DB::table('subscriptions')->where('user_id', $request->user()->id)->whereIn('channel_id', $creators->pluck('id'))->pluck('channel_id')->flip()
            : collect();
        $creators->each(fn (Channel $channel) => $channel->setAttribute('viewer_subscribed', $subscribed->has($channel->id)));

        return response()->json([
            'categories' => $categories,
            'trending_tags' => $tags,
            'creators' => ChannelSummaryResource::collection($creators)->resolve($request),
            'live_categories' => LiveStreamCategory::query()->where('enabled', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name'])->toArray(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function subscriptions(Request $request, VideoAccessService $access, ViewerStateService $states): JsonResponse
    {
        abort_unless($request->user(), 401);
        $data = $request->validate([
            'section' => ['nullable', Rule::in(['latest', 'popular', 'continue'])],
            'filter' => ['nullable', Rule::in(['all', 'today', 'new', 'unwatched', 'live'])],
            'type' => ['nullable', Rule::in(['all', 'videos', 'shorts'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $viewerId = $request->user()->id;
        $section = $data['section'] ?? 'latest';
        $filter = $data['filter'] ?? 'all';
        $query = $access->publicQuery()->select('videos.*')
            ->whereIn('channel_id', DB::table('subscriptions')->where('user_id', $viewerId)->select('channel_id'))
            ->with(['channel.user']);
        if (($data['type'] ?? 'all') === 'shorts') {
            $query->shorts();
        }
        if (($data['type'] ?? 'all') === 'videos') {
            $query->standardVideos();
        }
        if ($filter === 'today') {
            $query->where('published_at', '>=', now()->startOfDay());
        }
        if ($filter === 'new') {
            $query->standardVideos()->where('source_type', '!=', 'live_replay')->where('published_at', '>=', now()->subDays(14));
        }
        if ($filter === 'live') {
            $query->where('source_type', 'live_replay');
        }
        if ($filter === 'unwatched') {
            $query->whereNotExists(fn ($progress) => $progress->selectRaw('1')->from('video_watch_progress')
                ->whereColumn('video_watch_progress.video_id', 'videos.id')->where('video_watch_progress.user_id', $viewerId)->where('progress', '>', 0));
        }
        if ($section === 'continue') {
            WatchProgress::applyContinueScope(
                $query->join('video_watch_progress', 'video_watch_progress.video_id', '=', 'videos.id')
                    ->where('video_watch_progress.user_id', $viewerId)
            )->orderByDesc('video_watch_progress.updated_at');
        } elseif ($section === 'popular') {
            $query->orderByDesc('views_count');
        }
        $videos = $query->orderByDesc('published_at')->orderByDesc('videos.id')->paginate((int) ($data['per_page'] ?? 20));
        $states->hydrate($videos->getCollection(), $request->user());

        return PaginationPayload::lengthAware($videos, VideoCardResource::collection($videos->getCollection())->resolve($request))
            ->header('Cache-Control', 'private, no-store');
    }
}
