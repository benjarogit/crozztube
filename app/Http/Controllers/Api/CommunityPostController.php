<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Http\Resources\CommunityPostResource;
use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommunityPollVote;
use App\Models\CommunityPost;
use App\Models\Like;
use App\Support\KlipyStickers;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Video;
use App\Support\CommunityPhotoSales;
use App\Support\Images\ImageUploads;
use App\Support\MediaStorage;
use App\Support\PlatformFeatureSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class CommunityPostController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);
        $filter = (string) $request->query('filter', 'all');
        $sort = (string) $request->query('sort', 'latest');
        $search = trim((string) $request->query('q', ''));
        $perPage = min(30, max(1, (int) $request->integer('per_page', 10)));

        $posts = CommunityPost::query()
            ->with(['user', 'channel', 'video.channel.user', 'video.user', 'video.category', 'video.language'])
            ->where(fn ($query) => $query
                ->where('type', '!=', 'video')
                ->orWhereHas('video')
                ->orWhere(fn ($bodyQuery) => $bodyQuery->whereNotNull('body')->where('body', '!=', '')))
            ->when($filter === 'following', function ($query) use ($user): void {
                $channelIds = $user
                    ? DB::table('subscriptions')->where('user_id', $user->id)->pluck('channel_id')
                    : collect();
                $channelIds->isNotEmpty() ? $query->whereIn('channel_id', $channelIds) : $query->whereRaw('1 = 0');
            })
            ->when($filter === 'announcements', fn ($query) => $query->whereIn('type', ['text', 'url', 'cover_update']))
            ->when($filter === 'polls', fn ($query) => $query->where('type', 'poll'))
            ->when($filter === 'images', fn ($query) => $query->whereIn('type', ['image', 'cover_update']))
            ->when($filter === 'videos', fn ($query) => $query->where('type', 'video'))
            ->when($filter === 'live', fn ($query) => $query->where('type', 'event'))
            ->when($search !== '', fn ($query) => $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('body', 'like', "%{$search}%")
                    ->orWhere('url_title', 'like', "%{$search}%")
                    ->orWhere('metadata->title', 'like', "%{$search}%")
                    ->orWhere('metadata->location', 'like', "%{$search}%")
                    ->orWhereHas('channel', fn ($channelQuery) => $channelQuery->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"))
                    ->orWhereHas('video', fn ($videoQuery) => $videoQuery->where('title', 'like', "%{$search}%"));
            }))
            ->when($sort === 'relevant', fn ($query) => $query
                ->orderByRaw('(likes_count * 3 + comments_count * 4 + shares_count * 2 + votes_count) desc')
                ->latest(), fn ($query) => $query->latest())
            ->paginate($perPage);

        $this->hydrateViewerState($posts->getCollection(), $user);

        return response()->json([
            'posts' => CommunityPostResource::collection($posts->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
            'suggested' => $this->suggestedCommunities($user),
            'topics' => $this->trendingCommunityTopics(),
            'events' => $this->upcomingCommunityEvents(),
            'creators' => $this->topCommunityCreators(),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        return response()->json([
            'preview' => $this->urlPreview($data['url']),
        ]);
    }

    public function index(Request $request, Channel $channel): AnonymousResourceCollection
    {
        $filter = (string) $request->query('filter', 'all');
        $sort = (string) $request->query('sort', 'relevant');
        $search = trim((string) $request->query('q', ''));

        $posts = $channel->communityPosts()
            ->with(['user', 'channel', 'video.channel.user', 'video.user', 'video.category', 'video.language'])
            ->where(fn ($query) => $query
                ->where('type', '!=', 'video')
                ->orWhereHas('video')
                ->orWhere(fn ($bodyQuery) => $bodyQuery->whereNotNull('body')->where('body', '!=', '')))
            ->when($filter === 'images', fn ($query) => $query->whereIn('type', ['image', 'cover_update']))
            ->when($filter === 'polls', fn ($query) => $query->where('type', 'poll'))
            ->when($filter === 'updates', fn ($query) => $query->whereIn('type', ['text', 'url', 'cover_update']))
            ->when($filter === 'videos', fn ($query) => $query->where('type', 'video'))
            ->when($filter === 'events', fn ($query) => $query->where('type', 'event'))
            ->when($search !== '', fn ($query) => $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('body', 'like', "%{$search}%")
                    ->orWhere('url_title', 'like', "%{$search}%")
                    ->orWhere('metadata->title', 'like', "%{$search}%")
                    ->orWhere('metadata->location', 'like', "%{$search}%")
                    ->orWhereHas('video', fn ($videoQuery) => $videoQuery->where('title', 'like', "%{$search}%"));
            }))
            ->when($sort === 'newest', fn ($query) => $query->latest(), fn ($query) => $query
                ->orderByRaw('(likes_count * 3 + comments_count * 4 + shares_count * 2 + votes_count) desc')
                ->latest())
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        $this->hydrateViewerState($posts->getCollection(), $this->userFromRequest($request));

        return CommunityPostResource::collection($posts);
    }

    public function store(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($request->user()?->id === $channel->user_id, 403);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'type' => ['nullable', Rule::in(['text', 'url', 'image', 'poll', 'video', 'event'])],
            'url' => ['nullable', 'url', 'max:2048'],
            'url_title' => ['nullable', 'string', 'max:255'],
            'url_description' => ['nullable', 'string', 'max:1000'],
            'url_image' => ['nullable', 'url', 'max:2048'],
            'video_id' => ['nullable', 'integer'],
            'poll_options' => ['nullable', 'array', 'min:2', 'max:6'],
            'poll_options.*' => ['required_with:poll_options', 'string', 'max:120'],
            'event_title' => ['nullable', 'required_if:type,event', 'string', 'max:160'],
            'event_starts_at' => ['nullable', 'required_if:type,event', 'date'],
            'event_ends_at' => ['nullable', 'date', 'after:event_starts_at'],
            'event_timezone' => ['nullable', 'timezone'],
            'event_location' => ['nullable', 'string', 'max:160'],
            'event_url' => ['nullable', 'url', 'max:2048'],
            'images' => ['prohibited'],
            'image_ids' => ['nullable', 'array', 'max:4'],
            'image_ids.*' => ['required', 'uuid', 'distinct'],
            'sale_enabled' => ['nullable', 'boolean'],
            'sale_price' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
        ], ['images.prohibited' => 'Upload images first and submit image_ids. Multipart image attachments are no longer accepted.']);

        $body = PlatformFeatureSettings::filterProfanity(trim((string) ($data['body'] ?? '')));
        $pollOptions = collect($data['poll_options'] ?? [])->map(fn ($option) => trim((string) $option))->filter()->values();
        $video = ! empty($data['video_id'])
            ? Video::query()->where('channel_id', $channel->id)->where('visibility', 'public')->where('status', 'ready')->whereKey($data['video_id'])->firstOrFail()
            : null;
        $imageIds = $data['image_ids'] ?? [];
        app(ImageUploads::class)->validateIds($request->user(), 'community_post', $channel->id, $imageIds);
        $url = trim((string) ($data['url'] ?? ''));
        $prospectiveType = $this->postType($pollOptions, $video, $imageIds ? [['type' => 'image']] : [], $url, $data['type'] ?? null);
        $sale = CommunityPhotoSales::pricing($request->boolean('sale_enabled'), $data['sale_price'] ?? null, $prospectiveType === 'image');
        $type = $prospectiveType;
        $urlPreview = $url ? $this->urlPreview($url) : [];
        $metadata = $type === 'poll' ? ['options' => $pollOptions->map(fn ($label) => ['label' => $label, 'votes' => 0])->all()] : [];
        if ($type === 'event') {
            $timezone = $data['event_timezone'] ?? config('app.timezone', 'UTC');
            $startsAt = Carbon::parse($data['event_starts_at'], $timezone);
            $endsAt = ! empty($data['event_ends_at']) ? Carbon::parse($data['event_ends_at'], $timezone) : null;
            $metadata = [
                'title' => trim((string) $data['event_title']),
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt?->toIso8601String(),
                'timezone' => $timezone,
                'location' => trim((string) ($data['event_location'] ?? '')) ?: null,
                'url' => trim((string) ($data['event_url'] ?? '')) ?: null,
            ];
        }
        if (! empty($urlPreview['icon'])) {
            $metadata['url_icon'] = $urlPreview['icon'];
        }

        abort_if($body === '' && ! $imageIds && ! $video && ! $url && $pollOptions->count() < 2 && $type !== 'event', 422, 'Add text, media, a link, a video, an event or a poll.');

        $post = DB::transaction(function () use ($request, $channel, $video, $type, $body, $url, $data, $urlPreview, $metadata, $sale, $imageIds): CommunityPost {
            $post = CommunityPost::query()->create([
                'user_id' => $request->user()->id,
                'channel_id' => $channel->id,
                'video_id' => $video?->id,
                'type' => $type,
                'body' => $body ?: null,
                'url' => $url ?: null,
                'url_title' => trim((string) ($data['url_title'] ?? '')) ?: ($urlPreview['title'] ?? ($url ? parse_url($url, PHP_URL_HOST) : null)),
                'url_description' => trim((string) ($data['url_description'] ?? '')) ?: ($urlPreview['description'] ?? null),
                'url_image' => trim((string) ($data['url_image'] ?? '')) ?: ($urlPreview['image'] ?? null),
                'attachments' => null,
                'metadata' => $metadata ?: null,
                'sale_enabled' => $sale['sale_enabled'],
                'price_cents' => $sale['price_cents'],
            ]);
            if ($imageIds) {
                $attachments = app(ImageUploads::class)->bind($request->user(), 'community_post', $channel->id, $imageIds, $post);
                abort_unless(count($attachments) === count($imageIds), 422, 'An image is no longer ready to publish.');
                $post->update(['attachments' => $attachments]);
            }
            return $post;
        });
        $this->notifyMentionedUsers($request, $post);

        return response()->json([
            'message' => 'Community post published.',
            'post' => (new CommunityPostResource($post->load(['user', 'channel', 'video.channel.user', 'video.user', 'video.category', 'video.language'])))->resolve($request),
        ], 201);
    }

    public function update(Request $request, CommunityPost $communityPost): JsonResponse
    {
        abort_unless($request->user()?->id === $communityPost->user_id, 403);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'sale_enabled' => ['nullable', 'boolean'],
            'sale_price' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
        ]);

        $body = PlatformFeatureSettings::filterProfanity(trim((string) ($data['body'] ?? '')));
        $hasExistingContent = (bool) (
            $communityPost->url
            || $communityPost->video_id
            || $communityPost->attachments
            || in_array($communityPost->type, ['poll', 'cover_update', 'event'], true)
        );

        abort_if($body === '' && ! $hasExistingContent, 422, 'Post text cannot be empty.');

        $updates = [
            'body' => $body !== '' ? $body : null,
        ];
        if (array_key_exists('sale_enabled', $data)) {
            $updates = array_merge($updates, CommunityPhotoSales::pricing(
                $request->boolean('sale_enabled'),
                $data['sale_price'] ?? null,
                $communityPost->type === 'image' && collect($communityPost->attachments ?: [])->where('type', 'image')->isNotEmpty()
            ));
        }
        $communityPost->update($updates);
        $this->notifyMentionedUsers($request, $communityPost);

        return response()->json([
            'message' => 'Community post updated.',
            'post' => (new CommunityPostResource($communityPost->fresh(['user', 'channel', 'video.channel.user', 'video.user', 'video.category', 'video.language'])))->resolve($request),
        ]);
    }

    public function destroy(Request $request, CommunityPost $communityPost): JsonResponse
    {
        $communityPost->loadMissing('channel');
        abort_unless($this->canDeletePost($request->user(), $communityPost), 403);

        DB::transaction(function () use ($communityPost): void {
            app(ImageUploads::class)->detach($communityPost->attachments ?: []);
            $comments = Comment::withoutGlobalScopes()
                ->where('commentable_type', CommunityPost::class)
                ->where('commentable_id', $communityPost->id)
                ->get();
            // Include every reply before FK cascades remove its attachment refs.
            $knownIds = $comments->pluck('id')->flip();
            $roots = $comments->filter(fn (Comment $comment) => ! $comment->parent_id || ! $knownIds->has($comment->parent_id));
            $thread = $roots->flatMap(fn (Comment $comment) => $comment->threadModels())->concat($comments)->unique('id');
            $commentIds = $thread->pluck('id');
            app(ImageUploads::class)->detach($thread->flatMap(fn (Comment $comment) => $comment->attachments ?: [])->values()->all());

            Like::query()
                ->where('likeable_type', CommunityPost::class)
                ->where('likeable_id', $communityPost->id)
                ->delete();

            if ($commentIds->isNotEmpty()) {
                Like::query()
                    ->where('likeable_type', Comment::class)
                    ->whereIn('likeable_id', $commentIds)
                    ->delete();

                Comment::withoutGlobalScopes()->whereIn('id', $commentIds)->delete();
            }

            $communityPost->pollVotes()->delete();
            $communityPost->delete();
        });

        return response()->json([
            'message' => 'Community post deleted.',
        ]);
    }

    public function comments(Request $request, CommunityPost $communityPost): AnonymousResourceCollection
    {
        $user = $this->userFromRequest($request);
        app(\App\Services\Api\V1\CommunityAccessService::class)->assertVisible($communityPost, $user);
        $comments = $communityPost->comments()
            ->with(['user.channel', 'sticker'])
            ->withCount('replies')
            ->paginate(20);

        $this->hydrateCommentState($comments->getCollection(), $user, $communityPost->user_id);

        return CommentResource::collection($comments);
    }

    public function storeComment(StoreCommentRequest $request, CommunityPost $communityPost): JsonResponse
    {
        app(\App\Services\Api\V1\CommunityAccessService::class)->assertVisible($communityPost, $request->user());
        \App\Support\UserBlocks::assertInteraction($request->user(), (int) $communityPost->user_id);
        abort_if($request->user()->isBannedFrom('comments'), 403, $request->user()->banMessage('comments'));
        $data = $request->validated();
        $body = PlatformFeatureSettings::filterProfanity(trim((string) ($data['body'] ?? '')));
        $sticker = app(KlipyStickers::class)->resolveSelection($data['sticker_id'] ?? null, $request);

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = Comment::query()
                ->whereKey($data['parent_id'])
                ->where('commentable_type', CommunityPost::class)
                ->where('commentable_id', $communityPost->id)
                ->firstOrFail();
            \App\Support\UserBlocks::assertInteraction($request->user(), (int) $parent->user_id);
            $data['parent_id'] = $parent->id;
        }

        $imageIds = $data['image_ids'] ?? [];
        app(ImageUploads::class)->validateIds($request->user(), 'community_comment', $communityPost->id, $imageIds);

        $comment = DB::transaction(function () use ($request, $communityPost, $data, $imageIds, $body, $sticker): Comment {
            $comment = $communityPost->comments()->create([
                'user_id' => $request->user()->id,
                'parent_id' => $data['parent_id'] ?? null,
                'sticker_id' => $sticker?->id,
                'body' => $body,
                'attachments' => null,
            ]);
            if ($imageIds) {
                $attachments = app(ImageUploads::class)->bind($request->user(), 'community_comment', $communityPost->id, $imageIds, $comment);
                abort_unless(count($attachments) === count($imageIds), 422, 'An image is no longer ready to publish.');
                $comment->update(['attachments' => $attachments]);
            }

            $communityPost->increment('comments_count');

            return $comment;
        });

        if ($parent && (int) $parent->user_id !== (int) $request->user()->id
            && ! $this->notificationsMuted((int) $parent->user_id, (int) $request->user()->id)) {
            UserNotification::query()->create([
                'user_id' => $parent->user_id,
                'actor_id' => $request->user()->id,
                'channel_id' => $communityPost->channel_id,
                'community_post_id' => $communityPost->id,
                'comment_id' => $comment->id,
                'type' => 'comment_reply',
                'data' => [
                    'body' => mb_substr($comment->body ?: ($sticker ? 'Sent a sticker: '.$sticker->name : ''), 0, 180),
                    'parent_id' => $parent->id,
                ],
            ]);
        }
        if ((int) $communityPost->user_id !== (int) ($parent?->user_id ?? 0)) {
            $this->notifyPostAuthor($request, $communityPost, 'community_comment', ['body' => mb_substr($comment->body ?: ($sticker ? 'Sent a sticker: '.$sticker->name : ''), 0, 180)], $comment->id);
        }
        $this->notifyMentionedUsers($request, $communityPost, $comment);
        $this->setCommentPermissions($comment, $request->user(), $communityPost->user_id);

        return response()->json([
            'message' => 'Comment published.',
            'comment' => new CommentResource($comment->load(['user.channel', 'sticker'])),
            'post' => (new CommunityPostResource($communityPost->fresh(['user', 'channel', 'video.channel.user', 'video.user', 'video.category', 'video.language'])))->resolve($request),
        ], 201);
    }

    public function share(Request $request, CommunityPost $communityPost): JsonResponse
    {
        $communityPost->increment('shares_count');

        return response()->json([
            'shares_count' => (int) $communityPost->fresh()->shares_count,
        ]);
    }

    public function bookmarks(Request $request): JsonResponse
    {
        $perPage = min(30, max(1, (int) $request->integer('per_page', 10)));
        $posts = CommunityPost::query()
            ->with(['user', 'channel', 'video.channel.user', 'video.user', 'video.category', 'video.language'])
            ->join('community_post_bookmarks', 'community_post_bookmarks.community_post_id', '=', 'community_posts.id')
            ->where('community_post_bookmarks.user_id', $request->user()->id)
            ->where(fn ($query) => $query
                ->where('community_posts.type', '!=', 'video')
                ->orWhereHas('video')
                ->orWhere(fn ($bodyQuery) => $bodyQuery->whereNotNull('community_posts.body')->where('community_posts.body', '!=', '')))
            ->select('community_posts.*')
            ->orderByDesc('community_post_bookmarks.created_at')
            ->paginate($perPage);

        $this->hydrateViewerState($posts->getCollection(), $request->user());

        return response()->json([
            'posts' => CommunityPostResource::collection($posts->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    public function bookmark(Request $request, CommunityPost $communityPost): JsonResponse
    {
        $bookmarked = DB::transaction(function () use ($request, $communityPost): bool {
            $query = DB::table('community_post_bookmarks')
                ->where('user_id', $request->user()->id)
                ->where('community_post_id', $communityPost->id);

            if ($query->lockForUpdate()->exists()) {
                $query->delete();

                return false;
            }

            DB::table('community_post_bookmarks')->insert([
                'user_id' => $request->user()->id,
                'community_post_id' => $communityPost->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });

        return response()->json([
            'bookmarked' => $bookmarked,
        ]);
    }

    public function vote(Request $request, CommunityPost $communityPost): JsonResponse
    {
        abort_unless($communityPost->type === 'poll', 404);

        $optionIndex = (int) $request->validate([
            'option_index' => ['required', 'integer', 'min:0'],
        ])['option_index'];

        $post = DB::transaction(function () use ($request, $communityPost, $optionIndex): CommunityPost {
            $post = CommunityPost::query()->lockForUpdate()->findOrFail($communityPost->id);
            $options = collect($post->metadata['options'] ?? []);
            abort_unless($options->has($optionIndex), 422, 'Invalid poll option.');

            CommunityPollVote::query()->updateOrCreate([
                'community_post_id' => $post->id,
                'user_id' => $request->user()->id,
            ], [
                'option_index' => $optionIndex,
            ]);

            $counts = CommunityPollVote::query()
                ->where('community_post_id', $post->id)
                ->select('option_index', DB::raw('count(*) as total'))
                ->groupBy('option_index')
                ->pluck('total', 'option_index');

            $metadata = $post->metadata ?: [];
            $metadata['options'] = $options->map(fn (array $option, int $index): array => [
                'label' => $option['label'],
                'votes' => (int) ($counts[$index] ?? 0),
            ])->all();

            $post->update([
                'metadata' => $metadata,
                'votes_count' => (int) $counts->sum(),
            ]);

            return $post->fresh(['user', 'channel', 'video.channel.user', 'video.user', 'video.category', 'video.language']);
        });

        $post->user_poll_vote = $optionIndex;
        $this->notifyPostAuthor($request, $post, 'community_poll_vote');

        return response()->json([
            'message' => 'Vote saved.',
            'post' => (new CommunityPostResource($post))->resolve($request),
        ]);
    }

    public function voters(Request $request, CommunityPost $communityPost): JsonResponse
    {
        abort_unless($communityPost->type === 'poll', 404);

        $perPage = min(100, max(1, $request->integer('per_page', 50)));
        $options = collect($communityPost->metadata['options'] ?? []);
        $voters = CommunityPollVote::query()
            ->with(['user.channel'])
            ->where('community_post_id', $communityPost->id)
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json([
            'poll' => [
                'id' => $communityPost->id,
                'question' => $communityPost->body,
                'votes_count' => $voters->total(),
            ],
            'voters' => collect($voters->items())->map(function (CommunityPollVote $vote) use ($options): array {
                $user = $vote->user;
                $channel = $user->channel;

                return [
                    'id' => $vote->id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'channel_slug' => $channel?->slug,
                    'avatar_url' => ($channel?->avatar_path || $user->avatar_path) ? MediaStorage::url($channel?->avatar_path ?: $user->avatar_path) : null,
                    'option_index' => $vote->option_index,
                    'option_label' => (string) ($options->get($vote->option_index)['label'] ?? 'Unknown option'),
                    'voted_at' => $vote->updated_at?->toIso8601String(),
                ];
            })->values()->all(),
            'meta' => [
                'current_page' => $voters->currentPage(),
                'last_page' => $voters->lastPage(),
                'per_page' => $voters->perPage(),
                'total' => $voters->total(),
            ],
        ]);
    }

    private function postType(Collection $pollOptions, ?Video $video, array $attachments, string $url, ?string $requested): string
    {
        if ($requested === 'event') {
            return 'event';
        }

        if ($pollOptions->count() >= 2) {
            return 'poll';
        }

        if ($video) {
            return 'video';
        }

        if ($attachments) {
            return 'image';
        }

        if ($url !== '') {
            return 'url';
        }

        return $requested === 'text' ? 'text' : 'text';
    }

    private function urlPreview(string $url): array
    {
        $url = trim($url);
        $host = parse_url($url, PHP_URL_HOST);
        $fallback = [
            'url' => $url,
            'title' => $host,
            'description' => null,
            'image' => null,
            'icon' => $this->absoluteUrl('/favicon.ico', $url),
        ];

        if (! $this->isSafePreviewUrl($url)) {
            return $fallback;
        }

        try {
            $response = Http::timeout(4)
                ->connectTimeout(2)
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'PlayHubLinkPreview/1.0'])
                ->get($url);
        } catch (\Throwable) {
            return $fallback;
        }

        $contentType = strtolower((string) $response->header('content-type'));
        if (! $response->ok() || ! str_contains($contentType, 'text/html')) {
            return $fallback;
        }

        $html = substr($response->body(), 0, 250000);
        if (! class_exists(\DOMDocument::class) || trim($html) === '') {
            return $fallback;
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $fallback;
        }

        $meta = fn (array $keys): ?string => $this->firstMetaContent($dom, $keys);
        $link = fn (array $rels): ?string => $this->firstLinkHref($dom, $rels);
        $title = $meta(['og:title', 'twitter:title']) ?: $this->documentTitle($dom) ?: $fallback['title'];
        $description = $meta(['og:description', 'twitter:description', 'description']);
        $image = $meta(['og:image', 'twitter:image', 'twitter:image:src']) ?: $link(['image_src']);
        $icon = $meta(['og:logo']) ?: $link(['apple-touch-icon', 'apple-touch-icon-precomposed', 'icon', 'shortcut icon', 'mask-icon']) ?: '/favicon.ico';

        return [
            'url' => $url,
            'title' => $this->cleanPreviewText($title, 255),
            'description' => $this->cleanPreviewText($description, 500),
            'image' => $image ? $this->absoluteUrl($image, $url) : null,
            'icon' => $icon ? $this->absoluteUrl($icon, $url) : null,
        ];
    }

    private function isSafePreviewUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || in_array($host, ['localhost', '0.0.0.0'], true) || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        $ips = gethostbynamel($host) ?: [];
        if (! $ips) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    private function firstMetaContent(\DOMDocument $dom, array $keys): ?string
    {
        $wanted = array_map('strtolower', $keys);
        foreach ($dom->getElementsByTagName('meta') as $node) {
            $name = strtolower((string) ($node->getAttribute('property') ?: $node->getAttribute('name')));
            if (in_array($name, $wanted, true)) {
                $content = trim((string) $node->getAttribute('content'));
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return null;
    }

    private function firstLinkHref(\DOMDocument $dom, array $rels): ?string
    {
        $wanted = array_map('strtolower', $rels);
        foreach ($dom->getElementsByTagName('link') as $node) {
            $rel = strtolower((string) $node->getAttribute('rel'));
            if ($rel !== '' && collect($wanted)->contains(fn ($item) => str_contains($rel, $item))) {
                $href = trim((string) $node->getAttribute('href'));
                if ($href !== '') {
                    return $href;
                }
            }
        }

        return null;
    }

    private function documentTitle(\DOMDocument $dom): ?string
    {
        $titles = $dom->getElementsByTagName('title');

        return $titles->length ? trim((string) $titles->item(0)?->textContent) : null;
    }

    private function absoluteUrl(string $value, string $baseUrl): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $value)) {
            return $value;
        }

        $parts = parse_url($baseUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        if (str_starts_with($value, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$value;
        }

        if (str_starts_with($value, '/')) {
            return $origin.$value;
        }

        $path = $parts['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $origin.($directory ? '/'.$directory : '').'/'.$value;
    }

    private function cleanPreviewText(?string $value, int $limit): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function suggestedCommunities(?object $user): array
    {
        $subscribed = $user
            ? DB::table('subscriptions')->where('user_id', $user->id)->pluck('channel_id')->all()
            : [];

        return Channel::query()
            ->when($subscribed, fn ($query) => $query->whereNotIn('id', $subscribed))
            ->when($user, fn ($query) => $query->where('user_id', '!=', $user->id))
            ->has('communityPosts')
            ->orderByDesc('subscribers_count')
            ->orderByDesc('videos_count')
            ->limit(5)
            ->get()
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
                'user_id' => $channel->user_id,
                'avatar_url' => $channel->avatar_path ? MediaStorage::url($channel->avatar_path) : null,
                'subscribers_count' => (int) $channel->subscribers_count,
                'members' => (int) $channel->subscribers_count,
                'is_subscribed' => false,
            ])
            ->values()
            ->all();
    }

    private function trendingCommunityTopics(): array
    {
        $topics = [];
        CommunityPost::query()
            ->latest()
            ->limit(120)
            ->pluck('body')
            ->each(function (?string $body) use (&$topics): void {
                foreach (CommunityPost::hashtagLabels($body) as $tag) {
                    $key = mb_strtolower($tag);
                    $topics[$key] = ($topics[$key] ?? ['tag' => $tag, 'count' => 0]);
                    $topics[$key]['count']++;
                }
            });

        return collect($topics)
            ->sortByDesc('count')
            ->take(5)
            ->values()
            ->map(fn (array $topic): array => ['tag' => $topic['tag'], 'posts' => $topic['count']])
            ->all();
    }

    private function upcomingCommunityEvents(): array
    {
        return CommunityPost::query()
            ->with('channel')
            ->where('type', 'event')
            ->latest()
            ->limit(20)
            ->get()
            ->filter(fn (CommunityPost $post): bool => ! empty($post->metadata['starts_at']) && Carbon::parse($post->metadata['starts_at'])->greaterThanOrEqualTo(now()->subHour()))
            ->take(4)
            ->map(fn (CommunityPost $post): array => [
                'id' => $post->id,
                'title' => $post->metadata['title'] ?? 'Community event',
                'time' => Carbon::parse($post->metadata['starts_at'])->format('M j, g:i A'),
                'channel' => $post->channel?->name,
                'slug' => $post->channel?->slug,
            ])
            ->values()
            ->all();
    }

    private function topCommunityCreators(): array
    {
        $scores = CommunityPost::query()
            ->select('channel_id', DB::raw('count(*) as posts'), DB::raw('sum(likes_count + comments_count + shares_count + votes_count) as engagement'))
            ->groupBy('channel_id')
            ->orderByDesc('engagement')
            ->limit(5)
            ->get()
            ->keyBy('channel_id');

        return Channel::query()
            ->whereIn('id', $scores->keys())
            ->get()
            ->map(function (Channel $channel) use ($scores): array {
                $score = $scores->get($channel->id);

                return [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'slug' => $channel->slug,
                    'avatar_url' => $channel->avatar_path ? MediaStorage::url($channel->avatar_path) : null,
                    'engagement' => (int) ($score->engagement ?? 0),
                    'posts' => (int) ($score->posts ?? 0),
                ];
            })
            ->sortByDesc('engagement')
            ->values()
            ->all();
    }

    private function hydrateViewerState(Collection $posts, $user): void
    {
        if (! $user || $posts->isEmpty()) {
            return;
        }

        $postIds = $posts->pluck('id')->all();
        $likedIds = Like::query()
            ->where('user_id', $user->id)
            ->where('likeable_type', CommunityPost::class)
            ->whereIn('likeable_id', $postIds)
            ->pluck('likeable_id')
            ->all();
        $votes = CommunityPollVote::query()
            ->where('user_id', $user->id)
            ->whereIn('community_post_id', $postIds)
            ->pluck('option_index', 'community_post_id');
        $bookmarkedIds = DB::table('community_post_bookmarks')
            ->where('user_id', $user->id)
            ->whereIn('community_post_id', $postIds)
            ->pluck('community_post_id')
            ->all();

        $canModerate = $this->canModeratePosts($user);
        $posts->each(function (CommunityPost $post) use ($user, $likedIds, $bookmarkedIds, $votes, $canModerate): void {
            $post->is_liked = in_array($post->id, $likedIds, true);
            $post->is_bookmarked = in_array($post->id, $bookmarkedIds, true);
            $isAuthor = (int) $post->user_id === (int) $user->id;
            $post->can_manage = $isAuthor;
            $post->can_edit = $isAuthor;
            $post->can_delete = $isAuthor || (int) $post->channel?->user_id === (int) $user->id || $canModerate;
            if ($votes->has($post->id)) {
                $post->user_poll_vote = (int) $votes[$post->id];
            }
        });
    }

    private function canDeletePost(?object $user, CommunityPost $post): bool
    {
        if (! $user) {
            return false;
        }

        return (int) $post->user_id === (int) $user->id
            || (int) $post->channel?->user_id === (int) $user->id
            || $this->canModeratePosts($user);
    }

    private function canModeratePosts(object $user): bool
    {
        $settings = is_array($user->settings ?? null) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;

        return $adminEnabled || (bool) ($settings['is_admin'] ?? false) || in_array($role, ['admin', 'super admin', 'owner', 'moderator'], true);
    }

    private function hydrateCommentState(Collection $comments, $user, ?int $targetOwnerId): void
    {
        if (! $user || $comments->isEmpty()) {
            return;
        }

        $commentIds = $comments->pluck('id')->merge(
            $comments->flatMap(fn ($comment) => $comment->relationLoaded('replies') ? $comment->replies->pluck('id') : collect())
        );
        $likedIds = Comment::query()
            ->whereIn('id', $commentIds)
            ->whereHas('likes', fn ($query) => $query->where('user_id', $user->id))
            ->pluck('id')
            ->all();
        $reportedIds = DB::table('comment_reports')
            ->where('user_id', $user->id)
            ->whereIn('comment_id', $commentIds)
            ->pluck('comment_id')
            ->all();

        $comments->each(function ($comment) use ($user, $targetOwnerId, $likedIds, $reportedIds): void {
            $comment->is_liked = in_array($comment->id, $likedIds, true);
            $comment->reported = in_array($comment->id, $reportedIds, true);
            $this->setCommentPermissions($comment, $user, $targetOwnerId);
            if (! $comment->relationLoaded('replies')) {
                return;
            }

            $comment->replies->each(function ($reply) use ($user, $targetOwnerId, $likedIds, $reportedIds): void {
                $reply->is_liked = in_array($reply->id, $likedIds, true);
                $reply->reported = in_array($reply->id, $reportedIds, true);
                $this->setCommentPermissions($reply, $user, $targetOwnerId);
            });
        });
    }

    private function setCommentPermissions(Comment $comment, ?object $user, ?int $targetOwnerId): void
    {
        if (! $user) {
            return;
        }

        $isCommentAuthor = (int) $comment->user_id === (int) $user->id;
        $isTargetOwner = $targetOwnerId && (int) $targetOwnerId === (int) $user->id;
        $comment->can_delete = $isCommentAuthor || $isTargetOwner;
        $comment->can_pin = $isTargetOwner;
        $comment->can_report = ! $isCommentAuthor;
        $comment->can_mute = ! $isCommentAuthor;
    }

    private function notifyPostAuthor(Request $request, CommunityPost $post, string $type, array $data = [], ?int $commentId = null): void
    {
        if ($post->user_id === $request->user()->id || $this->notificationsMuted($post->user_id, $request->user()->id)) {
            return;
        }

        UserNotification::query()->create([
            'user_id' => $post->user_id,
            'actor_id' => $request->user()->id,
            'channel_id' => $post->channel_id,
            'community_post_id' => $post->id,
            'comment_id' => $commentId,
            'type' => $type,
            'data' => $data,
        ]);
    }

    private function notifyMentionedUsers(Request $request, CommunityPost $post, ?Comment $comment = null): void
    {
        $handles = collect($this->mentionedHandles($comment?->body ?? $post->body));
        if ($handles->isEmpty()) {
            return;
        }

        $actorId = (int) $request->user()->id;
        User::query()
            ->with('channel')
            ->where(function ($query) use ($handles): void {
                $query->whereIn(DB::raw('LOWER(username)'), $handles->all())
                    ->orWhereHas('channel', fn ($channelQuery) => $channelQuery->whereIn(DB::raw('LOWER(slug)'), $handles->all()));
            })
            ->where('id', '!=', $actorId)
            ->when($comment, fn ($query) => $query->where('id', '!=', $post->user_id))
            ->get()
            ->each(function (User $user) use ($request, $post, $comment, $actorId): void {
                if (! $this->acceptsMentionNotifications($user) || $this->notificationsMuted((int) $user->id, $actorId)) {
                    return;
                }

                UserNotification::query()->updateOrCreate([
                    'user_id' => $user->id,
                    'actor_id' => $request->user()->id,
                    'channel_id' => $post->channel_id,
                    'community_post_id' => $post->id,
                    'comment_id' => $comment?->id,
                    'type' => $comment ? 'comment_mention' : 'community_mention',
                ], [
                    'data' => ['body' => mb_substr((string) ($comment?->body ?? $post->body), 0, 180)],
                ]);
            });
    }

    private function mentionedHandles(?string $body): array
    {
        preg_match_all('/(^|[^\pL\pN_])@([A-Za-z0-9_.-]{2,40})/u', (string) $body, $matches);

        return collect($matches[2] ?? [])
            ->map(fn ($handle) => strtolower(trim((string) $handle, '.-_')))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    private function acceptsMentionNotifications(User $user): bool
    {
        return (bool) data_get(is_array($user->settings) ? $user->settings : [], 'preferences.mentions', true);
    }

    private function notificationsMuted(int $recipientId, int $actorId): bool
    {
        return DB::table('user_mutes')
            ->where('user_id', $recipientId)
            ->where('muted_user_id', $actorId)
            ->exists();
    }

    private function userFromRequest(Request $request): ?object
    {
        $token = $request->bearerToken();

        return $request->user() ?: ($token ? PersonalAccessToken::findToken($token)?->tokenable : null);
    }
}
