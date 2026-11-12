<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\MediaController;
use App\Models\Video;
use App\Support\VideoCaptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$timed = "WEBVTT\n\n00:01:30.000 --> 00:01:33.000\nHello <b>world</b>.\n\n00:01:33.000 --> 00:01:36.000\nSecond line &amp; more.\n";
$cues = VideoCaptions::cues($timed);
$check(count($cues) === 2 && $cues[0]['start_seconds'] === 90.0 && $cues[0]['text'] === 'Hello world.', 'Timed cues keep the real start and drop markup.');
$check($cues[1]['text'] === 'Second line & more.', 'Cue text decodes entities.');

$plainVideo = new Video;
$plainVideo->forceFill([
    'source_type' => 'import',
    'captions_path' => null,
    'caption_metadata' => ['imported_tracks' => [[
        'language' => 'en',
        'label' => 'English',
        'content' => "A note without a clock.\nIt stays readable.",
    ]]],
]);
$plain = VideoCaptions::document($plainVideo, 0);
$check($plain['cues'] === null && $plain['text'] === "A note without a clock.\nIt stays readable.", 'Plain text stays readable.');
$check(! str_contains(json_encode($plain, JSON_THROW_ON_ERROR), 'start_seconds'), 'Plain text does not invent a timestamp.');

$missing = VideoCaptions::document($plainVideo, 1);
$check($missing === null, 'A missing transcript language is absent.');

DB::beginTransaction();
try {
    $source = Video::query()->whereNotNull('slug')->first();
    $check($source !== null, 'Transcript checks require one video.');
    $suffix = bin2hex(random_bytes(4));
    $video = $source->replicate();
    $video->forceFill([
        'slug' => 'transcript-check-'.$suffix,
        'source_type' => 'import',
        'import_provider' => 'youtube',
        'import_url' => 'https://youtube.com/watch?v=transcript'.$suffix,
        'video_path' => 'external:youtube:transcript'.$suffix,
        'visibility' => 'public',
        'status' => 'ready',
        'approval_status' => 'approved',
        'captions_path' => null,
        'sale_enabled' => false,
        'caption_metadata' => ['imported_tracks' => [
            ['language' => 'en', 'label' => 'English', 'content' => $timed, 'automatic' => false],
            ['language' => 'de', 'label' => 'Deutsch', 'content' => "WEBVTT\n\n00:00:12.000 --> 00:00:14.000\nHallo Welt.\n", 'automatic' => false],
        ]],
    ]);
    $video->save();

    $tracks = VideoCaptions::forResource($video);
    $check(count($tracks) === 2 && str_contains($tracks[1]['transcript_url'], 'track=1') && str_contains($tracks[1]['transcript_url'], 'format=json'), 'Each language has its own transcript URL.');
    $response = app(MediaController::class)->captions(Request::create('https://crozztube.local:8443/media/videos/'.$video->id.'/captions?transcript=1&format=json&track=1'), $video);
    $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $check($response->getStatusCode() === 200 && ($body['language'] ?? '') === 'de', 'The selected language is returned.');
    $check(isset($body['cues'][0]['start_seconds']) && (float) $body['cues'][0]['start_seconds'] === 12.0 && ($body['cues'][0]['text'] ?? '') === 'Hallo Welt.', 'The selected language returns its own cue.');
    $check($body['text'] === null && ! str_contains($response->getContent(), 'Hello world'), 'A language transcript does not include another language.');

    $plainResponse = app(MediaController::class)->captions(Request::create('https://crozztube.local:8443/media/videos/'.$video->id.'/captions?transcript=1'), $video);
    $check(str_starts_with((string) $plainResponse->headers->get('Content-Type'), 'text/plain') && str_contains($plainResponse->getContent(), 'Hello world.'), 'The existing plain transcript stays text.');
} finally {
    DB::rollBack();
}

echo "Transcript checks passed: {$checks}\n";
