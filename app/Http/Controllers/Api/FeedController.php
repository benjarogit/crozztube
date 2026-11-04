<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoCategory;
use App\Support\VideoBoosts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class FeedController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $this->viewer($request);
        $query = Video::query()
            ->public()
            ->with(['channel.user', 'user', 'category', 'language', 'liveStream']);

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($channelSlug = trim((string) $request->query('channel'))) {
            $channel = Channel::query()->where('slug', $channelSlug)->firstOrFail();
            $query->where('channel_id', $channel->id);
        }

        if ($categorySlug = trim((string) $request->query('category'))) {
            $query->whereHas('category', fn (Builder $category) => $category->where('slug', $categorySlug));
        }

        if ($tag = trim((string) $request->query('tag'))) {
            $query->whereJsonContains('tags', $tag);
        }
        $since = match ((string) $request->query('upload_date')) {
            'today' => now()->subDay(),
            'week' => now()->subWeek(),
            'month' => now()->subDays(30),
            default => null,
        };
        if ($since) {
            $query->where('published_at', '>=', $since);
        }

        if ($request->boolean('featured')) {
            $query->whereIn('videos.id', Channel::query()
                ->whereNotNull('featured_video_id')
                ->select('featured_video_id'));
        }

        $type = strtolower(trim((string) $request->query('type')));
        if (in_array($type, ['short', 'shorts'], true)) {
            $query->shorts();
        } elseif (in_array($type, ['video', 'videos'], true)) {
            $query->standardVideos();
        }

        $sort = strtolower(trim((string) $request->query('sort')));
        $context = null;
        if ($sort === 'related') {
            $context = $this->relatedContext($request, $viewer);
            $query->where('videos.id', '!=', $context->id);
        }
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);
        if (in_array($sort, ['recommended', 'related'], true)) {
            $videos = $this->recommendedVideos($query, $request, $viewer, $perPage, $context);
        } elseif ($sort === 'hero') {
            $videos = $this->homeHeroVideos($query, $request, $viewer, $perPage);
        } elseif ($sort === 'trending') {
            $query->orderByDesc('views_count')->orderByDesc('likes_count')->orderByDesc('comments_count')->orderByDesc('videos.id');
            $videos = $query->paginate($perPage);
        } else {
            $query->latest('published_at')->latest('videos.id');
            $videos = $query->paginate($perPage);
        }

        if (! $request->filled('category')) {
            $videos = VideoBoosts::inject($videos, $request, $viewer, $sort, $type);
        }

        $this->hydrateViewerState($videos->getCollection(), $viewer);

        return VideoResource::collection($videos);
    }

    public function homeCategories(Request $request): JsonResponse
    {
        $visibleVideos = fn (Builder $query) => $query->public()->standardVideos();
        $categories = VideoCategory::query()
            ->where('enabled', true)
            ->whereHas('videos', $visibleVideos)
            ->withCount(['videos' => $visibleVideos])
            ->with(['videos' => fn ($query) => $query->public()->standardVideos()
                ->with(['channel.user', 'user', 'category', 'language', 'liveStream'])
                ->latest('published_at')->latest('videos.id')->limit(8)])
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->get()
            ->filter(fn (VideoCategory $category): bool => $category->videos->isNotEmpty());

        $this->hydrateViewerState(
            $categories->flatMap(fn (VideoCategory $category): Collection => $category->videos),
            $this->viewer($request),
        );

        return response()->json([
            'data' => $categories->map(fn (VideoCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'videos_count' => (int) $category->videos_count,
                'videos' => VideoResource::collection($category->videos)->resolve($request),
            ])->values(),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function hydrateViewerState(Collection $videos, ?User $viewer): void
    {
        if ($viewer && $videos->isNotEmpty()) {
            $saved = DB::table('watch_laters')
                ->where('user_id', $viewer->id)
                ->whereIn('video_id', $videos->pluck('id'))
                ->pluck('video_id')
                ->flip();
            $subscribed = DB::table('subscriptions')
                ->where('user_id', $viewer->id)
                ->whereIn('channel_id', $videos->pluck('channel_id')->unique())
                ->pluck('channel_id')
                ->flip();

            $videos->each(function (Video $video) use ($saved, $subscribed): void {
                $video->is_watch_later = $saved->has($video->id);
                if ($video->relationLoaded('channel') && $video->channel) {
                    $video->channel->is_subscribed = $subscribed->has($video->channel_id);
                }
            });
        }
    }

    private function recommendedVideos(Builder $query, Request $request, ?User $viewer, int $perPage, ?Video $context = null): LengthAwarePaginator
    {
        $profile = $this->recommendationProfile($viewer);
        $contextProfile = $this->recommendationContext($context);
        $sourceLimit = max(80, min(240, $perPage * 8));
        $recentIds = (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->latest('published_at')
            ->latest('videos.id')
            ->limit($sourceLimit)
            ->pluck('videos.id');
        $popularIds = (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->orderByRaw('(videos.views_count + (videos.likes_count * 6) + (videos.comments_count * 12)) DESC')
            ->latest('published_at')
            ->limit($sourceLimit)
            ->pluck('videos.id');
        $affinityIds = collect();
        $contextIds = collect();

        if ($viewer) {
            $channelIds = collect($profile['channels'])->sortDesc()->keys()
                ->merge($profile['subscriptions'])
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->take(24)
                ->values();
            $categoryIds = collect($profile['categories'])->sortDesc()->keys()
                ->map(fn ($id): int => (int) $id)
                ->take(12)
                ->values();

            if ($channelIds->isNotEmpty() || $categoryIds->isNotEmpty()) {
                $affinityIds = (clone $query)
                    ->withoutEagerLoads()
                    ->reorder()
                    ->where(function (Builder $builder) use ($channelIds, $categoryIds): void {
                        if ($channelIds->isNotEmpty()) {
                            $builder->whereIn('videos.channel_id', $channelIds);
                        }
                        if ($categoryIds->isNotEmpty()) {
                            $method = $channelIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                            $builder->{$method}('videos.category_id', $categoryIds);
                        }
                    })
                    ->latest('published_at')
                    ->limit($sourceLimit)
                    ->pluck('videos.id');
            }
        }

        if ($context) {
            $contextIds = (clone $query)
                ->withoutEagerLoads()
                ->reorder()
                ->where(function (Builder $builder) use ($contextProfile): void {
                    $builder->where('videos.channel_id', $contextProfile['channel_id']);
                    if ($contextProfile['category_id'] > 0) {
                        $builder->orWhere('videos.category_id', $contextProfile['category_id']);
                    }
                    foreach ($contextProfile['tags'] as $tag) {
                        $builder->orWhereJsonContains('videos.tags', $tag);
                    }
                })
                ->latest('published_at')
                ->latest('videos.id')
                ->limit($sourceLimit)
                ->pluck('videos.id');
        }

        $candidateIds = $contextIds->merge($affinityIds)->merge($recentIds)->merge($popularIds)->unique()->values();
        $candidates = $candidateIds->isEmpty()
            ? collect()
            : (clone $query)
                ->withoutEagerLoads()
                ->reorder()
                ->whereIn('videos.id', $candidateIds)
                ->get([
                    'videos.id', 'videos.user_id', 'videos.channel_id', 'videos.category_id',
                    'videos.language_id', 'videos.tags',
                    'videos.views_count', 'videos.likes_count', 'videos.comments_count',
                    'videos.published_at', 'videos.created_at',
                ]);
        $seed = now()->utc()->format('Y-m-d').'|'.($viewer?->id ?: 'guest').'|'.($context?->id ?: 'home');
        $ranked = $this->diversifyRecommendations($candidates->map(fn (Video $video): array => [
            'video' => $video,
            'score' => $this->recommendationScore($video, $profile, $seed, $contextProfile),
        ]));
        $page = max(1, (int) $request->integer('page', 1));
        $pageIds = $ranked->forPage($page, $perPage)->pluck('id')->values();
        $loaded = $pageIds->isEmpty()
            ? collect()
            : (clone $query)->reorder()->whereIn('videos.id', $pageIds)->get()->keyBy('id');
        $videos = $pageIds->map(fn ($id) => $loaded->get($id))->filter()->values();

        return new LengthAwarePaginator($videos, $ranked->count(), $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);
    }

    private function homeHeroVideos(Builder $query, Request $request, ?User $viewer, int $perPage): LengthAwarePaginator
    {
        $sourceLimit = max(80, min(200, $perPage * 20));
        $featuredIds = (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->whereIn('videos.id', Channel::query()->whereNotNull('featured_video_id')->select('featured_video_id'))
            ->latest('published_at')
            ->limit($sourceLimit)
            ->pluck('videos.id');
        $recentIds = (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->latest('published_at')
            ->latest('videos.id')
            ->limit($sourceLimit)
            ->pluck('videos.id');
        $popularIds = (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->orderByRaw('(videos.views_count + (videos.likes_count * 6) + (videos.comments_count * 12)) DESC')
            ->latest('published_at')
            ->limit($sourceLimit)
            ->pluck('videos.id');
        $candidateIds = $featuredIds->merge($recentIds)->merge($popularIds)->unique()->values();
        $candidates = $candidateIds->isEmpty()
            ? collect()
            : (clone $query)
                ->withoutEagerLoads()
                ->reorder()
                ->whereIn('videos.id', $candidateIds)
                ->get([
                    'videos.id', 'videos.user_id', 'videos.channel_id', 'videos.category_id',
                    'videos.language_id', 'videos.tags', 'videos.thumbnail_path',
                    'videos.views_count', 'videos.likes_count', 'videos.comments_count',
                    'videos.published_at', 'videos.created_at',
                ]);
        $visualCandidates = $candidates->filter(fn (Video $video): bool => trim((string) $video->thumbnail_path) !== '')->values();
        if ($visualCandidates->count() >= min($perPage, $candidates->count())) {
            $candidates = $visualCandidates;
        }

        $rotationValue = $request->query('rotation', '');
        $rotation = is_scalar($rotationValue)
            ? mb_substr((string) preg_replace('/[^A-Za-z0-9._:-]/', '', (string) $rotationValue), 0, 80)
            : '';
        $audience = $viewer
            ? 'user:'.$viewer->id
            : 'guest:'.hash('sha256', (string) $request->ip().'|'.mb_substr((string) $request->userAgent(), 0, 300));
        $seed = $audience.'|'.($rotation !== '' ? $rotation : '20m-'.intdiv(now()->getTimestamp(), 1200));
        $profile = $this->recommendationProfile($viewer);
        $context = $this->recommendationContext(null);
        $featured = $featuredIds->map(fn ($id): int => (int) $id)->flip();
        $recommendations = $candidates->map(function (Video $video) use ($profile, $context, $featured, $seed): array {
            $rotation = unpack('N', substr(hash('sha256', 'hero|'.$seed.'|'.$video->id, true), 0, 4))[1] / 4294967295;

            return [
                'video' => $video,
                'score' => $this->recommendationScore($video, $profile, $seed, $context)
                    + ($featured->has((int) $video->id) ? 18 : 0)
                    + (trim((string) $video->thumbnail_path) !== '' ? 7 : 0)
                    + ($rotation * 12),
            ];
        });

        $excludeValue = $request->query('exclude_ids', '');
        $excludeIds = collect(is_array($excludeValue) ? $excludeValue : preg_split('/,/', mb_substr((string) $excludeValue, 0, 500)))
            ->filter(fn ($id): bool => is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->take(24)
            ->flip();
        $fresh = $recommendations->reject(fn (array $item): bool => $excludeIds->has((int) $item['video']->id));
        $recentlyShown = $recommendations->filter(fn (array $item): bool => $excludeIds->has((int) $item['video']->id));
        $ranked = $this->diversifyHomeHero($fresh)
            ->concat($this->diversifyHomeHero($recentlyShown))
            ->unique('id')
            ->values();
        $page = max(1, (int) $request->integer('page', 1));
        $pageIds = $ranked->forPage($page, $perPage)->pluck('id')->values();
        $loaded = $pageIds->isEmpty()
            ? collect()
            : (clone $query)->reorder()->whereIn('videos.id', $pageIds)->get()->keyBy('id');
        $videos = $pageIds->map(fn ($id) => $loaded->get($id))->filter()->values();

        return new LengthAwarePaginator($videos, $ranked->count(), $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);
    }

    private function recommendationProfile(?User $viewer): array
    {
        $profile = [
            'user_id' => $viewer?->id,
            'subscriptions' => [],
            'channels' => [],
            'categories' => [],
            'watched' => [],
            'liked' => [],
            'saved' => [],
        ];

        if (! $viewer) {
            return $profile;
        }

        $profile['subscriptions'] = DB::table('subscriptions')
            ->where('user_id', $viewer->id)
            ->pluck('channel_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        foreach ($profile['subscriptions'] as $channelId) {
            $profile['channels'][$channelId] = ($profile['channels'][$channelId] ?? 0) + 2.5;
        }

        $history = DB::table('video_watch_progress')
            ->join('videos', 'videos.id', '=', 'video_watch_progress.video_id')
            ->where('video_watch_progress.user_id', $viewer->id)
            ->orderByDesc('video_watch_progress.updated_at')
            ->limit(200)
            ->get([
                'videos.id', 'videos.channel_id', 'videos.category_id', 'video_watch_progress.progress',
                'video_watch_progress.updated_at as interacted_at',
            ]);
        foreach ($history as $event) {
            $profile['watched'][(int) $event->id] = (int) $event->progress;
            $weight = (0.35 + (min(100, (int) $event->progress) / 100 * 1.65)) * $this->recommendationRecency($event->interacted_at);
            $this->addRecommendationAffinity($profile, $event, $weight);
        }

        $likes = DB::table('likes')
            ->join('videos', 'videos.id', '=', 'likes.likeable_id')
            ->where('likes.user_id', $viewer->id)
            ->where('likes.likeable_type', Video::class)
            ->orderByDesc('likes.created_at')
            ->limit(200)
            ->get(['videos.id', 'videos.channel_id', 'videos.category_id', 'likes.created_at as interacted_at']);
        foreach ($likes as $event) {
            $profile['liked'][(int) $event->id] = true;
            $this->addRecommendationAffinity($profile, $event, 2.4 * $this->recommendationRecency($event->interacted_at));
        }

        $saved = DB::table('watch_laters')
            ->join('videos', 'videos.id', '=', 'watch_laters.video_id')
            ->where('watch_laters.user_id', $viewer->id)
            ->orderByDesc('watch_laters.created_at')
            ->limit(100)
            ->get(['videos.id', 'videos.channel_id', 'videos.category_id', 'watch_laters.created_at as interacted_at']);
        foreach ($saved as $event) {
            $profile['saved'][(int) $event->id] = true;
            $this->addRecommendationAffinity($profile, $event, 1.25 * $this->recommendationRecency($event->interacted_at));
        }

        return $profile;
    }

    private function addRecommendationAffinity(array &$profile, object $event, float $weight): void
    {
        $channelId = (int) ($event->channel_id ?? 0);
        $categoryId = (int) ($event->category_id ?? 0);
        if ($channelId > 0) {
            $profile['channels'][$channelId] = ($profile['channels'][$channelId] ?? 0) + $weight;
        }
        if ($categoryId > 0) {
            $profile['categories'][$categoryId] = ($profile['categories'][$categoryId] ?? 0) + $weight;
        }
    }

    private function recommendationRecency(mixed $timestamp): float
    {
        if (! $timestamp) {
            return 1.0;
        }

        try {
            $ageDays = max(0, (now()->timestamp - Carbon::parse($timestamp)->timestamp) / 86400);
        } catch (\Throwable) {
            return 1.0;
        }

        return pow(0.5, $ageDays / 120);
    }

    private function recommendationScore(Video $video, array $profile, string $seed, array $context): float
    {
        $publishedAt = $video->published_at ?: $video->created_at;
        $ageDays = $publishedAt ? max(0, (now()->timestamp - $publishedAt->timestamp) / 86400) : 365;
        $views = max(0, (int) $video->views_count);
        $likes = max(0, (int) $video->likes_count);
        $comments = max(0, (int) $video->comments_count);
        $volume = $views + ($likes * 6) + ($comments * 12);
        $momentum = min(1, log1p($volume / pow($ageDays + 1, 0.65)) / log(5001));
        $freshness = pow(0.5, $ageDays / 21);
        $popularity = min(1, log1p($views) / log(100001));
        $likeQuality = min(1, (($likes + 2) / ($views + 80)) / 0.12);
        $commentQuality = min(1, (($comments + 1) / ($views + 120)) / 0.04);
        $quality = ($likeQuality * 0.65) + ($commentQuality * 0.35);
        $score = ($momentum * 34) + ($freshness * 22) + ($quality * 16) + ($popularity * 10);

        if ($context['video_id']) {
            $score += (int) $video->channel_id === $context['channel_id'] ? 28 : 0;
            $score += (int) ($video->category_id ?? 0) === $context['category_id'] && $context['category_id'] > 0 ? 22 : 0;
            $score += (int) ($video->language_id ?? 0) === $context['language_id'] && $context['language_id'] > 0 ? 4 : 0;
            $candidateTags = $this->recommendationTags($video->tags);
            $overlap = count(array_intersect($context['tags'], $candidateTags));
            if ($overlap > 0) {
                $score += (2 * $overlap / max(1, count($context['tags']) + count($candidateTags))) * 22;
            }
        }

        if ($profile['user_id']) {
            $videoId = (int) $video->id;
            $channelId = (int) $video->channel_id;
            $categoryId = (int) ($video->category_id ?? 0);
            $score += in_array($channelId, $profile['subscriptions'], true) ? 14 : 0;
            $score += $this->affinityStrength($profile['channels'], $channelId) * 16;
            $score += $this->affinityStrength($profile['categories'], $categoryId) * 13;
            $score += isset($profile['saved'][$videoId]) ? 2 : 0;
            $score -= isset($profile['liked'][$videoId]) ? 3 : 0;
            $score -= (int) $video->user_id === (int) $profile['user_id'] ? 10 : 0;

            $progress = (int) ($profile['watched'][$videoId] ?? 0);
            $score -= match (true) {
                $progress >= 95 => 34,
                $progress >= 20 => 10,
                $progress > 0 => 4,
                default => -2.5,
            };
        }

        $hash = unpack('N', substr(hash('sha256', $seed.'|'.$video->id, true), 0, 4))[1] / 4294967295;
        $score += $hash * ($views < 100 ? 5 : 2);

        return round($score, 6);
    }

    private function recommendationContext(?Video $video): array
    {
        return [
            'video_id' => (int) ($video?->id ?? 0),
            'channel_id' => (int) ($video?->channel_id ?? 0),
            'category_id' => (int) ($video?->category_id ?? 0),
            'language_id' => (int) ($video?->language_id ?? 0),
            'tags' => $this->recommendationTags($video?->tags),
        ];
    }

    private function recommendationTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $tags);
        }

        return collect(is_array($tags) ? $tags : [])
            ->map(function ($tag): string {
                $value = is_array($tag) ? ($tag['name'] ?? $tag['label'] ?? $tag['slug'] ?? '') : $tag;

                return mb_strtolower(ltrim(trim((string) $value), '#'));
            })
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    private function affinityStrength(array $affinities, int $id): float
    {
        if ($id < 1 || empty($affinities[$id])) {
            return 0;
        }

        $maximum = max($affinities);
        $value = (float) $affinities[$id];

        return $maximum > 0 ? min(1, $value / $maximum) * (1 - exp(-$value / 3)) : 0;
    }

    private function diversifyRecommendations(Collection $recommendations): Collection
    {
        $remaining = $recommendations->values();
        $selected = collect();
        $channelCounts = [];
        $categoryCounts = [];
        $lastChannelId = null;

        while ($remaining->isNotEmpty()) {
            $bestKey = null;
            $bestScore = -INF;
            foreach ($remaining as $key => $recommendation) {
                $video = $recommendation['video'];
                $channelId = (int) $video->channel_id;
                $categoryId = (int) ($video->category_id ?? 0);
                $adjusted = $recommendation['score']
                    - (($channelCounts[$channelId] ?? 0) * 10)
                    - (($categoryCounts[$categoryId] ?? 0) * 2.5)
                    - ($lastChannelId === $channelId ? 5 : 0);
                if ($bestKey === null || $adjusted > $bestScore) {
                    $bestKey = $key;
                    $bestScore = $adjusted;
                }
            }

            $picked = $remaining->get($bestKey)['video'];
            $remaining->forget($bestKey);
            $selected->push($picked);
            $channelId = (int) $picked->channel_id;
            $categoryId = (int) ($picked->category_id ?? 0);
            $channelCounts[$channelId] = ($channelCounts[$channelId] ?? 0) + 1;
            if ($categoryId > 0) {
                $categoryCounts[$categoryId] = ($categoryCounts[$categoryId] ?? 0) + 1;
            }
            $lastChannelId = $channelId;
        }

        return $selected;
    }

    private function diversifyHomeHero(Collection $recommendations): Collection
    {
        $remaining = $recommendations->values();
        $selected = collect();
        $usedChannels = [];
        $categoryCounts = [];
        $lastChannelId = null;

        while ($remaining->isNotEmpty()) {
            $eligible = $remaining;
            if ($lastChannelId !== null && $remaining->contains(fn (array $item): bool => (int) $item['video']->channel_id !== $lastChannelId)) {
                $eligible = $remaining->filter(fn (array $item): bool => (int) $item['video']->channel_id !== $lastChannelId);
            }
            $unusedChannels = $eligible->reject(fn (array $item): bool => isset($usedChannels[(int) $item['video']->channel_id]));
            if ($unusedChannels->isNotEmpty()) {
                $eligible = $unusedChannels;
            }

            $bestKey = null;
            $bestScore = -INF;
            foreach ($eligible as $key => $recommendation) {
                $categoryId = (int) ($recommendation['video']->category_id ?? 0);
                $adjusted = $recommendation['score'] - (($categoryCounts[$categoryId] ?? 0) * 3);
                if ($bestKey === null || $adjusted > $bestScore) {
                    $bestKey = $key;
                    $bestScore = $adjusted;
                }
            }

            $picked = $remaining->get($bestKey)['video'];
            $remaining->forget($bestKey);
            $selected->push($picked);
            $lastChannelId = (int) $picked->channel_id;
            $usedChannels[$lastChannelId] = true;
            $categoryId = (int) ($picked->category_id ?? 0);
            if ($categoryId > 0) {
                $categoryCounts[$categoryId] = ($categoryCounts[$categoryId] ?? 0) + 1;
            }
        }

        return $selected;
    }

    private function viewer(Request $request): ?User
    {
        $viewer = $request->user();
        if ($viewer instanceof User) {
            return $viewer;
        }

        $token = $request->bearerToken();
        $viewer = $token ? PersonalAccessToken::findToken($token)?->tokenable : null;

        return $viewer instanceof User ? $viewer : null;
    }

    private function relatedContext(Request $request, ?User $viewer): Video
    {
        $slug = trim((string) $request->query('related_to'));
        abort_if($slug === '' || mb_strlen($slug) > 190, 404, 'Video not found.');

        $video = Video::query()
            ->where('slug', $slug)
            ->where(function (Builder $query) use ($viewer): void {
                $query->whereIn('visibility', ['public', 'unlisted']);
                if ($viewer) {
                    $query->orWhere(function (Builder $query) use ($viewer): void {
                        $query->where('visibility', 'private')->where('user_id', $viewer->id);
                    });
                }
            })
            ->first();

        abort_unless($video, 404, 'Video not found.');

        return $video;
    }
}
