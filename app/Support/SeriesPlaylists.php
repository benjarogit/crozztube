<?php

namespace App\Support;

use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Policies\PlaylistPolicy;
use App\Services\Api\V1\VideoAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Episode metadata belongs to a playlist membership; sort_order is the only order. */
final class SeriesPlaylists
{
    public static function revision(Playlist $playlist): string
    {
        $series = (bool) Playlist::query()->whereKey($playlist->id)->value('is_series');
        $rows = DB::table('playlist_video')->where('playlist_id', $playlist->id)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['video_id', 'sort_order', 'season_number', 'episode_number', 'episode_title']);

        return hash('sha256', json_encode([$series, $rows->toArray()], JSON_THROW_ON_ERROR));
    }

    public static function update(User $actor, Playlist $playlist, array $input): array
    {
        abort_unless((new PlaylistPolicy)->update($actor, $playlist), 403);
        $data = Validator::make($input, [
            'expected_revision' => ['required', 'string', 'size:64'],
            'is_series' => ['sometimes', 'boolean'],
            'episodes' => ['sometimes', 'array', 'max:1000'],
            'episodes.*.video_id' => ['required', 'integer', 'min:1', 'distinct'],
            'episodes.*.season_number' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'episodes.*.episode_number' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'episodes.*.episode_title' => ['nullable', 'string', 'max:160'],
        ])->validate();

        return DB::transaction(function () use ($actor, $playlist, $data): array {
            $locked = Playlist::query()->lockForUpdate()->findOrFail($playlist->id);
            abort_unless((new PlaylistPolicy)->update($actor, $locked), 403);
            abort_unless(hash_equals(self::revision($locked), $data['expected_revision']), 409, 'Playlist changed. Reload before saving.');
            $rows = DB::table('playlist_video')->where('playlist_id', $locked->id)->lockForUpdate()->get()->keyBy('video_id');
            foreach ($data['episodes'] ?? [] as $episode) {
                $row = $rows->get((int) $episode['video_id']);
                if (! $row) throw ValidationException::withMessages(['episodes' => 'Choose videos from this playlist.']);
                foreach (['season_number', 'episode_number', 'episode_title'] as $key) {
                    if (array_key_exists($key, $episode)) $row->{$key} = $episode[$key];
                }
                if ($row->episode_title !== null) $row->episode_title = trim($row->episode_title) ?: null;
            }
            $numbers = [];
            foreach ($rows as $row) {
                if (($row->season_number === null) !== ($row->episode_number === null)) {
                    throw ValidationException::withMessages(['episodes' => 'Set both season and episode numbers, or leave both empty.']);
                }
                if ($row->season_number !== null) {
                    $key = (int) $row->season_number.':'.(int) $row->episode_number;
                    if (isset($numbers[$key])) throw ValidationException::withMessages(['episodes' => 'An episode number can only appear once in each season.']);
                    $numbers[$key] = true;
                }
            }
            // Clear the numbering first so an atomic swap cannot collide with the unique index.
            if (! empty($data['episodes'])) {
                DB::table('playlist_video')->where('playlist_id', $locked->id)->update(['season_number' => null, 'episode_number' => null]);
                foreach ($rows as $row) {
                    DB::table('playlist_video')->where('id', $row->id)->update([
                        'season_number' => $row->season_number === null ? null : (int) $row->season_number,
                        'episode_number' => $row->episode_number === null ? null : (int) $row->episode_number,
                        'episode_title' => $row->episode_title,
                        'updated_at' => now(),
                    ]);
                }
            }
            if (array_key_exists('is_series', $data)) $locked->is_series = (bool) $data['is_series'];
            $locked->save();

            return self::payload($locked, $actor);
        }, 3);
    }

    public static function payload(Playlist $playlist, ?User $viewer): array
    {
        // Serialize the metadata/revision snapshot with every playlist writer.
        // Otherwise an intervening edit could pair old fields with a new token.
        return DB::transaction(function () use ($playlist, $viewer): array {
            $playlist = Playlist::query()->lockForUpdate()->find($playlist->id);
            abort_unless($playlist && (new PlaylistPolicy)->view($viewer, $playlist), 404);
            $manageable = $viewer && (new PlaylistPolicy)->update($viewer, $playlist);
            $episodes = [];
            if ($playlist->is_series || $manageable) {
                $videos = $playlist->videos()->orderByPivot('id')->without('thumbnailMirrors')->with('user')->get();
                foreach ($videos as $video) {
                    $available = self::available($video, $viewer);
                    if (! $available && ! $manageable) continue;
                    $episodes[] = self::episode($video, $available);
                }
            }
            $seasons = [];
            foreach ($episodes as $episode) {
                $number = $episode['season_number'];
                if ($number === null) continue;
                $seasons[$number] ??= ['number' => $number, 'video_ids' => []];
                $seasons[$number]['video_ids'][] = $episode['video_id'];
            }

            return [
                'playlist_id' => (int) $playlist->id,
                'is_series' => (bool) $playlist->is_series,
                'series_revision' => $manageable ? self::revision($playlist) : null,
                'can_manage_series' => (bool) $manageable,
                'episodes' => $episodes,
                'seasons' => array_values($seasons),
            ];
        }, 3);
    }

    public static function next(Playlist $playlist, ?User $viewer, int $afterVideoId, array $queueIds = []): ?array
    {
        $playlist = $playlist->fresh();
        abort_unless($playlist && (new PlaylistPolicy)->view($viewer, $playlist), 404);
        if (! $playlist->is_series) return null;
        $videos = $playlist->videos()->orderByPivot('id')->without('thumbnailMirrors')->with('user')->get();
        $current = $videos->firstWhere('id', $afterVideoId);
        abort_unless($current && self::available($current, $viewer), 404);
        Validator::make(['queue' => $queueIds], [
            'queue' => ['array', 'max:50'],
            'queue.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])->validate();
        $queued = $queueIds ? Video::query()->without('thumbnailMirrors')->with('user')->whereKey($queueIds)->get()->keyBy('id') : collect();
        foreach ($queueIds as $id) {
            $id = (int) $id;
            if ($id === $afterVideoId) continue;
            $candidate = $queued->get($id);
            if ($candidate && self::available($candidate, $viewer)) {
                $candidate = $videos->firstWhere('id', $id) ?? $candidate;

                return [...self::episode($candidate, true), 'from_queue' => true];
            }
        }
        $found = false;
        foreach ($videos as $video) {
            if ((int) $video->id === $afterVideoId) { $found = true; continue; }
            if ($found && self::available($video, $viewer)) return [...self::episode($video, true), 'from_queue' => false];
        }

        return null;
    }

    private static function available(Video $video, ?User $viewer): bool
    {
        return (! $viewer || ! $viewer->isBannedFrom('watch'))
            && $video->status === 'ready'
            && (! $video->approval_status || $video->approval_status === 'approved')
            && (! $video->scheduled_at || ! $video->scheduled_at->isFuture())
            && (new VideoAccessService)->canView($video, $viewer)
            && VideoSales::hasAccess($video, $viewer);
    }

    private static function episode(Video $video, bool $available): array
    {
        $pivot = $video->pivot;

        return [
            'video_id' => (int) $video->id,
            'sort_order' => $pivot === null ? null : (int) $pivot->sort_order,
            'season_number' => $pivot?->season_number === null ? null : (int) $pivot->season_number,
            'episode_number' => $pivot?->episode_number === null ? null : (int) $pivot->episode_number,
            'episode_title' => $pivot?->episode_title,
            'available' => $available,
            'video' => $available ? ['id' => (int) $video->id, 'slug' => $video->slug, 'title' => $video->title, 'duration_seconds' => $video->duration_seconds] : null,
        ];
    }
}
