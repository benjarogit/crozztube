<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Jobs\FinalizeLiveStreamReplay;
use App\Jobs\GenerateLiveStreamThumbnail;
use App\Models\Gift;
use App\Models\LiveStream;
use App\Models\LiveStreamAdImpression;
use App\Models\LiveStreamCategory;
use App\Models\LiveStreamChatMessage;
use App\Models\LiveStreamContribution;
use App\Models\LiveStreamPoll;
use App\Models\Sticker;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Api\V1\LiveCapabilityService;
use App\Services\Api\V1\LiveKitTokenService;
use App\Services\LiveKitAdmissionService;
use App\Support\BrandedEmail;
use App\Support\Client\ClientContext;
use App\Support\Diagnostics\LiveProbeTiming;
use App\Support\EmailTemplates;
use App\Support\IntegrationSettings;
use App\Support\KlipyStickers;
use App\Support\KlipyStickerSettings;
use App\Support\LiveKitSettings;
use App\Support\LiveStreamingSettings;
use App\Support\LiveStreamMedia;
use App\Support\MediaStorage;
use App\Support\PlatformFeatureSettings;
use App\Support\PlayerAdSettings;
use App\Support\PublishingAccess;
use App\Support\UserBlocks;
use App\Support\WalletLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LiveStreamController extends Controller
{
    private const LIVE_AD_COMPLETION_WINDOW_MINUTES = 15;

    private const LIVE_AD_FALLBACK_DURATION_SECONDS = 5;

    public function gifts(): JsonResponse
    {
        $this->ensureAvailable();
        abort_unless(LiveStreamingSettings::settings()['features']['gifts'], 403, 'Live gifts are disabled.');

        return response()->json(['gifts' => $this->giftCatalogPayload()]);
    }

    public function superStickers(Request $request): JsonResponse
    {
        $this->ensureAvailable();
        abort_unless(LiveStreamingSettings::settings()['features']['super_stickers'], 403, 'Live stickers are disabled.');

        return response()->json($this->superStickerCatalogPayload(
            $request,
            max(1, $request->integer('page', 1)),
            min(50, max(1, $request->integer('per_page', 24))),
            trim((string) $request->query('category', '')) ?: null
        ));
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAvailable();
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'live', 'upcoming', 'replay'])],
            'category' => ['nullable', 'string', 'max:190'],
            'q' => ['nullable', 'string', 'max:160'],
            'channel' => ['nullable', 'string', 'max:190'],
            'following' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = auth('sanctum')->user();
        $now = now();
        $status = (string) ($data['status'] ?? 'all');
        $category = trim((string) ($data['category'] ?? ''));
        $search = trim((string) ($data['q'] ?? ''));
        $channel = trim((string) ($data['channel'] ?? ''));
        $limit = (int) ($data['limit'] ?? 100);
        $subscribedChannelIds = $user
            ? DB::table('subscriptions')->where('user_id', $user->id)->pluck('channel_id')->flip()
            : collect();
        $invitedStreamIds = $user
            ? DB::table('live_stream_invites')->where('user_id', $user->id)->pluck('live_stream_id')->flip()
            : collect();

        $baseQuery = LiveStream::query()
            ->where('visibility', 'public')
            ->where(fn ($streams) => $streams
                ->whereHas('user', fn ($owner) => $owner->publicProfileEnabled())
                ->when($user, fn ($streams) => $streams->orWhere('user_id', $user->id)))
            ->when($channel !== '', fn ($query) => $query->whereHas('channel', fn ($query) => $query->where('slug', $channel)))
            ->where(fn ($query) => $query->whereNull('category_id')->orWhereHas('category', fn ($query) => $query->where('enabled', true)))
            ->where(function ($query) use ($user, $subscribedChannelIds, $invitedStreamIds): void {
                $query->whereNull('stream_settings')
                    ->orWhereNull('stream_settings->audience_mode')
                    ->orWhere('stream_settings->audience_mode', 'everyone');
                if (! $user) {
                    return;
                }

                $query->orWhere('stream_settings->audience_mode', 'signed_in');
                if ($subscribedChannelIds->isNotEmpty()) {
                    $query->orWhere(function ($query) use ($subscribedChannelIds): void {
                        $query->where('stream_settings->audience_mode', 'subscribers')
                            ->whereIn('channel_id', $subscribedChannelIds->keys());
                    });
                }
                if ($invitedStreamIds->isNotEmpty()) {
                    $query->orWhere(function ($query) use ($invitedStreamIds): void {
                        $query->where('stream_settings->audience_mode', 'invited')
                            ->whereIn('id', $invitedStreamIds->keys());
                    });
                }
            });
        $upcomingScope = fn ($query) => $query->where('status', 'scheduled')->whereNotNull('scheduled_at')->where('scheduled_at', '>=', $now);
        $replayScope = fn ($query) => $query->where('status', 'ended')
            ->whereNotNull('replay_video_id')
            ->where(fn ($query) => $query->whereNull('replay_expires_at')->orWhere('replay_expires_at', '>', $now))
            ->whereHas('replayVideo', fn ($query) => $query->where('status', 'ready')->where('visibility', 'public'));
        $discoverableScope = fn ($query) => $query->where('status', 'live')->orWhere($upcomingScope)->orWhere($replayScope);

        $liveUuids = (clone $baseQuery)->where('status', 'live')->pluck('uuid');
        $viewerCounts = $liveUuids->isNotEmpty() ? LiveKitSettings::activeRoomViewerCounts() : [];
        $upcomingCount = (clone $baseQuery)->where($upcomingScope)->count();
        $replayCount = (clone $baseQuery)->where($replayScope)->count();
        $discoverableQuery = (clone $baseQuery)->where($discoverableScope);
        $categoryCounts = (clone $discoverableQuery)
            ->select('category_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id');
        $categories = LiveStreamCategory::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (LiveStreamCategory $category): array => [
                'id' => (int) $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'count' => (int) ($categoryCounts[$category->id] ?? 0),
            ])
            ->values();

        $streamsQuery = (clone $baseQuery)->with(['category', 'channel.user', 'replayVideo']);
        if ($status === 'live') {
            $streamsQuery->where('status', 'live');
        } elseif ($status === 'upcoming') {
            $streamsQuery->where($upcomingScope);
        } elseif ($status === 'replay') {
            $streamsQuery->where($replayScope);
        } else {
            $streamsQuery->where($discoverableScope);
        }
        if ($category !== '' && $category !== 'all') {
            $streamsQuery->whereHas('category', fn ($query) => $query->where('slug', $category));
        }
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $streamsQuery->where(function ($query) use ($like): void {
                $query->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('tags', 'like', $like)
                    ->orWhereHas('channel', fn ($query) => $query->where('name', 'like', $like))
                    ->orWhereHas('category', fn ($query) => $query->where('name', 'like', $like));
            });
        }
        if ($request->boolean('following')) {
            $subscribedChannelIds->isEmpty()
                ? $streamsQuery->whereRaw('1 = 0')
                : $streamsQuery->whereIn('channel_id', $subscribedChannelIds->keys());
        }

        $streams = $streamsQuery
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 WHEN status = 'scheduled' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN status = 'scheduled' THEN scheduled_at END ASC")
            ->orderByRaw("CASE WHEN status = 'live' THEN actual_started_at END DESC")
            ->orderByRaw("CASE WHEN status = 'ended' THEN ended_at END DESC")
            ->limit($limit)
            ->get()
            ->map(fn (LiveStream $stream): array => $this->directoryPayload($stream, $viewerCounts, $subscribedChannelIds))
            ->values();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'streams' => $streams,
            'categories' => $categories,
            'stats' => [
                'live_channels' => $liveUuids->count(),
                'watching_now' => $liveUuids->sum(fn ($uuid): int => (int) ($viewerCounts['playhub-live-'.$uuid] ?? 0)),
                'upcoming_streams' => $upcomingCount,
                'replay_count' => $replayCount,
            ],
        ]);
    }

    public function studio(Request $request): JsonResponse
    {
        $this->ensureAvailable();
        PublishingAccess::authorizeLive($request->user());
        $user = $request->user()->loadMissing('channel');
        abort_unless($user->channel, 422, 'Create a channel before configuring a live stream.');
        $stream = LiveStream::query()
            ->with(['category', 'polls.options'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'scheduled', 'waiting-for-source', 'live'])
            ->latest('updated_at')
            ->first();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'settings' => LiveStreamingSettings::studioSettings(),
            'categories' => LiveStreamCategory::query()->where('enabled', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug']),
            'stream' => $stream ? $this->streamPayload($stream) : null,
            'moderators' => $stream ? User::query()->with('channel')
                ->whereIn('id', DB::table('live_stream_moderators')->where('live_stream_id', $stream->id)->select('user_id'))
                ->orderBy('name')->get()->map(fn (User $moderator): array => $this->moderatorPayload($moderator))->all() : [],
        ]);
    }

    public function room(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->ensureAvailable();
        abort_unless(in_array($liveStream->status, ['waiting-for-source', 'live', 'scheduled', 'ended'], true), 404);
        $user = auth('sanctum')->user();
        $isOwner = $user && $liveStream->user_id === $user->id;
        $liveStream->loadMissing('user');
        abort_unless($isOwner || $liveStream->user?->hasPublicProfileEnabled(), 404);
        $isModerator = $user && $this->isModerator($user, $liveStream);
        $isPreview = $request->boolean('preview');
        $streamSettings = array_replace_recursive($this->defaultStreamSettings(), (array) $liveStream->stream_settings);
        $participantRole = $user ? DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->value('role') : null;
        $canPublish = ! $isPreview && $user && ! $user->isBannedFrom('live') && ! ClientContext::fromRequest($request)->isTv()
            && $user->tokenCan('live:publish') && ($streamSettings['accept_participants'] ?? true)
            && in_array($liveStream->status, ['waiting-for-source', 'live'], true) && in_array($participantRole, ['guest', 'cohost'], true);
        $this->authorizeViewer($user, $liveStream);
        $presentation = (bool) $request->attributes->get('playhub.live.presentation', false);
        if ($canPublish && ! $presentation) {
            DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
        }
        if ($user && ! $isPreview && ! $presentation && in_array($liveStream->status, ['waiting-for-source', 'live'], true)) {
            $this->recordLiveStreamViewer($liveStream, $user->id, $isOwner ? 'host' : ($canPublish ? (string) $participantRole : ($isModerator ? 'moderator' : 'viewer')));
        }

        $participantRequest = $user && ! $isOwner
            ? DB::table('live_stream_participant_requests')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->first()
            : null;
        $connection = ! $presentation && ($isPreview || ! $isOwner) && in_array($liveStream->status, ['waiting-for-source', 'live'], true)
            ? $this->liveKitConnection($liveStream, $user, (bool) $canPublish, $isPreview ? 'preview' : ($canPublish ? $participantRole : ($isModerator ? 'moderator' : 'viewer')))
            : null;
        if ($participantRequest && $connection && in_array($participantRequest->status, ['pending', 'accepted'], true)) {
            DB::table('live_stream_participant_requests')->where('id', $participantRequest->id)->update(['identity' => $connection['identity'], 'updated_at' => now()]);
            $participantRequest->identity = $connection['identity'];
        }

        $streamPayload = $this->streamPayload($liveStream, $user?->id);
        $streamPayload['creator']['user_id'] = $liveStream->user_id;
        $streamPayload['creator']['is_subscribed'] = $user
            ? DB::table('subscriptions')->where('user_id', $user->id)->where('channel_id', $liveStream->channel_id)->exists()
            : false;

        $response = [
            'stream' => $streamPayload,
            'access' => [
                'is_owner' => (bool) $isOwner, 'is_moderator' => (bool) $isModerator,
                'can_manage' => (bool) $isOwner, 'can_moderate' => (bool) ($isOwner || $isModerator),
                'can_publish' => (bool) $canPublish && ! ClientContext::fromRequest($request)->isTv() && ! $user?->isBannedFrom('live') && $user?->tokenCan('live:publish'),
                'participant_role' => $canPublish ? $participantRole : null,
                'can_request_participation' => $user && $user->tokenCan('live:publish') && ! $user->isBannedFrom('live') && ! ClientContext::fromRequest($request)->isTv() && ! $isOwner && $liveStream->status === 'live' && (bool) ($streamSettings['accept_participants'] ?? true),
            ],
            'connection' => $connection,
            'participant_request' => $participantRequest ? $this->participantRequestPayload($participantRequest) : null,
            'moderation' => $isOwner || $isModerator ? [
                'participant_requests' => $this->pendingParticipantRequestsPayload($liveStream),
                'restrictions' => $this->activeRestrictions($liveStream),
            ] : null,
            'capabilities' => app(LiveCapabilityService::class)->payload($liveStream),
            'participants' => $this->publicParticipants($liveStream),
            'contributions' => $liveStream->contributions()->with(['sender.channel', 'gift', 'sticker', 'superSticker'])->where('status', 'completed')
                ->where(fn ($query) => UserBlocks::filter($query, 'sender_id', $user?->id))
                ->latest()->limit(40)->get()->reverse()->values()->map(fn ($contribution): array => $this->contributionActivityPayload($contribution))->all(),
        ];
        if ($presentation || ! $isOwner) {
            unset($response['stream']['preflight'], $response['stream']['preflight_tested_at'], $response['stream']['stream_settings']['invited_usernames'], $response['stream']['stream_settings']['blocked_terms']);
        }
        if (! $request->has('catalog') || $request->boolean('catalog')) {
            $response['gifts'] = LiveStreamingSettings::settings()['features']['gifts'] ? $this->giftCatalogPayload() : [];
            $stickerCatalog = LiveStreamingSettings::settings()['features']['super_stickers'] ? $this->superStickerCatalogPayload($request) : ['stickers' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'has_more' => false], 'categories' => []];
            $response['super_stickers'] = $stickerCatalog['stickers'];
            $response['super_stickers_meta'] = $stickerCatalog['meta'];
            $response['super_sticker_categories'] = $stickerCatalog['categories'];
        }

        return response()->json($response)->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        return Cache::lock('playhub:live:create:'.$request->user()->id, 30)->get(fn () => $this->storeLocked($request))
            ?: abort(409, 'A live draft is already being prepared.');
    }

    private function storeLocked(Request $request): JsonResponse
    {
        $this->ensureAvailable();
        PublishingAccess::authorizeLive($request->user());
        $user = $request->user()->loadMissing('channel');
        abort_unless($user->channel, 422, 'Create a channel before configuring a live stream.');
        $stream = LiveStream::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'scheduled', 'waiting-for-source', 'live'])
            ->latest('updated_at')
            ->first();

        $created = false;
        if (! $stream) {
            $created = true;
            $uuid = (string) Str::uuid();
            $stream = LiveStream::query()->create([
                'uuid' => $uuid,
                'user_id' => $user->id,
                'channel_id' => $user->channel->id,
                'category_id' => null,
                'slug' => 'live-'.$uuid,
                'visibility' => 'public',
                'status' => 'draft',
                'source_type' => 'webcam',
                'appearance' => $this->defaultAppearance(),
                'stream_settings' => $this->defaultStreamSettings(),
                'monetization_settings' => $this->defaultMonetizationSettings(),
                'engagement_settings' => $this->defaultEngagementSettings(),
            ]);
        }

        return $this->update($request, $stream, $created);
    }

    public function update(Request $request, LiveStream $liveStream, bool $created = false): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        PublishingAccess::authorizeLive($request->user());
        abort_unless(in_array($liveStream->status, ['draft', 'scheduled', 'waiting-for-source', 'live'], true), 409, 'This live stream can no longer be edited.');
        $isActive = in_array($liveStream->status, ['waiting-for-source', 'live'], true);
        $settings = LiveStreamingSettings::settings();
        $data = $request->validate($this->streamRules($settings));
        abort_if($isActive && array_key_exists('description', $data) && trim((string) $data['description']) === '', 422, 'Live stream description is required.');
        if ($isActive) {
            unset($data['source_type'], $data['scheduled_at']);
        }
        abort_if(! $settings['features']['scheduling'] && filled($data['scheduled_at'] ?? null), 403, 'Live stream scheduling is disabled.');
        $scheduledAt = array_key_exists('scheduled_at', $data)
            ? $this->scheduledAt($data['scheduled_at'], (int) $settings['limits']['schedule_max_days'])
            : $liveStream->scheduled_at;
        $nextSourceType = $data['source_type'] ?? $liveStream->source_type;
        if ($liveStream->source_type === 'rtmp' && $nextSourceType !== 'rtmp' && $liveStream->livekit_ingress_id) {
            try {
                LiveKitSettings::deleteIngress($liveStream->livekit_ingress_id);
            } catch (\RuntimeException $exception) {
                abort(502, $exception->getMessage());
            }
            $liveStream->livekit_ingress_id = null;
        }
        $currentStream = array_replace_recursive($this->defaultStreamSettings(), (array) $liveStream->stream_settings);
        $currentMoney = array_replace_recursive($this->defaultMonetizationSettings(), (array) $liveStream->monetization_settings);
        $currentAppearance = array_replace_recursive($this->defaultAppearance(), (array) $liveStream->appearance);
        $currentEngagement = array_replace_recursive($this->defaultEngagementSettings(), (array) $liveStream->engagement_settings);
        $incomingStreamValues = (array) ($data['stream_settings'] ?? []);
        unset($incomingStreamValues['_recording'], $incomingStreamValues['_lifecycle'], $incomingStreamValues[LiveKitAdmissionService::VERSION_SETTINGS]);
        $streamValues = array_replace_recursive($currentStream, $incomingStreamValues);
        $moneyValues = array_replace_recursive($currentMoney, (array) ($data['monetization_settings'] ?? []));
        $appearanceValues = array_replace_recursive($currentAppearance, (array) ($data['appearance'] ?? []));
        $engagementValues = array_replace_recursive($currentEngagement, (array) ($data['engagement_settings'] ?? []));
        if (! $settings['features']['appearance']) {
            $appearanceValues = $currentAppearance;
        }
        if (! $settings['features']['dvr']) {
            $streamValues['enable_dvr'] = false;
        }
        if (! $settings['features']['chat']) {
            $streamValues['enable_chat'] = false;
        }
        if (! $settings['features']['replays']) {
            $streamValues['save_replay'] = 'off';
        }
        foreach (['ads', 'super_chat', 'super_stickers', 'gifts', 'tips', 'goal_bar'] as $feature) {
            if (! $settings['features'][$feature]) {
                $moneyValues[$feature] = false;
            }
        }
        if (! $settings['features']['pinned_messages']) {
            $engagementValues['pinned_message'] = '';
        }
        if (! $settings['features']['alerts']) {
            $engagementValues['alerts'] = array_fill_keys(['followers', 'subscriptions', 'contributions', 'goals', 'sound'], false) + ['duration_seconds' => 6];
        }
        if (! $settings['features']['stream_labels']) {
            $engagementValues['labels'] = [];
        }
        $this->validateSelectedOptions($streamValues, $settings);
        $this->validateMonetization($moneyValues, $settings);
        $this->validateEngagement($engagementValues, $settings);
        unset($streamValues['captions'], $streamValues['captions_language'], $moneyValues['memberships']);

        $liveStream->fill([
            'title' => array_key_exists('title', $data) ? trim((string) $data['title']) ?: null : $liveStream->title,
            'description' => array_key_exists('description', $data) ? trim((string) $data['description']) ?: null : $liveStream->description,
            'tags' => array_key_exists('tags', $data) ? $this->normalizeStreamTags($data['tags']) : $liveStream->tags,
            'category_id' => array_key_exists('category_id', $data) ? $data['category_id'] : $liveStream->category_id,
            'visibility' => $data['visibility'] ?? $liveStream->visibility,
            'source_type' => $data['source_type'] ?? $liveStream->source_type,
            'scheduled_at' => $scheduledAt,
            'status' => $isActive ? $liveStream->status : ($scheduledAt?->isFuture() ? 'scheduled' : 'draft'),
            'appearance' => $appearanceValues,
            'stream_settings' => $streamValues,
            'monetization_settings' => $moneyValues,
            'engagement_settings' => $engagementValues,
        ])->save();
        if ($isActive) {
            $this->sendParticipantEvent($liveStream, ['type' => 'stream_updated'], []);
        }
        $invitedUsernames = collect($streamValues['invited_usernames'] ?? [])->map(fn ($value): string => ltrim(trim((string) $value), '@'))->filter()->unique()->take(200)->values();
        $invitedUserIds = User::query()->whereIn('username', $invitedUsernames)->pluck('id');
        $viewerInvites = DB::table('live_stream_invites')
            ->where('live_stream_id', $liveStream->id)
            ->where('role', 'viewer');
        $invitedUserIds->isEmpty()
            ? $viewerInvites->delete()
            : $viewerInvites->whereNotIn('user_id', $invitedUserIds)->delete();
        if ($invitedUserIds->isNotEmpty()) {
            $existingInviteUserIds = DB::table('live_stream_invites')
                ->where('live_stream_id', $liveStream->id)
                ->whereIn('user_id', $invitedUserIds)
                ->pluck('user_id');
            $newViewerIds = $invitedUserIds->diff($existingInviteUserIds);
            if ($newViewerIds->isNotEmpty()) {
                DB::table('live_stream_invites')->insert($newViewerIds->map(fn ($userId): array => [
                    'live_stream_id' => $liveStream->id,
                    'user_id' => $userId,
                    'role' => 'viewer',
                    'invited_by' => $liveStream->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            }
        }

        return response()->json([
            'message' => $created ? 'Live stream draft created.' : ($isActive ? 'Live stream settings saved.' : 'Live stream draft saved.'),
            'server_time' => now()->toIso8601String(),
            'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options'])),
        ], $created ? 201 : 200);
    }

    public function schedule(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        PublishingAccess::authorizeLive($request->user());
        $settings = LiveStreamingSettings::settings();
        abort_unless($settings['features']['scheduling'], 403, 'Live stream scheduling is disabled.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'scheduled_at' => ['required', 'date'],
        ]);
        $this->validateScheduleConfiguration($liveStream, $settings);
        $scheduledAt = $this->scheduledAt($data['scheduled_at'], (int) $settings['limits']['schedule_max_days'], true);
        $liveStream->update(['title' => trim($data['title']), 'scheduled_at' => $scheduledAt, 'status' => 'scheduled']);

        return response()->json([
            'message' => 'Live stream scheduled.',
            'server_time' => now()->toIso8601String(),
            'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options'])),
        ]);
    }

    public function thumbnailStatus(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);

        return response()->json([
            'thumbnail_url' => LiveStreamMedia::thumbnailUrl($liveStream),
            'server_generation_available' => LiveKitSettings::egressAvailable(),
        ]);
    }

    public function thumbnail(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        PublishingAccess::authorizeLive($request->user());
        abort_unless(LiveStreamingSettings::settings()['features']['thumbnails'], 403, 'Live stream thumbnails are disabled.');
        $maxKb = (int) LiveStreamingSettings::settings()['limits']['thumbnail_max_mb'] * 1024;
        $data = $request->validate([
            'thumbnail' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
            'only_if_missing' => ['sometimes', 'boolean'],
        ]);
        if (($data['only_if_missing'] ?? false) && $liveStream->thumbnail_path) {
            return response()->json(['message' => 'Thumbnail already available.', 'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options']))]);
        }
        $disk = MediaStorage::diskName();
        $thumbnailDirectory = 'live-streams/thumbnails/'.now()->format('Y/m');
        if (MediaStorage::isLocal()) {
            MediaStorage::ensureLocalDirectory($thumbnailDirectory);
        }
        $path = $data['thumbnail']->store($thumbnailDirectory, $disk);
        abort_unless($path && Storage::disk($disk)->exists($path), 500, 'Unable to store the live stream thumbnail.');
        $oldPath = $liveStream->thumbnail_path;
        if ($data['only_if_missing'] ?? false) {
            $updated = LiveStream::query()->whereKey($liveStream->id)->whereNull('thumbnail_path')->update(['thumbnail_path' => $path]);
            if (! $updated) {
                MediaStorage::delete($path);

                return response()->json(['message' => 'Thumbnail already available.', 'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options']))]);
            }
            $liveStream->refresh();
        } else {
            $liveStream->update(['thumbnail_path' => $path]);
        }
        if ($oldPath && $oldPath !== $path) {
            MediaStorage::delete($oldPath);
        }

        return response()->json(['message' => 'Thumbnail uploaded.', 'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options']))]);
    }

    public function connectionProbe(Request $request, LiveStream $liveStream): JsonResponse
    {
        return LiveProbeTiming::measure($request, 'controller', function () use ($request, $liveStream): JsonResponse {
            LiveProbeTiming::measure($request, 'owner_access', fn () => $this->authorizeOwner($request, $liveStream));
            LiveProbeTiming::measure($request, 'publishing_access', fn () => PublishingAccess::authorizeLive($request->user()));
            $data = LiveProbeTiming::measure($request, 'file_validation', function () use ($request): array {
                $maxKb = (int) LiveStreamingSettings::settings()['limits']['connection_probe_kb'];

                return $request->validate(['probe' => ['required', 'file', 'max:'.$maxKb]]);
            });

            return LiveProbeTiming::measure($request, 'initial_json', fn () => response()->json([
                'bytes_received' => (int) $data['probe']->getSize(),
                'server_time' => now()->toIso8601String(),
            ]));
        });
    }

    public function rtmpIngress(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        PublishingAccess::authorizeLive($request->user());
        abort_unless(in_array($liveStream->status, ['draft', 'scheduled', 'waiting-for-source', 'live'], true), 409, 'This live stream can no longer use RTMP credentials.');
        abort_unless($liveStream->source_type === 'rtmp', 422, 'Select RTMP as the stream source first.');
        abort_unless(LiveKitSettings::ingressAvailable(), 503, 'The RTMP ingress service is not running.');

        try {
            $ingress = $this->ensureRtmpIngress($liveStream);
        } catch (\RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }

        $preview = clone $liveStream;
        $preview->setAttribute('_live_preview', true);

        return response()->json([
            'message' => 'RTMP credentials are ready.',
            'server_time' => now()->toIso8601String(),
            'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options'])),
            'ingress' => $this->rtmpIngressPayload($ingress),
            'connection' => $this->liveKitConnection($liveStream, $request->user(), false, 'viewer'),
            'preview_connection' => app(LiveKitTokenService::class)->issue($preview, $request->user(), 'viewer', false),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function preflight(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        PublishingAccess::authorizeLive($request->user());
        $data = $request->validate([
            'camera_permission' => ['required', Rule::in(['granted', 'denied', 'prompt', 'unavailable'])],
            'microphone_permission' => ['required', Rule::in(['granted', 'denied', 'prompt', 'unavailable'])],
            'camera_label' => ['nullable', 'string', 'max:160'],
            'microphone_label' => ['nullable', 'string', 'max:160'],
            'width' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'height' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'frame_rate' => ['nullable', 'numeric', 'min:1', 'max:240'],
            'upload_mbps' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'round_trip_ms' => ['nullable', 'numeric', 'min:0', 'max:60000'],
        ]);
        $settings = LiveStreamingSettings::settings();
        $limits = $settings['limits'];
        $streamValues = $this->effectiveStreamSettings($liveStream);
        $goodMbps = (float) $limits['connection_good_mbps'];
        $goodLatencyMs = (float) $limits['connection_good_latency_ms'];
        $uploadMbps = (float) ($data['upload_mbps'] ?? 0);
        $roundTripMs = (float) ($data['round_trip_ms'] ?? 0);
        $autoQuality = ($streamValues['quality'] ?? 'auto') === 'auto';
        $requiredMbps = $autoQuality ? 0.1 : max(0.1, (((int) $streamValues['target_bitrate_kbps'] + (int) $streamValues['audio_bitrate_kbps']) / 1000) * 1.25);
        [$targetWidth, $targetHeight] = $this->streamVideoDimensions((string) $streamValues['quality']);
        $actualWidth = (int) ($data['width'] ?? 0);
        $actualHeight = (int) ($data['height'] ?? 0);
        $actualFrameRate = (float) ($data['frame_rate'] ?? 0);
        $targetFrameRate = (int) $streamValues['frame_rate'];
        $permissionsReady = $data['camera_permission'] === 'granted' && $data['microphone_permission'] === 'granted';
        $metricsReady = $uploadMbps > 0 && $roundTripMs > 0;
        $resolutionReady = $targetWidth === null || ($actualWidth >= (int) floor($targetWidth * .85) && $actualHeight >= (int) floor($targetHeight * .85));
        $frameRateReady = $actualFrameRate >= max(1, $targetFrameRate - 1);
        $connectionReady = $uploadMbps >= $requiredMbps && $roundTripMs <= $goodLatencyMs;
        $ready = $permissionsReady && $metricsReady && $resolutionReady && $frameRateReady && $connectionReady;
        $quality = ! $permissionsReady || ! $metricsReady ? 'needs_attention' : ($ready && ($autoQuality || $uploadMbps >= max($goodMbps, $requiredMbps * 1.2)) ? 'good' : 'limited');
        $preflight = array_merge($data, [
            'ready' => $ready,
            'quality' => $quality,
            'required_upload_mbps' => round($requiredMbps, 2),
            'resolution_ready' => $resolutionReady,
            'frame_rate_ready' => $frameRateReady,
            'connection_ready' => $connectionReady,
            'settings_fingerprint' => $this->preflightSettingsFingerprint($liveStream, $streamValues),
        ]);
        $liveStream->update(['preflight' => $preflight, 'preflight_tested_at' => now()]);

        return response()->json([
            'message' => $ready ? 'Pre-flight test completed.' : 'Pre-flight test needs attention.',
            'preflight' => $preflight,
            'tested_at' => $liveStream->preflight_tested_at?->toIso8601String(),
        ]);
    }

    public function start(Request $request, LiveStream $liveStream): JsonResponse
    {
        return Cache::lock('playhub:live:lifecycle:'.$liveStream->id, 60)->get(function () use ($request, $liveStream): JsonResponse {
            return $this->startLocked($request, $liveStream->fresh());
        }) ?: abort(409, 'A live lifecycle operation is already in progress.');
    }

    private function startLocked(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        PublishingAccess::authorizeLive($request->user());
        abort_unless(in_array($liveStream->status, ['draft', 'scheduled'], true), 409, 'This live stream has already been started.');
        $settings = LiveStreamingSettings::settings();
        $this->validateStartConfiguration($liveStream, $settings);
        abort_unless(LiveKitSettings::configured(), 503, 'Streaming credentials are unavailable.');
        abort_unless(LiveKitSettings::isRunning(), 503, 'The streaming service is not running.');
        if ($liveStream->source_type === 'rtmp') {
            abort_unless(LiveKitSettings::ingressAvailable(), 503, 'The streaming service is not running.');
        }
        $streamValues = $this->effectiveStreamSettings($liveStream);
        abort_if($settings['schedule']['strict_start'] && $liveStream->scheduled_at && $liveStream->scheduled_at->isFuture(), 409, 'This live stream cannot start before its scheduled time.');
        abort_if(! trim((string) $liveStream->title), 422, 'Add a stream title before starting.');
        $connection = $liveStream->source_type === 'webcam' ? $this->liveKitConnection($liveStream, $request->user(), true, 'host') : null;
        $ingress = null;
        try {
            if ($liveStream->source_type === 'rtmp') {
                $ingress = $this->ensureRtmpIngress($liveStream);
            } else {
                LiveKitSettings::recreateRoom($connection['room'], [
                    'live_stream_id' => $liveStream->id,
                    'live_stream_uuid' => $liveStream->uuid,
                    'channel_id' => $liveStream->channel_id,
                ], $streamValues);
            }
        } catch (\RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
        if ($liveStream->source_type === 'rtmp') {
            abort_unless($ingress && LiveKitSettings::ingressIsPublishing($ingress), 409, 'Start OBS and wait until the RTMP preview is playing before going live.');
            try {
                $this->startReplayRecording($liveStream, $streamValues);
            } catch (\RuntimeException $exception) {
                abort(502, $exception->getMessage());
            }
        }
        $startedStatus = $ingress && LiveKitSettings::ingressIsPublishing($ingress) ? 'live' : 'waiting-for-source';
        $lifecycleSettings = (array) $liveStream->stream_settings;
        $lifecycleSettings['_lifecycle'] = ['prepared_at' => now()->toIso8601String()];
        $liveStream->update([
            'status' => $startedStatus,
            'actual_started_at' => $startedStatus === 'live' ? now() : null,
            'livekit_ingress_id' => $ingress['ingress_id'] ?? $ingress['ingressId'] ?? null,
            'stream_settings' => $lifecycleSettings,
        ]);
        if ($startedStatus === 'live' && ! $liveStream->thumbnail_path && $settings['features']['thumbnails']) {
            GenerateLiveStreamThumbnail::dispatch($liveStream->id)->onQueue('videos')->afterCommit();
        }

        return response()->json([
            'message' => 'Connecting to live stream...',
            'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options'])),
            'connection' => $liveStream->source_type === 'webcam' ? $connection : null,
            'ingress' => $ingress ? $this->rtmpIngressPayload($ingress) : null,
        ]);
    }

    public function activate(Request $request, LiveStream $liveStream): JsonResponse
    {
        return Cache::lock('playhub:live:lifecycle:'.$liveStream->id, 60)->get(function () use ($request, $liveStream): JsonResponse {
            return $this->activateLocked($request, $liveStream->fresh());
        }) ?: abort(409, 'A live lifecycle operation is already in progress.');
    }

    private function activateLocked(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        PublishingAccess::authorizeLive($request->user());
        abort_unless(in_array($liveStream->status, ['waiting-for-source', 'live'], true), 409, 'Start the live stream before activating its media source.');
        abort_unless($liveStream->source_type === 'webcam', 422, 'Browser WebRTC currently requires the webcam source.');
        $room = $this->liveKitRoomName($liveStream);
        try {
            $publishing = LiveKitSettings::participantIsPublishing($room, 'creator-'.$liveStream->user_id);
        } catch (\RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
        abort_unless($publishing, 409, 'Publish a working camera and microphone before activating the live stream.');
        try {
            $this->startReplayRecording($liveStream, $this->effectiveStreamSettings($liveStream));
        } catch (\RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
        if ($liveStream->status !== 'live') {
            $liveStream->update(['status' => 'live', 'actual_started_at' => now()]);
        }
        if (! $liveStream->thumbnail_path && LiveStreamingSettings::settings()['features']['thumbnails']) {
            // Thumbnail capture waits for Egress output. Queue it for the videos
            // worker rather than running it synchronously on HTTP termination.
            GenerateLiveStreamThumbnail::dispatch($liveStream->id)->onQueue('videos')->afterCommit();
        }

        return response()->json(['message' => 'Live stream started.', 'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options']))]);
    }

    public function end(Request $request, LiveStream $liveStream): JsonResponse
    {
        return Cache::lock('playhub:live:lifecycle:'.$liveStream->id, 60)->get(function () use ($request, $liveStream): JsonResponse {
            return DB::transaction(fn () => $this->endLocked($request, LiveStream::query()->lockForUpdate()->findOrFail($liveStream->id)));
        }) ?: abort(409, 'A live lifecycle operation is already in progress.');
    }

    private function endLocked(Request $request, LiveStream $liveStream): JsonResponse
    {
        // Disabling Live must not prevent the owner or reconciliation from releasing an existing room.
        $this->authorizeOwner($request, $liveStream, true);
        abort_unless(in_array($liveStream->status, ['waiting-for-source', 'live'], true), 409, 'This live stream is not active.');
        if ($request->attributes->get('playhub.live.reconcile') && ! $request->attributes->get('playhub.live.disabled')) {
            abort_if($this->liveSourcePresent($liveStream), 409, 'The stream source reconnected.');
        }
        $retention = (string) data_get($liveStream->stream_settings, 'save_replay', 'off');
        $finalizeReplay = $this->stopReplayRecording($liveStream) && $retention !== 'off';
        $cleanupIngressId = null;
        if ($liveStream->livekit_ingress_id) {
            try {
                LiveKitSettings::deleteIngress($liveStream->livekit_ingress_id);
            } catch (\RuntimeException $exception) {
                report($exception);
                $cleanupIngressId = $liveStream->livekit_ingress_id;
            }
        }
        $replayExpiresAt = match ($retention) {
            '24_hours' => now()->addDay(),
            '7_days' => now()->addDays(7),
            '30_days' => now()->addDays(30),
            '90_days' => now()->addDays(90),
            'forever' => null,
            default => now(),
        };
        $liveStream->update(['status' => 'ended', 'ended_at' => now(), 'replay_expires_at' => $replayExpiresAt, 'livekit_ingress_id' => null]);
        if ($cleanupIngressId) {
            $values = (array) $liveStream->stream_settings;
            $values['_lifecycle']['cleanup_pending'] = true;
            $values['_lifecycle']['ingress_to_cleanup'] = $cleanupIngressId;
            $liveStream->forceFill(['stream_settings' => $values])->save();
        }
        $this->sendParticipantEvent($liveStream, ['type' => 'stream_ended'], []);
        try {
            LiveKitSettings::deleteRoom($this->liveKitRoomName($liveStream));
        } catch (\RuntimeException $exception) {
            report($exception);
            $values = (array) $liveStream->stream_settings;
            $values['_lifecycle']['cleanup_pending'] = true;
            $liveStream->forceFill(['stream_settings' => $values])->save();
        }
        DB::table('live_stream_participant_requests')->where('live_stream_id', $liveStream->id)->whereIn('status', ['pending', 'accepted'])->update(['status' => 'ended', 'responded_at' => now(), 'updated_at' => now()]);
        $liveStream->polls()->where('status', 'active')->update(['status' => 'closed', 'ends_at' => now()]);
        if ($finalizeReplay) {
            FinalizeLiveStreamReplay::dispatch($liveStream->id)->onQueue('videos')->afterCommit();
        } elseif ($retention === 'off') {
            $this->cleanupUnretainedStream($liveStream);
        }

        return response()->json(['message' => 'Live stream ended.', 'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options']))]);
    }

    public function reconcileInactiveHosts(): void
    {
        if (! LiveKitSettings::configured()) {
            return;
        }
        $enabled = app(LiveCapabilityService::class)->payload()['enabled'];
        LiveStream::query()->where(fn ($query) => $query->whereIn('status', ['waiting-for-source', 'live'])
            ->orWhere(fn ($ended) => $ended->where('status', 'ended')->where('stream_settings->_lifecycle->cleanup_pending', true)))
            ->with('user')->chunkById(50, function ($streams) use ($enabled): void {
                foreach ($streams as $stream) {
                    try {
                        if ($stream->status === 'ended') {
                            $cleanupIngressId = data_get($stream->stream_settings, '_lifecycle.ingress_to_cleanup');
                            if ($cleanupIngressId) {
                                LiveKitSettings::deleteIngress($cleanupIngressId);
                            }
                            LiveKitSettings::deleteRoom($this->liveKitRoomName($stream));
                            $values = (array) $stream->stream_settings;
                            unset($values['_lifecycle']['cleanup_pending'], $values['_lifecycle']['ingress_to_cleanup']);
                            $stream->forceFill(['stream_settings' => $values])->save();

                            continue;
                        }
                        if (! $enabled || $stream->user?->isBannedFrom('live') || $stream->user?->isBannedFrom('watch')) {
                            $request = Request::create('/internal/live-disable', 'POST');
                            $request->setUserResolver(fn () => $stream->user);
                            $request->attributes->set('playhub.live.reconcile', true);
                            $request->attributes->set('playhub.live.disabled', true);
                            $this->end($request, $stream);

                            continue;
                        }
                        $participants = LiveKitSettings::participants($this->liveKitRoomName($stream));
                        $visibleParticipants = [];
                        foreach ($participants as $participant) {
                            $metadata = json_decode((string) ($participant['metadata'] ?? ''), true) ?: [];
                            $participantUser = ! empty($metadata['user_id']) ? User::query()->find((int) $metadata['user_id']) : null;
                            $publishingRole = in_array($metadata['role'] ?? '', ['host', 'guest', 'cohost'], true);
                            $guestRole = in_array($metadata['role'] ?? '', ['guest', 'cohost'], true);
                            $guestAuthorized = ! $guestRole || ($participantUser && (bool) data_get($stream->stream_settings, 'accept_participants', true)
                                && DB::table('live_stream_invites')->where('live_stream_id', $stream->id)->where('user_id', $participantUser->id)->whereIn('role', ['guest', 'cohost'])->exists());
                            if ($participantUser && ($participantUser->isBannedFrom('watch') || ($publishingRole && $participantUser->isBannedFrom('live'))
                                || $this->hasViewerRestriction($participantUser, $stream, 'stream') || ! $guestAuthorized)) {
                                LiveKitSettings::removeParticipant($this->liveKitRoomName($stream), (string) $participant['identity']);

                                continue;
                            }
                            $visibleParticipants[] = $participant;
                        }
                        $values = (array) $stream->stream_settings;
                        if ($this->liveSourcePresent($stream, $visibleParticipants)) {
                            if (isset($values['_lifecycle']['publisher_missing_since'])) {
                                unset($values['_lifecycle']['publisher_missing_since']);
                                $stream->forceFill(['stream_settings' => $values])->save();
                            }

                            continue;
                        }
                        $missingSince = data_get($values, '_lifecycle.publisher_missing_since');
                        if (! $missingSince) {
                            $values['_lifecycle']['publisher_missing_since'] = now()->toIso8601String();
                            $stream->forceFill(['stream_settings' => $values])->save();

                            continue;
                        }
                        $grace = max(5, min(300, (int) data_get($values, 'disconnect_grace_seconds', 30)));
                        if (Carbon::parse($missingSince)->addSeconds($grace)->isFuture()) {
                            continue;
                        }
                        $request = Request::create('/internal/live-reconcile', 'POST');
                        $request->setUserResolver(fn () => $stream->user);
                        $request->attributes->set('playhub.live.reconcile', true);
                        $this->end($request, $stream);
                    } catch (\Throwable $exception) {
                        // RTC outages must not end a broadcast whose publisher cannot be inspected.
                        if (! $exception instanceof HttpException || $exception->getStatusCode() !== 409) {
                            report($exception);
                        }
                    }
                }
            });
    }

    private function liveSourcePresent(LiveStream $stream, ?array $participants = null): bool
    {
        if ($stream->source_type === 'rtmp') {
            $ingress = LiveKitSettings::findIngress((string) $stream->livekit_ingress_id);

            return $ingress && LiveKitSettings::ingressIsPublishing($ingress);
        }

        return collect($participants ?? LiveKitSettings::participants($this->liveKitRoomName($stream)))->contains('identity', 'creator-'.$stream->user_id);
    }

    public function controlRoom(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeModerator($request, $liveStream);
        $isOwner = (int) $liveStream->user_id === (int) $request->user()->id;
        abort_unless(in_array($liveStream->status, ['waiting-for-source', 'live'], true), 409, 'This live stream is not active.');
        $ingress = null;
        if ($isOwner && $liveStream->source_type === 'rtmp' && $liveStream->livekit_ingress_id) {
            try {
                $ingress = LiveKitSettings::findIngress($liveStream->livekit_ingress_id);
                if ($ingress && $liveStream->status === 'waiting-for-source' && LiveKitSettings::ingressIsPublishing($ingress)) {
                    $liveStream->update(['status' => 'live']);
                }
            } catch (\RuntimeException) {
                $ingress = null;
            }
        }
        $messages = $liveStream->messages()->with('user.channel')->where('moderation_status', 'visible')->latest()->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $pinnedMessage = $liveStream->messages()->with('user.channel')->where('moderation_status', 'visible')->whereNotNull('pinned_at')->latest('pinned_at')->first();
        $contributions = $liveStream->contributions()->with(['sender.channel', 'gift', 'sticker', 'superSticker'])->where('status', 'completed')->latest()->limit(30)->get();
        $startedAt = $liveStream->actual_started_at ?: now();
        $invites = DB::table('live_stream_invites')
            ->join('users', 'users.id', '=', 'live_stream_invites.user_id')
            ->leftJoin('channels', 'channels.user_id', '=', 'users.id')
            ->where('live_stream_invites.live_stream_id', $liveStream->id)
            ->whereIn('live_stream_invites.role', ['guest', 'cohost'])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.username', 'users.avatar_path', 'channels.avatar_path as channel_avatar_path', 'live_stream_invites.role', 'live_stream_invites.accepted_at']);
        $participantRequests = DB::table('live_stream_participant_requests')
            ->join('users', 'users.id', '=', 'live_stream_participant_requests.user_id')
            ->leftJoin('channels', 'channels.user_id', '=', 'users.id')
            ->where('live_stream_participant_requests.live_stream_id', $liveStream->id)
            ->where('live_stream_participant_requests.status', 'pending')
            ->latest('live_stream_participant_requests.updated_at')
            ->get([
                'live_stream_participant_requests.id', 'live_stream_participant_requests.user_id', 'live_stream_participant_requests.identity',
                'live_stream_participant_requests.media_mode', 'live_stream_participant_requests.status', 'live_stream_participant_requests.created_at',
                'users.name', 'users.username', 'users.avatar_path', 'channels.avatar_path as channel_avatar_path',
            ]);
        $moderators = DB::table('live_stream_moderators')
            ->join('users', 'users.id', '=', 'live_stream_moderators.user_id')
            ->leftJoin('channels', 'channels.user_id', '=', 'users.id')
            ->where('live_stream_moderators.live_stream_id', $liveStream->id)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.username', 'users.avatar_path', 'channels.avatar_path as channel_avatar_path']);
        $viewers = DB::table('live_stream_viewers')
            ->join('users', 'users.id', '=', 'live_stream_viewers.user_id')
            ->leftJoin('channels', 'channels.user_id', '=', 'users.id')
            ->where('live_stream_viewers.live_stream_id', $liveStream->id)
            ->where('users.id', '!=', $liveStream->user_id)
            ->orderByDesc('live_stream_viewers.last_seen_at')
            ->get(['users.id', 'users.name', 'users.username', 'users.avatar_path', 'channels.avatar_path as channel_avatar_path', 'live_stream_viewers.role', 'live_stream_viewers.last_seen_at']);

        $payload = [
            'server_time' => now()->toIso8601String(),
            'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options'])),
            'connection' => $isOwner && $request->boolean('connect') ? $this->liveKitConnection($liveStream, $request->user(), true, 'host') : null,
            'ingress' => $ingress ? $this->rtmpIngressPayload($ingress) : null,
            'messages' => $messages->map(fn ($message): array => $this->messagePayload($message))->all(),
            'pinned_chat_message' => $pinnedMessage ? $this->messagePayload($pinnedMessage) : null,
            'contributions' => $contributions->map(fn (LiveStreamContribution $contribution): array => [
                ...$this->contributionPayload($contribution),
                'amount' => IntegrationSettings::formatMoney((int) $contribution->net_cents, (string) $contribution->currency),
                'message' => $contribution->message ?: '',
                'gift' => $this->contributionGiftPayload($contribution),
                'super_sticker' => $this->contributionSuperStickerPayload($contribution),
                'created_at' => $contribution->created_at?->toIso8601String(),
                'sender' => [
                    'id' => $contribution->sender_id,
                    'name' => $contribution->sender?->name ?: 'Viewer',
                    'username' => $contribution->sender?->username,
                    'channel_slug' => $contribution->sender?->channel?->slug,
                    'verified' => (bool) $contribution->sender?->verified_at,
                    'avatar_url' => ($contribution->sender?->channel?->avatar_path || $contribution->sender?->avatar_path) ? MediaStorage::url($contribution->sender?->channel?->avatar_path ?: $contribution->sender?->avatar_path) : null,
                ],
            ])->all(),
            'invites' => $invites->map(fn ($invite): array => [
                'user_id' => $invite->id,
                'name' => $invite->name,
                'username' => $invite->username,
                'role' => $invite->role,
                'accepted_at' => $invite->accepted_at,
                'avatar_url' => ($invite->channel_avatar_path || $invite->avatar_path) ? MediaStorage::url($invite->channel_avatar_path ?: $invite->avatar_path) : null,
            ])->all(),
            'participant_requests' => $participantRequests->map(fn ($participantRequest): array => [
                ...$this->participantRequestPayload($participantRequest),
                'name' => $participantRequest->name,
                'username' => $participantRequest->username,
                'avatar_url' => ($participantRequest->channel_avatar_path || $participantRequest->avatar_path) ? MediaStorage::url($participantRequest->channel_avatar_path ?: $participantRequest->avatar_path) : null,
            ])->all(),
            'moderators' => $moderators->map(fn ($moderator): array => [
                'user_id' => $moderator->id,
                'name' => $moderator->name,
                'username' => $moderator->username,
                'avatar_url' => ($moderator->channel_avatar_path || $moderator->avatar_path) ? MediaStorage::url($moderator->channel_avatar_path ?: $moderator->avatar_path) : null,
            ])->all(),
            'viewers' => $viewers->map(fn ($viewer): array => [
                'user_id' => $viewer->id,
                'name' => $viewer->name,
                'username' => $viewer->username,
                'role' => $viewer->role,
                'last_seen_at' => $viewer->last_seen_at,
                'avatar_url' => ($viewer->channel_avatar_path || $viewer->avatar_path) ? MediaStorage::url($viewer->channel_avatar_path ?: $viewer->avatar_path) : null,
            ])->all(),
            'metrics' => [
                'duration_seconds' => max(0, (int) floor($startedAt->diffInSeconds(now()))),
                'followers_count' => DB::table('subscriptions')->where('channel_id', $liveStream->channel_id)->count(),
                'new_followers' => DB::table('subscriptions')->where('channel_id', $liveStream->channel_id)->where('created_at', '>=', $startedAt)->count(),
                'chat_messages' => $liveStream->messages()->where('moderation_status', 'visible')->count(),
                'contributions_count' => $liveStream->contributions()->where('status', 'completed')->count(),
                'contributions_total_cents' => $liveStream->contributions()->where('status', 'completed')->sum('net_cents'),
            ],
            'capabilities' => app(LiveCapabilityService::class)->payload($liveStream),
            'participants' => $this->publicParticipants($liveStream),
            'restrictions' => $this->activeRestrictions($liveStream),
        ];
        if (! $isOwner) {
            unset($payload['stream']['preflight'], $payload['stream']['preflight_tested_at'], $payload['stream']['stream_settings']['invited_usernames'], $payload['stream']['stream_settings']['blocked_terms']);
        }

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function addModerator(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        abort_unless(in_array($liveStream->status, ['draft', 'scheduled', 'waiting-for-source', 'live'], true), 409, 'Moderators can only be assigned to an active stream draft.');
        $data = $request->validate(['username' => ['required', 'string', 'max:80']]);
        $moderator = User::query()->with('channel')->where('username', ltrim(trim($data['username']), '@'))->first();
        abort_unless($moderator, 404, 'No user was found with that username.');
        abort_if($moderator->id === $liveStream->user_id, 422, 'The stream owner already has moderation access.');
        $alreadyAssigned = DB::table('live_stream_moderators')
            ->where('live_stream_id', $liveStream->id)
            ->where('user_id', $moderator->id)
            ->exists();
        DB::table('live_stream_moderators')->updateOrInsert(
            ['live_stream_id' => $liveStream->id, 'user_id' => $moderator->id],
            ['assigned_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]
        );

        $mailSent = false;
        if (! $alreadyAssigned) {
            UserNotification::query()->create([
                'user_id' => $moderator->id,
                'actor_id' => $request->user()->id,
                'type' => 'live_moderator',
                'data' => [
                    'detail' => 'You can now moderate "'.$liveStream->title.'".',
                    'to' => '/live/'.$liveStream->slug,
                    'live_stream_id' => $liveStream->id,
                ],
            ]);
            $mailSent = $this->sendLiveModeratorEmail($moderator, $liveStream);
        }

        return response()->json([
            'message' => $moderator->name.' is now a stream moderator.'.(! $alreadyAssigned && ! $mailSent ? ' Email notification was not sent.' : ''),
            'moderator' => $this->moderatorPayload($moderator),
        ], $alreadyAssigned ? 200 : 201);
    }

    public function removeModerator(Request $request, LiveStream $liveStream, User $user): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        $deleted = DB::table('live_stream_moderators')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->delete();
        abort_unless($deleted, 404, 'Stream moderator not found.');

        return response()->json(['message' => $user->name.' is no longer a stream moderator.']);
    }

    public function inviteParticipant(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        abort_unless(in_array($liveStream->status, ['waiting-for-source', 'live'], true), 409, 'Start the live stream before inviting participants.');
        $streamSettings = array_replace_recursive($this->defaultStreamSettings(), (array) $liveStream->stream_settings);
        abort_unless($streamSettings['accept_participants'] ?? true, 403, 'Participants are disabled for this stream.');
        $data = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'role' => ['nullable', Rule::in(['guest', 'cohost'])],
        ]);
        $participant = User::query()->with('channel')->where('username', ltrim(trim($data['username']), '@'))->first();
        abort_unless($participant, 404, 'No user was found with that username.');
        abort_if($participant->id === $liveStream->user_id, 422, 'The stream owner is already the host.');
        abort_if($participant->isBannedFrom('live') || $participant->isBannedFrom('watch') || $this->hasViewerRestriction($participant, $liveStream, 'stream'), 403, 'This account cannot join the stage.');
        $role = $data['role'] ?? 'guest';
        DB::table('live_stream_invites')->updateOrInsert(
            ['live_stream_id' => $liveStream->id, 'user_id' => $participant->id],
            ['role' => $role, 'invited_by' => $request->user()->id, 'accepted_at' => null, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json([
            'message' => $participant->name.' can now join as '.($role === 'cohost' ? 'a co-host.' : 'a guest.'),
            'invite_url' => url('/live/'.$liveStream->slug),
            'invite' => [
                'user_id' => $participant->id,
                'name' => $participant->name,
                'username' => $participant->username,
                'role' => $role,
                'accepted_at' => null,
                'avatar_url' => ($participant->channel?->avatar_path || $participant->avatar_path) ? MediaStorage::url($participant->channel?->avatar_path ?: $participant->avatar_path) : null,
            ],
        ], 201);
    }

    public function requestParticipation(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeAudience($request, $liveStream);
        abort_if(ClientContext::fromRequest($request)->isTv(), 403, 'TV clients cannot publish live media.');
        abort_if($request->user()->currentAccessToken()?->cant('live:publish'), 403, 'This device session cannot publish live media.');
        abort_if($request->user()->isBannedFrom('live'), 403, $request->user()->banMessage('live'));
        abort_unless($liveStream->status === 'live', 409, 'The live stream must be active before requesting to participate.');
        abort_if($liveStream->user_id === $request->user()->id, 422, 'The stream owner is already the host.');
        $streamSettings = array_replace_recursive($this->defaultStreamSettings(), (array) $liveStream->stream_settings);
        abort_unless($streamSettings['accept_participants'] ?? true, 403, 'Participants are disabled for this stream.');
        $data = $request->validate([
            'media_mode' => ['required', Rule::in(['audio', 'audio_video'])],
            'identity' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);
        $user = $request->user();
        $this->validateParticipantIdentity($user, $data['identity']);
        $current = DB::table('live_stream_participant_requests')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->first();
        abort_if($current && $current->status === 'accepted', 409, 'You are already approved to participate.');

        DB::table('live_stream_participant_requests')->updateOrInsert(
            ['live_stream_id' => $liveStream->id, 'user_id' => $user->id],
            [
                'identity' => $data['identity'], 'media_mode' => $data['media_mode'], 'status' => 'pending',
                'responded_by' => null, 'responded_at' => null, 'updated_at' => now(), 'created_at' => $current?->created_at ?: now(),
            ]
        );
        $participantRequest = DB::table('live_stream_participant_requests')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->first();
        $this->sendParticipantEvent($liveStream, [
            'type' => 'participant_request_created', 'request_id' => $participantRequest->id, 'user_id' => $user->id,
            'name' => $user->name, 'media_mode' => $participantRequest->media_mode, 'status' => 'pending',
        ], $this->moderationDestinationIdentities($liveStream));

        return response()->json([
            'message' => 'Your request was sent to the streamer.',
            'participant_request' => $this->participantRequestPayload($participantRequest),
        ], $current ? 200 : 201);
    }

    public function respondParticipantRequest(Request $request, LiveStream $liveStream, int $participantRequest): JsonResponse
    {
        return Cache::lock('playhub:live:lifecycle:'.$liveStream->id, 60)->get(fn () => $this->respondParticipantRequestLocked($request, $liveStream->fresh(), $participantRequest))
            ?: abort(409, 'A live lifecycle operation is already in progress.');
    }

    private function respondParticipantRequestLocked(Request $request, LiveStream $liveStream, int $participantRequest): JsonResponse
    {
        $this->authorizeModerator($request, $liveStream);
        abort_unless(in_array($liveStream->status, ['waiting-for-source', 'live'], true), 409, 'This live stream is not active.');
        $data = $request->validate(['decision' => ['required', Rule::in(['accepted', 'declined'])]]);
        $streamSettings = array_replace_recursive($this->defaultStreamSettings(), (array) $liveStream->stream_settings);
        if ($data['decision'] === 'accepted') {
            abort_unless($streamSettings['accept_participants'] ?? true, 403, 'Participants are disabled for this stream.');
        }

        $participant = DB::transaction(function () use ($request, $liveStream, $participantRequest, $data) {
            $row = DB::table('live_stream_participant_requests')
                ->where('id', $participantRequest)->where('live_stream_id', $liveStream->id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Participant request not found.');
            abort_unless($row->status === 'pending', 409, 'This participant request has already been handled.');
            if ($data['decision'] === 'accepted') {
                $participantUser = User::query()->findOrFail($row->user_id);
                $this->authorizeViewer($participantUser, $liveStream);
                abort_if($participantUser->isBannedFrom('live'), 403, $participantUser->banMessage('live'));
                $existingRole = DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $row->user_id)->value('role');
                DB::table('live_stream_invites')->updateOrInsert(
                    ['live_stream_id' => $liveStream->id, 'user_id' => $row->user_id],
                    [
                        'role' => $existingRole === 'cohost' ? 'cohost' : 'guest', 'invited_by' => $request->user()->id,
                        'accepted_at' => now(), 'updated_at' => now(), 'created_at' => now(),
                    ]
                );
            }
            DB::table('live_stream_participant_requests')->where('id', $row->id)->update([
                'status' => $data['decision'], 'responded_by' => $request->user()->id, 'responded_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('live_stream_participant_requests')
                ->join('users', 'users.id', '=', 'live_stream_participant_requests.user_id')
                ->where('live_stream_participant_requests.id', $row->id)
                ->first(['live_stream_participant_requests.*', 'users.name', 'users.username']);
        });

        $role = DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $participant->user_id)->value('role') ?: 'guest';
        if ($data['decision'] === 'accepted') {
            try {
                LiveKitSettings::updateParticipantPublishing(
                    $this->liveKitRoomName($liveStream), $participant->identity, true, $participant->media_mode,
                    ['user_id' => $participant->user_id, 'live_stream_id' => $liveStream->id, 'role' => $role, 'media_mode' => $participant->media_mode, 'name' => $participant->name, 'username' => $participant->username],
                );
            } catch (\RuntimeException $exception) {
                report($exception);
            }
        }
        $this->sendParticipantEvent($liveStream, [
            'type' => 'participant_request_updated', 'request_id' => $participant->id, 'user_id' => $participant->user_id,
            'name' => $participant->name, 'media_mode' => $participant->media_mode, 'status' => $data['decision'],
        ], array_values(array_unique([...$this->moderationDestinationIdentities($liveStream), $participant->identity])));
        if ($data['decision'] === 'accepted') {
            $this->sendParticipantEvent($liveStream, [
                'type' => 'participant_stage_joined', 'request_id' => $participant->id, 'user_id' => $participant->user_id,
                'identity' => $participant->identity, 'name' => $participant->name, 'status' => 'joined',
            ], []);
        }

        return response()->json([
            'message' => $data['decision'] === 'accepted' ? $participant->name.' can now join the stage.' : $participant->name.' participant request was declined.',
            'participant_request' => $this->participantRequestPayload($participant),
        ]);
    }

    public function leaveParticipation(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeAudience($request, $liveStream);
        $data = $request->validate(['identity' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9._-]+$/']]);
        $user = $request->user();
        $this->validateParticipantIdentity($user, $data['identity']);
        $participantRequest = DB::table('live_stream_participant_requests')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->first();
        $role = DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->value('role');
        $wasPublisher = in_array($role, ['guest', 'cohost'], true) || $participantRequest?->status === 'accepted';
        abort_unless($participantRequest || $wasPublisher, 404, 'No participant request was found.');
        $nextStatus = $wasPublisher ? 'left' : 'cancelled';

        DB::transaction(function () use ($liveStream, $user, $participantRequest, $wasPublisher, $nextStatus): void {
            if ($participantRequest) {
                DB::table('live_stream_participant_requests')->where('id', $participantRequest->id)->update([
                    'status' => $nextStatus, 'responded_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($wasPublisher) {
                DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->whereIn('role', ['guest', 'cohost'])
                    ->update(['role' => 'viewer', 'accepted_at' => null, 'updated_at' => now()]);
            }
        });
        if ($wasPublisher && in_array($liveStream->status, ['waiting-for-source', 'live'], true)) {
            try {
                LiveKitSettings::updateParticipantPublishing(
                    $this->liveKitRoomName($liveStream), $data['identity'], false, $participantRequest?->media_mode ?: 'audio_video',
                    ['user_id' => $user->id, 'live_stream_id' => $liveStream->id, 'role' => 'viewer', 'name' => $user->name, 'username' => $user->username],
                );
            } catch (\RuntimeException $exception) {
                abort(502, 'Stage permission was revoked, but LiveKit could not confirm connected participant cleanup.');
            }
        }
        $this->sendParticipantEvent($liveStream, [
            'type' => 'participant_request_updated', 'request_id' => $participantRequest?->id, 'user_id' => $user->id,
            'name' => $user->name, 'media_mode' => $participantRequest?->media_mode, 'status' => $nextStatus,
        ], array_values(array_unique([...$this->moderationDestinationIdentities($liveStream), $data['identity']])));
        if ($wasPublisher) {
            $this->sendParticipantEvent($liveStream, [
                'type' => 'participant_stage_left', 'request_id' => $participantRequest?->id, 'user_id' => $user->id,
                'identity' => $data['identity'], 'name' => $user->name, 'status' => 'left',
            ], []);
        }

        return response()->json(['message' => $wasPublisher ? 'You left the participant stage.' : 'Your participant request was cancelled.', 'participant_request' => ['status' => $nextStatus]]);
    }

    public function participantAction(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeModerator($request, $liveStream);
        abort_unless(in_array($liveStream->status, ['waiting-for-source', 'live'], true), 409, 'This live stream is not active.');
        $data = $request->validate([
            'action' => ['required', Rule::in(['mute_track', 'request_camera', 'remove'])],
            'identity' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9._-]+$/'],
            'track_sid' => ['required_if:action,mute_track,request_camera', 'nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9_-]+$/'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);
        abort_if($data['identity'] === 'creator-'.$liveStream->user_id, 422, 'The host cannot be removed from the control room.');
        try {
            $participant = collect(LiveKitSettings::participants($this->liveKitRoomName($liveStream)))->firstWhere('identity', $data['identity']);
        } catch (\RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
        abort_unless($participant, 404, 'Participant not found.');
        $metadata = json_decode((string) ($participant['metadata'] ?? ''), true) ?: [];
        $participantUserId = (int) ($metadata['user_id'] ?? 0);
        abort_if(isset($data['user_id']) && (int) $data['user_id'] !== $participantUserId, 422, 'Participant account does not match.');
        $data['user_id'] = $participantUserId ?: null;
        if (in_array($data['action'], ['mute_track', 'request_camera'], true)) {
            abort_unless(collect($participant['tracks'] ?? [])->contains('sid', $data['track_sid']), 422, 'This track does not belong to the participant.');
        }
        if ($data['action'] === 'request_camera') {
            $track = collect($participant['tracks'] ?? [])->firstWhere('sid', $data['track_sid']);
            abort_unless(in_array($track['source'] ?? null, ['CAMERA', 'camera', 1], true) && ($track['muted'] ?? false), 422, 'This participant camera is not stopped.');
            $permission = $participant['permission'] ?? [];
            $sources = $permission['canPublishSources'] ?? $permission['can_publish_sources'] ?? [];
            abort_unless(($permission['canPublish'] ?? $permission['can_publish'] ?? false) && ($sources === [] || array_intersect($sources, ['CAMERA', 'camera', 1])), 422, 'This participant cannot publish a camera.');
        }
        if (! empty($data['user_id'])) {
            $this->assertModeratableUser(User::query()->findOrFail($data['user_id']), $liveStream);
        }
        $room = 'playhub-live-'.$liveStream->uuid;
        try {
            if ($data['action'] === 'remove') {
                $participantName = ! empty($data['user_id']) ? User::query()->whereKey($data['user_id'])->value('name') : null;
                $this->sendParticipantEvent($liveStream, [
                    'type' => 'participant_stage_removed', 'user_id' => $data['user_id'] ?? null, 'identity' => $data['identity'],
                    'name' => $participantName ?: 'A participant', 'status' => 'removed',
                ], []);
                LiveKitSettings::removeParticipant($room, $data['identity']);
                if (! empty($data['user_id'])) {
                    DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $data['user_id'])->whereIn('role', ['guest', 'cohost'])->delete();
                    DB::table('live_stream_participant_requests')->where('live_stream_id', $liveStream->id)->where('user_id', $data['user_id'])->update([
                        'status' => 'removed', 'responded_by' => $request->user()->id, 'responded_at' => now(), 'updated_at' => now(),
                    ]);
                }
            } elseif ($data['action'] === 'request_camera') {
                // Camera restoration is opt-in on the participant device. Remote unmute stays disabled.
                LiveKitSettings::sendParticipantEvent($room, [
                    'type' => 'participant_camera_requested', 'request_id' => (string) Str::uuid(),
                    'live_stream_id' => $liveStream->id, 'identity' => $data['identity'],
                    'track_sid' => $data['track_sid'], 'expires_at' => now()->addMinute()->toIso8601String(),
                ], [$data['identity']]);
            } else {
                LiveKitSettings::mutePublishedTrack($room, $data['identity'], (string) $data['track_sid']);
            }
        } catch (\RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }

        return response()->json(['message' => match ($data['action']) {
            'remove' => 'Participant removed.',
            'request_camera' => 'Camera request sent. The participant can choose to enable it.',
            default => 'Participant track muted.',
        }]);
    }

    public function restrictViewer(Request $request, LiveStream $liveStream, User $user): JsonResponse
    {
        $this->authorizeModerator($request, $liveStream);
        $this->assertModeratableUser($user, $liveStream);
        $data = $request->validate([
            'type' => ['required', Rule::in(['stream', 'chat'])],
            'reason' => ['nullable', 'string', 'max:240'],
        ]);
        DB::table('live_stream_restrictions')->updateOrInsert(
            ['live_stream_id' => $liveStream->id, 'user_id' => $user->id, 'type' => $data['type']],
            ['created_by' => $request->user()->id, 'reason' => trim((string) ($data['reason'] ?? '')) ?: null, 'expires_at' => null, 'created_at' => now(), 'updated_at' => now()]
        );
        if ($data['type'] === 'stream') {
            LiveKitAdmissionService::revokeUser($liveStream, $user->id);
            DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->delete();
            DB::table('live_stream_participant_requests')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->update([
                'status' => 'removed', 'responded_by' => $request->user()->id, 'responded_at' => now(), 'updated_at' => now(),
            ]);
            $this->removeUserFromLiveRoom($liveStream, $user);
        }

        return response()->json(['message' => $data['type'] === 'stream' ? $user->name.' was banned from this stream.' : $user->name.' was banned from live chat.']);
    }

    public function removeViewerRestriction(Request $request, LiveStream $liveStream, User $user, string $type): JsonResponse
    {
        $this->authorizeModerator($request, $liveStream);
        abort_unless(in_array($type, ['stream', 'chat'], true), 404);
        $deleted = DB::table('live_stream_restrictions')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->whereIn('type', $type === 'stream' ? ['stream', 'ban'] : ['chat'])->delete();
        abort_unless($deleted, 404, 'Viewer restriction not found.');

        return response()->json(['message' => 'Viewer restriction removed.']);
    }

    public function engagement(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        $settings = LiveStreamingSettings::settings();
        $data = $request->validate([
            'pinned_message' => ['nullable', 'string', 'max:500'],
            'alerts' => ['nullable', 'array'],
            'alerts.followers' => ['nullable', 'boolean'],
            'alerts.subscriptions' => ['nullable', 'boolean'],
            'alerts.contributions' => ['nullable', 'boolean'],
            'alerts.goals' => ['nullable', 'boolean'],
            'alerts.sound' => ['nullable', 'boolean'],
            'alerts.duration_seconds' => ['nullable', 'integer', 'min:2', 'max:30'],
            'labels' => ['nullable', 'array'],
            'labels.*.label' => ['required_with:labels', 'string', 'max:60'],
            'labels.*.value' => ['nullable', 'string', 'max:120'],
        ]);
        if (array_key_exists('pinned_message', $data)) {
            abort_unless($settings['features']['pinned_messages'], 403, 'Pinned messages are disabled.');
        }
        if (array_key_exists('alerts', $data)) {
            abort_unless($settings['features']['alerts'], 403, 'Live alerts are disabled.');
        }
        if (array_key_exists('labels', $data)) {
            abort_unless($settings['features']['stream_labels'], 403, 'Stream labels are disabled.');
        }
        $values = array_replace_recursive($this->defaultEngagementSettings(), (array) $liveStream->engagement_settings, $data);
        $this->validateEngagement($values, $settings);
        $liveStream->update(['engagement_settings' => $values]);
        if ($liveStream->status === 'live') {
            $this->sendParticipantEvent($liveStream, ['type' => 'stream_updated'], []);
        }

        return response()->json(['message' => 'Engagement settings saved.', 'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options']))]);
    }

    public function chatSettings(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        abort_unless(in_array($liveStream->status, ['waiting-for-source', 'live'], true), 409, 'This live stream is not active.');
        $settings = LiveStreamingSettings::settings();
        abort_unless($settings['features']['chat'], 403, 'Live chat is disabled.');
        $data = $request->validate([
            'enable_chat' => ['sometimes', 'boolean'],
            'slow_mode' => ['sometimes', 'boolean'],
            'slow_mode_interval' => ['sometimes', 'integer'],
            'followers_only' => ['sometimes', 'boolean'],
            'unique_chat' => ['sometimes', 'boolean'],
            'chat_moderation' => ['sometimes', 'string'],
        ]);
        abort_if($data === [], 422, 'Add at least one chat setting to update.');
        if (array_key_exists('slow_mode_interval', $data)) {
            abort_unless(in_array($data['slow_mode_interval'], $settings['options']['slow_mode_intervals'], true), 422, 'The selected slow mode interval is unavailable.');
        }
        if (array_key_exists('chat_moderation', $data)) {
            abort_unless(in_array($data['chat_moderation'], $settings['options']['moderation_levels'], true), 422, 'The selected chat moderation is unavailable.');
        }
        $streamSettings = array_replace_recursive($this->defaultStreamSettings(), (array) $liveStream->stream_settings, $data);
        $liveStream->update(['stream_settings' => $streamSettings]);
        $this->sendParticipantEvent($liveStream, ['type' => 'stream_updated'], []);

        return response()->json([
            'message' => 'Live chat settings updated.',
            'stream' => $this->streamPayload($liveStream->fresh(['category', 'polls.options'])),
        ]);
    }

    public function createPoll(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        $settings = LiveStreamingSettings::settings();
        abort_unless($settings['features']['polls'], 403, 'Live polls are disabled.');
        $maxOptions = (int) $settings['limits']['poll_max_options'];
        $data = $request->validate([
            'question' => ['required', 'string', 'max:240'],
            'options' => ['required', 'array', 'min:2', 'max:'.$maxOptions],
            'options.*' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:'.(int) $settings['limits']['poll_max_duration_minutes']],
            'starts_at' => ['nullable', 'date'],
        ]);
        $startsAt = filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at'])->utc() : null;
        abort_if($startsAt && ! $startsAt->isFuture(), 422, 'Poll schedule must be in the future.');
        $poll = DB::transaction(function () use ($liveStream, $data): LiveStreamPoll {
            $startsAt = filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at'])->utc() : null;
            $durationSeconds = isset($data['duration_minutes']) ? (int) $data['duration_minutes'] * 60 : null;
            $activeNow = $liveStream->status === 'live' && ! $startsAt;
            if ($activeNow) {
                $liveStream->polls()->where('status', 'active')->update(['status' => 'closed', 'ends_at' => now()]);
            }
            $poll = $liveStream->polls()->create([
                'question' => trim($data['question']),
                'status' => $startsAt ? 'scheduled' : ($activeNow ? 'active' : 'draft'),
                'duration_seconds' => $durationSeconds,
                'starts_at' => $startsAt ?: ($activeNow ? now() : null),
                'ends_at' => $startsAt && $durationSeconds ? $startsAt->copy()->addSeconds($durationSeconds) : ($activeNow && $durationSeconds ? now()->addSeconds($durationSeconds) : null),
            ]);
            foreach (array_values($data['options']) as $position => $label) {
                $poll->options()->create(['label' => trim($label), 'position' => $position]);
            }

            return $poll;
        });

        $poll->load('options');
        if ($liveStream->status === 'live') {
            $this->sendParticipantEvent($liveStream, ['type' => 'poll_updated', 'poll_id' => $poll->id, 'status' => $poll->status], []);
        }

        return response()->json(['message' => 'Poll created.', 'poll' => $this->pollPayload($poll)], 201);
    }

    public function updatePoll(Request $request, LiveStream $liveStream, LiveStreamPoll $poll): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        $settings = LiveStreamingSettings::settings();
        abort_unless($settings['features']['polls'], 403, 'Live polls are disabled.');
        abort_unless($poll->live_stream_id === $liveStream->id, 404);
        $data = $request->validate([
            'question' => ['sometimes', 'required', 'string', 'max:240'],
            'options' => ['sometimes', 'array', 'min:2', 'max:'.(int) $settings['limits']['poll_max_options']],
            'options.*' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.(int) $settings['limits']['poll_max_duration_minutes']],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(['draft', 'scheduled', 'active', 'closed'])],
        ]);
        $options = $data['options'] ?? null;
        unset($data['options']);
        if (array_key_exists('duration_minutes', $data)) {
            $data['duration_seconds'] = $data['duration_minutes'] ? (int) $data['duration_minutes'] * 60 : null;
            unset($data['duration_minutes']);
        }
        if (($data['status'] ?? null) === 'scheduled' || (array_key_exists('starts_at', $data) && filled($data['starts_at'])) || (array_key_exists('duration_seconds', $data) && $poll->status === 'scheduled')) {
            $startsAt = Carbon::parse($data['starts_at'] ?? $poll->starts_at)->utc();
            abort_if(! $startsAt->isFuture(), 422, 'Poll schedule must be in the future.');
            $data['status'] = 'scheduled';
            $data['starts_at'] = $startsAt;
            $durationSeconds = $data['duration_seconds'] ?? $poll->duration_seconds;
            $data['ends_at'] = $durationSeconds ? $startsAt->copy()->addSeconds($durationSeconds) : null;
        }
        if (($data['status'] ?? null) === 'active') {
            abort_unless($liveStream->status === 'live', 409, 'A poll can only be activated while the stream is live.');
            LiveStreamPoll::query()->where('live_stream_id', $liveStream->id)->where('id', '!=', $poll->id)->where('status', 'active')->update(['status' => 'closed', 'ends_at' => now()]);
            $data['starts_at'] = now();
            $durationSeconds = $data['duration_seconds'] ?? $poll->duration_seconds;
            $data['ends_at'] = $durationSeconds ? now()->addSeconds($durationSeconds) : null;
        } elseif (($data['status'] ?? null) === 'closed') {
            $data['ends_at'] = now();
        }
        DB::transaction(function () use ($poll, $data, $options): void {
            $poll->update($data);
            if (is_array($options)) {
                abort_if($poll->status === 'active' || $poll->options()->sum('votes_count') > 0, 409, 'Poll options cannot be changed after voting starts.');
                $poll->options()->delete();
                foreach (array_values($options) as $position => $label) {
                    $poll->options()->create(['label' => trim($label), 'position' => $position]);
                }
            }
        });

        $updatedPoll = $poll->fresh('options');
        if ($liveStream->status === 'live') {
            $this->sendParticipantEvent($liveStream, ['type' => 'poll_updated', 'poll_id' => $updatedPoll->id, 'status' => $updatedPoll->status], []);
        }

        return response()->json(['message' => 'Poll updated.', 'poll' => $this->pollPayload($updatedPoll)]);
    }

    public function deletePoll(Request $request, LiveStream $liveStream, LiveStreamPoll $poll): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        abort_unless(LiveStreamingSettings::settings()['features']['polls'], 403, 'Live polls are disabled.');
        abort_unless($poll->live_stream_id === $liveStream->id, 404);
        abort_if($poll->status === 'active', 409, 'Close the poll before deleting it.');
        $poll->delete();

        return response()->json(['message' => 'Poll deleted.']);
    }

    public function pollVoters(Request $request, LiveStream $liveStream, LiveStreamPoll $poll): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        abort_unless($poll->live_stream_id === $liveStream->id, 404);
        $perPage = min(100, max(1, $request->integer('per_page', 50)));
        $voters = DB::table('live_stream_poll_votes')
            ->join('users', 'users.id', '=', 'live_stream_poll_votes.user_id')
            ->join('live_stream_poll_options', 'live_stream_poll_options.id', '=', 'live_stream_poll_votes.option_id')
            ->leftJoin('channels', 'channels.user_id', '=', 'users.id')
            ->where('live_stream_poll_votes.poll_id', $poll->id)
            ->latest('live_stream_poll_votes.created_at')
            ->select([
                'live_stream_poll_votes.id', 'live_stream_poll_votes.user_id', 'live_stream_poll_votes.option_id',
                'live_stream_poll_votes.created_at', 'users.name', 'users.username', 'users.avatar_path',
                'channels.slug as channel_slug', 'channels.avatar_path as channel_avatar_path',
                'live_stream_poll_options.label as option_label',
            ])
            ->paginate($perPage);

        return response()->json([
            'poll' => ['id' => $poll->id, 'question' => $poll->question, 'votes_count' => $poll->options()->sum('votes_count')],
            'voters' => collect($voters->items())->map(fn ($voter): array => [
                'id' => $voter->id,
                'user_id' => $voter->user_id,
                'name' => $voter->name,
                'username' => $voter->username,
                'channel_slug' => $voter->channel_slug,
                'avatar_url' => ($voter->channel_avatar_path || $voter->avatar_path) ? MediaStorage::url($voter->channel_avatar_path ?: $voter->avatar_path) : null,
                'option_id' => $voter->option_id,
                'option_label' => $voter->option_label,
                'voted_at' => $voter->created_at ? Carbon::parse($voter->created_at)->toIso8601String() : null,
            ])->values()->all(),
            'meta' => ['current_page' => $voters->currentPage(), 'last_page' => $voters->lastPage(), 'total' => $voters->total()],
        ]);
    }

    public function vote(Request $request, LiveStream $liveStream, LiveStreamPoll $poll): JsonResponse
    {
        abort_unless(LiveStreamingSettings::settings()['features']['polls'], 403, 'Live polls are disabled.');
        abort_unless($poll->live_stream_id === $liveStream->id, 404);
        $this->synchronizePolls($liveStream);
        $poll->refresh();
        if ($liveStream->status === 'live' && $poll->status === 'scheduled' && $poll->starts_at?->isPast()) {
            $poll->update(['status' => 'active']);
        }
        abort_unless($liveStream->status === 'live' && $poll->status === 'active', 409, 'This poll is not accepting votes.');
        abort_if($poll->ends_at && $poll->ends_at->isPast(), 409, 'This poll has ended.');
        $this->authorizeAudience($request, $liveStream);
        $data = $request->validate(['option_id' => ['required', 'integer', Rule::exists('live_stream_poll_options', 'id')->where('poll_id', $poll->id)]]);
        DB::transaction(function () use ($request, $poll, $data): void {
            $inserted = DB::table('live_stream_poll_votes')->insertOrIgnore(['poll_id' => $poll->id, 'option_id' => $data['option_id'], 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            abort_if($inserted === 0, 409, 'You have already voted in this poll.');
            DB::table('live_stream_poll_options')->where('id', $data['option_id'])->increment('votes_count');
        });

        $updatedPoll = $poll->fresh('options');
        $this->sendParticipantEvent($liveStream, ['type' => 'poll_voted', 'poll_id' => $updatedPoll->id], []);

        return response()->json(['message' => 'Vote recorded.', 'poll' => $this->pollPayload($updatedPoll, (int) $data['option_id'])]);
    }

    public function polls(Request $request, LiveStream $liveStream): JsonResponse
    {
        $user = auth('sanctum')->user();
        $this->authorizeViewer($user, $liveStream);
        abort_unless(LiveStreamingSettings::settings()['features']['polls'], 403, 'Live polls are disabled.');
        $this->synchronizePolls($liveStream);
        $polls = $liveStream->polls()->with('options')->whereIn('status', ['active', 'closed'])->latest()->limit(10)->get();
        $viewerVotes = $user
            ? DB::table('live_stream_poll_votes')->where('user_id', $user->id)->whereIn('poll_id', $polls->pluck('id'))->pluck('option_id', 'poll_id')
            : collect();

        return response()->json(['polls' => $polls->map(fn (LiveStreamPoll $poll): array => $this->pollPayload($poll, $viewerVotes->has($poll->id) ? (int) $viewerVotes->get($poll->id) : null))->all()]);
    }

    public function messages(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeViewer(auth('sanctum')->user(), $liveStream);
        abort_unless(LiveStreamingSettings::settings()['features']['chat'], 403, 'Live chat is disabled.');
        abort_unless(in_array($liveStream->status, ['live', 'ended'], true), 409, 'Live chat is not active.');
        $streamSettings = array_replace_recursive($this->defaultStreamSettings(), (array) $liveStream->stream_settings);
        abort_unless($streamSettings['enable_chat'], 403, 'Live chat is disabled for this stream.');
        $messages = $liveStream->messages()->with('user.channel')->where('moderation_status', 'visible')->latest()->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $contributions = $liveStream->contributions()->with(['sender.channel', 'gift', 'sticker', 'superSticker'])->where('status', 'completed')
            ->where(fn ($query) => UserBlocks::filter($query, 'sender_id', auth('sanctum')->id()))
            ->latest()->limit(40)->get()->reverse()->values();

        return response()->json([
            'messages' => $messages->map(fn ($message): array => $this->messagePayload($message))->all(),
            'contributions' => $contributions->map(fn (LiveStreamContribution $contribution): array => $this->contributionActivityPayload($contribution))->all(),
        ]);
    }

    public function sendMessage(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeAudience($request, $liveStream);
        abort_unless(LiveStreamingSettings::settings()['features']['chat'], 403, 'Live chat is disabled.');
        abort_unless($liveStream->status === 'live', 409, 'Live chat is not active.');
        $streamSettings = array_replace_recursive($this->defaultStreamSettings(), (array) $liveStream->stream_settings);
        abort_unless($streamSettings['enable_chat'], 403, 'Live chat is disabled for this stream.');
        abort_if($this->hasViewerRestriction($request->user(), $liveStream, 'chat'), 403, 'You are not allowed to send messages in this live stream.');
        if ($streamSettings['followers_only'] && $request->user()->id !== $liveStream->user_id) {
            abort_unless(DB::table('subscriptions')->where('user_id', $request->user()->id)->where('channel_id', $liveStream->channel_id)->exists(), 403, 'Chat is available to subscribers only.');
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);
        $body = trim($data['body']);
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $body) ?? $body);
        $lastMessage = $liveStream->messages()->where('user_id', $request->user()->id)->latest()->first();
        $moderationLevel = (string) $streamSettings['chat_moderation'];
        if ($moderationLevel !== 'off') {
            $messageLimit = match ($moderationLevel) {
                'strict' => 3,
                'standard', 'custom' => 4,
                default => 6,
            };
            $recentMessages = $liveStream->messages()->where('user_id', $request->user()->id)->where('created_at', '>=', now()->subSeconds(10))->count();
            abort_if($recentMessages >= $messageLimit, 429, 'You are sending messages too quickly.');
        }
        if ($streamSettings['slow_mode'] && $lastMessage) {
            $retryAfter = max(0, (int) $streamSettings['slow_mode_interval'] - $lastMessage->created_at->diffInSeconds(now()));
            abort_if($retryAfter > 0, 429, 'Slow mode is active. Wait '.$retryAfter.' seconds before sending another message.');
        }
        $blockRepeated = $streamSettings['unique_chat'] || $moderationLevel !== 'off';
        abort_if($blockRepeated && $lastMessage && hash_equals((string) $lastMessage->normalized_hash, hash('sha256', $normalized)), 422, 'Send a different message.');
        [$moderationStatus, $moderationReason, $moderatedBody] = $this->moderateMessage($body, $streamSettings);
        $message = $liveStream->messages()->create([
            'user_id' => $request->user()->id,
            'body' => $moderatedBody,
            'normalized_hash' => hash('sha256', $normalized),
            'moderation_status' => $moderationStatus,
            'moderation_reason' => $moderationReason,
        ]);
        if ($moderationStatus === 'visible') {
            $this->sendParticipantEvent($liveStream, ['type' => 'chat_updated', 'message_id' => $message->id], []);
        }

        return response()->json([
            'message' => $moderationStatus === 'visible' ? 'Message sent.' : 'Message held for moderation.',
            'chat_message' => $this->messagePayload($message->load('user.channel')),
        ], 201);
    }

    public function pinMessage(Request $request, LiveStream $liveStream, LiveStreamChatMessage $message): JsonResponse
    {
        $this->authorizeOwner($request, $liveStream);
        abort_unless((int) $message->live_stream_id === (int) $liveStream->id, 404);
        abort_unless(LiveStreamingSettings::settings()['features']['pinned_messages'], 403, 'Pinned messages are disabled.');
        abort_unless($message->moderation_status === 'visible', 422, 'Only visible chat messages can be pinned.');

        $data = $request->validate(['pinned' => ['nullable', 'boolean']]);
        $pin = array_key_exists('pinned', $data) ? (bool) $data['pinned'] : ! $message->pinned_at;

        if ($pin) {
            $liveStream->messages()->whereNotNull('pinned_at')->whereKeyNot($message->id)->update(['pinned_at' => null]);
            $message->forceFill(['pinned_at' => now()])->save();
        } else {
            $message->forceFill(['pinned_at' => null])->save();
        }
        if ($liveStream->status === 'live') {
            $this->sendParticipantEvent($liveStream, ['type' => 'chat_updated', 'message_id' => $message->id], []);
        }

        return response()->json([
            'message' => $pin ? 'Chat message pinned.' : 'Chat message unpinned.',
            'chat_message' => $this->messagePayload($message->fresh('user.channel')),
        ]);
    }

    public function deleteMessage(Request $request, LiveStream $liveStream, LiveStreamChatMessage $message): JsonResponse
    {
        $this->authorizeModerator($request, $liveStream);
        abort_unless((int) $message->live_stream_id === (int) $liveStream->id, 404);
        if ($message->user_id !== $liveStream->user_id) {
            $this->assertModeratableUser(User::query()->findOrFail($message->user_id), $liveStream);
        } elseif ($request->user()->id !== $liveStream->user_id) {
            abort(403, 'Moderators cannot delete the streamer’s messages.');
        }
        $messageId = $message->id;
        $message->delete();
        if ($liveStream->status === 'live') {
            $this->sendParticipantEvent($liveStream, ['type' => 'chat_updated', 'message_id' => $messageId], []);
        }

        return response()->json(['message' => 'Live chat message deleted.', 'message_id' => $messageId]);
    }

    public function contribute(Request $request, LiveStream $liveStream): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['tip', 'super_chat', 'super_sticker', 'gift'])],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'gift_id' => ['nullable', 'integer'],
            'super_sticker_id' => ['nullable', static function (string $attribute, mixed $value, \Closure $fail): void {
                if ((! is_int($value) && ! is_string($value)) || (is_string($value) && mb_strlen($value) > 16384)) {
                    $fail('The selected Super Sticker is invalid.');
                }
            }],
            'message' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:12', 'max:100'],
            'price' => ['prohibited'], 'price_cents' => ['prohibited'], 'recipient_id' => ['prohibited'],
        ]);
        $existing = LiveStreamContribution::query()->where('sender_id', $request->user()->id)->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            // Recover the sender's immutable receipt even if the stream/catalogue changed after the debit.
            if ($data['type'] === 'super_sticker') {
                $replayedSticker = app(KlipyStickers::class)->resolveSelection($data['super_sticker_id'] ?? null, $request, forReplay: true);
                abort_unless($replayedSticker, 409, 'This idempotency key belongs to a different contribution.');
                $data['super_sticker_id'] = $replayedSticker->id;
            }
            $this->assertContributionReplay($existing, $liveStream, $data, (int) round((float) ($data['amount'] ?? 0) * 100));

            return response()->json(['message' => 'Contribution already processed.', 'created' => false, 'contribution' => $this->contributionPayload($existing)]);
        }
        $this->authorizeAudience($request, $liveStream);
        abort_unless($liveStream->status === 'live', 409, 'Contributions are available only while the stream is live.');
        $settings = LiveStreamingSettings::settings();
        $streamMoney = array_replace_recursive($this->defaultMonetizationSettings(), (array) $liveStream->monetization_settings);
        $featureMap = ['tip' => 'tips', 'super_chat' => 'super_chat', 'super_sticker' => 'super_stickers', 'gift' => 'gifts'];
        abort_unless($settings['features'][$featureMap[$data['type']]] && $streamMoney[$featureMap[$data['type']]], 403, 'This contribution type is disabled.');
        abort_if($request->user()->id === $liveStream->user_id, 422, 'You cannot contribute to your own stream.');
        $gift = null;
        $superSticker = null;
        if ($data['type'] === 'gift') {
            $gift = Gift::query()->whereKey($data['gift_id'] ?? 0)->where('enabled', true)->first();
            abort_unless($gift, 422, 'Select an available gift.');
        } elseif ($data['type'] === 'super_sticker') {
            $superSticker = app(KlipyStickers::class)->resolveSelection($data['super_sticker_id'] ?? null, $request);
            abort_unless($superSticker, 422, 'Select an available Super Sticker.');
            $data['super_sticker_id'] = $superSticker->id;
        } else {
            abort_unless(array_key_exists('amount', $data) && $data['amount'] !== null, 422, 'Enter a contribution amount.');
        }
        $grossCents = $superSticker
            ? (int) KlipyStickerSettings::settings()['live_price_cents']
            : ($gift?->price_cents ?? (int) round(((float) $data['amount']) * 100));
        $creatorMin = max((int) $settings['monetization']['tip_min_cents'], (int) round(((float) $streamMoney['tip_min']) * 100));
        $creatorMax = min((int) $settings['monetization']['tip_max_cents'], (int) round(((float) $streamMoney['tip_max']) * 100));
        abort_if(! $gift && ! $superSticker && ($grossCents < $creatorMin || $grossCents > $creatorMax), 422, 'Contribution amount is outside the allowed range.');
        [$contribution, $created] = DB::transaction(function () use ($request, $liveStream, $data, $gift, $superSticker, $grossCents, $settings): array {
            $lockedStream = LiveStream::query()->lockForUpdate()->findOrFail($liveStream->id);
            $this->authorizeAudience($request, $lockedStream);
            abort_unless($lockedStream->status === 'live', 409, 'Contributions are available only while the stream is live.');
            $feature = ['tip' => 'tips', 'super_chat' => 'super_chat', 'super_sticker' => 'super_stickers', 'gift' => 'gifts'][$data['type']];
            abort_unless(LiveStreamingSettings::settings()['features'][$feature] && (bool) data_get($lockedStream->monetization_settings, $feature, false), 403, 'This contribution type is disabled.');
            DB::table('users')->whereIn('id', [$request->user()->id, $liveStream->user_id])->orderBy('id')->lockForUpdate()->get();
            $existing = LiveStreamContribution::query()->where('sender_id', $request->user()->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $this->assertContributionReplay($existing, $lockedStream, $data, $grossCents);

                return [$existing, false];
            }
            if ($gift) {
                $gift = Gift::query()->lockForUpdate()->whereKey($gift->id)->where('enabled', true)->first();
                abort_unless($gift, 422, 'Select an available gift.');
                $grossCents = (int) $gift->price_cents;
            }
            if ($superSticker) {
                abort_unless(KlipyStickerSettings::isAvailable(), 422, 'Select an available Super Sticker.');
                $superSticker = Sticker::query()->lockForUpdate()->whereKey($superSticker->id)->where('provider', 'klipy')->where('enabled', true)->first();
                abort_unless($superSticker, 422, 'Select an available Super Sticker.');
                $grossCents = (int) KlipyStickerSettings::settings()['live_price_cents'];
            }
            if (! $gift && ! $superSticker) {
                $minimum = max((int) $settings['monetization']['tip_min_cents'], (int) round((float) data_get($lockedStream->monetization_settings, 'tip_min', 1) * 100));
                $maximum = min((int) $settings['monetization']['tip_max_cents'], (int) round((float) data_get($lockedStream->monetization_settings, 'tip_max', 500) * 100));
                abort_if($grossCents < $minimum || $grossCents > $maximum, 422, 'Contribution amount is outside the allowed range.');
            }
            abort_if($this->walletBalanceCents($request->user()->id) < $grossCents, 422, 'Insufficient wallet balance.');
            $feeCents = (int) round($grossCents * ((float) $settings['monetization']['contribution_fee_percent'] / 100));
            $netCents = max(0, $grossCents - $feeCents);
            abort_if($netCents <= 0, 422, 'Contribution amount is too small after fees.');
            $uuid = (string) Str::uuid();
            $currency = IntegrationSettings::currency();
            $contribution = LiveStreamContribution::query()->create([
                'uuid' => $uuid, 'live_stream_id' => $liveStream->id, 'sender_id' => $request->user()->id,
                'recipient_id' => $liveStream->user_id, 'type' => $data['type'], 'gift_id' => $gift?->id, 'sticker_id' => $superSticker?->id, 'gross_cents' => $grossCents,
                'fee_cents' => $feeCents, 'net_cents' => $netCents, 'currency' => $currency,
                'status' => 'completed',
                'idempotency_key' => $data['idempotency_key'], 'message' => trim((string) ($data['message'] ?? '')) ?: null,
                'gift_name' => $gift?->name, 'gift_image_path' => $gift?->image_path,
                'super_sticker_name' => $superSticker?->name, 'super_sticker_image_path' => $superSticker?->image_path,
                'sticker_name' => $superSticker?->name, 'sticker_image_path' => $superSticker?->image_path,
            ]);
            $eventPayload = ['live_stream_id' => $liveStream->id, 'contribution_id' => $contribution->id, 'event' => $data['type'], 'gift_id' => $gift?->id, 'gift_name' => $gift?->name, 'super_sticker_id' => $superSticker?->id, 'super_sticker_name' => $superSticker?->name];
            $this->recordWalletTransaction($request->user()->id, 'spend', $grossCents, $currency, 'live_contribution', 'sent:'.$uuid, $data['type'].' sent', $eventPayload);
            $this->recordWalletTransaction($liveStream->user_id, 'earning', $netCents, $currency, 'live_contribution', 'received:'.$uuid, ucfirst(str_replace('_', ' ', $data['type'])).' received', [...$eventPayload, 'gross_cents' => $grossCents, 'fee_cents' => $feeCents]);

            return [$contribution, true];
        });

        if ($created && $contribution->type === 'tip') {
            UserNotification::query()->create([
                'user_id' => $liveStream->user_id,
                'actor_id' => $request->user()->id,
                'channel_id' => $liveStream->channel_id,
                'type' => 'creator_tip',
                'data' => [
                    'detail' => IntegrationSettings::formatMoney((int) $contribution->gross_cents, (string) $contribution->currency).($contribution->message ? ' - '.$contribution->message : ''),
                    'to' => '/live/'.$liveStream->slug,
                ],
            ]);
        }
        if ($created) {
            $this->sendParticipantEvent($liveStream, ['type' => 'contribution_created', 'contribution_id' => $contribution->uuid], []);
        }

        return response()->json([
            'message' => $created ? 'Contribution sent.' : 'Contribution already processed.',
            'created' => $created,
            'contribution' => $this->contributionPayload($contribution->loadMissing(['gift', 'sticker', 'superSticker'])),
        ], $created ? 201 : 200);
    }

    public function serveAd(Request $request, LiveStream $liveStream): JsonResponse
    {
        $this->authorizeAudience($request, $liveStream);
        abort_if($liveStream->user_id === $request->user()->id, 403, 'Stream owners cannot earn from their own ad views.');
        $settings = LiveStreamingSettings::settings();
        $streamMoney = array_replace_recursive($this->defaultMonetizationSettings(), (array) $liveStream->monetization_settings);
        abort_unless($liveStream->status === 'live' && $streamMoney['ads'], 409, 'Live ads are not active.');
        abort_unless($settings['features']['ads'] && $settings['monetization']['ad_gross_cents'] > 0 && $settings['monetization']['ad_creator_share_percent'] > 0, 403, 'Live ad earnings are not configured.');
        $frequencyMinutes = max(5, (int) ($streamMoney['ad_frequency_minutes'] ?? 20));
        $recentImpression = LiveStreamAdImpression::query()
            ->where('live_stream_id', $liveStream->id)
            ->where('viewer_id', $request->user()->id)
            ->where('served_at', '>', now()->subMinutes($frequencyMinutes))
            ->exists();
        abort_if($recentImpression, 429, 'The next live ad is not available yet.');
        $ads = collect(PlayerAdSettings::settings()['ads'] ?? [])->filter(fn ($ad): bool => ($ad['enabled'] ?? true) && filled($ad['vastUrl'] ?? null));
        $ad = $ads->first();
        abort_unless($ad, 404, 'No live ad is available.');
        $adId = (string) ($ad['id'] ?? Str::slug($ad['name'] ?? 'live-ad'));
        $resolvedAd = collect(PlayerAdSettings::resolve([$adId]))->first();
        abort_unless($resolvedAd, 404, 'The live ad could not be resolved.');
        $durationSeconds = max(0, min(86400, (int) ($resolvedAd['duration'] ?? 0)));
        $impression = DB::transaction(function () use ($request, $liveStream, $adId, $settings, $frequencyMinutes, $durationSeconds): LiveStreamAdImpression {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $recentImpression = LiveStreamAdImpression::query()
                ->where('live_stream_id', $liveStream->id)
                ->where('viewer_id', $request->user()->id)
                ->where('served_at', '>', now()->subMinutes($frequencyMinutes))
                ->exists();
            abort_if($recentImpression, 429, 'The next live ad is not available yet.');

            return LiveStreamAdImpression::query()->create([
                'uuid' => (string) Str::uuid(), 'live_stream_id' => $liveStream->id, 'viewer_id' => $request->user()->id,
                'ad_id' => $adId, 'status' => 'served',
                'gross_cents' => $settings['monetization']['ad_gross_cents'],
                'creator_share_percent' => $settings['monetization']['ad_creator_share_percent'],
                'creator_amount_cents' => (int) round($settings['monetization']['ad_gross_cents'] * ($settings['monetization']['ad_creator_share_percent'] / 100)),
                'duration_seconds' => $durationSeconds,
                'served_at' => now(),
            ]);
        });

        return response()->json(['impression_id' => $impression->uuid, 'ad' => $resolvedAd]);
    }

    public function completeAd(Request $request, LiveStream $liveStream, LiveStreamAdImpression $impression): JsonResponse
    {
        abort_if($liveStream->user_id === $request->user()->id, 403, 'Stream owners cannot earn from their own ad views.');
        abort_unless($impression->live_stream_id === $liveStream->id && $impression->viewer_id === $request->user()->id, 404);
        $alreadyCompleted = DB::transaction(function () use ($request, $liveStream, $impression): bool {
            $locked = LiveStreamAdImpression::query()->lockForUpdate()->findOrFail($impression->id);
            abort_unless($locked->live_stream_id === $liveStream->id && $locked->viewer_id === $request->user()->id, 404);
            if ($locked->status === 'completed') {
                return true;
            }
            abort_unless($locked->status === 'served', 409, 'This ad impression cannot be completed.');

            $servedAt = $locked->served_at;
            abort_if(
                ! $servedAt || $servedAt->lt(now()->subMinutes(self::LIVE_AD_COMPLETION_WINDOW_MINUTES)),
                410,
                'This ad impression has expired.'
            );

            $durationSeconds = max(0, (int) $locked->duration_seconds);
            $requiredSeconds = $durationSeconds > 0
                ? max(1, (int) ceil($durationSeconds * .9))
                : self::LIVE_AD_FALLBACK_DURATION_SECONDS;
            $elapsedSeconds = max(0, now()->timestamp - $servedAt->timestamp);
            abort_if($elapsedSeconds < $requiredSeconds, 409, 'The ad has not played long enough to complete.');
            abort_if($locked->creator_amount_cents <= 0, 422, 'This ad impression has no creator earnings.');
            $locked->update(['status' => 'completed', 'completed_at' => now()]);
            $this->recordWalletTransaction($liveStream->user_id, 'earning', $locked->creator_amount_cents, IntegrationSettings::currency(), 'live_ad', 'impression:'.$locked->uuid, 'Live ad earning', [
                'live_stream_id' => $liveStream->id, 'event' => 'live_ad', 'ad_id' => $locked->ad_id,
                'gross_cents' => $locked->gross_cents, 'creator_share_percent' => $locked->creator_share_percent,
            ]);

            return false;
        });

        return response()->json(['message' => $alreadyCompleted ? 'Ad impression already completed.' : 'Ad impression completed.']);
    }

    private function streamRules(array $settings): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('live_stream_categories', 'id')->where('enabled', true)],
            'visibility' => ['sometimes', Rule::in(['public', 'unlisted', 'private'])],
            'source_type' => ['sometimes', Rule::in(['webcam', 'rtmp'])],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'appearance' => ['sometimes', 'array'],
            'appearance.accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.overlay_theme' => ['nullable', Rule::in(['minimal', 'creator_dark', 'clean_light'])],
            'stream_settings' => ['sometimes', 'array'],
            'stream_settings.quality' => ['nullable', 'string'],
            'stream_settings.latency_mode' => ['nullable', 'string'],
            'stream_settings.enable_dvr' => ['nullable', 'boolean'],
            'stream_settings.enable_chat' => ['nullable', 'boolean'],
            'stream_settings.save_replay' => ['nullable', 'string'],
            'stream_settings.audience_mode' => ['nullable', 'string'],
            'stream_settings.stream_delay_seconds' => ['nullable', 'integer', Rule::in([0, 5, 15, 30])],
            'stream_settings.accept_participants' => ['nullable', 'boolean'],
            'stream_settings.chat_moderation' => ['nullable', 'string'],
            'stream_settings.slow_mode' => ['nullable', 'boolean'],
            'stream_settings.slow_mode_interval' => ['nullable', 'integer'],
            'stream_settings.followers_only' => ['nullable', 'boolean'],
            'stream_settings.unique_chat' => ['nullable', 'boolean'],
            'stream_settings.frame_rate' => ['nullable', 'integer'],
            'stream_settings.target_bitrate_kbps' => ['nullable', 'integer', 'min:250', 'max:50000'],
            'stream_settings.audio_bitrate_kbps' => ['nullable', Rule::in([96, 128, 160, 192, 256, 320])],
            'stream_settings.keyframe_interval_seconds' => ['nullable', Rule::in([1, 2, 4])],
            'stream_settings.auto_reconnect' => ['nullable', 'boolean'],
            'stream_settings.disconnect_grace_seconds' => ['nullable', 'integer', 'min:5', 'max:300'],
            'stream_settings.dvr_window_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'stream_settings.blocked_terms' => ['nullable', 'array', 'max:100'],
            'stream_settings.blocked_terms.*' => ['string', 'max:80'],
            'stream_settings.invited_usernames' => ['nullable', 'array', 'max:200'],
            'stream_settings.invited_usernames.*' => ['string', 'max:80', 'distinct:ignore_case'],
            'monetization_settings' => ['sometimes', 'array'],
            'monetization_settings.ads' => ['nullable', 'boolean'],
            'monetization_settings.super_chat' => ['nullable', 'boolean'],
            'monetization_settings.super_stickers' => ['nullable', 'boolean'],
            'monetization_settings.gifts' => ['nullable', 'boolean'],
            'monetization_settings.tips' => ['nullable', 'boolean'],
            'monetization_settings.goal_bar' => ['nullable', 'boolean'],
            'monetization_settings.tip_min' => ['nullable', 'numeric', 'min:0.01'],
            'monetization_settings.tip_max' => ['nullable', 'numeric', 'min:0.01'],
            'monetization_settings.goal_title' => ['nullable', 'string', 'max:120'],
            'monetization_settings.goal_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'monetization_settings.ad_frequency_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'engagement_settings' => ['sometimes', 'array'],
        ];
    }

    private function normalizeStreamTags(?array $tags): array
    {
        return collect($tags ?: [])
            ->map(fn ($tag): string => ltrim(trim((string) $tag), '#'))
            ->filter()
            ->unique(fn (string $tag): string => mb_strtolower($tag))
            ->take(10)
            ->values()
            ->all();
    }

    private function validateSelectedOptions(array $values, array $settings): void
    {
        $checks = [
            'quality' => 'resolutions', 'latency_mode' => 'latency_modes', 'save_replay' => 'replay_retention',
            'audience_mode' => 'audience_modes', 'chat_moderation' => 'moderation_levels',
            'slow_mode_interval' => 'slow_mode_intervals', 'frame_rate' => 'frame_rates',
        ];
        foreach ($checks as $field => $option) {
            abort_unless(in_array($values[$field], $settings['options'][$option], true), 422, 'The selected '.str_replace('_', ' ', $field).' is unavailable.');
        }
    }

    private function validateMonetization(array $values, array $settings): void
    {
        $min = (int) round(((float) $values['tip_min']) * 100);
        $max = (int) round(((float) $values['tip_max']) * 100);
        abort_if($min < $settings['monetization']['tip_min_cents'] || $max > $settings['monetization']['tip_max_cents'] || $min > $max, 422, 'Tip range must stay within the administrator limits.');
        if ($values['ads']) {
            abort_unless($settings['features']['ads'] && $settings['monetization']['ad_gross_cents'] > 0 && $settings['monetization']['ad_creator_share_percent'] > 0, 422, 'Live ad earnings must be configured by an administrator before ads can be enabled.');
        }
    }

    private function validateEngagement(array $values, array $settings): void
    {
        abort_if(count((array) ($values['labels'] ?? [])) > (int) $settings['limits']['stream_label_max_count'], 422, 'Too many stream labels.');
    }

    private function validateScheduleConfiguration(LiveStream $liveStream, array $settings): void
    {
        abort_unless($liveStream->source_type === 'webcam', 422, 'Scheduled browser streams require the webcam source.');
        abort_unless(trim((string) $liveStream->description) !== '', 422, 'Add a stream description before scheduling.');

        $streamValues = array_replace_recursive($this->defaultStreamSettings(), (array) $liveStream->stream_settings);
        $moneyValues = array_replace_recursive($this->defaultMonetizationSettings(), (array) $liveStream->monetization_settings);
        $this->validateSelectedOptions($streamValues, $settings);
        $this->validateMonetization($moneyValues, $settings);

        if ($streamValues['audience_mode'] === 'invited') {
            abort_unless(DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->exists(), 422, 'Add at least one invited viewer before scheduling.');
        }
        if ($moneyValues['goal_bar']) {
            abort_unless(trim((string) $moneyValues['goal_title']) !== '' && (float) $moneyValues['goal_amount'] > 0, 422, 'Add a goal title and amount before scheduling.');
        }
    }

    private function validateStartConfiguration(LiveStream $liveStream, array $settings): void
    {
        abort_unless(trim((string) $liveStream->title) !== '', 422, 'Add a stream title before starting.');
        abort_unless(trim((string) $liveStream->description) !== '', 422, 'Add a stream description before starting.');
        $streamValues = $this->effectiveStreamSettings($liveStream);
        $moneyValues = array_replace_recursive($this->defaultMonetizationSettings(), (array) $liveStream->monetization_settings);
        $this->validateSelectedOptions($streamValues, $settings);
        $this->validateMonetization($moneyValues, $settings);
        if ($liveStream->source_type === 'webcam') {
            $preflight = (array) $liveStream->preflight;
            abort_unless($liveStream->preflight_tested_at?->gte(now()->subMinutes(15)) && ($preflight['ready'] ?? false), 422, 'Run a successful connection test before starting.');
            abort_unless(hash_equals($this->preflightSettingsFingerprint($liveStream, $streamValues), (string) ($preflight['settings_fingerprint'] ?? '')), 422, 'Stream quality settings changed. Run the connection test again.');
        }
        if ($streamValues['audience_mode'] === 'invited') {
            abort_unless(DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->exists(), 422, 'Add at least one invited viewer before starting.');
        }
        if ($moneyValues['goal_bar']) {
            abort_unless(trim((string) $moneyValues['goal_title']) !== '' && (float) $moneyValues['goal_amount'] > 0, 422, 'Add a goal title and amount before starting.');
        }
    }

    private function scheduledAt(?string $value, int $maxDays, bool $requiredFuture = false): ?Carbon
    {
        if (! $value) {
            return null;
        }
        $scheduledAt = Carbon::parse($value)->utc();
        abort_if($scheduledAt->lt(now()->subSeconds(5)), 422, 'Schedule must not be in the past.');
        abort_if($requiredFuture && ! $scheduledAt->isFuture(), 422, 'Schedule must be in the future.');
        abort_if($scheduledAt->gt(now()->addDays($maxDays)), 422, 'Schedule is too far in the future.');

        return $scheduledAt;
    }

    private function ensureAvailable(): void
    {
        $platformEnabled = (bool) data_get(PlatformFeatureSettings::settings(), 'liveStreaming.enabled', true);
        abort_unless($platformEnabled && LiveStreamingSettings::settings()['enabled'], 403, 'Live streaming is disabled by the site administrator.');
    }

    private function authorizeOwner(Request $request, LiveStream $liveStream, bool $allowDisabled = false): void
    {
        if (! $allowDisabled) {
            $this->ensureAvailable();
        }
        abort_unless($liveStream->user_id === $request->user()->id, 403);
    }

    private function authorizeModerator(Request $request, LiveStream $liveStream): void
    {
        $this->ensureAvailable();
        abort_if($request->user()->isBannedFrom('watch'), 403, $request->user()->banMessage('watch'));
        abort_unless($liveStream->user_id === $request->user()->id || $this->isModerator($request->user(), $liveStream), 403);
    }

    private function isModerator(?User $user, LiveStream $liveStream): bool
    {
        return $user && DB::table('live_stream_moderators')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->exists();
    }

    private function hasViewerRestriction(User $user, LiveStream $liveStream, string $type): bool
    {
        return DB::table('live_stream_restrictions')
            ->where('live_stream_id', $liveStream->id)
            ->where('user_id', $user->id)
            ->whereIn('type', $type === 'stream' ? ['stream', 'ban'] : [$type])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    private function activeRestrictions(LiveStream $stream): array
    {
        return DB::table('live_stream_restrictions')->join('users', 'users.id', '=', 'live_stream_restrictions.user_id')
            ->where('live_stream_id', $stream->id)->whereIn('type', ['chat', 'stream', 'ban'])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['users.id as user_id', 'users.name', 'users.username', 'type', 'reason', 'expires_at'])
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id, 'name' => $row->name, 'username' => $row->username,
                'type' => $row->type === 'ban' ? 'stream' : $row->type, 'reason' => $row->reason,
                'expires_at' => $row->expires_at ? Carbon::parse($row->expires_at)->toIso8601String() : null,
            ])->all();
    }

    private function assertModeratableUser(User $target, LiveStream $liveStream): void
    {
        abort_if($target->id === $liveStream->user_id, 422, 'The stream owner cannot be moderated.');
        abort_if($this->isModerator($target, $liveStream), 422, 'Remove this moderator role before applying viewer moderation.');
    }

    private function authorizeAudience(Request $request, LiveStream $liveStream): void
    {
        $this->authorizeViewer($request->user(), $liveStream);
    }

    private function authorizeViewer(?User $user, LiveStream $liveStream): void
    {
        abort_unless(UserBlocks::canSee($user, (int) $liveStream->user_id), 404, 'Live stream not found.');
        $this->ensureAvailable();
        $mode = (string) data_get($liveStream->stream_settings, 'audience_mode', 'everyone');
        if ($user && $liveStream->user_id === $user->id) {
            return;
        }
        abort_if($user && $user->isBannedFrom('watch'), 403, $user?->banMessage('watch'));
        abort_if($user && $this->hasViewerRestriction($user, $liveStream, 'stream'), 403, 'You are banned from this live stream.');
        $liveStream->loadMissing('user');
        abort_unless($liveStream->user?->hasPublicProfileEnabled(), 404);
        if ($user && $this->isModerator($user, $liveStream)) {
            return;
        }
        if ($user && DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->whereIn('role', ['guest', 'cohost'])->exists()) {
            return;
        }
        abort_if($liveStream->visibility === 'private' || $mode === 'private', 403, 'This live stream is private.');
        if ($mode === 'everyone') {
            return;
        }
        abort_unless($user, 401, 'Sign in to watch this live stream.');
        if ($mode === 'signed_in') {
            return;
        }
        if ($mode === 'subscribers') {
            abort_unless(DB::table('subscriptions')->where('user_id', $user->id)->where('channel_id', $liveStream->channel_id)->exists(), 403, 'This stream is available to subscribers only.');

            return;
        }
        if ($mode === 'invited') {
            abort_unless(DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->exists(), 403, 'This stream is available to invited viewers only.');

            return;
        }
        abort(403, 'This live stream is private.');
    }

    private function validateParticipantIdentity(User $user, string $identity): void
    {
        $viewerPrefix = 'viewer-'.$user->id.'-';
        $participantIdentity = 'participant-'.$user->id;
        abort_unless($identity === $participantIdentity || (str_starts_with($identity, $viewerPrefix) && strlen($identity) <= strlen($viewerPrefix) + 32), 422, 'The participant identity is invalid.');
    }

    private function participantRequestPayload(object $participantRequest): array
    {
        return [
            'id' => $participantRequest->id,
            'user_id' => $participantRequest->user_id,
            'media_mode' => $participantRequest->media_mode,
            'status' => $participantRequest->status,
            'requested_at' => $participantRequest->created_at,
            'responded_at' => $participantRequest->responded_at ?? null,
        ];
    }

    private function pendingParticipantRequestsPayload(LiveStream $liveStream): array
    {
        return DB::table('live_stream_participant_requests')
            ->join('users', 'users.id', '=', 'live_stream_participant_requests.user_id')
            ->leftJoin('channels', 'channels.user_id', '=', 'users.id')
            ->where('live_stream_participant_requests.live_stream_id', $liveStream->id)
            ->where('live_stream_participant_requests.status', 'pending')
            ->latest('live_stream_participant_requests.updated_at')
            ->get([
                'live_stream_participant_requests.id', 'live_stream_participant_requests.user_id', 'live_stream_participant_requests.media_mode',
                'live_stream_participant_requests.status', 'live_stream_participant_requests.created_at', 'users.name', 'users.username',
                'users.avatar_path', 'channels.avatar_path as channel_avatar_path',
            ])->map(fn ($participantRequest): array => [
                ...$this->participantRequestPayload($participantRequest),
                'name' => $participantRequest->name,
                'username' => $participantRequest->username,
                'avatar_url' => ($participantRequest->channel_avatar_path || $participantRequest->avatar_path) ? MediaStorage::url($participantRequest->channel_avatar_path ?: $participantRequest->avatar_path) : null,
            ])->all();
    }

    private function moderatorPayload(User $moderator): array
    {
        return [
            'user_id' => $moderator->id,
            'name' => $moderator->name,
            'username' => $moderator->username,
            'avatar_url' => ($moderator->channel?->avatar_path || $moderator->avatar_path) ? MediaStorage::url($moderator->channel?->avatar_path ?: $moderator->avatar_path) : null,
        ];
    }

    private function sendLiveModeratorEmail(User $moderator, LiveStream $liveStream): bool
    {
        $configured = IntegrationSettings::configureSmtpMailer();
        if (! $configured) {
            $driver = (string) config('mail.default', '');
            $configured = in_array($driver, ['smtp', 'ses', 'mailgun', 'postmark', 'sendmail'], true)
                && filled(config('mail.from.address'));
        }
        if (! $configured || ! filled($moderator->email)) {
            return false;
        }

        try {
            $template = EmailTemplates::render('live_moderator_assignment', [
                'USERNAME' => $moderator->name,
                'USER_EMAIL' => $moderator->email,
                'STREAM_TITLE' => $liveStream->title,
                'STREAM_URL' => url('/live/'.$liveStream->slug),
            ]);
            $subject = $template['subject'];
            $body = $template['body'];
            $locale = $template['locale'];
            Mail::raw($body, function ($message) use ($moderator, $subject, $body, $locale): void {
                BrandedEmail::apply($message, $subject, $body, $locale);
                $message->to($moderator->email, $moderator->name)->subject($subject);
            });

            return true;
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function removeUserFromLiveRoom(LiveStream $liveStream, User $user): void
    {
        try {
            foreach (LiveKitSettings::participants($this->liveKitRoomName($liveStream)) as $participant) {
                $metadata = json_decode((string) ($participant['metadata'] ?? ''), true);
                if ((int) ($metadata['user_id'] ?? 0) !== $user->id) {
                    continue;
                }
                $identity = (string) ($participant['identity'] ?? '');
                if ($identity !== '') {
                    LiveKitSettings::removeParticipant($this->liveKitRoomName($liveStream), $identity);
                }
            }
        } catch (\RuntimeException $exception) {
            abort(502, 'Stream restriction was saved, but LiveKit could not confirm connected participant cleanup.');
        }
    }

    private function moderationDestinationIdentities(LiveStream $liveStream): array
    {
        $identities = ['creator-'.$liveStream->user_id];
        $moderatorIds = DB::table('live_stream_moderators')->where('live_stream_id', $liveStream->id)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        if (! $moderatorIds) {
            return $identities;
        }
        try {
            foreach (LiveKitSettings::participants($this->liveKitRoomName($liveStream)) as $participant) {
                $metadata = json_decode((string) ($participant['metadata'] ?? ''), true);
                if (! in_array((int) ($metadata['user_id'] ?? 0), $moderatorIds, true)) {
                    continue;
                }
                $identity = (string) ($participant['identity'] ?? '');
                if ($identity !== '') {
                    $identities[] = $identity;
                }
            }
        } catch (\RuntimeException $exception) {
            report($exception);
        }

        return array_values(array_unique($identities));
    }

    private function sendParticipantEvent(LiveStream $liveStream, array $payload, array $destinationIdentities): void
    {
        try {
            LiveKitSettings::sendParticipantEvent($this->liveKitRoomName($liveStream), [
                ...$payload, 'live_stream_id' => $liveStream->id,
            ], $destinationIdentities);
        } catch (\RuntimeException $exception) {
            report($exception);
        }
    }

    private function liveKitConnection(LiveStream $liveStream, ?User $user, bool $publisher, ?string $participantRole = null): array
    {
        $settings = LiveKitSettings::settings();
        $url = trim((string) $settings['url']);
        $apiKey = trim((string) $settings['api_key']);
        $apiSecret = trim((string) $settings['api_secret']);
        abort_unless($url && $apiKey && $apiSecret, 503, 'Streaming credentials are unavailable.');

        $now = time();
        $room = $this->liveKitRoomName($liveStream);
        $preview = ! $publisher && $participantRole === 'preview';
        $role = $publisher ? (in_array($participantRole, ['host', 'cohost', 'guest'], true) ? $participantRole : 'guest') : ($preview ? 'preview' : ($participantRole === 'moderator' ? 'moderator' : 'viewer'));
        $mediaMode = $publisher && $role !== 'host' && $user
            ? DB::table('live_stream_participant_requests')->where('live_stream_id', $liveStream->id)->where('user_id', $user->id)->where('status', 'accepted')->value('media_mode')
            : null;
        $sources = ! $publisher ? [] : ($role === 'host' ? ['camera', 'microphone', 'screen_share', 'screen_share_audio'] : ($mediaMode === 'audio' ? ['microphone'] : ['camera', 'microphone']));
        $identity = $publisher
            ? ($role === 'host' ? 'creator-'.$liveStream->user_id : 'participant-'.$user?->id)
            : ($preview ? 'preview-'.Str::lower(Str::random(16)) : ($user ? 'viewer-'.$user->id.'-'.Str::lower(Str::random(8)) : 'guest-'.Str::lower(Str::random(16))));
        $payload = [
            'iss' => $apiKey,
            'sub' => $identity,
            'iat' => $now,
            'nbf' => $now - 5,
            'exp' => $now + ($role === 'host' ? 300 : 90),
            'jti' => (string) Str::uuid(),
            'name' => $preview ? 'Directory preview' : ($publisher ? ($user?->name ?: 'Creator') : ($user?->name ?: 'Guest')),
            'metadata' => json_encode(['user_id' => $user?->id, 'live_stream_id' => $liveStream->id, 'role' => $role, 'name' => $user?->name, 'username' => $user?->username, LiveKitAdmissionService::VERSION_METADATA => LiveKitAdmissionService::versionFor($liveStream, $identity, $user?->id)]),
            'video' => [
                'roomJoin' => true,
                'room' => $room,
                'canPublish' => $publisher,
                'canSubscribe' => true,
                'canPublishData' => false,
                'canPublishSources' => $sources,
                'canUpdateOwnMetadata' => false,
                'hidden' => $preview,
            ],
        ];
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64Url(hash_hmac('sha256', $header.'.'.$claims, $apiSecret, true));

        return [
            'url' => $url,
            'token' => $header.'.'.$claims.'.'.$signature,
            'room' => $room,
            'identity' => $identity,
            'can_publish' => $publisher,
            'role' => $role,
            'rtc_config' => LiveKitSettings::clientRtcConfig($settings),
        ];
    }

    private function liveKitRoomName(LiveStream $liveStream): string
    {
        return 'playhub-live-'.$liveStream->uuid;
    }

    private function ensureRtmpIngress(LiveStream $liveStream): array
    {
        $room = $this->liveKitRoomName($liveStream);
        $streamSettings = $this->effectiveStreamSettings($liveStream);
        $quality = (string) ($streamSettings['quality'] ?? 'auto');
        $metadata = ['live_stream_id' => $liveStream->id, 'live_stream_uuid' => $liveStream->uuid, 'role' => 'ingress', 'encoding_quality' => $quality];
        $ingress = $liveStream->livekit_ingress_id ? LiveKitSettings::findIngress($liveStream->livekit_ingress_id) : null;
        if ($ingress && ! LiveKitSettings::ingressIsPublishing($ingress) && $quality === 'auto') {
            $existingMetadata = json_decode((string) ($ingress['participant_metadata'] ?? $ingress['participantMetadata'] ?? ''), true);
            if (! is_array($existingMetadata) || ($existingMetadata['encoding_quality'] ?? null) !== 'auto') {
                LiveKitSettings::deleteIngress((string) ($ingress['ingress_id'] ?? $ingress['ingressId'] ?? ''));
                $liveStream->forceFill(['livekit_ingress_id' => null])->save();
                $ingress = null;
            }
        }
        if (! $ingress) {
            LiveKitSettings::recreateRoom($room, $metadata, $streamSettings);
            $ingress = LiveKitSettings::createRtmpIngress(
                $room,
                'ingress-'.$liveStream->uuid,
                trim((string) $liveStream->title) ?: 'PlayHub RTMP '.$liveStream->uuid,
                $metadata,
                $streamSettings
            );
        } elseif (! LiveKitSettings::ingressIsPublishing($ingress)) {
            LiveKitSettings::recreateRoom($room, $metadata, $streamSettings);
            $ingress = LiveKitSettings::updateRtmpIngress(
                (string) ($ingress['ingress_id'] ?? $ingress['ingressId'] ?? ''),
                $room,
                'ingress-'.$liveStream->uuid,
                trim((string) $liveStream->title) ?: 'PlayHub RTMP '.$liveStream->uuid,
                $metadata,
                $streamSettings
            );
        }
        $ingressId = (string) ($ingress['ingress_id'] ?? $ingress['ingressId'] ?? '');
        if ($ingressId !== '' && $liveStream->livekit_ingress_id !== $ingressId) {
            $liveStream->forceFill(['livekit_ingress_id' => $ingressId])->save();
        }

        return $ingress;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function moderateMessage(string $body, array $settings): array
    {
        $level = $settings['chat_moderation'];
        if ($level === 'off') {
            return ['visible', null, $body];
        }
        $filtered = PlatformFeatureSettings::filterProfanity($body);
        $hasProfanity = $filtered !== $body;
        $hasLink = preg_match('/https?:\/\/|www\./iu', $body) === 1;
        $blockedTerms = collect($settings['blocked_terms'] ?? [])->map(fn ($term): string => trim((string) $term))->filter();
        $hasBlockedTerm = $blockedTerms->contains(fn (string $term): bool => mb_stripos($body, $term) !== false);
        if ($level === 'custom' && $hasBlockedTerm) {
            return ['held', 'blocked_term', $filtered];
        }
        if ($level === 'strict' && ($hasProfanity || $hasLink || $hasBlockedTerm)) {
            return ['held', $hasLink ? 'link' : 'language', $filtered];
        }
        if ($level === 'standard' && $hasLink) {
            return ['held', 'link', $filtered];
        }

        return ['visible', $hasProfanity ? 'filtered_language' : null, $filtered];
    }

    private function walletBalanceCents(int $userId): int
    {
        return (int) WalletLedger::totals(User::query()->findOrFail($userId))['balance_cents'];
    }

    private function assertContributionReplay(LiveStreamContribution $existing, LiveStream $stream, array $data, int $grossCents): void
    {
        $same = (int) $existing->live_stream_id === (int) $stream->id && (int) $existing->recipient_id === (int) $stream->user_id
            && $existing->type === $data['type'] && (int) $existing->gift_id === (int) ($data['gift_id'] ?? 0)
            && (int) ($existing->sticker_id ?: $existing->super_sticker_id) === (int) ($data['super_sticker_id'] ?? 0)
            && (string) ($existing->message ?? '') === trim((string) ($data['message'] ?? ''))
            && (in_array($data['type'], ['gift', 'super_sticker'], true) || (int) $existing->gross_cents === $grossCents);
        abort_unless($same, 409, 'This idempotency key belongs to a different contribution.');
    }

    private function publicParticipants(LiveStream $stream): array
    {
        if (! in_array($stream->status, ['waiting-for-source', 'live'], true)) {
            return [];
        }
        try {
            return collect(LiveKitSettings::participants($this->liveKitRoomName($stream)))
                ->reject(fn (array $participant): bool => str_starts_with((string) ($participant['identity'] ?? ''), 'preview-') || (bool) data_get($participant, 'permission.hidden', false) || (bool) data_get($participant, 'permission.recorder', false))
                ->map(function (array $participant): array {
                    $metadata = json_decode((string) ($participant['metadata'] ?? ''), true) ?: [];

                    return [
                        'identity' => (string) ($participant['identity'] ?? ''),
                        'user_id' => isset($metadata['user_id']) ? (int) $metadata['user_id'] : null,
                        'name' => (string) ($metadata['name'] ?? $participant['name'] ?? 'Viewer'),
                        'username' => $metadata['username'] ?? null,
                        'role' => in_array($metadata['role'] ?? '', ['host', 'cohost', 'guest', 'moderator', 'viewer'], true) ? $metadata['role'] : 'viewer',
                        'tracks' => collect($participant['tracks'] ?? [])->map(fn (array $track): array => [
                            'sid' => (string) ($track['sid'] ?? ''), 'source' => $track['source'] ?? null,
                            'type' => $track['type'] ?? null, 'muted' => (bool) ($track['muted'] ?? false),
                        ])->values()->all(),
                    ];
                })->values()->all();
        } catch (\RuntimeException) {
            return [];
        }
    }

    private function recordWalletTransaction(int $userId, string $type, int $amountCents, string $currency, string $provider, string $reference, string $note, array $payload): void
    {
        DB::table('wallet_transactions')->insertOrIgnore([
            'user_id' => $userId, 'type' => $type, 'amount_cents' => $amountCents, 'currency' => $currency,
            'status' => 'completed', 'provider' => $provider, 'provider_reference' => $reference, 'note' => $note,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES), 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rtmpIngressPayload(array $ingress): array
    {
        return [
            'id' => (string) ($ingress['ingress_id'] ?? $ingress['ingressId'] ?? ''),
            'url' => (string) ($ingress['url'] ?? ''),
            'stream_key' => (string) ($ingress['stream_key'] ?? $ingress['streamKey'] ?? ''),
            'status' => data_get($ingress, 'state.status', 'ENDPOINT_INACTIVE'),
            'error' => (string) data_get($ingress, 'state.error', ''),
            'video' => [
                'width' => (int) data_get($ingress, 'state.video.width', 0),
                'height' => (int) data_get($ingress, 'state.video.height', 0),
                'frame_rate' => (float) data_get($ingress, 'state.video.framerate', 0),
                'average_bitrate' => (int) data_get($ingress, 'state.video.average_bitrate', 0),
            ],
            'audio' => [
                'channels' => (int) data_get($ingress, 'state.audio.channels', 0),
                'sample_rate' => (int) data_get($ingress, 'state.audio.sample_rate', 0),
                'average_bitrate' => (int) data_get($ingress, 'state.audio.average_bitrate', 0),
            ],
        ];
    }

    private function effectiveStreamSettings(LiveStream $liveStream): array
    {
        $values = array_replace_recursive($this->defaultStreamSettings(), (array) $liveStream->stream_settings);
        unset($values['_recording'], $values['_lifecycle']);

        return $values;
    }

    private function preflightSettingsFingerprint(LiveStream $liveStream, array $streamSettings): string
    {
        $autoQuality = ($streamSettings['quality'] ?? 'auto') === 'auto';

        return hash('sha256', json_encode([
            'source_type' => $liveStream->source_type,
            'quality' => $streamSettings['quality'],
            'frame_rate' => (int) $streamSettings['frame_rate'],
            'target_bitrate_kbps' => $autoQuality ? null : (int) $streamSettings['target_bitrate_kbps'],
            'audio_bitrate_kbps' => (int) $streamSettings['audio_bitrate_kbps'],
        ], JSON_THROW_ON_ERROR));
    }

    private function streamVideoDimensions(string $quality): array
    {
        return match ($quality) {
            '480p' => [854, 480],
            '720p' => [1280, 720],
            '1080p' => [1920, 1080],
            '1440p' => [2560, 1440],
            '2160p' => [3840, 2160],
            default => [null, null],
        };
    }

    private function startReplayRecording(LiveStream $liveStream, array $streamSettings): void
    {
        if (($streamSettings['save_replay'] ?? 'off') === 'off') {
            return;
        }

        $lock = Cache::lock('playhub:live-replay:start:'.$liveStream->id, 30);
        if (! $lock->get()) {
            throw new \RuntimeException('Live stream recording is already being prepared.');
        }
        try {
            $liveStream->refresh();
            $storedSettings = (array) $liveStream->stream_settings;
            $recording = (array) ($storedSettings['_recording'] ?? []);
            if (filled($recording['egress_id'] ?? null) && in_array($recording['status'] ?? null, ['recording', 'finalizing', 'processing', 'complete'], true)) {
                return;
            }

            $recordingDatePath = now()->format('Y/m');
            $recordingDirectory = 'live-streams/source/'.$recordingDatePath;
            MediaStorage::ensureLocalDirectory($recordingDirectory, 0777);
            $basename = 'replay-'.$liveStream->uuid.'-'.now()->format('YmdHis').'.mp4';
            $egress = LiveKitSettings::startRoomRecording(
                $this->liveKitRoomName($liveStream),
                '/out/source/'.$recordingDatePath.'/'.$basename,
                $streamSettings,
            );
            $egressId = (string) ($egress['egress_id'] ?? $egress['egressId'] ?? '');
            if ($egressId === '') {
                throw new \RuntimeException('The recording service did not return a replay recording ID.');
            }

            $storedSettings['_recording'] = [
                'egress_id' => $egressId,
                'room' => $this->liveKitRoomName($liveStream),
                'local_path' => $recordingDirectory.'/'.$basename,
                'status' => 'recording',
                'started_at' => now()->toIso8601String(),
            ];
            $liveStream->forceFill(['stream_settings' => $storedSettings])->save();
        } finally {
            $lock->release();
        }
    }

    private function stopReplayRecording(LiveStream $liveStream): bool
    {
        $storedSettings = (array) $liveStream->stream_settings;
        $recording = (array) ($storedSettings['_recording'] ?? []);
        $egressId = trim((string) ($recording['egress_id'] ?? ''));
        if ($egressId === '') {
            return false;
        }

        try {
            LiveKitSettings::stopEgress($egressId, (string) ($recording['room'] ?? $this->liveKitRoomName($liveStream)));
        } catch (\Throwable $exception) {
            report($exception);
        }
        $recording['status'] = 'finalizing';
        $recording['stopped_at'] = now()->toIso8601String();
        $storedSettings['_recording'] = $recording;
        $liveStream->forceFill(['stream_settings' => $storedSettings])->save();

        return true;
    }

    private function cleanupUnretainedStream(LiveStream $liveStream): void
    {
        $settings = (array) $liveStream->stream_settings;
        $recording = (array) ($settings['_recording'] ?? []);
        $paths = array_filter([$liveStream->thumbnail_path, $recording['local_path'] ?? null, $recording['media_path'] ?? null]);
        unset($settings['_recording']);

        DB::transaction(function () use ($liveStream, $settings): void {
            $liveStream->messages()->delete();
            $liveStream->polls()->delete();
            DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->delete();
            DB::table('live_stream_viewers')->where('live_stream_id', $liveStream->id)->delete();
            $liveStream->forceFill(['thumbnail_path' => null, 'stream_settings' => $settings])->save();
        });

        MediaStorage::delete($paths);
    }

    private function synchronizePolls(LiveStream $liveStream): void
    {
        $now = now();
        DB::transaction(function () use ($liveStream, $now): void {
            $liveStream->polls()->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<=', $now)->update(['status' => 'closed']);
            if ($liveStream->status !== 'live') {
                if ($liveStream->status === 'ended') {
                    $liveStream->polls()->where('status', 'active')->update(['status' => 'closed', 'ends_at' => $now]);
                }

                return;
            }

            $liveStream->polls()->where('status', 'scheduled')->whereNotNull('ends_at')->where('ends_at', '<=', $now)->update(['status' => 'closed']);
            $duePoll = $liveStream->polls()
                ->where('status', 'scheduled')
                ->whereNotNull('starts_at')
                ->where('starts_at', '<=', $now)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
                ->latest('starts_at')
                ->first();
            if ($duePoll) {
                $liveStream->polls()->where('status', 'active')->whereKeyNot($duePoll->id)->update(['status' => 'closed', 'ends_at' => $now]);
                $liveStream->polls()->where('status', 'scheduled')->where('starts_at', '<=', $now)->whereKeyNot($duePoll->id)->update(['status' => 'closed']);
                $duePoll->update(['status' => 'active']);

                return;
            }

            if (! $liveStream->actual_started_at || $liveStream->polls()->whereIn('status', ['active', 'closed'])->exists()) {
                return;
            }
            $preparedPoll = $liveStream->polls()->where('status', 'draft')->where('created_at', '<=', $liveStream->actual_started_at)->oldest()->first();
            if ($preparedPoll) {
                $preparedPoll->update([
                    'status' => 'active',
                    'starts_at' => $now,
                    'ends_at' => $preparedPoll->duration_seconds ? $now->copy()->addSeconds($preparedPoll->duration_seconds) : null,
                ]);
            }
        });
        $liveStream->unsetRelation('polls');
    }

    private function recordLiveStreamViewer(LiveStream $liveStream, int $userId, string $role): void
    {
        $now = now();
        DB::table('live_stream_viewers')->upsert([[
            'live_stream_id' => $liveStream->id,
            'user_id' => $userId,
            'role' => in_array($role, ['host', 'cohost', 'guest', 'moderator', 'viewer'], true) ? $role : 'viewer',
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['live_stream_id', 'user_id'], ['role', 'last_seen_at', 'updated_at']);
    }

    private function directoryPayload(LiveStream $stream, array $viewerCounts, $subscribedChannelIds): array
    {
        $channel = $stream->channel;
        $replay = $stream->replayVideo;
        $status = match ($stream->status) {
            'scheduled' => 'upcoming',
            'ended' => 'replay',
            default => 'live',
        };
        $viewerCount = $status === 'live'
            ? (int) ($viewerCounts['playhub-live-'.$stream->uuid] ?? 0)
            : 0;
        $thumbnailUrls = array_values(array_filter([
            LiveStreamMedia::thumbnailUrl($stream),
            $replay?->thumbnail_url,
            $channel?->cover_path ? MediaStorage::url($channel->cover_path) : null,
        ]));
        $thumbnailUrl = $thumbnailUrls[0] ?? null;
        $watchUrl = '/live/'.$stream->slug;

        return [
            'id' => (int) $stream->id,
            'uuid' => $stream->uuid,
            'slug' => $stream->slug,
            'title' => $stream->title ?: 'Live stream',
            'description' => $stream->description ?: '',
            'tags' => $stream->tags ?: [],
            'status' => $status,
            'visibility' => $stream->visibility,
            'source_type' => $stream->source_type,
            'appearance' => array_replace_recursive($this->defaultAppearance(), (array) $stream->appearance),
            'thumbnail_url' => $thumbnailUrl,
            'fallback_thumbnail_url' => $thumbnailUrls[1] ?? null,
            'viewer_count' => $viewerCount,
            'watch_url' => $watchUrl,
            'scheduled_at' => $stream->scheduled_at?->toIso8601String(),
            'actual_started_at' => $stream->actual_started_at?->toIso8601String(),
            'ended_at' => $stream->ended_at?->toIso8601String(),
            'category' => $stream->category ? [
                'id' => (int) $stream->category->id,
                'name' => $stream->category->name,
                'slug' => $stream->category->slug,
            ] : null,
            'creator' => [
                'id' => $channel ? (int) $channel->id : null,
                'user_id' => (int) $stream->user_id,
                'name' => $channel?->name ?: $stream->user?->name ?: 'Creator',
                'slug' => $channel?->slug,
                'avatar_url' => $channel?->avatar_path ? MediaStorage::url($channel->avatar_path) : null,
                'verified' => (bool) $channel?->user?->verified_at,
                'subscribers_count' => (int) ($channel?->subscribers_count ?? 0),
                'is_subscribed' => $subscribedChannelIds->has($stream->channel_id),
            ],
            'replay' => $replay ? [
                'id' => (int) $replay->id,
                'slug' => $replay->slug,
                'duration_seconds' => (int) ($replay->duration_seconds ?? 0),
                'views_count' => (int) $replay->views_count,
                'thumbnail_url' => $replay->thumbnail_url,
                'watch_url' => '/live/'.$stream->slug,
            ] : null,
        ];
    }

    private function streamPayload(LiveStream $stream, ?int $viewerUserId = null): array
    {
        $this->synchronizePolls($stream);
        $stream->unsetRelation('polls');
        $stream->loadMissing(['category', 'polls.options', 'channel', 'user.channel', 'replayVideo.channel.user', 'replayVideo.user', 'replayVideo.category', 'replayVideo.language', 'replayVideo.liveStream']);

        $contributionCents = (int) $stream->contributions()->where('status', 'completed')->sum('net_cents');
        $viewerVotes = $viewerUserId
            ? DB::table('live_stream_poll_votes')->where('user_id', $viewerUserId)->whereIn('poll_id', $stream->polls->pluck('id'))->pluck('option_id', 'poll_id')
            : collect();
        $streamSettings = $this->effectiveStreamSettings($stream);
        $monetizationSettings = array_replace_recursive($this->defaultMonetizationSettings(), (array) $stream->monetization_settings);
        $goalAmount = $monetizationSettings['goal_bar'] ? (float) $monetizationSettings['goal_amount'] : 0.0;
        $replay = ! $stream->replay_expires_at || $stream->replay_expires_at->isFuture() ? $stream->replayVideo : null;
        unset($streamSettings['captions'], $streamSettings['captions_language'], $streamSettings[LiveKitAdmissionService::VERSION_SETTINGS], $monetizationSettings['memberships']);

        return [
            'id' => $stream->id, 'uuid' => $stream->uuid, 'slug' => $stream->slug, 'title' => $stream->title ?: '',
            'description' => $stream->description ?: '', 'tags' => $stream->tags ?: [], 'category_id' => $stream->category_id,
            'category' => $stream->category ? ['id' => $stream->category->id, 'name' => $stream->category->name, 'slug' => $stream->category->slug] : null,
            'creator' => [
                'id' => $stream->user_id,
                'user_id' => $stream->user_id,
                'channel_id' => $stream->channel_id,
                'name' => $stream->channel?->name ?: $stream->user?->name,
                'slug' => $stream->channel?->slug,
                'bio' => $stream->channel?->bio ?: '',
                'verified' => (bool) $stream->user?->verified_at,
                'avatar_url' => $stream->channel?->avatar_path ? MediaStorage::url($stream->channel->avatar_path) : null,
                'followers_count' => DB::table('subscriptions')->where('channel_id', $stream->channel_id)->count(),
            ],
            'visibility' => $stream->visibility, 'status' => $stream->status, 'source_type' => $stream->source_type,
            'thumbnail_url' => LiveStreamMedia::thumbnailUrl($stream) ?: $replay?->thumbnail_url,
            'appearance' => array_replace_recursive($this->defaultAppearance(), (array) $stream->appearance),
            'stream_settings' => $streamSettings,
            'monetization_settings' => $monetizationSettings,
            'engagement_settings' => array_replace_recursive($this->defaultEngagementSettings(), (array) $stream->engagement_settings),
            'preflight' => $stream->preflight, 'preflight_tested_at' => $stream->preflight_tested_at?->toIso8601String(),
            'scheduled_at' => $stream->scheduled_at?->toIso8601String(), 'actual_started_at' => $stream->actual_started_at?->toIso8601String(),
            'ended_at' => $stream->ended_at?->toIso8601String(), 'replay_expires_at' => $stream->replay_expires_at?->toIso8601String(),
            'viewer_count' => (int) (LiveKitSettings::activeRoomViewerCounts()[$this->liveKitRoomName($stream)] ?? 0),
            'watch_url' => url('/live/'.$stream->slug),
            'features' => app(LiveCapabilityService::class)->payload($stream)['features'],
            'recording' => [
                'status' => (string) data_get($stream->stream_settings, '_recording.status', 'off'),
                'started_at' => data_get($stream->stream_settings, '_recording.started_at'),
                'stopped_at' => data_get($stream->stream_settings, '_recording.stopped_at'),
            ],
            'replay' => $replay ? (new VideoResource($replay))->resolve(request()) : null,
            'polls' => $stream->polls->map(fn (LiveStreamPoll $poll): array => $this->pollPayload($poll, $viewerVotes->has($poll->id) ? (int) $viewerVotes->get($poll->id) : null))->values()->all(),
            'contribution_summary' => [
                'total_cents' => $contributionCents,
                'total' => IntegrationSettings::formatMoney($contributionCents),
                'currency' => IntegrationSettings::currency(),
                'goal_amount' => $goalAmount,
                'goal_percent' => $goalAmount > 0 ? min(100, round((($contributionCents / 100) / $goalAmount) * 100, 1)) : 0,
            ],
            'updated_at' => $stream->updated_at?->toIso8601String(),
        ];
    }

    private function pollPayload(LiveStreamPoll $poll, ?int $viewerVoteOptionId = null): array
    {
        $poll->loadMissing('options');
        $total = (int) $poll->options->sum('votes_count');

        return [
            'id' => $poll->id, 'question' => $poll->question, 'status' => $poll->status,
            'duration_seconds' => $poll->duration_seconds, 'starts_at' => $poll->starts_at?->toIso8601String(),
            'ends_at' => $poll->ends_at?->toIso8601String(), 'votes_count' => $total,
            'created_at' => $poll->created_at?->toIso8601String(), 'updated_at' => $poll->updated_at?->toIso8601String(),
            'viewer_has_voted' => $viewerVoteOptionId !== null, 'viewer_vote_option_id' => $viewerVoteOptionId,
            'options' => $poll->options->map(fn ($option): array => [
                'id' => $option->id, 'label' => $option->label, 'votes_count' => (int) $option->votes_count,
                'percent' => $total > 0 ? round(((int) $option->votes_count / $total) * 100, 1) : 0,
            ])->values()->all(),
        ];
    }

    private function messagePayload($message): array
    {
        return [
            'id' => $message->id, 'body' => $message->body, 'moderation_status' => $message->moderation_status,
            'moderation_reason' => $message->moderation_reason, 'pinned_at' => $message->pinned_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'user' => [
                'id' => $message->user_id,
                'name' => $message->user?->name,
                'username' => $message->user?->username,
                'channel_slug' => $message->user?->channel?->slug,
                'verified' => (bool) $message->user?->verified_at,
                'avatar_url' => ($message->user?->channel?->avatar_path || $message->user?->avatar_path)
                    ? MediaStorage::url($message->user?->channel?->avatar_path ?: $message->user?->avatar_path)
                    : null,
            ],
        ];
    }

    private function contributionActivityPayload(LiveStreamContribution $contribution): array
    {
        $sender = $contribution->sender;

        return [
            'id' => $contribution->uuid,
            'type' => $contribution->type,
            'amount_cents' => $contribution->gross_cents,
            'amount' => IntegrationSettings::formatMoney((int) $contribution->gross_cents, (string) $contribution->currency),
            'message' => $contribution->message,
            'gift' => $this->contributionGiftPayload($contribution),
            'super_sticker' => $this->contributionSuperStickerPayload($contribution),
            'created_at' => $contribution->created_at?->toIso8601String(),
            'sender' => [
                'id' => $contribution->sender_id,
                'name' => $sender?->name,
                'username' => $sender?->username,
                'channel_slug' => $sender?->channel?->slug,
                'verified' => (bool) $sender?->verified_at,
                'avatar_url' => ($sender?->channel?->avatar_path || $sender?->avatar_path)
                    ? MediaStorage::url($sender?->channel?->avatar_path ?: $sender?->avatar_path)
                    : null,
            ],
        ];
    }

    private function contributionPayload(LiveStreamContribution $contribution): array
    {
        $contribution->loadMissing(['gift', 'sticker', 'superSticker']);

        return [
            'id' => $contribution->uuid, 'type' => $contribution->type, 'gross_cents' => $contribution->gross_cents,
            'fee_cents' => $contribution->fee_cents, 'net_cents' => $contribution->net_cents,
            'currency' => $contribution->currency, 'status' => $contribution->status,
            'gift' => $this->contributionGiftPayload($contribution),
            'super_sticker' => $this->contributionSuperStickerPayload($contribution),
        ];
    }

    private function giftCatalogPayload(): array
    {
        return Gift::query()->where('enabled', true)->orderBy('sort_order')->orderBy('name')->get()->map(fn (Gift $gift): array => [
            'id' => $gift->id,
            'name' => $gift->name,
            'image_url' => url('/'.ltrim($gift->image_path, '/')),
            'price_cents' => $gift->price_cents,
            'price' => number_format($gift->price_cents / 100, 2, '.', ''),
            'currency' => strtoupper(IntegrationSettings::currency()),
        ])->all();
    }

    private function contributionGiftPayload(LiveStreamContribution $contribution): ?array
    {
        if ($contribution->type !== 'gift') {
            return null;
        }
        $path = $contribution->gift_image_path ?: $contribution->gift?->image_path;

        return [
            'id' => $contribution->gift_id,
            'name' => $contribution->gift_name ?: $contribution->gift?->name ?: 'Gift',
            'image_url' => $path ? url('/'.ltrim($path, '/')) : null,
        ];
    }

    private function superStickerCatalogPayload(Request $request, int $page = 1, int $perPage = 24, ?string $category = null): array
    {
        return app(KlipyStickers::class)->catalog($request, $page, $perPage, $category);
    }

    private function contributionSuperStickerPayload(LiveStreamContribution $contribution): ?array
    {
        if ($contribution->type !== 'super_sticker') {
            return null;
        }
        $path = $contribution->sticker_image_path ?: $contribution->sticker?->image_path ?: $contribution->super_sticker_image_path ?: $contribution->superSticker?->image_path;

        return [
            'id' => $contribution->sticker_id ?: $contribution->super_sticker_id,
            'name' => $contribution->sticker_name ?: $contribution->sticker?->name ?: $contribution->super_sticker_name ?: $contribution->superSticker?->name ?: 'Super Sticker',
            'image_url' => app(KlipyStickers::class)->imageUrl($path),
        ];
    }

    private function defaultAppearance(): array
    {
        return ['accent_color' => '#6b2cff', 'overlay_theme' => 'minimal'];
    }

    private function defaultStreamSettings(): array
    {
        $settings = LiveStreamingSettings::settings();
        $defaults = $settings['defaults'];
        $quality = in_array('auto', $settings['options']['resolutions'] ?? [], true) ? 'auto' : $defaults['resolution'];
        $targetBitrate = match ($quality) {
            '480p' => 1500,
            '1080p' => 6000,
            '1440p' => 12000,
            '2160p' => 24000,
            default => 3000,
        };

        return [
            'quality' => $quality, 'latency_mode' => $defaults['latency_mode'], 'enable_dvr' => true, 'enable_chat' => true,
            'save_replay' => $defaults['replay_retention'], 'audience_mode' => $defaults['audience_mode'], 'stream_delay_seconds' => 0,
            'accept_participants' => true, 'chat_moderation' => $defaults['moderation_level'], 'slow_mode' => false, 'slow_mode_interval' => $defaults['slow_mode_interval'],
            'followers_only' => false, 'unique_chat' => false, 'frame_rate' => $defaults['frame_rate'], 'target_bitrate_kbps' => $targetBitrate,
            'audio_bitrate_kbps' => 128, 'keyframe_interval_seconds' => 2, 'auto_reconnect' => true,
            'disconnect_grace_seconds' => 30, 'dvr_window_minutes' => 120, 'blocked_terms' => [],
            'invited_usernames' => [],
        ];
    }

    private function defaultMonetizationSettings(): array
    {
        $settings = LiveStreamingSettings::studioSettings();

        return [
            'ads' => false, 'super_chat' => false, 'super_stickers' => false,
            'gifts' => false, 'tips' => false, 'goal_bar' => false, 'tip_min' => $settings['monetization']['tip_min'],
            'tip_max' => $settings['monetization']['tip_max'], 'goal_title' => '', 'goal_amount' => 0,
            'ad_frequency_minutes' => 20,
        ];
    }

    private function defaultEngagementSettings(): array
    {
        return [
            'pinned_message' => '',
            'alerts' => ['followers' => true, 'subscriptions' => true, 'contributions' => true, 'goals' => true, 'sound' => true, 'duration_seconds' => 6],
            'labels' => [],
        ];
    }
}
