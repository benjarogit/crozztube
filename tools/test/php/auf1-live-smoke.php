<?php

// Optional bounded upstream smoke test. No Video records, publications or media files are created.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use CrzAuf1\Auf1Provider;
use CrzSourceResolver\Resolver;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class.': AUF1 smoke failed. '.mb_substr($error->getMessage(), 0, 160)."\n"); exit(1);
});
$mode = $argv[1] ?? 'metadata';
$url = 'https://auf1.tv/nachrichten-auf1/nachrichten-auf1-vom-06-november-2026/';
if ($mode === 'metadata') {
    foreach ([$url,
        'https://auf1.tv/auf1-am-abend/die-grosse-verarsche-wir-haben-es-so-satt-aaa-5-11-26/',
        'https://auf1.tv/nachrichten-auf1/risiko-ki-was-ich-nicht-aendern-kann-muss-ich-verwenden/'] as $page) {
        $data = Auf1Provider::metadata($page);
        $discovery = Auf1Provider::sourceCandidates($page);
        if (! $data['provider_id'] || ! $data['duration_seconds'] || $discovery['playback_candidates'] === []
            || ! Auf1Provider::embedUrl($data['provider_id'], '', $page)) throw new RuntimeException('Incomplete AUF1 metadata.');
        echo json_encode(['provider_id' => $data['provider_id'], 'duration' => $data['duration_seconds'],
            'chapters' => count($data['chapters']), 'captions' => count($data['captions']),
            'candidates' => count($discovery['playback_candidates']), 'official_embed' => true]),PHP_EOL;
    }
    exit(0);
}
if ($mode === 'media') {
    $videoId = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (! $videoId) throw new RuntimeException('Supply an existing AUF1 video ID; no test video is created.');
    $video = App\Models\Video::query()->where('import_provider', 'auf1')->findOrFail($videoId);
    $source = CrzSourceResolver\Models\CrozztubeImportSource::query()->where('video_id', $videoId)->first();
    if (! $source || ! $source->isFresh() || $source->kind !== 'hls') {
        throw new RuntimeException('A fresh native muxed AUF1 HLS source is required.');
    }
    // Metadata and fresh queue status cannot prove media playback. Read real
    // init/byte-range segments and decode a bounded amount of both A/V streams.
    $process = new Symfony\Component\Process\Process([
        'ffmpeg', '-nostdin', '-v', 'error', '-rw_timeout', '10000000',
        '-i', $source->media_url, '-t', '2', '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-',
    ], null, null, null, 30.0);
    $process->run();
    if (! $process->isSuccessful()) throw new RuntimeException('Real AUF1 audio/video decoding failed.');
    echo json_encode(['ok' => true, 'video_id' => $video->id, 'kind' => $source->kind,
        'decoded_seconds' => 2, 'video_and_audio' => true, 'media_files_written' => false]), PHP_EOL;
    exit(0);
}
if ($mode === 'dispatch') {
    $queue = $argv[2] ?? 'auf1-smoke';
    if (! in_array($queue, ['auf1-smoke', 'maintenance'], true)) throw new RuntimeException('Use the isolated smoke queue or maintenance.');
    $token = bin2hex(random_bytes(12));
    $job = CallQueuedClosure::create(static function () use ($url, $token): void {
        try {
            $resolved = app(Resolver::class)->resolve($url);
            $data = $resolved['metadata'];
            if (($data['provider_id'] ?? null) !== '6428' || ! in_array($resolved['kind'], ['hls', 'hls-synth', 'mp4'], true)
                || count($data['chapters'] ?? []) < 2) throw new RuntimeException('Unexpected provider resolution.');
            Cache::put('auf1:smoke:'.$token, ['ok' => true, 'pid' => getmypid(), 'provider_id' => $data['provider_id'],
                'kind' => $resolved['kind'], 'duration' => $data['duration_seconds'],
                'chapters' => count($data['chapters']), 'variants' => count($resolved['variants']),
                'audio_tracks' => count($resolved['audio_tracks']),
                'original_labels' => count(array_filter($resolved['audio_tracks'], static fn (array $track): bool => (bool) ($track['original'] ?? false)))], 600);
        } catch (Throwable $error) {
            Cache::put('auf1:smoke:'.$token, ['ok' => false, 'error_type' => $error::class], 600);
            throw $error;
        }
    });
    $id = Queue::pushOn($queue, $job);
    echo json_encode(['job_id' => $id, 'queue' => $queue, 'token' => $token]),PHP_EOL;
    exit(0);
}
if ($mode === 'result' && preg_match('/^[a-f0-9]{24}$/D', $argv[2] ?? '')) {
    $result = Cache::pull('auf1:smoke:'.$argv[2]);
    echo json_encode($result ?? ['pending' => true]),PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
}
throw new RuntimeException('Usage: auf1-live-smoke.php metadata | media VIDEO_ID | dispatch [auf1-smoke|maintenance] | result TOKEN');
