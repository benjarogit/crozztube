<?php

// Included only after the guarded, fresh MySQL fixture has passed its 69 checks.
// Each child opens its own PDO connection; performance_schema proves a real
// InnoDB row-lock wait before the controlling transaction is released.
if (! isset($mysql) || ! $mysql || getenv('SERIES_TEST_MYSQL') !== '1') {
    throw new RuntimeException('Run the isolated step-08-series.sh --mysql command.');
}
if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) throw new RuntimeException('Parallel test process support is required.');

use App\Models\Playlist;
use App\Models\User;
use App\Support\SeriesPlaylists;
use App\Support\SharedPlaylists;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

$parallelChecks = 0;
$assertParallel = static function (bool $ok, string $message) use (&$parallelChecks): void {
    if (! $ok) throw new RuntimeException($message);
    $parallelChecks++;
};
$owner = User::findOrFail(1);
$series = Playlist::create(['user_id' => 1, 'title' => 'Isolated MySQL concurrency', 'privacy' => 'public']);
$series->videos()->attach([1 => ['sort_order' => 0], 2 => ['sort_order' => 1]]);
SeriesPlaylists::update($owner, $series, ['expected_revision' => SeriesPlaylists::revision($series), 'is_series' => true,
    'episodes' => [['video_id' => 1, 'season_number' => 1, 'episode_number' => 1], ['video_id' => 2, 'season_number' => 1, 'episode_number' => 2]]]);

$read = static function ($socket): array {
    stream_set_timeout($socket, 10);
    $line = fgets($socket);
    if ($line === false) throw new RuntimeException('Parallel fixture peer timed out or exited.');
    return json_decode($line, true, 32, JSON_THROW_ON_ERROR);
};
$send = static function ($socket, array $value): void { fwrite($socket, json_encode($value, JSON_THROW_ON_ERROR)."\n"); };
$pair = static function (callable $first, callable $second, bool $rollback = false) use ($read, $send, $assertParallel): array {
    DB::disconnect(); // Never share a live PDO socket across fork().
    [$aParent, $aChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $children = [];
    $a = pcntl_fork();
    if ($a === -1) throw new RuntimeException('Could not start first database test process.');
    if ($a === 0) {
        fclose($aParent);
        pcntl_async_signals(true); pcntl_signal(SIGALRM, static fn () => exit(124)); pcntl_alarm(12);
        DB::purge();
        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout=5');
            $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            DB::transaction(static function () use ($first, $rollback, $aChild, $read, $send, $connection): void {
                $first();
                $send($aChild, ['phase' => 'locked', 'connection' => $connection]);
                if (($read($aChild)['phase'] ?? '') !== 'release') throw new RuntimeException('Missing release signal.');
                if ($rollback) throw new RuntimeException('Controlled transaction rollback');
            });
            $send($aChild, ['status' => 200]);
        } catch (Throwable $error) {
            $send($aChild, ['status' => $error instanceof HttpException ? $error->getStatusCode() : 500, 'error_class' => get_class($error)]);
        }
        fclose($aChild); exit(0);
    }
    $children[] = $a; fclose($aChild); $bParent = null;
    try {
        $firstReady = $read($aParent);
        $assertParallel(($firstReady['phase'] ?? '') === 'locked', 'First transaction must reach its held lock.');
        [$bParent, $bChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $b = pcntl_fork();
        if ($b === -1) throw new RuntimeException('Could not start second database test process.');
        if ($b === 0) {
            fclose($bParent); fclose($aParent);
            pcntl_async_signals(true); pcntl_signal(SIGALRM, static fn () => exit(124)); pcntl_alarm(12);
            DB::purge();
            try {
                DB::statement('SET SESSION innodb_lock_wait_timeout=5');
                $send($bChild, ['phase' => 'started', 'connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
                $value = $second();
                $send($bChild, ['status' => 200, 'value' => $value]);
            } catch (Throwable $error) {
                $send($bChild, ['status' => $error instanceof HttpException ? $error->getStatusCode() : 500, 'error_class' => get_class($error)]);
            }
            fclose($bChild); exit(0);
        }
        $children[] = $b; fclose($bChild);
        $secondReady = $read($bParent);
        $assertParallel(($secondReady['phase'] ?? '') === 'started', 'Second connection must start.');
        $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $assertParallel(count(array_unique([$firstReady['connection'], $secondReady['connection'], $parentId])) === 3, 'Use three independent MySQL connections.');
        $deadline = microtime(true) + 3;
        do {
            $waiting = (int) DB::selectOne('SELECT COUNT(*) AS waiting FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID=?', [$secondReady['connection']])->waiting;
            if ($waiting > 0) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
        $assertParallel($waiting > 0, 'MySQL must report an actual InnoDB row-lock wait.');
        $send($aParent, ['phase' => 'release']);
        return [$read($aParent), $read($bParent)];
    } finally {
        if (is_resource($aParent)) { @fwrite($aParent, "{\"phase\":\"release\"}\n"); fclose($aParent); }
        if (is_resource($bParent)) fclose($bParent);
        foreach ($children as $pid) {
            $deadline = microtime(true) + 3;
            do { $done = pcntl_waitpid($pid, $status, WNOHANG); if ($done !== 0) break; usleep(20000); } while (microtime(true) < $deadline);
            if ($done === 0) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
            if ($done > 0 && (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0)) throw new RuntimeException('Parallel fixture process did not finish cleanly.');
        }
    }
};
$holdUpdate = static function (string $title) use ($owner, $series): void {
    DB::table('playlists')->where('id', $series->id)->lockForUpdate()->first();
    SeriesPlaylists::update($owner, $series, ['expected_revision' => SeriesPlaylists::revision($series), 'episodes' => [['video_id' => 1, 'episode_title' => $title]]]);
};
$revision = SeriesPlaylists::revision($series);
[$a, $b] = $pair(fn () => $holdUpdate('Winning edit'), fn () => SeriesPlaylists::update($owner, $series, ['expected_revision' => $revision, 'episodes' => [['video_id' => 1, 'episode_title' => 'Must not overwrite']]]));
$assertParallel($a['status'] === 200 && $b['status'] === 409, 'Concurrent edits reject the stale writer: '.json_encode([$a, $b]));
$assertParallel(DB::table('playlist_video')->where('playlist_id', $series->id)->where('video_id', 1)->value('episode_title') === 'Winning edit', 'Committed metadata is not overwritten.');

[$a, $b] = $pair(fn () => $holdUpdate('Consistent snapshot'), static function () use ($series, $owner): array {
    $payload = SeriesPlaylists::payload($series, $owner);
    return ['revision' => $payload['series_revision'], 'title' => $payload['episodes'][0]['episode_title']];
});
$assertParallel($a['status'] === 200 && $b['status'] === 200, 'Snapshot reader continues after writer commits.');
$assertParallel($b['value']['title'] === 'Consistent snapshot' && $b['value']['revision'] === SeriesPlaylists::revision($series), 'Fields and revision belong to the same committed snapshot.');

$revision = SeriesPlaylists::revision($series);
[$a, $b] = $pair(fn () => SharedPlaylists::reorder($owner, $series, [2, 1]), fn () => SeriesPlaylists::update($owner, $series, ['expected_revision' => $revision, 'episodes' => [['video_id' => 1, 'episode_title' => 'Stale order edit']]]));
$assertParallel($a['status'] === 200 && $b['status'] === 409, 'Concurrent reorder invalidates old metadata revision.');
$assertParallel(DB::table('playlist_video')->where('playlist_id', $series->id)->orderBy('sort_order')->pluck('video_id')->all() === [2, 1], 'There is one committed manual order.');

$revision = SeriesPlaylists::revision($series);
[$a, $b] = $pair(fn () => $holdUpdate('Rolled back edit'), fn () => SeriesPlaylists::update($owner, $series, ['expected_revision' => $revision, 'episodes' => [['video_id' => 1, 'episode_title' => 'After rollback']]]), true);
$assertParallel($a['status'] === 500 && $b['status'] === 200, 'Rollback releases the lock and preserves the prior valid revision.');
$assertParallel(DB::table('playlist_video')->where('playlist_id', $series->id)->where('video_id', 1)->value('episode_title') === 'After rollback', 'No failed transaction metadata survives.');
echo "Series MySQL concurrency: {$parallelChecks} assertions, four real lock-wait pairs passed; isolated test database only.\n";
