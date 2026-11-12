<?php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error::class.': '.$error->getMessage()."\n"); exit(1); });

use App\Jobs\ProcessVideoUpload;
use App\Models\Video;
use App\Support\CompleteVideoProcessing;
use App\Support\MediaBinaries;
use App\Support\ShortVideos;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

config(['cache.default' => 'array']); Bus::fake(); Queue::fake(); Http::preventStrayRequests();
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void { if (! $condition) throw new RuntimeException($message); $checks++; };
$temporary = sys_get_temp_dir().'/crozztube-short-policy-'.bin2hex(random_bytes(6));
mkdir($temporary, 0700);
DB::beginTransaction();
try {
    $check(ShortVideos::MAX_SECONDS === 60, 'Published creator/API limit is60seconds');
    $template = Video::query()->withoutEagerLoads()->firstOrFail();
    $make = static function (int $duration, ?int $width, ?int $height, bool $flag = true, string $source = 'upload') use ($template): Video {
        $video = $template->replicate();
        $video->forceFill(['slug' => 'short-policy-'.bin2hex(random_bytes(8)), 'source_type' => $source, 'duration_seconds' => $duration, 'video_width' => $width, 'video_height' => $height, 'is_short' => $flag, 'publish_as_draft' => false, 'scheduled_at' => null, 'approval_status' => 'approved', 'requested_visibility' => 'public']);
        $video->save();
        return $video;
    };
    foreach ([[60,1080,1920,true], [61,1080,1920,false], [180,1080,1920,false], [60,1080,1080,false], [60,1920,1080,false], [60,800,900,true], [0,1080,1920,false], [60,null,null,false]] as [$seconds,$width,$height,$expected]) {
        $video = $make($seconds,$width,$height,!$expected);
        $check($video->isShort() === $expected, 'Real metadata controls API classification');
        $check(Video::shorts()->whereKey($video->id)->exists() === $expected, 'Shorts SQL follows the same boundaries');
        $check(Video::standardVideos()->whereKey($video->id)->exists() === !$expected, 'Standard-video SQL is the complement, including square clips');
    }
    $check(! ShortVideos::qualifies(60.01,1080,1920), 'A fraction above60seconds does not qualify');
    $check(! Video::isShortContent('upload',30,true), 'A legacy flag without dimensions cannot prove portrait orientation');
    $live = $make(30,1080,1920,true,'live_replay');
    $check(! $live->isShort() && ! Video::shorts()->whereKey($live->id)->exists(), 'Live replays remain outside Shorts');
    $processed = $make(30,1080,1920,true);
    CompleteVideoProcessing::save($processed->id, ['duration_seconds' => 61, 'video_width' => 1080, 'video_height' => 1920]);
    $check(! $processed->fresh()->is_short && ! $processed->fresh()->isShort(), 'Probed long duration overrides the original upload flag at completion');
    CompleteVideoProcessing::save($processed->id, ['duration_seconds' => 60, 'video_width' => 1080, 'video_height' => 1080]);
    $check(! $processed->fresh()->is_short, 'Probed square dimensions stay outside Shorts');
    CompleteVideoProcessing::save($processed->id, ['duration_seconds' => 60, 'video_width' => 1080, 'video_height' => 1920]);
    $check($processed->fresh()->is_short && ! $processed->fresh()->chapters_enabled && ! $processed->fresh()->monetization_enabled, 'Actual qualifying media uses the existing Shorts publication rules');
    $landscape = $temporary.'/landscape.mp4';
    (new Process([MediaBinaries::ffmpeg(), '-v','error','-f','lavfi','-i','color=c=black:s=64x32:d=0.2','-c:v','libx264','-pix_fmt','yuv420p',$landscape]))->mustRun();
    $rotated = $temporary.'/rotated.mp4';
    (new Process([MediaBinaries::ffmpeg(), '-v','error','-i',$landscape,'-c','copy','-metadata:s:v:0','rotate=90',$rotated]))->mustRun();
    $probe = new ReflectionMethod(ProcessVideoUpload::class, 'probeVideoStream');
    $plain = $probe->invoke(new ProcessVideoUpload(0), $landscape);
    $upright = $probe->invoke(new ProcessVideoUpload(0), $rotated);
    $check($plain['video_width'] === 64 && $plain['video_height'] === 32, 'Worker reads actual source dimensions rather than client claims');
    $check($upright['video_width'] === 32 && $upright['video_height'] === 64, 'Phone display rotation is respected when deciding portrait orientation');
    echo $checks." Shorts-policy checks passed. Database changes rolled back; only disposable media used.\n";
} finally {
    DB::rollBack();
    foreach (glob($temporary.'/*') ?: [] as $file) unlink($file);
    rmdir($temporary);
}
