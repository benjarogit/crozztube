<?php

namespace App\Support;

use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Season and episode structure layered on top of an existing playlist.
 *
 * A series never owns videos; it is a playlist with `kind = series` plus
 * episode metadata on the playlist_video pivot. That keeps membership,
 * privacy, collaboration and ordering in the place the platform already
 * uses, and existing playlists stay valid when they are converted.
 */
final class SeriesStructure
{
    public const KIND_PLAYLIST = 'playlist';
    public const KIND_SERIES = 'series';

    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_COMPLETED = 'completed';

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_PLAYLIST, self::KIND_SERIES];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_ONGOING, self::STATUS_COMPLETED];
    }

    public static function kind(mixed $value): string
    {
        return in_array($value, self::kinds(), true) ? (string) $value : self::KIND_PLAYLIST;
    }

    public static function status(mixed $value): string
    {
        return in_array($value, self::statuses(), true) ? (string) $value : self::STATUS_ONGOING;
    }

    public static function isSeries(Playlist|array|null $playlist): bool
    {
        $kind = $playlist instanceof Playlist ? $playlist->kind : data_get($playlist, 'kind');

        return self::kind($kind) === self::KIND_SERIES;
    }

    /**
     * Ordered episodes with a stable, effective episode number.
     *
     * An editor may leave the episode number empty; the current position inside
     * the season then supplies it. Explicit numbers always win, so re-ordering a
     * playlist cannot renumber a published episode. The final order is the
     * effective episode number, which keeps synthesized numbers in place even
     * when an explicit number sits next to them.
     *
     * @return list<array{video_id: int, slug: string, title: string, season_number: int, episode_number: int, episode_title: ?string, episode_description: ?string, aired_at: ?string, label: string, sort_order: int}>
     */
    public static function episodes(Playlist $playlist): array
    {
        $videos = $playlist->relationLoaded('videos')
            ? $playlist->videos
            : $playlist->videos()->get();

        return self::numberEpisodes($videos);
    }

    /**
     * @param  iterable<int, Video>  $videos
     * @return list<array{video_id: int, slug: string, title: string, season_number: int, episode_number: int, episode_title: ?string, episode_description: ?string, aired_at: ?string, label: string, sort_order: int}>
     */
    public static function numberEpisodes(iterable $videos): array
    {
        $bySeason = [];
        foreach ($videos as $video) {
            $season = max(1, (int) ($video->pivot->season_number ?? 1));
            $bySeason[$season][] = $video;
        }

        ksort($bySeason);
        $episodes = [];

        foreach ($bySeason as $season => $seasonVideos) {
            usort($seasonVideos, static function (Video $left, Video $right): int {
                return [(int) ($left->pivot?->sort_order ?? 0), (int) $left->id]
                    <=> [(int) ($right->pivot?->sort_order ?? 0), (int) $right->id];
            });

            $explicit = [];
            foreach ($seasonVideos as $index => $video) {
                $number = $video->pivot?->episode_number;
                $explicit[$index] = $number === null || $number === '' ? null : (int) $number;
            }
            $numbers = self::numberSeason($explicit);

            $seasonEpisodes = [];
            foreach ($seasonVideos as $index => $video) {
                $number = $numbers[$index];
                $airedAt = $video->pivot?->aired_at ?? null;
                $seasonEpisodes[] = [
                    'video_id' => (int) $video->id,
                    'slug' => (string) $video->slug,
                    'title' => (string) $video->title,
                    'season_number' => (int) $season,
                    'episode_number' => (int) $number,
                    'episode_title' => self::nullableString($video->pivot?->episode_title),
                    'episode_description' => self::nullableString($video->pivot?->episode_description),
                    'aired_at' => $airedAt ? (string) (is_object($airedAt) ? $airedAt->toIso8601String() : $airedAt) : null,
                    'label' => 'S'.$season.' E'.$number,
                    'sort_order' => (int) ($video->pivot?->sort_order ?? 0),
                ];
            }

            usort($seasonEpisodes, static function (array $left, array $right): int {
                return [$left['episode_number'], $left['sort_order'], $left['video_id']]
                    <=> [$right['episode_number'], $right['sort_order'], $right['video_id']];
            });

            foreach ($seasonEpisodes as $episode) {
                $episodes[] = $episode;
            }
        }

        return $episodes;
    }

    /**
     * Assign effective episode numbers inside one season.
     *
     * The caller passes the season in display order (the pivot position) and
     * gets the same keys back with a number. A missing number starts at that
     * position and moves to the next free slot, which keeps the editor's list
     * order instead of pulling an unnumbered episode to the front. Explicit
     * numbers are reserved first, so a synthesized number never collides with
     * an authored one.
     *
     * @param  array<int, int|null>  $explicit
     * @return array<int, int>
     */
    private static function numberSeason(array $explicit): array
    {
        $taken = [];
        foreach ($explicit as $number) {
            if ($number !== null) {
                $taken[max(1, $number)] = true;
            }
        }

        $numbers = [];
        $position = 0;
        foreach ($explicit as $key => $number) {
            $position++;
            if ($number !== null) {
                $numbers[$key] = max(1, $number);
                continue;
            }
            $candidate = $position;
            while (isset($taken[$candidate])) {
                $candidate++;
            }
            $numbers[$key] = $candidate;
            $taken[$candidate] = true;
        }

        return $numbers;
    }

    /**
     * Episodes grouped by season for the playlist and watch surfaces.
     *
     * @return list<array{number: int, episode_count: int, episodes: list<array<string, mixed>>}>
     */
    public static function seasons(Playlist $playlist): array
    {
        $seasons = [];
        foreach (self::episodes($playlist) as $episode) {
            $seasons[$episode['season_number']][] = $episode;
        }

        $grouped = [];
        foreach ($seasons as $number => $episodes) {
            $grouped[] = [
                'number' => (int) $number,
                'episode_count' => count($episodes),
                'episodes' => array_values($episodes),
            ];
        }

        return $grouped;
    }

    /**
     * Next episode the viewer may actually watch.
     *
     * Access uses the same rule as the player, so scheduled, private, removed
     * or otherwise blocked episodes are skipped instead of dead-ending the
     * viewer. `$currentSlug` null starts at the first accessible episode.
     *
     * @return array<string, mixed>|null
     */
    public static function nextEpisode(Playlist $playlist, ?string $currentSlug, ?User $viewer = null): ?array
    {
        $episodes = self::episodes($playlist);
        if ($episodes === []) {
            return null;
        }

        $videos = $playlist->videos()->with('user')->get()->keyBy('id');
        $access = app(VideoAccessService::class);
        $currentIndex = null;
        if ($currentSlug !== null && $currentSlug !== '') {
            foreach ($episodes as $index => $episode) {
                if ($episode['slug'] === $currentSlug) {
                    $currentIndex = $index;
                    break;
                }
            }
        }

        $start = $currentIndex === null ? 0 : $currentIndex + 1;
        foreach (array_slice($episodes, $start) as $episode) {
            $video = $videos->get($episode['video_id']);
            if ($video instanceof Video && $access->canView($video, $viewer)) {
                return $episode;
            }
        }

        return null;
    }

    /**
     * Previous episode the viewer may watch, mirroring nextEpisode().
     *
     * @return array<string, mixed>|null
     */
    public static function previousEpisode(Playlist $playlist, ?string $currentSlug, ?User $viewer = null): ?array
    {
        $episodes = self::episodes($playlist);
        $currentIndex = null;
        if ($currentSlug !== null && $currentSlug !== '') {
            foreach ($episodes as $index => $episode) {
                if ($episode['slug'] === $currentSlug) {
                    $currentIndex = $index;
                    break;
                }
            }
        }
        if ($currentIndex === null || $currentIndex === 0) {
            return null;
        }

        $videos = $playlist->videos()->with('user')->get()->keyBy('id');
        $access = app(VideoAccessService::class);
        foreach (array_reverse(array_slice($episodes, 0, $currentIndex)) as $episode) {
            $video = $videos->get($episode['video_id']);
            if ($video instanceof Video && $access->canView($video, $viewer)) {
                return $episode;
            }
        }

        return null;
    }

    /**
     * Persist episode metadata and make the playlist order match it.
     *
     * @param  array<int, array<string, mixed>>  $episodes
     */
    public static function applyEpisodes(Playlist $playlist, array $episodes): void
    {
        if ($episodes === []) {
            return;
        }

        $attached = DB::table('playlist_video')
            ->where('playlist_id', $playlist->id)
            ->get()
            ->keyBy('video_id');

        $bySeason = [];
        foreach ($episodes as $episode) {
            $videoId = (int) ($episode['video_id'] ?? 0);
            if (! $attached->has($videoId)) {
                continue;
            }

            $season = max(1, min(500, (int) ($episode['season_number'] ?? 1)));
            $bySeason[$season][] = [
                'video_id' => $videoId,
                'season_number' => $season,
                'episode_number' => isset($episode['episode_number']) && $episode['episode_number'] !== ''
                    ? max(1, min(10000, (int) $episode['episode_number']))
                    : null,
                'episode_title' => self::nullableString($episode['episode_title'] ?? null),
                'episode_description' => self::nullableString($episode['episode_description'] ?? null),
                'aired_at' => ! empty($episode['aired_at']) ? $episode['aired_at'] : null,
            ];
        }

        ksort($bySeason);
        $rows = [];
        foreach ($bySeason as $seasonRows) {
            $explicit = [];
            foreach ($seasonRows as $index => $row) {
                $explicit[$index] = $row['episode_number'];
            }
            $numbers = self::numberSeason($explicit);
            foreach ($seasonRows as $index => $row) {
                $row['episode_number'] = $numbers[$index];
                // The submitted order decides where an unnumbered episode sits,
                // the effective number decides the final order.
                $row['position'] = $index;
                $rows[] = $row;
            }
        }

        usort($rows, static function (array $left, array $right): int {
            return [$left['season_number'], $left['episode_number'], $left['position']]
                <=> [$right['season_number'], $right['episode_number'], $right['position']];
        });

        $order = 0;
        foreach ($rows as $row) {
            $position = $row['position'];
            unset($row['position']);
            DB::table('playlist_video')
                ->where('playlist_id', $playlist->id)
                ->where('video_id', $row['video_id'])
                ->update([...$row, 'sort_order' => $order++, 'updated_at' => now()]);
        }

        $playlist->touch();
    }

    /**
     * Watch-page series payload: membership, seasons and the resolved next
     * episode for this viewer.
     *
     * @return array<string, mixed>
     */
    public static function payload(Playlist $playlist, ?User $viewer, ?string $currentSlug): array
    {
        $episodes = self::episodes($playlist);
        $current = null;
        foreach ($episodes as $episode) {
            if ($episode['slug'] === $currentSlug) {
                $current = $episode;
                break;
            }
        }

        return [
            'id' => (int) $playlist->id,
            'title' => (string) $playlist->title,
            'kind' => self::kind($playlist->kind),
            'status' => self::status($playlist->series_status),
            'season_count' => count(self::seasons($playlist)),
            'episode_count' => count($episodes),
            'current' => $current,
            'next' => self::nextEpisode($playlist, $currentSlug, $viewer),
            'seasons' => self::seasons($playlist),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'kind' => ['nullable', 'string', Rule::in(self::kinds())],
            'series_status' => ['nullable', 'string', Rule::in(self::statuses())],
            'episodes' => ['nullable', 'array'],
            'episodes.*.video_id' => ['required', 'integer'],
            'episodes.*.season_number' => ['nullable', 'integer', 'min:1', 'max:500'],
            'episodes.*.episode_number' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'episodes.*.episode_title' => ['nullable', 'string', 'max:160'],
            'episodes.*.episode_description' => ['nullable', 'string', 'max:1000'],
            'episodes.*.aired_at' => ['nullable', 'date'],
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 1000);
    }
}
