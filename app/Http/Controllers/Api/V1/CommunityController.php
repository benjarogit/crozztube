<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\CommunityPostController as LegacyCommunityPostController;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\Api\V1\CommentResource;
use App\Http\Resources\CommunityPostResource;
use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommunityPollVote;
use App\Models\CommunityPost;
use App\Services\Api\V1\CommentContextService;
use App\Services\Api\V1\CommunityAccessService;
use App\Services\Api\V1\EngagementService;
use App\Services\Api\V1\VideoAccessService;
use App\Support\Api\V1\PaginationPayload;
use App\Support\MonetizationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CommunityController extends Controller
{
    public function preview(Request $request, LegacyCommunityPostController $legacy): JsonResponse
    {
        return response()->json(['data' => $legacy->preview($request)->getData(true)]);
    }

    public function discovery(Request $request, CommunityAccessService $access): JsonResponse
    {
        $visible = $access->visibleQuery($request->user());
        $subscribed = $request->user() ? DB::table('subscriptions')->where('user_id', $request->user()->id)->pluck('channel_id') : collect();
        $suggested = Channel::query()->whereIn('id', (clone $visible)->select('channel_id'))
            ->whereNotIn('id', $subscribed)->when($request->user(), fn ($query) => $query->where('user_id', '!=', $request->user()->id))
            ->orderByDesc('subscribers_count')->orderByDesc('videos_count')->limit(5)->get()
            ->map(fn (Channel $channel) => ['id' => $channel->id, 'name' => $channel->name, 'slug' => $channel->slug, 'subscribers_count' => (int) $channel->subscribers_count]);
        $topics = [];
        (clone $visible)->latest()->limit(120)->pluck('body')->each(function (?string $body) use (&$topics): void {
            foreach (CommunityPost::hashtagLabels($body) as $tag) {
                $key = mb_strtolower($tag);
                $topics[$key] ??= ['tag' => $tag, 'posts' => 0];
                $topics[$key]['posts']++;
            }
        });
        $events = (clone $visible)->where('type', 'event')->with('channel')->latest()->limit(20)->get()
            ->filter(fn (CommunityPost $post) => ! empty($post->metadata['starts_at']) && Carbon::parse($post->metadata['starts_at'])->gte(now()->subHour()))
            ->take(4)->map(fn (CommunityPost $post) => ['id' => $post->id, 'title' => $post->metadata['title'] ?? 'Community event', 'starts_at' => $post->metadata['starts_at'], 'channel' => $post->channel?->name, 'slug' => $post->channel?->slug])->values();
        $scores = (clone $visible)->select('channel_id')->selectRaw('COUNT(*) as posts, SUM(likes_count + comments_count + shares_count + votes_count) as engagement')
            ->groupBy('channel_id')->orderByDesc('engagement')->limit(5)->get()->keyBy('channel_id');
        $creators = Channel::query()->whereIn('id', $scores->keys())->get()->map(fn (Channel $channel) => [
            'id' => $channel->id, 'name' => $channel->name, 'slug' => $channel->slug,
            'posts' => (int) $scores[$channel->id]->posts, 'engagement' => (int) $scores[$channel->id]->engagement,
        ])->sortByDesc('engagement')->values();

        return response()->json(['suggested' => $suggested->values(), 'topics' => collect($topics)->sortByDesc('posts')->take(5)->values(), 'events' => $events, 'creators' => $creators]);
    }

    public function options(Request $request, VideoAccessService $videos): JsonResponse
    {
        $channel = $request->user()->channel;
        $published = $channel ? $videos->publicQuery()->where('channel_id', $channel->id)->latest('published_at')->limit(100)->get(['id', 'title']) : collect();

        return response()->json([
            'channel' => $channel ? ['id' => $channel->id, 'slug' => $channel->slug, 'name' => $channel->name] : null,
            'can_create' => (bool) $channel,
            'videos' => $published->toArray(),
            'photo_sales' => MonetizationSettings::paidCommunityPhotoSettings(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Channel $channel, LegacyCommunityPostController $legacy): JsonResponse
    {
        return $legacy->store($request, $channel);
    }

    public function update(Request $request, CommunityPost $communityPost, CommunityAccessService $access, LegacyCommunityPostController $legacy): JsonResponse
    {
        $access->assertVisible($communityPost, $request->user());

        return $legacy->update($request, $communityPost);
    }

    public function destroy(Request $request, CommunityPost $communityPost, CommunityAccessService $access, LegacyCommunityPostController $legacy): JsonResponse
    {
        $access->assertVisible($communityPost, $request->user());

        return $legacy->destroy($request, $communityPost);
    }

    public function vote(Request $request, CommunityPost $communityPost, CommunityAccessService $access, LegacyCommunityPostController $legacy): JsonResponse
    {
        $access->assertVisible($communityPost, $request->user());

        return $legacy->vote($request, $communityPost);
    }

    public function voters(Request $request, CommunityPost $communityPost, CommunityAccessService $access): JsonResponse
    {
        $access->assertVisible($communityPost, $request->user());
        abort_unless($communityPost->type === 'poll', 404);
        $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $votes = CommunityPollVote::query()->where('community_post_id', $communityPost->id)
            ->whereHas('user', fn ($user) => $user->publicProfileEnabled())->with('user.channel')->latest('updated_at')->paginate($request->integer('per_page', 20));
        $items = $votes->getCollection()->map(fn (CommunityPollVote $vote) => [
            'id' => $vote->id, 'name' => $vote->user->name, 'channel_slug' => $vote->user->channel?->slug,
            'option_index' => (int) $vote->option_index,
            'option_label' => (string) data_get($communityPost->metadata, 'options.'.$vote->option_index.'.label', ''),
            'voted_at' => $vote->updated_at?->toIso8601String(),
        ])->all();

        return PaginationPayload::lengthAware($votes, $items);
    }

    public function bookmark(Request $request, CommunityPost $communityPost, CommunityAccessService $access, LegacyCommunityPostController $legacy): JsonResponse
    {
        $access->assertVisible($communityPost, $request->user());

        return $legacy->bookmark($request, $communityPost);
    }

    public function bookmarks(Request $request, CommunityAccessService $access): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $posts = $access->visibleQuery($request->user())->select('community_posts.*')
            ->join('community_post_bookmarks', 'community_post_bookmarks.community_post_id', '=', 'community_posts.id')
            ->where('community_post_bookmarks.user_id', $request->user()->id)
            ->with(['user', 'channel.user', 'video.channel.user', 'video.user', 'video.category', 'video.language'])
            ->orderByDesc('community_post_bookmarks.created_at')->orderByDesc('community_posts.id')->paginate($request->integer('per_page', 20));
        $this->hydratePosts($posts->getCollection(), $request);

        return PaginationPayload::lengthAware($posts, CommunityPostResource::collection($posts->getCollection())->resolve($request))->header('Cache-Control', 'private, no-store');
    }

    public function share(Request $request, CommunityPost $communityPost, CommunityAccessService $access, LegacyCommunityPostController $legacy): JsonResponse
    {
        $access->assertVisible($communityPost, $request->user());

        return $legacy->share($request, $communityPost);
    }

    public function index(Request $request, CommunityAccessService $access): JsonResponse
    {
        $data = $this->validateListing($request);
        $posts = $this->posts($request, $access, $data)->paginate((int) ($data['per_page'] ?? 20));
        $this->hydratePosts($posts->getCollection(), $request);

        return PaginationPayload::lengthAware(
            $posts,
            CommunityPostResource::collection($posts->getCollection())->resolve($request),
        );
    }

    public function channel(Request $request, Channel $channel, CommunityAccessService $access): JsonResponse
    {
        $access->assertChannelVisible($channel, $request->user());
        $data = $this->validateListing($request);
        $posts = $this->posts($request, $access, $data)
            ->where('channel_id', $channel->id)
            ->paginate((int) ($data['per_page'] ?? 20));
        $this->hydratePosts($posts->getCollection(), $request);

        return PaginationPayload::lengthAware(
            $posts,
            CommunityPostResource::collection($posts->getCollection())->resolve($request),
        );
    }

    public function show(Request $request, CommunityPost $communityPost, CommunityAccessService $access): JsonResponse
    {
        $access->assertVisible($communityPost, $request->user());
        $communityPost->load(['user', 'channel.user', 'video.channel.user', 'video.user', 'video.category', 'video.language']);
        $this->hydratePosts(collect([$communityPost]), $request);

        return response()->json((new CommunityPostResource($communityPost))->resolve($request));
    }

    public function comments(Request $request, CommunityPost $communityPost, CommunityAccessService $access): JsonResponse
    {
        $access->assertVisible($communityPost, $request->user());
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $comments = $communityPost->comments()
            ->with(['user.channel', 'sticker'])->withCount('replies')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));
        $this->hydrateComments($comments->getCollection(), $communityPost, $request);

        return PaginationPayload::lengthAware(
            $comments,
            CommentResource::collection($comments->getCollection())->resolve($request),
        );
    }

    public function commentContext(
        Request $request,
        CommunityPost $communityPost,
        Comment $comment,
        CommunityAccessService $access,
        CommentContextService $context,
    ): JsonResponse {
        $access->assertVisible($communityPost, $request->user());

        return response()->json($context->resolve($comment, $communityPost));
    }

    public function storeComment(
        StoreCommentRequest $request,
        CommunityPost $communityPost,
        CommunityAccessService $access,
        LegacyCommunityPostController $legacy,
    ): JsonResponse {
        $access->assertVisible($communityPost, $request->user());
        $response = $legacy->storeComment($request, $communityPost);
        $id = (int) data_get($response->getData(true), 'comment.id');
        abort_unless($id > 0, 500);
        $comment = Comment::query()->with(['user.channel', 'sticker'])->withCount('replies')->findOrFail($id);
        $this->hydrateComments(collect([$comment]), $communityPost, $request);

        return response()->json([
            'comment' => (new CommentResource($comment))->resolve($request),
            'comments_count' => (int) $communityPost->fresh()->comments_count,
        ], 201);
    }

    public function like(Request $request, CommunityPost $communityPost, CommunityAccessService $access, EngagementService $engagement): JsonResponse
    {
        $access->assertVisible($communityPost, $request->user());

        return response()->json($engagement->setLike($request->user(), $communityPost, 'likes_count', true));
    }

    public function unlike(Request $request, CommunityPost $communityPost, CommunityAccessService $access, EngagementService $engagement): JsonResponse
    {
        $access->assertVisible($communityPost, $request->user());

        return response()->json($engagement->setLike($request->user(), $communityPost, 'likes_count', false));
    }

    private function validateListing(Request $request): array
    {
        return $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'following', 'images', 'polls', 'updates', 'videos', 'events'])],
            'q' => ['nullable', 'string', 'max:160'],
            'sort' => ['nullable', Rule::in(['latest', 'relevant'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
    }

    private function posts(Request $request, CommunityAccessService $access, array $data)
    {
        $filter = $data['filter'] ?? 'all';

        return $access->visibleQuery($request->user())
            ->with(['user', 'channel.user', 'video.channel.user', 'video.user', 'video.category', 'video.language'])
            ->where(fn ($query) => $query
                ->where('type', '!=', 'video')
                ->orWhereHas('video')
                ->orWhere(fn ($body) => $body->whereNotNull('body')->where('body', '!=', '')))
            ->when($filter === 'following', function ($query) use ($request): void {
                $subscriptions = $request->user()
                    ? DB::table('subscriptions')->where('user_id', $request->user()->id)->select('channel_id')
                    : null;
                $subscriptions ? $query->whereIn('channel_id', $subscriptions) : $query->whereRaw('1 = 0');
            })
            ->when($filter === 'images', fn ($query) => $query->whereIn('type', ['image', 'cover_update']))
            ->when($filter === 'polls', fn ($query) => $query->where('type', 'poll'))
            ->when($filter === 'updates', fn ($query) => $query->whereIn('type', ['text', 'url', 'cover_update']))
            ->when($filter === 'videos', fn ($query) => $query->where('type', 'video'))
            ->when($filter === 'events', fn ($query) => $query->where('type', 'event'))
            ->when(filled($data['q'] ?? null), function ($query) use ($data): void {
                $term = '%'.addcslashes(trim($data['q']), '\\%_').'%';
                $query->where(fn ($search) => $search->where('body', 'like', $term)->orWhere('url_title', 'like', $term)
                    ->orWhere('metadata->title', 'like', $term)->orWhere('metadata->location', 'like', $term)
                    ->orWhereHas('channel', fn ($channel) => $channel->where('name', 'like', $term)->orWhere('slug', 'like', $term))
                    ->orWhereHas('video', fn ($video) => $video->where('title', 'like', $term)));
            })
            ->when(($data['sort'] ?? 'latest') === 'relevant', fn ($query) => $query->orderByRaw('(likes_count * 3 + comments_count * 4 + shares_count * 2 + votes_count) DESC'))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    private function hydratePosts(Collection $posts, Request $request): void
    {
        if (! $request->user() || $posts->isEmpty()) {
            return;
        }
        $liked = DB::table('likes')
            ->where('user_id', $request->user()->id)
            ->where('likeable_type', CommunityPost::class)
            ->whereIn('likeable_id', $posts->pluck('id'))
            ->pluck('likeable_id')->flip();
        $posts->each(fn (CommunityPost $post) => $post->setAttribute('is_liked', $liked->has($post->id)));
        $bookmarked = DB::table('community_post_bookmarks')->where('user_id', $request->user()->id)->whereIn('community_post_id', $posts->pluck('id'))->pluck('community_post_id')->flip();
        $votes = CommunityPollVote::query()->where('user_id', $request->user()->id)->whereIn('community_post_id', $posts->pluck('id'))->pluck('option_index', 'community_post_id');
        $posts->each(function (CommunityPost $post) use ($bookmarked, $votes): void {
            $post->setAttribute('is_bookmarked', $bookmarked->has($post->id));
            if ($votes->has($post->id)) {
                $post->setAttribute('user_poll_vote', (int) $votes[$post->id]);
            }
        });
    }

    private function hydrateComments(Collection $comments, CommunityPost $post, Request $request): void
    {
        $viewer = $request->user();
        $liked = collect();
        $reported = collect();
        if ($viewer && $comments->isNotEmpty()) {
            $ids = $comments->pluck('id');
            $liked = DB::table('likes')->where('user_id', $viewer->id)
                ->where('likeable_type', Comment::class)->whereIn('likeable_id', $ids)
                ->pluck('likeable_id')->flip();
            $reported = DB::table('comment_reports')->where('user_id', $viewer->id)
                ->whereIn('comment_id', $ids)->pluck('comment_id')->flip();
        }

        $comments->each(function (Comment $comment) use ($viewer, $post, $liked, $reported): void {
            $comment->setRelation('commentable', $post);
            $comment->setAttribute('viewer_liked', $liked->has($comment->id));
            $comment->setAttribute('viewer_reported', $reported->has($comment->id));
            $comment->setAttribute('viewer_can_update', $viewer && (int) $viewer->id === (int) $comment->user_id);
            $comment->setAttribute('viewer_can_delete', $viewer && ((int) $viewer->id === (int) $post->user_id
                || Gate::forUser($viewer)->allows('delete', $comment)));
            $comment->setAttribute('viewer_can_pin', $viewer && (int) $viewer->id === (int) $post->user_id);
            $comment->setAttribute('viewer_can_report', $viewer && (int) $viewer->id !== (int) $comment->user_id);
        });
    }
}
