<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PlaylistSummaryResource;
use App\Http\Resources\Api\V1\ProfileResource;
use App\Http\Resources\Api\V1\UserPublicResource;
use App\Http\Resources\Api\V1\VideoCardResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;
use App\Services\Api\V1\ViewerStateService;
use App\Support\Api\V1\PaginationPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function resolveMention(Request $request, string $handle): JsonResponse
    {
        $normalized = mb_strtolower($handle);
        $channels = Channel::query()->with('user')
            ->whereRaw('LOWER(slug) = ?', [$normalized])->limit(2)->get();
        $users = User::query()->with('channel.user')
            ->whereRaw('LOWER(username) = ?', [$normalized])->limit(2)->get();
        $users = $users->filter(fn (User $user) => \App\Support\UserBlocks::canSee($request->user(), (int) $user->id));
        $owners = $channels->pluck('user_id')->merge($users->pluck('id'))->unique();

        // A handle shared across the two namespaces cannot safely identify one profile.
        abort_unless($owners->count() === 1, 404, 'Mention not found.');
        $channel = $channels->first() ?? $users->first()?->channel;
        abort_unless($channel, 404, 'Mention not found.');
        $this->assertProfileVisible($channel, $request);

        return response()->json(['channel_slug' => (string) $channel->slug]);
    }

    public function show(Request $request, Channel $channel, VideoAccessService $access): JsonResponse
    {
        $this->assertProfileVisible($channel, $request);
        $isOwner = (int) ($request->user()?->id ?? 0) === (int) $channel->user_id;
        $channel->loadCount(['videos' => fn ($query) => $isOwner ? $query->publishedPublic() : $access->constrainPublic($query)]);
        $channel->setAttribute('public_views_count', (int) ($isOwner ? Video::query()->publishedPublic() : $access->publicQuery())
            ->where('channel_id', $channel->id)->sum('views_count'));
        if ($isOwner || (bool) data_get($channel->user->settings, 'preferences.showSubscriptions', true)) {
            $channel->setAttribute('public_subscriptions_count', DB::table('subscriptions')
                ->where('user_id', $channel->user_id)->count());
        }
        if ($request->user()) {
            $channel->setAttribute('viewer_subscribed', DB::table('subscriptions')
                ->where('user_id', $request->user()->id)->where('channel_id', $channel->id)->exists());
            $channel->setAttribute('viewer_favorite', DB::table('channel_favorites')
                ->where('user_id', $request->user()->id)->where('channel_id', $channel->id)->exists());
            $channel->setAttribute('viewer_reported', DB::table('user_reports')
                ->where('user_id', $request->user()->id)->where('reported_user_id', $channel->user_id)->exists());
            $channel->setAttribute('viewer_muted', DB::table('user_mutes')
                ->where('user_id', $request->user()->id)->where('muted_user_id', $channel->user_id)->exists());
        }

        return response()->json((new ProfileResource($channel))->resolve($request))
            ->header('Cache-Control', 'private, no-store');
    }

    public function videos(Request $request, Channel $channel, ViewerStateService $states, VideoAccessService $access): JsonResponse
    {
        $this->assertProfileVisible($channel, $request);
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'content_type' => ['nullable', 'in:all,videos,shorts,replays'],
        ]);
        $type = (string) $request->query('content_type', 'all');
        $isOwner = (int) ($request->user()?->id ?? 0) === (int) $channel->user_id;
        $videos = ($isOwner ? Video::query()->publishedPublic() : $access->publicQuery())->where('channel_id', $channel->id)
            ->when($type === 'videos', fn ($query) => $query->standardVideos())
            ->when($type === 'shorts', fn ($query) => $query->shorts())
            ->when($type === 'replays', fn ($query) => $query->where('source_type', 'live_replay'))
            ->with(['channel.user'])->latest('published_at')->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));
        $states->hydrate($videos->getCollection(), $request->user());

        return PaginationPayload::lengthAware($videos, VideoCardResource::collection($videos->getCollection())->resolve($request));
    }

    public function playlists(Request $request, Channel $channel, VideoAccessService $access): JsonResponse
    {
        $this->assertProfileVisible($channel, $request);
        $isOwner = (int) ($request->user()?->id ?? 0) === (int) $channel->user_id;
        $playlists = Playlist::query()->where('channel_id', $channel->id)
            ->when(! $isOwner, fn ($query) => $query->where('privacy', 'public'))
            ->with(['channel.user', 'coverVideo' => fn ($query) => $isOwner ? $query : $access->constrainPublic($query)])
            ->withCount(['videos' => fn ($query) => $isOwner ? $query : $access->constrainPublic($query)])
            ->latest()->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));

        return PaginationPayload::lengthAware($playlists, PlaylistSummaryResource::collection($playlists->getCollection())->resolve($request));
    }

    public function subscribers(Request $request, Channel $channel): JsonResponse
    {
        $this->assertProfileVisible($channel, $request);
        $users = User::query()
            ->when($request->user(), fn ($query) => \App\Support\UserBlocks::filter($query, 'users.id', (int) $request->user()->id))
            ->whereIn('id', DB::table('subscriptions')->where('channel_id', $channel->id)->select('user_id'))
            ->where(fn ($query) => $query
                ->whereNull('settings->preferences->publicProfile')
                ->orWhere('settings->preferences->publicProfile', true))
            ->where(fn ($query) => $query
                ->whereNull('settings->status')
                ->orWhere('settings->status', 'active'))
            ->with('channel.user')
            ->orderBy('name')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));

        return PaginationPayload::lengthAware($users, UserPublicResource::collection($users->getCollection())->resolve($request));
    }

    private function assertProfileVisible(Channel $channel, Request $request): void
    {
        abort_unless(\App\Support\UserBlocks::canSee($request->user(), (int) $channel->user_id), 404, 'Profile not found.');
        $channel->loadMissing('user');
        abort_unless($channel->user, 404, 'Profile not found.');
        $isOwner = (int) ($request->user()?->id ?? 0) === (int) $channel->user_id;
        $status = strtolower(str_replace(['_', '-'], ' ', trim((string) data_get($channel->user->settings, 'status', 'active'))));
        abort_unless($isOwner || ! in_array($status, ['suspended', 'deleted'], true), 404, 'Profile not found.');
        $public = (bool) data_get($channel->user->settings, 'preferences.publicProfile', true);
        abort_unless($isOwner || $public, 404, 'Profile not found.');
    }
}
