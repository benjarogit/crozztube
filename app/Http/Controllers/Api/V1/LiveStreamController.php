<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LiveStreamResource;
use App\Models\Channel;
use App\Models\LiveStream;
use App\Models\LiveStreamCategory;
use App\Models\Video;
use App\Services\Api\V1\LiveCapabilityService;
use App\Services\Api\V1\LiveKitTokenService;
use App\Services\Api\V1\LiveStreamAccessService;
use App\Support\Api\V1\PaginationPayload;
use App\Support\Client\ClientContext;
use App\Support\LiveKitSettings;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LiveStreamController extends Controller
{
    public function options(LiveCapabilityService $capabilities): JsonResponse
    {
        return response()->json($capabilities->payload())->header('Cache-Control', 'private, max-age=60');
    }

    public function room(Request $request, LiveStream $liveStream, LiveStreamAccessService $access, \App\Http\Controllers\Api\LiveStreamController $web): JsonResponse
    {
        $access->assertViewer($liveStream, $request->user());
        $request->attributes->set('playhub.live.presentation', true);

        return $web->room($request, $liveStream)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, LiveStreamAccessService $access): JsonResponse
    {
        abort_unless(app(LiveCapabilityService::class)->payload()['enabled'], 403, 'Live streaming is disabled.');
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'live', 'upcoming', 'replay'])],
            'category' => ['nullable', 'string', 'max:140'],
            'q' => ['nullable', 'string', 'max:160'],
            'channel' => ['nullable', 'string', 'max:190'],
            'sort' => ['nullable', Rule::in(['recent', 'viewers', 'start'])],
            'date_range' => ['nullable', Rule::in(['any', 'today', 'tomorrow', 'week'])],
            'following' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $status = (string) ($data['status'] ?? 'all');
        $query = $access->directoryQuery($request->user())
            ->with(['channel.user', 'category', 'replayVideo.channel.user'])
            ->when(filled($data['category'] ?? null), fn ($query) => $query->whereHas('category', fn ($category) => $category->where('slug', $data['category'])))
            ->when(filled($data['channel'] ?? null), fn ($query) => $query->whereHas('channel', fn ($channel) => $channel->where('slug', $data['channel'])))
            ->when(filled($data['q'] ?? null), function ($query) use ($data): void {
                $term = '%'.addcslashes((string) $data['q'], '\\%_').'%';
                $query->where(fn ($search) => $search->where('title', 'like', $term)->orWhere('description', 'like', $term)->orWhere('tags', 'like', $term)
                    ->orWhereHas('channel', fn ($channel) => $channel->where('name', 'like', $term))->orWhereHas('category', fn ($category) => $category->where('name', 'like', $term)));
            });
        $now = now();

        if ($request->boolean('following')) {
            $query->whereIn('channel_id', DB::table('subscriptions')->where('user_id', $request->user()?->id ?? 0)->select('channel_id'));
        }
        $dateRange = $data['date_range'] ?? 'any';
        if ($dateRange === 'today' || $dateRange === 'tomorrow') {
            $day = $dateRange === 'today' ? $now->copy() : $now->copy()->addDay();
            $query->whereBetween('scheduled_at', [$day->startOfDay(), $day->copy()->endOfDay()]);
        } elseif ($dateRange === 'week') {
            $query->whereBetween('scheduled_at', [$now, $now->copy()->addDays(7)->endOfDay()]);
        }

        $query->where(function ($query) use ($status, $now): void {
            if ($status === 'live') {
                $query->where('status', 'live');
            } elseif ($status === 'upcoming') {
                $query->where('status', 'scheduled')->where('scheduled_at', '>=', $now);
            } elseif ($status === 'replay') {
                $query->where('status', 'ended')->whereNotNull('replay_video_id')
                    ->where(fn ($expiry) => $expiry->whereNull('replay_expires_at')->orWhere('replay_expires_at', '>', $now));
            } else {
                $query->where('status', 'live')
                    ->orWhere(fn ($upcoming) => $upcoming->where('status', 'scheduled')->where('scheduled_at', '>=', $now))
                    ->orWhere(fn ($replay) => $replay->where('status', 'ended')->whereNotNull('replay_video_id'));
            }
        });
        $sort = $data['sort'] ?? 'recent';
        $viewerCounts = null;
        if ($sort === 'viewers' && $status === 'replay') {
            $query->addSelect(['replay_views' => Video::query()->select('views_count')->whereColumn('videos.id', 'live_streams.replay_video_id')])
                ->orderByDesc('replay_views');
        } elseif ($sort === 'viewers') {
            // Use the same live room counts as the web directory before pagination.
            $viewerCounts = LiveKitSettings::activeRoomViewerCounts();
            $parts = [];
            $bindings = [];
            foreach ((clone $query)->where('status', 'live')->pluck('uuid') as $uuid) {
                $parts[] = 'WHEN uuid = ? THEN ?';
                $bindings[] = $uuid;
                $bindings[] = (int) ($viewerCounts['playhub-live-'.$uuid] ?? 0);
            }
            if ($parts) {
                $query->orderByRaw('CASE '.implode(' ', $parts).' ELSE 0 END DESC', $bindings);
            }
        }
        $query->orderByRaw("CASE WHEN status = 'live' THEN 0 WHEN status = 'scheduled' THEN 1 ELSE 2 END");
        if ($sort === 'start' || $status === 'upcoming') {
            $query->orderBy('scheduled_at');
        }
        $streams = $query->orderByRaw('COALESCE(ended_at, actual_started_at, created_at) DESC')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20));
        $this->hydrateViewerCounts($streams->getCollection(), $viewerCounts);

        $response = PaginationPayload::lengthAware(
            $streams,
            LiveStreamResource::collection($streams->getCollection())->resolve($request),
        );
        $payload = $response->getData(true);
        $payload['meta']['live'] = $this->directoryMetadata($request, $access, $viewerCounts);

        return $response->setData($payload);
    }

    private function directoryMetadata(Request $request, LiveStreamAccessService $access, ?array $counts): array
    {
        $public = $access->directoryQuery($request->user())->where(fn ($query) => $query->where('status', 'live')
            ->orWhere(fn ($upcoming) => $upcoming->where('status', 'scheduled')->where('scheduled_at', '>=', now()))
            ->orWhere('status', 'ended'));
        $items = $public->with('category')->get(['id', 'uuid', 'status', 'channel_id', 'category_id']);
        $counts ??= $items->contains('status', 'live') ? LiveKitSettings::activeRoomViewerCounts() : [];
        $categoryCounts = $items->countBy('category_id');
        $creators = Channel::query()->whereIn('id', $items->pluck('channel_id'))->with('user')->withCount('subscriptions')->get()
            ->map(function (Channel $channel) use ($items, $counts): array {
                $streams = $items->where('channel_id', $channel->id);
                $categoryId = $streams->countBy('category_id')->sortDesc()->keys()->first();
                $category = $streams->firstWhere('category_id', $categoryId)?->category;

                return [
                    'user_id' => (int) $channel->user_id, 'channel_id' => (int) $channel->id,
                    'slug' => $channel->slug, 'name' => $channel->name, 'avatar_url' => MediaStorage::url($channel->avatar_path),
                    'verified' => (bool) $channel->user?->verified_at, 'followers_count' => (int) $channel->subscriptions_count,
                    'viewer_count' => $streams->where('status', 'live')->sum(fn ($stream): int => (int) ($counts['playhub-live-'.$stream->uuid] ?? 0)),
                    'streams_count' => $streams->count(),
                    'category' => $category ? ['id' => (int) $category->id, 'name' => $category->name, 'slug' => $category->slug] : null,
                ];
            });
        $topics = fn (string $status): array => $items->where('status', $status)->whereNotNull('category_id')->groupBy('category_id')
            ->map(function ($streams): array {
                $category = $streams->first()->category;

                return ['id' => (int) $category->id, 'name' => $category->name, 'slug' => $category->slug, 'count' => $streams->count()];
            })->sort(fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcasecmp($a['name'], $b['name']))->take(5)->values()->all();

        return [
            'server_time' => now()->toIso8601String(),
            'categories' => LiveStreamCategory::query()->where('enabled', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug'])->map(fn ($category): array => [
                'id' => (int) $category->id, 'name' => $category->name, 'slug' => $category->slug, 'count' => (int) ($categoryCounts[$category->id] ?? 0),
            ])->all(),
            'stats' => [
                'live_channels' => $items->where('status', 'live')->count(), 'watching_now' => $creators->sum('viewer_count'),
                'upcoming_streams' => $items->where('status', 'scheduled')->count(),
                'replay_count' => $items->where('status', 'ended')->count(),
            ],
            'top_creators' => $creators->where('viewer_count', '>', 0)->sort(fn (array $a, array $b): int => $b['viewer_count'] <=> $a['viewer_count'] ?: $b['followers_count'] <=> $a['followers_count'])->take(5)->values()->all(),
            'popular_creators' => $creators->sort(fn (array $a, array $b): int => $b['followers_count'] <=> $a['followers_count'] ?: $b['streams_count'] <=> $a['streams_count'])->take(5)->values()->all(),
            'live_topics' => $topics('live'), 'replay_topics' => $topics('ended'),
        ];
    }

    public function show(Request $request, LiveStream $liveStream, LiveStreamAccessService $access): JsonResponse
    {
        $access->assertViewer($liveStream, $request->user());
        $liveStream->load(['channel.user', 'category', 'replayVideo.channel.user']);
        $this->hydrateViewerCounts(collect([$liveStream]));

        return response()->json((new LiveStreamResource($liveStream))->resolve($request));
    }

    public function viewerJoin(
        Request $request,
        LiveStream $liveStream,
        LiveStreamAccessService $access,
        LiveKitTokenService $tokens,
    ): JsonResponse {
        $access->assertViewer($liveStream, $request->user());
        abort_unless(in_array($liveStream->status, ['waiting-for-source', 'live'], true), 409, 'This live stream is not currently joinable.');
        $request->validate(['preview' => ['nullable', 'boolean']]);
        $preview = $request->boolean('preview');
        $liveStream->setAttribute('_live_preview', $preview);

        $role = $request->user() && DB::table('live_stream_moderators')
            ->where('live_stream_id', $liveStream->id)
            ->where('user_id', $request->user()->id)
            ->exists() ? 'moderator' : 'viewer';
        $publisherRole = $request->user() ? DB::table('live_stream_invites')
            ->where('live_stream_id', $liveStream->id)->where('user_id', $request->user()->id)
            ->whereIn('role', ['guest', 'cohost'])->value('role') : null;
        $canPublish = ! $preview && $publisherRole && (bool) data_get($liveStream->stream_settings, 'accept_participants', true)
            && ! ClientContext::fromRequest($request)->isTv();
        if ($canPublish) {
            abort_if($request->user()->currentAccessToken()?->cant('live:publish'), 403, 'This device session cannot publish live video.');
            abort_if($request->user()->isBannedFrom('live'), 403, $request->user()->banMessage('live'));
            $role = $publisherRole;
            DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $request->user()->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
        }
        $grant = $tokens->issue($liveStream, $request->user(), $role, (bool) $canPublish);
        if (! $preview) {
            $this->recordViewer($liveStream, $request, $role);
        }
        if (! $preview && $request->user()) {
            DB::table('live_stream_participant_requests')->where('live_stream_id', $liveStream->id)->where('user_id', $request->user()->id)->whereIn('status', ['pending', 'accepted'])->update(['identity' => $grant['identity'], 'updated_at' => now()]);
        }

        return response()->json($grant)
            ->header('Cache-Control', 'private, no-store');
    }

    public function creatorJoin(
        Request $request,
        LiveStream $liveStream,
        LiveStreamAccessService $access,
        LiveKitTokenService $tokens,
    ): JsonResponse {
        abort_if(ClientContext::fromRequest($request)->isTv(), 403, 'TV clients cannot request live publishing access.');
        abort_if($request->user()?->currentAccessToken()?->cant('live:publish'), 403, 'This device session cannot publish live video.');
        $access->assertPublisher($liveStream, $request->user());

        return response()->json($tokens->issue($liveStream, $request->user(), 'host', true))
            ->header('Cache-Control', 'private, no-store');
    }

    private function hydrateViewerCounts($streams, ?array $counts = null): void
    {
        $rooms = $streams->where('status', 'live')->pluck('uuid');
        if ($rooms->isEmpty()) {
            return;
        }
        $counts ??= LiveKitSettings::activeRoomViewerCounts();
        $streams->each(function (LiveStream $stream) use ($counts): void {
            $stream->setAttribute('viewer_count', $stream->status === 'live'
                ? (int) ($counts['playhub-live-'.$stream->uuid] ?? 0)
                : 0);
        });
    }

    private function recordViewer(LiveStream $stream, Request $request, string $role = 'viewer'): void
    {
        if (! $request->user()) {
            return;
        }

        DB::table('live_stream_viewers')->upsert([[
            'live_stream_id' => $stream->id,
            'user_id' => $request->user()->id,
            'role' => $role,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['live_stream_id', 'user_id'], ['role', 'last_seen_at', 'updated_at']);
    }
}
