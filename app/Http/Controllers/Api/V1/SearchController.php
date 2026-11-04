<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChannelSummaryResource;
use App\Http\Resources\Api\V1\LiveStreamResource;
use App\Http\Resources\Api\V1\PlaylistSummaryResource;
use App\Http\Resources\Api\V1\VideoCardResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\VideoCategory;
use App\Services\Api\V1\LiveStreamAccessService;
use App\Services\Api\V1\VideoAccessService;
use App\Services\Api\V1\ViewerStateService;
use App\Support\Api\V1\PaginationPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SearchController extends Controller
{
    public function index(
        Request $request,
        VideoAccessService $videos,
        ViewerStateService $viewerState,
        LiveStreamAccessService $live,
    ): JsonResponse {
        $this->trimQuery($request);
        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:160'],
            'type' => ['nullable', Rule::in(['all', 'videos', 'shorts', 'creators', 'users', 'live', 'playlists', 'categories'])],
            'sort' => ['nullable', Rule::in(['relevance', 'latest', 'popular'])],
            'upload_date' => ['nullable', Rule::in(['hour', 'today', 'week', 'month', 'year'])],
            'duration' => ['nullable', Rule::in(['short', 'medium', 'long'])],
            'resolution' => ['nullable', Rule::in(['hd', '4k'])],
            'features' => ['nullable', Rule::in(['captions', 'kids'])],
            'category' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $type = ($data['type'] ?? 'all') === 'users' ? 'creators' : (string) ($data['type'] ?? 'all');
        $perPage = (int) ($data['per_page'] ?? 20);
        $term = trim((string) $data['q']);
        $like = '%'.addcslashes($term, '\\%_').'%';
        $uploadDate = $data['upload_date'] ?? null;
        $duration = $data['duration'] ?? null;
        $category = $data['category'] ?? null;

        if ($type === 'all') {
            return $this->all($request, $videos, $viewerState, $live, $term, $like);
        }

        if (in_array($type, ['videos', 'shorts'], true)) {
            $query = $videos->publicQuery()
                ->when($type === 'shorts', fn ($query) => $query->shorts(), fn ($query) => $query->standardVideos())
                ->where(fn ($query) => $this->applyVideoMatch($query, $term, $like))
                ->when($uploadDate, fn ($query) => $this->applyUploadDateFilter($query, $uploadDate))
                ->when($duration, fn ($query) => $this->applyDurationFilter($query, $duration))
                ->when($category, fn ($query) => $query->whereHas('category', fn ($c) => $c->where('slug', $category)))
                ->with(['channel.user']);
            $this->sortVideos($query, (string) ($data['sort'] ?? 'relevance'), $term);
            $this->applyMediaFilters($query, $data['resolution'] ?? null, $data['features'] ?? null);
            $results = $query->paginate($perPage);
            $viewerState->hydrate($results->getCollection(), $request->user());

            return PaginationPayload::lengthAware($results, VideoCardResource::collection($results->getCollection())->resolve($request));
        }

        if ($type === 'creators') {
            $results = Channel::query()
                ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('slug', 'like', $like)->orWhere('bio', 'like', $like))
                ->whereHas('user', fn ($query) => $this->publicProfile($query))
                ->with('user')
                ->withCount(['videos' => fn ($query) => $videos->constrainPublic($query)])
                ->orderByDesc('subscribers_count')->orderBy('id')
                ->paginate($perPage);

            return PaginationPayload::lengthAware($results, ChannelSummaryResource::collection($results->getCollection())->resolve($request));
        }

        if ($type === 'live') {
            $results = $live->directoryQuery($request->user())
                ->where(fn ($query) => $query->where('title', 'like', $like)->orWhere('description', 'like', $like))
                ->whereIn('status', ['live', 'scheduled', 'ended'])
                ->with(['channel.user', 'category', 'replayVideo.channel.user'])
                ->latest()->orderByDesc('id')->paginate($perPage);

            return PaginationPayload::lengthAware($results, LiveStreamResource::collection($results->getCollection())->resolve($request));
        }

        if ($type === 'playlists') {
            $results = $this->playlistQuery($videos, $like)->paginate($perPage);

            return PaginationPayload::lengthAware($results, PlaylistSummaryResource::collection($results->getCollection())->resolve($request));
        }

        $results = VideoCategory::query()->where('enabled', true)
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('slug', 'like', $like))
            ->withCount(['videos' => fn ($query) => $videos->constrainPublic($query)])
            ->orderByDesc('videos_count')->orderBy('name')->orderBy('id')->paginate($perPage);
        $items = $results->getCollection()->map(fn (VideoCategory $category): array => [
            'id' => (int) $category->id,
            'slug' => (string) $category->slug,
            'name' => (string) $category->name,
            'videos_count' => (int) $category->videos_count,
        ])->all();

        return PaginationPayload::lengthAware($results, $items);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $this->trimQuery($request);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $term = trim((string) $data['q']);
        $like = '%'.addcslashes($term, '\\%_').'%';
        $videos = app(VideoAccessService::class)->publicQuery()
            ->where('title', 'like', $like)->orderByDesc('views_count')->orderByDesc('id')->limit(8)->pluck('title');
        $channels = Channel::query()->where('name', 'like', $like)
            ->whereHas('user', fn ($query) => $this->publicProfile($query))
            ->orderByDesc('subscribers_count')->limit(5)->pluck('name');
        $categories = VideoCategory::query()->where('enabled', true)->where('name', 'like', $like)
            ->orderBy('name')->limit(3)->pluck('name');
        $suggestions = $videos->concat($channels)->concat($categories)->filter()
            ->unique(fn ($value) => mb_strtolower($value))->take(10)->values()->all();

        return response()->json(['query' => $term, 'suggestions' => $suggestions])
            ->header('Cache-Control', 'private, no-store');
    }

    private function all(Request $request, VideoAccessService $videos, ViewerStateService $states, LiveStreamAccessService $live, string $term, string $like): JsonResponse
    {
        $videoResults = $videos->publicQuery()
            ->standardVideos()->where(fn ($query) => $this->applyVideoMatch($query, $term, $like))
            ->with(['channel.user'])->orderByDesc('views_count')->orderByDesc('id')->limit(8)->get();
        $shortResults = $videos->publicQuery()->shorts()
            ->where(fn ($query) => $this->applyVideoMatch($query, $term, $like))
            ->with(['channel.user'])->orderByDesc('views_count')->orderByDesc('id')->limit(6)->get();
        $states->hydrate($videoResults, $request->user());
        $states->hydrate($shortResults, $request->user());
        $creators = Channel::query()->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('slug', 'like', $like))
            ->whereHas('user', fn ($query) => $this->publicProfile($query))
            ->with('user')->withCount(['videos' => fn ($query) => $videos->constrainPublic($query)])->orderByDesc('subscribers_count')->limit(5)->get();
        $streams = $live->directoryQuery($request->user())->where('title', 'like', $like)
            ->whereIn('status', ['live', 'scheduled', 'ended'])->with(['channel.user', 'category', 'replayVideo.channel.user'])->limit(5)->get();
        $playlists = $this->playlistQuery($videos, $like)->limit(5)->get();
        $categories = VideoCategory::query()->where('enabled', true)
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('slug', 'like', $like))
            ->withCount(['videos' => fn ($query) => $videos->constrainPublic($query)])
            ->orderByDesc('videos_count')->limit(5)->get()
            ->map(fn (VideoCategory $category): array => [
                'id' => (int) $category->id,
                'slug' => (string) $category->slug,
                'name' => (string) $category->name,
                'videos_count' => (int) $category->videos_count,
            ])->all();

        return response()->json([
            'query' => $term,
            'sections' => [
                ['type' => 'videos', 'items' => VideoCardResource::collection($videoResults)->resolve($request), 'more_url' => route('api.v1.search', ['q' => $term, 'type' => 'videos'])],
                ['type' => 'shorts', 'items' => VideoCardResource::collection($shortResults)->resolve($request), 'more_url' => route('api.v1.search', ['q' => $term, 'type' => 'shorts'])],
                ['type' => 'creators', 'items' => ChannelSummaryResource::collection($creators)->resolve($request), 'more_url' => route('api.v1.search', ['q' => $term, 'type' => 'creators'])],
                ['type' => 'playlists', 'items' => PlaylistSummaryResource::collection($playlists)->resolve($request), 'more_url' => route('api.v1.search', ['q' => $term, 'type' => 'playlists'])],
                ['type' => 'live', 'items' => LiveStreamResource::collection($streams)->resolve($request), 'more_url' => route('api.v1.search', ['q' => $term, 'type' => 'live'])],
                ['type' => 'categories', 'items' => $categories, 'more_url' => route('api.v1.search', ['q' => $term, 'type' => 'categories'])],
            ],
        ]);
    }

    private function playlistQuery(VideoAccessService $videos, string $like): Builder
    {
        return Playlist::query()
            ->where('privacy', 'public')
            ->whereHas('user', fn ($owner) => $this->publicProfile($owner))
            ->where(fn ($query) => $query->where('title', 'like', $like)->orWhere('description', 'like', $like))
            ->with(['channel.user', 'coverVideo' => fn ($query) => $videos->constrainPublic($query)])
            ->withCount(['videos' => fn ($query) => $videos->constrainPublic($query)])
            ->latest()->orderByDesc('id');
    }

    private function applyUploadDateFilter($query, string $range): void
    {
        $since = match ($range) {
            'hour' => now()->subHour(),
            'today' => now()->startOfDay(),
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'year' => now()->subYear(),
            default => null,
        };
        if ($since) {
            $query->where('published_at', '>=', $since);
        }
    }

    /** Match the website's rendition and feature filters over public media only. */
    private function applyMediaFilters(Builder $query, ?string $resolution, ?string $feature): void
    {
        $labels = match ($resolution) {
            'hd' => ['high', 'original', '1080', '720'],
            '4k' => ['4k', '2160', '3840'],
            default => [],
        };
        if ($labels) {
            $query->where(function (Builder $renditions) use ($labels): void {
                foreach ($labels as $label) {
                    $renditions->orWhere('renditions', 'like', '%'.$label.'%');
                }
            });
        }
        if ($feature === 'captions') {
            $query->whereNotNull('captions_path');
        } elseif ($feature === 'kids') {
            $query->where('made_for_kids', true);
        }
    }

    private function applyVideoMatch($query, string $term, string $like): void
    {
        $query->where('title', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->orWhereJsonContains('tags', $term)
            ->orWhereHas('channel', fn ($channel) => $channel->where('name', 'like', $like))
            ->orWhereHas('category', fn ($category) => $category->where('enabled', true)->where('name', 'like', $like));
    }

    private function applyDurationFilter($query, string $duration): void
    {
        match ($duration) {
            'short' => $query->where('duration_seconds', '<', 240),
            'medium' => $query->whereBetween('duration_seconds', [240, 1200]),
            'long' => $query->where('duration_seconds', '>', 1200),
            default => null,
        };
    }

    private function sortVideos($query, string $sort, string $term): void
    {
        if ($sort === 'latest') {
            $query->latest('published_at')->latest('id');
        } elseif ($sort === 'popular') {
            $query->orderByDesc('views_count')->orderByDesc('likes_count')->orderByDesc('id');
        } else {
            $query->orderByRaw('CASE WHEN LOWER(title) = ? THEN 0 WHEN LOWER(title) LIKE ? THEN 1 ELSE 2 END', [mb_strtolower($term), mb_strtolower($term).'%'])
                ->orderByDesc('views_count')->orderByDesc('id');
        }
    }

    private function publicProfile($query)
    {
        return $query
            ->where(fn ($privacy) => $privacy
                ->whereNull('settings->preferences->publicProfile')
                ->orWhere('settings->preferences->publicProfile', true))
            ->where(fn ($status) => $status
                ->whereNull('settings->status')
                ->orWhere('settings->status', 'active'));
    }

    private function trimQuery(Request $request): void
    {
        $query = $request->query('q');
        if (is_string($query)) {
            $request->merge(['q' => trim($query)]);
        }
    }
}
