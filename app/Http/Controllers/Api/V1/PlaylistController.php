<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PlaylistSummaryResource;
use App\Http\Resources\Api\V1\VideoCardResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;
use App\Services\Api\V1\ViewerStateService;
use App\Support\Slugs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlaylistController extends Controller
{
    public function show(Request $request, Playlist $playlist, ViewerStateService $states, VideoAccessService $access): JsonResponse
    {
        // This route accepts optional Sanctum tokens. The middleware resolves
        // the bearer user on the request without switching the default guard,
        // so Gate::allows() can otherwise evaluate a private owner as a guest.
        abort_unless(Gate::forUser($request->user())->allows('view', $playlist), 404, 'Playlist not found.');
        $filters = $request->validate([
            'sort' => ['nullable', Rule::in(['playlist', 'newest'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $isOwner = (int) ($request->user()?->id ?? 0) === (int) $playlist->user_id;
        $playlist->load([
            'channel.user',
            'coverVideo' => fn ($query) => $isOwner
                ? $access->constrainViewable($query, $request->user())
                : $access->constrainPublic($query),
        ])->loadCount(['videos' => fn ($query) => $isOwner
            ? $access->constrainViewable($query, $request->user())
            : $access->constrainPublic($query)]);
        $videos = ($isOwner
            ? $access->viewableQuery($request->user())
            : $access->publicQuery())->select('videos.*')
            ->join('playlist_video', 'playlist_video.video_id', '=', 'videos.id')
            ->where('playlist_video.playlist_id', $playlist->id)
            ->with(['channel.user'])
            ->when(($filters['sort'] ?? 'playlist') === 'playlist', fn ($query) => $query
                ->orderBy('playlist_video.sort_order')->orderBy('playlist_video.id'))
            ->when(($filters['sort'] ?? 'playlist') === 'newest', fn ($query) => $query
                ->orderByDesc('playlist_video.created_at')->orderByDesc('playlist_video.id'))
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));
        $states->hydrate($videos->getCollection(), $request->user());

        return response()->json([
            'playlist' => (new PlaylistSummaryResource($playlist))->resolve($request),
            'videos' => VideoCardResource::collection($videos->getCollection())->resolve($request),
            'pagination' => [
                'page' => $videos->currentPage(), 'per_page' => $videos->perPage(), 'total' => $videos->total(),
                'last_page' => $videos->lastPage(), 'has_more' => $videos->hasMorePages(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, VideoAccessService $access): JsonResponse
    {
        $data = $this->validateCoverAccess($this->validated($request), $request, $access);
        $playlist = DB::transaction(function () use ($request, $data): Playlist {
            $owner = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            // Every registered account normally has a channel. Repair legacy accounts
            // without granting publishing privileges or creating a second identity.
            $channel = $owner->channel()->first();
            if (! $channel) {
                $channel = $owner->channel()->firstOrCreate(
                    ['user_id' => $owner->id],
                    ['name' => $owner->name, 'slug' => Slugs::unique($owner->username, Channel::class), 'bio' => null],
                );
            }

            $attributes = $data;
            unset($attributes['video_ids']);
            $playlist = Playlist::query()->create([
                ...$attributes,
                'user_id' => $owner->id,
                'channel_id' => $channel->id,
            ]);
            $this->syncVideos($playlist, $data);

            return $playlist;
        }, 3);

        return response()->json((new PlaylistSummaryResource($playlist->load(['channel.user', 'coverVideo'])->loadCount('videos')))->resolve($request), 201);
    }

    public function update(Request $request, Playlist $playlist, VideoAccessService $access): JsonResponse
    {
        $this->assertAllowed('update', $playlist);
        $data = $this->validateCoverAccess($this->validated($request, true), $request, $access);
        DB::transaction(function () use ($playlist, $data): void {
            $locked = Playlist::query()->lockForUpdate()->findOrFail($playlist->id);
            $attributes = $data;
            unset($attributes['video_ids']);
            $locked->update($attributes);
            $this->syncVideos($locked, $data);
        }, 3);

        $playlist = $playlist->fresh()->load([
            'channel.user',
            'coverVideo' => fn ($query) => $access->constrainViewable($query, $request->user()),
        ])->loadCount(['videos' => fn ($query) => $access->constrainViewable($query, $request->user())]);

        return response()->json((new PlaylistSummaryResource($playlist))->resolve($request));
    }

    public function destroy(Request $request, Playlist $playlist): JsonResponse
    {
        $this->assertAllowed('delete', $playlist);
        $playlist->delete();

        return response()->json([], 204);
    }

    public function addVideo(Request $request, Playlist $playlist, Video $video, VideoAccessService $access): JsonResponse
    {
        $this->assertAllowed('manageVideos', $playlist);
        $access->assertViewable($video, $request->user());

        DB::transaction(function () use ($playlist, $video): void {
            $lockedPlaylist = Playlist::query()->lockForUpdate()->findOrFail($playlist->id);
            $maximum = (int) DB::table('playlist_video')->where('playlist_id', $lockedPlaylist->id)->max('sort_order');
            $inserted = DB::table('playlist_video')->insertOrIgnore([
                'playlist_id' => $lockedPlaylist->id,
                'video_id' => $video->id,
                'sort_order' => $maximum + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted > 0) {
                $lockedPlaylist->touch();
            }
        }, 3);

        return response()->json(['playlist_id' => $playlist->id, 'video_id' => $video->id, 'included' => true]);
    }

    public function removeVideo(Request $request, Playlist $playlist, string $video): JsonResponse
    {
        $this->assertAllowed('manageVideos', $playlist);
        // Resolve only a membership in this owned playlist. A missing slug and an
        // unrelated private video must have the same idempotent response.
        $membership = DB::table('playlist_video')
            ->join('videos', 'videos.id', '=', 'playlist_video.video_id')
            ->where('playlist_video.playlist_id', $playlist->id)
            ->where('videos.slug', $video)
            ->select('playlist_video.video_id')
            ->first();
        $removed = $membership
            ? DB::table('playlist_video')->where('playlist_id', $playlist->id)->where('video_id', $membership->video_id)->delete()
            : 0;
        if ($removed > 0) {
            $playlist->touch();
        }

        return response()->json([
            'playlist_id' => $playlist->id,
            'video_id' => $membership ? (int) $membership->video_id : null,
            'included' => false,
        ]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes' : 'required';
        if (is_string($request->input('title'))) {
            $request->merge(['title' => trim($request->input('title'))]);
        }

        return $request->validate([
            'title' => [$prefix, 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'privacy' => [$prefix, Rule::in(['public', 'private', 'unlisted'])],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'cover_video_id' => ['nullable', 'integer', 'min:1'],
            'video_ids' => ['sometimes', 'array', 'max:1000'],
            'video_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
    }

    private function validateCoverAccess(array $data, Request $request, VideoAccessService $access): array
    {
        if (array_key_exists('video_ids', $data)) {
            $data['video_ids'] = array_map('intval', $data['video_ids']);
            $visible = $access->viewableQuery($request->user())->whereKey($data['video_ids'])->count();
            abort_unless($visible === count($data['video_ids']), 404, 'Video not found.');
            if (! empty($data['cover_video_id']) && ! in_array((int) $data['cover_video_id'], $data['video_ids'], true)) {
                throw ValidationException::withMessages(['cover_video_id' => 'Choose a cover from the selected videos.']);
            }
            if (! array_key_exists('cover_video_id', $data)) {
                $data['cover_video_id'] = $data['video_ids'][0] ?? null;
            }
        }
        if (array_key_exists('cover_video_id', $data) && $data['cover_video_id'] !== null) {
            $access->assertViewable(Video::query()->findOrFail($data['cover_video_id']), $request->user());
        }

        return $data;
    }

    private function syncVideos(Playlist $playlist, array $data): void
    {
        if (array_key_exists('video_ids', $data)) {
            $playlist->videos()->sync(collect($data['video_ids'])->mapWithKeys(fn ($id, $index) => [$id => ['sort_order' => $index]])->all());
            $playlist->touch();
        }
    }

    private function assertAllowed(string $ability, Playlist $playlist): void
    {
        abort_unless(Gate::allows($ability, $playlist), 404, 'Playlist not found.');
    }
}
