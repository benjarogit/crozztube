<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error::class.': '.$error->getMessage()."\n"); exit(1); });
require_once __DIR__.'/../../../docker/plugin-src/crozztube.source-resolver/src/Support/Mirror.php';

use App\Models\Video;
use CrzSourceResolver\Support\Mirror;

$checks = 0;
foreach (['file:///tmp/video.html', 'file:///', 'javascript:alert(1)', 'data:text/html,test', '/local/path', ''] as $url) {
    $video = new Video(['source_type' => 'import', 'import_provider' => 'direct', 'import_url' => $url]);
    if (Mirror::forVideo($video, 'crozztube.local') !== null) throw new RuntimeException('Non-web mirror accepted.');
    $checks++;
}
foreach (['https://example.test/video.mp4?key=fixture', 'http://example.test/video.mp4'] as $url) {
    $video = new Video(['source_type' => 'import', 'import_provider' => 'direct', 'import_url' => $url]);
    $mirror = Mirror::forVideo($video, 'crozztube.local');
    if ($mirror['mode'] !== 'link' || $mirror['external_url'] !== $url) throw new RuntimeException('Valid external link changed.');
    $checks++;
}
foreach ([
    'youtube' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'bitchute' => 'https://www.bitchute.com/video/WtW1GZBy4Tme/',
    'rumble' => 'https://rumble.com/embed/v7hpvpi/',
    'odysee' => 'https://odysee.com/@example:1/video:'.str_repeat('a', 40),
] as $provider => $url) {
    $video = new Video(['source_type' => 'import', 'import_provider' => $provider, 'import_url' => $url]);
    $mirror = Mirror::forVideo($video, 'crozztube.local');
    if ($mirror === null || $mirror['mode'] !== 'iframe' || ! str_starts_with($mirror['embed_url'], 'https://')) throw new RuntimeException('Official provider mirror stopped working: '.$provider);
    $checks++;
}
echo $checks." mirror URL checks passed without database writes.\n";
