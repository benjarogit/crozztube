<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Resources\PlaylistResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\SeriesStructure;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

$pass = $fail = 0;
function check(bool $condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

// --- Schema: series structure extends existing playlists -------------------
check(Schema::hasColumn('playlists', 'kind'), 'playlists gain a kind column');
check(Schema::hasColumn('playlists', 'series_status'), 'playlists gain a series status column');
foreach (['season_number', 'episode_number', 'episode_title', 'episode_description', 'aired_at'] as $column) {
    check(Schema::hasColumn('playlist_video', $column), "playlist_video gains {$column}");
}
check(Schema::hasColumns('playlist_video', ['sort_order', 'season_number', 'episode_number']), 'episode metadata sits beside the existing pivot order');

// --- Normalisation ---------------------------------------------------------
check(SeriesStructure::kind('series') === 'series', 'series kind is preserved');
check(SeriesStructure::kind('bogus') === 'playlist', 'unknown kinds fall back to playlist');
check(SeriesStructure::kind(null) === 'playlist', 'missing kind means playlist');
check(SeriesStructure::status('completed') === 'completed', 'completed status is preserved');
check(SeriesStructure::status('bogus') === 'ongoing', 'unknown status falls back to ongoing');
check(SeriesStructure::isSeries(['kind' => 'series']), 'array payloads can be recognised as series');
check(! SeriesStructure::isSeries(['kind' => 'playlist']), 'playlists are not series');
check(SeriesStructure::rules()['episodes.*.video_id'][0] === 'required', 'episodes require a video');
check(in_array('max:500', SeriesStructure::rules()['episodes.*.season_number'], true), 'seasons stay bounded');
check(in_array('max:10000', SeriesStructure::rules()['episodes.*.episode_number'], true), 'episodes stay bounded');

// --- Numbering, position synthesis and ordering ---------------------------
$pivotVideo = static function (int $id, string $slug, int $season, int|null $episode, int $sort, ?string $title = null, ?string $airedAt = null): Video {
    $video = new Video();
    $video->forceFill(['id' => $id, 'slug' => $slug, 'title' => "Video {$id}"]);
    $video->setRelation('pivot', new Pivot([
        'season_number' => $season,
        'episode_number' => $episode,
        'episode_title' => $title,
        'aired_at' => $airedAt,
        'sort_order' => $sort,
    ]));

    return $video;
};

$numbered = SeriesStructure::numberEpisodes([
    $pivotVideo(1, 'first', 1, null, 0, 'Pilot'),
    $pivotVideo(2, 'fifth', 1, 5, 1),
    $pivotVideo(3, 'second', 1, null, 2),
    $pivotVideo(4, 'other-season', 2, null, 3, 'Season two'),
]);
check(array_column($numbered, 'slug') === ['first', 'second', 'fifth', 'other-season'], 'episodes are ordered by effective number, not by raw input');
check(array_column($numbered, 'episode_number') === [1, 3, 5, 1], 'missing numbers keep their position while skipping explicit numbers');
check(array_column($numbered, 'season_number') === [1, 1, 1, 2], 'seasons stay grouped in ascending order');
check($numbered[0]['label'] === 'S1 E1' && $numbered[2]['label'] === 'S1 E5', 'labels carry season and episode');
check($numbered[0]['episode_title'] === 'Pilot' && $numbered[0]['title'] === 'Video 1', 'episode metadata keeps the authored title separate from the video title');
check($numbered[3]['aired_at'] === null, 'missing air dates stay empty');

$explicitFirst = SeriesStructure::numberEpisodes([
    $pivotVideo(10, 'explicit-last', 1, 3, 0),
    $pivotVideo(11, 'unnumbered', 1, null, 1),
]);
check(array_column($explicitFirst, 'episode_number') === [2, 3], 'synthesis never renumbers an explicit episode');

$implicit = SeriesStructure::numberEpisodes([
    $pivotVideo(20, 'a', 1, null, 0),
    $pivotVideo(21, 'b', 1, null, 1),
    $pivotVideo(22, 'c', 1, null, 2),
]);
check(array_column($implicit, 'episode_number') === [1, 2, 3], 'an unnumbered season numbers itself from its positions');

// --- Database: apply, read back and resolve access ------------------------
$request = Request::create('/api/playlists/1', 'GET');

DB::beginTransaction();
try {
    $suffix = bin2hex(random_bytes(5));
    $owner = User::create([
        'name' => 'Series check '.$suffix,
        'username' => 'series-check-'.$suffix,
        'email' => 'series-check-'.$suffix.'@example.test',
        'password' => bcrypt('not-a-real-password'),
    ]);
    $channel = Channel::create([
        'user_id' => $owner->id,
        'name' => 'Series check channel '.$suffix,
        'slug' => 'series-check-channel-'.$suffix,
    ]);

    $makeVideo = static function (string $name, array $overrides = []) use ($owner, $channel, $suffix): Video {
        return Video::create([
            'user_id' => $owner->id,
            'channel_id' => $channel->id,
            'title' => 'Series check '.$name,
            'slug' => 'series-check-'.strtolower(str_replace(' ', '-', $name)).'-'.$suffix,
            'video_path' => 'videos/series-check-'.$suffix.'.mp4',
            'status' => 'ready',
            'visibility' => 'public',
            ...$overrides,
        ]);
    };

    $episodeOne = $makeVideo('one');
    $episodeTwo = $makeVideo('two');
    $episodeThree = $makeVideo('three');
    $episodeFour = $makeVideo('four');

    $playlist = Playlist::create([
        'user_id' => $owner->id,
        'channel_id' => $channel->id,
        'title' => 'Series check '.$suffix,
        'privacy' => 'public',
    ]);
    check($playlist->fresh()->kind === 'playlist', 'existing playlists keep the default kind');
    check($playlist->fresh()->series_status === null, 'playlists do not pretend to have a series status');

    $playlist->videos()->attach($episodeOne->id, ['sort_order' => 0]);
    $playlist->videos()->attach($episodeTwo->id, ['sort_order' => 1]);
    $playlist->videos()->attach($episodeThree->id, ['sort_order' => 2]);
    $playlist->videos()->attach($episodeFour->id, ['sort_order' => 3]);

    $playlist->forceFill(['kind' => 'series', 'series_status' => 'ongoing'])->save();
    check($playlist->fresh()->isSeries(), 'a playlist converts into a series in place');

    SeriesStructure::applyEpisodes($playlist, [
        ['video_id' => $episodeTwo->id, 'season_number' => 1, 'episode_title' => 'Second in the list'],
        ['video_id' => $episodeOne->id, 'season_number' => 1, 'episode_number' => 2, 'episode_title' => 'Explicit two'],
        ['video_id' => $episodeThree->id, 'season_number' => 2, 'episode_title' => 'Next season'],
        ['video_id' => $episodeFour->id, 'season_number' => 1],
    ]);

    $pivots = DB::table('playlist_video')->where('playlist_id', $playlist->id)->orderBy('sort_order')->get();
    check($pivots->pluck('video_id')->map(fn ($id) => (int) $id)->all() === [$episodeTwo->id, $episodeOne->id, $episodeFour->id, $episodeThree->id], 'submitted order and explicit numbers decide the stored order');
    check($pivots->pluck('episode_number')->map(fn ($number) => $number === null ? null : (int) $number)->all() === [1, 2, 3, 1], 'effective numbers are stored on the pivot');
    check($pivots->pluck('sort_order')->map(fn ($order) => (int) $order)->all() === [0, 1, 2, 3], 'sort order is rewritten to match the episodes');
    check($pivots->firstWhere('video_id', $episodeTwo->id)->episode_title === 'Second in the list', 'episode titles are stored on the pivot');
    check($pivots->firstWhere('video_id', $episodeThree->id)->season_number === 2, 'season numbers are stored on the pivot');

    $playlist = $playlist->fresh()->load('videos');
    $readBack = SeriesStructure::episodes($playlist);
    check(array_column($readBack, 'video_id') === [$episodeTwo->id, $episodeOne->id, $episodeFour->id, $episodeThree->id], 'reading the series reproduces the stored order');
    check(SeriesStructure::seasons($playlist)[0]['episode_count'] === 3 && count(SeriesStructure::seasons($playlist)) === 2, 'seasons group the episodes');

    $resource = (new PlaylistResource($playlist))->resolve($request);
    check($resource['kind'] === 'series' && $resource['series_status'] === 'ongoing', 'the playlist resource exposes the series kind and status');
    check($resource['series']['season_count'] === 2 && $resource['series']['episode_count'] === 4, 'the resource reports seasons and episode counts');
    $resourceTwo = collect($resource['videos'])->firstWhere('id', $episodeTwo->id);
    check(($resourceTwo['episode_label'] ?? null) === 'S1 E1' && ($resourceTwo['episode_title'] ?? null) === 'Second in the list', 'each video keeps its episode context in the resource');

    $plain = Playlist::create([
        'user_id' => $owner->id,
        'channel_id' => $channel->id,
        'title' => 'Plain playlist '.$suffix,
        'privacy' => 'public',
    ]);
    $plain->videos()->attach($episodeOne->id, ['sort_order' => 0]);
    $plainResource = (new PlaylistResource($plain->fresh()->load('videos')))->resolve($request);
    check($plainResource['kind'] === 'playlist' && $plainResource['series'] === null, 'plain playlists stay plain in the resource');
    check(! isset($plainResource['videos'][0]['episode_label']), 'plain playlist videos do not invent episode fields');

    // Access-aware next episode: private and scheduled episodes are skipped
    // for viewers who cannot open them, while the owner keeps access.
    $accessSeries = Playlist::create([
        'user_id' => $owner->id,
        'channel_id' => $channel->id,
        'title' => 'Access series '.$suffix,
        'privacy' => 'public',
        'kind' => 'series',
        'series_status' => 'ongoing',
    ]);
    $visibleFirst = $makeVideo('visible first');
    $privateSecond = $makeVideo('private second', ['visibility' => 'private']);
    $scheduledThird = $makeVideo('scheduled third', ['scheduled_at' => now()->addDay()]);
    $visibleFourth = $makeVideo('visible fourth');
    foreach ([$visibleFirst, $privateSecond, $scheduledThird, $visibleFourth] as $index => $video) {
        $accessSeries->videos()->attach($video->id, ['sort_order' => $index, 'season_number' => 1, 'episode_number' => $index + 1]);
    }
    $accessSeries = $accessSeries->fresh()->load('videos');

    check(SeriesStructure::nextEpisode($accessSeries, $visibleFirst->slug, null)['video_id'] === $visibleFourth->id, 'anonymous next episode skips private and scheduled episodes');
    check(SeriesStructure::nextEpisode($accessSeries, $visibleFirst->slug, $owner)['video_id'] === $privateSecond->id, 'the owner keeps access to their own next episode');
    check(SeriesStructure::nextEpisode($accessSeries, null, null)['video_id'] === $visibleFirst->id, 'without a current episode the first accessible episode starts');
    check(SeriesStructure::nextEpisode($accessSeries, $visibleFourth->slug, null) === null, 'the last episode has no next episode');
    check(SeriesStructure::nextEpisode($accessSeries, 'not-in-this-series', null)['video_id'] === $visibleFirst->id, 'an outside slug restarts at the first accessible episode');
    check(SeriesStructure::previousEpisode($accessSeries, $visibleFourth->slug, null)['video_id'] === $visibleFirst->id, 'previous episode applies the same access rule');
    check(SeriesStructure::previousEpisode($accessSeries, $visibleFirst->slug, null) === null, 'the first episode has no previous episode');

    $payload = SeriesStructure::payload($accessSeries, null, $visibleFirst->slug);
    check($payload['next']['video_id'] === $visibleFourth->id && $payload['current']['video_id'] === $visibleFirst->id, 'the watch payload carries current and next episode');
    check($payload['season_count'] === 1 && $payload['episode_count'] === 4, 'the watch payload counts seasons and episodes');
} finally {
    DB::rollBack();
}

// --- Routes -----------------------------------------------------------------
$routes = collect(Route::getRoutes()->getRoutes());
$nextEpisodeRoute = $routes->first(fn ($route) => $route->uri() === 'api/playlists/{playlist}/next-episode');
check($nextEpisodeRoute !== null, 'the next-episode endpoint is registered');
check($nextEpisodeRoute && in_array('GET', $nextEpisodeRoute->methods(), true), 'the next-episode endpoint is a GET route');

// --- Reviewed copy ------------------------------------------------------------
$batchPath = __DIR__.'/../../i18n/batches/series-and-seasons.json';
check(is_file($batchPath), 'the series copy batch exists');
if (is_file($batchPath)) {
    $batch = json_decode(file_get_contents($batchPath), true, 512, JSON_THROW_ON_ERROR);
    check(trim((string) ($batch['context'] ?? '')) !== '', 'the series copy batch names its review context');
    foreach (['en', 'de_de'] as $locale) {
        $missing = [];
        foreach ($batch['messages'] as $key => $values) {
            if (! isset($values[$locale]) || trim((string) $values[$locale]) === '') $missing[] = $key;
        }
        check($missing === [], "the series copy batch covers {$locale}");
    }
    $formal = [];
    foreach ($batch['messages'] as $key => $values) {
        $german = (string) ($values['de_de'] ?? '');
        if (preg_match('/\b(Sie|Ihnen|Ihrer)\b/u', $german)) $formal[] = $key;
    }
    check($formal === [], 'the German series copy keeps the personal address');
}

echo "Series checks: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
