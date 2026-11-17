<?php

/**
 * Cross-process lock check for series episode saves.
 *
 * The fixture exists only for the duration of this script and is removed in a
 * finally block. The parent holds the playlist row lock while a second PHP
 * process calls applyEpisodes(); the child must wait for that lock instead of
 * interleaving its pivot writes. No existing user data is touched.
 */

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

$pass = $fail = 0;
function check(bool $condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

$suffix = bin2hex(random_bytes(5));
$root = dirname(__DIR__, 3);
$childFile = sys_get_temp_dir().'/series-lock-child-'.$suffix.'.php';
$startedFile = sys_get_temp_dir().'/series-lock-started-'.$suffix;
$childOutput = '';
$childExit = null;
$owner = null;
$channel = null;
$videos = [];
$playlist = null;

$childCode = <<<'PHP'
<?php
[$root, $playlistId, $startedFile, $episodes] = [$argv[1], (int) $argv[2], $argv[3], json_decode($argv[4], true)];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$playlist = App\Models\Playlist::findOrFail($playlistId);
file_put_contents($startedFile, '1');
App\Support\SeriesStructure::applyEpisodes($playlist, $episodes);
echo "done\n";
PHP;

try {
    $owner = User::create([
        'name' => 'Series lock '.$suffix,
        'username' => 'series-lock-'.$suffix,
        'email' => 'series-lock-'.$suffix.'@example.test',
        'password' => bcrypt('not-a-real-password'),
    ]);
    $channel = Channel::create([
        'user_id' => $owner->id,
        'name' => 'Series lock channel '.$suffix,
        'slug' => 'series-lock-channel-'.$suffix,
    ]);

    $makeVideo = static function (string $name) use ($owner, $channel, $suffix): Video {
        return Video::create([
            'user_id' => $owner->id,
            'channel_id' => $channel->id,
            'title' => 'Series lock '.$name,
            'slug' => 'series-lock-'.strtolower(str_replace(' ', '-', $name)).'-'.$suffix,
            'video_path' => 'videos/series-lock-'.$suffix.'.mp4',
            'status' => 'ready',
            'visibility' => 'public',
        ]);
    };

    $videos = [$makeVideo('one'), $makeVideo('two'), $makeVideo('three')];
    $playlist = Playlist::create([
        'user_id' => $owner->id,
        'channel_id' => $channel->id,
        'title' => 'Series lock '.$suffix,
        'privacy' => 'public',
        'kind' => 'series',
        'series_status' => 'ongoing',
    ]);
    foreach ($videos as $index => $video) {
        $playlist->videos()->attach($video->id, [
            'sort_order' => $index,
            'season_number' => 1,
            'episode_number' => $index + 1,
        ]);
    }

    file_put_contents($childFile, $childCode);
    $episodes = json_encode([
        ['video_id' => $videos[2]->id, 'season_number' => 1, 'episode_number' => 1],
        ['video_id' => $videos[1]->id, 'season_number' => 1, 'episode_number' => 2],
        ['video_id' => $videos[0]->id, 'season_number' => 1, 'episode_number' => 3],
    ], JSON_THROW_ON_ERROR);

    $process = proc_open(
        [PHP_BINARY, $childFile, $root, (string) $playlist->id, $startedFile, $episodes],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );
    check(is_resource($process), 'the concurrent editor process starts');

    DB::beginTransaction();
    DB::table('playlists')->where('id', $playlist->id)->lockForUpdate()->first();

    $deadline = microtime(true) + 15;
    while (! file_exists($startedFile) && microtime(true) < $deadline) {
        usleep(50_000);
    }
    check(file_exists($startedFile), 'the second editor reaches the save call');
    usleep(400_000);
    $status = is_resource($process) ? proc_get_status($process) : null;
    $waited = (bool) ($status['running'] ?? false);
    check($waited, 'the second editor waits for the playlist row lock');

    DB::commit();

    if (is_resource($process)) {
        // Read without blocking and cap the wait, so a stuck child fails the
        // suite instead of hanging it.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            if (! (proc_get_status($process)['running'] ?? false)) {
                break;
            }
            usleep(50_000);
        }
        $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        if (proc_get_status($process)['running'] ?? false) {
            proc_terminate($process);
        }
        $childOutput = $output;
        $childExit = proc_close($process);
    }
    check($childExit === 0, 'the second editor completes after the lock is released');
    check(str_contains($childOutput, 'done'), 'the second editor reports a completed save');

    $stored = DB::table('playlist_video')
        ->where('playlist_id', $playlist->id)
        ->orderBy('sort_order')
        ->pluck('video_id')
        ->map(static fn ($id) => (int) $id)
        ->all();
    check($stored === [$videos[2]->id, $videos[1]->id, $videos[0]->id], 'the serialized save writes one complete episode order');
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if ($playlist?->exists) {
        DB::table('playlist_video')->where('playlist_id', $playlist->id)->delete();
        $playlist->delete();
    }
    foreach ($videos as $video) {
        if ($video?->exists) {
            $video->delete();
        }
    }
    $channel?->delete();
    $owner?->delete();
    foreach ([$childFile, $startedFile] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

echo "Series concurrency checks: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
