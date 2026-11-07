<?php

require __DIR__.'/../../../vendor/autoload.php';

use App\Support\Clips\ClipPlaybackReadiness;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) throw new RuntimeException($message);
    $checks++;
};
$history = [];
$probe = static function (array $responses) use (&$history): ClipPlaybackReadiness {
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new ClipPlaybackReadiness(new Client(['handler' => $stack]));
};
$url = 'https://secure.odycdn.com/fixture/clip.mp4?hash77=fixture-not-real';
$check($probe([new Response(200, ['Content-Type' => 'video/mp4', 'Content-Length' => 12051])])->check($url), 'A nonempty provider video can become available.');
$check(count($history) === 1 && $history[0]['request']->getMethod() === 'HEAD', 'Video readiness must never GET video into PHP.');
$check($history[0]['request']->getHeaderLine('Origin') === 'https://odysee.com' && $history[0]['request']->getHeaderLine('Referer') === 'https://odysee.com/', 'Match provider embed delivery context; the live CDN rejects context-free HEAD requests.');
$check(! $history[0]['request']->hasHeader('X-Lbry-Auth-Token') && ! $history[0]['request']->hasHeader('Authorization'), 'Never send account credentials to the delivery host.');
$check($history[0]['options']['verify'] && ! $history[0]['options']['allow_redirects'] && $history[0]['options']['stream'], 'Verify TLS and handle redirects explicitly without buffering media.');
foreach ([new Response(403, ['Content-Type' => 'text/html']), new Response(401), new Response(404), new Response(503),
    new Response(200, ['Content-Type' => 'text/html', 'Content-Length' => 100]),
    new Response(200, ['Content-Type' => 'video/mp4', 'Content-Length' => 0]), new Response(200, ['Content-Type' => 'video/mp4'])] as $response) {
    $check(! $probe([$response])->check($url), 'An error, HTML page or empty file is not playback availability.');
}
foreach (['http://secure.odycdn.com/file', 'https://127.0.0.1/private', 'https://odycdn.com.attacker.invalid/file',
    'https://user@secure.odycdn.com/file', 'https://secure.odycdn.com:443/file', 'https://secure.odycdn.com/file#fragment'] as $unsafe) {
    $check(! $probe([])->check($unsafe) && count($history) === 0, 'Untrusted playback destinations must fail before any request.');
}
$check(! $probe([new Response(302, ['Location' => 'http://127.0.0.1/private'])])->check($url) && count($history) === 1, 'A provider redirect cannot turn into an internal or insecure request.');
$check($probe([new Response(302, ['Location' => '/fixture/ready.mp4']),
    new Response(200, ['Content-Type' => 'video/mp4', 'Content-Length' => 50])])->check($url), 'Safe provider redirects remain usable.');
$hls = 'application/vnd.apple.mpegurl';
$check($probe([
    new Response(200, ['Content-Type' => $hls]),
    new Response(200, ['Content-Type' => $hls], "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=120000\nlow/main.m3u8\n"),
    new Response(200, ['Content-Type' => $hls]),
    new Response(200, ['Content-Type' => $hls], "#EXTM3U\n#EXTINF:5,\nclip.ts\n#EXT-X-ENDLIST\n"),
    new Response(200, ['Content-Type' => 'video/mp2t', 'Content-Length' => 1000]),
])->check('https://player.odycdn.com/fixture/master.m3u8'), 'HLS needs both a valid manifest and an available media segment.');
$check(array_map(static fn ($row) => $row['request']->getMethod(), $history) === ['HEAD', 'GET', 'HEAD', 'GET', 'HEAD'], 'Only textual HLS manifests may be fetched; video segments are HEAD-only.');
$check(! $probe([
    new Response(200, ['Content-Type' => $hls]),
    new Response(200, ['Content-Type' => $hls], "#EXTM3U\n#EXTINF:5,\nclip.ts\n"), new Response(404),
])->check('https://player.odycdn.com/fixture/main.m3u8'), 'A manifest with unavailable segments must remain waiting.');
$check(! $probe([new Response(200, ['Content-Type' => $hls]),
    new Response(200, ['Content-Type' => $hls], "#EXTM3U\nhttp://127.0.0.1/private\n"),
])->check('https://player.odycdn.com/fixture/main.m3u8') && count($history) === 2, 'Playlist entries cannot trigger SSRF.');
$check(! $probe([new Response(200, ['Content-Type' => $hls]),
    new Response(200, ['Content-Type' => 'video/mp4'], 'fixture-media-not-read'),
])->check('https://player.odycdn.com/fixture/main.m3u8'), 'A manifest request that returns video is rejected without reading the body.');
$check(! $probe([new Response(200, ['Content-Type' => $hls]),
    new Response(200, ['Content-Type' => $hls], '#EXTM3U'.str_repeat('x', 65537)),
])->check('https://player.odycdn.com/fixture/main.m3u8'), 'Manifest responses must be bounded.');
echo $checks." clip readiness checks passed. Simulated provider headers/manifests only; no video bytes or external requests.\n";
