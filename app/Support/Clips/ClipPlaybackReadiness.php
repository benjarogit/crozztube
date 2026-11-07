<?php

namespace App\Support\Clips;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Throwable;

/** Provider HEAD checks and bounded textual HLS metadata only, never video GET. */
final class ClipPlaybackReadiness
{
    private int $requests = 0;

    public function __construct(private ?ClientInterface $http = null) {}

    public function check(#[\SensitiveParameter] string $url): bool
    {
        $this->requests = 0;
        try {
            return $this->probe($url);
        } catch (Throwable) {
            return false;
        }
    }

    private function probe(string $url, int $depth = 0): bool
    {
        $head = $this->request('HEAD', $url);
        if ($head === null) {
            return false;
        }
        $type = strtolower(trim(explode(';', $head['type'])[0]));
        if (str_starts_with($type, 'video/')) {
            return $head['length'] > 0;
        }
        if ($depth > 1 || ! in_array($type, ['application/vnd.apple.mpegurl', 'application/x-mpegurl'], true)) {
            return false;
        }
        $manifest = $this->request('GET', $head['url'], true);
        if ($manifest === null || ! str_starts_with(ltrim($manifest['body']), '#EXTM3U')
            || preg_match('/#EXT-X-KEY:(?![^\r\n]*METHOD=NONE)/', $manifest['body'])) {
            return false;
        }
        foreach (preg_split('/\r\n|\r|\n/', $manifest['body']) as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $next = (string) UriResolver::resolve(new Uri($manifest['url']), new Uri($line));

                return $this->probe($next, $depth + 1);
            }
        }

        return false;
    }

    private function request(string $method, string $url, bool $manifest = false): ?array
    {
        for ($hop = 0; $hop < 4; $hop++) {
            if (++$this->requests > 6 || ! self::allowed($url)) {
                return null;
            }
            $response = ($this->http ??= new Client())->request($method, $url, [
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'stream' => true, 'connect_timeout' => 3, 'timeout' => 5,
            ]);
            try {
                $status = $response->getStatusCode();
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $location = $response->getHeaderLine('Location');
                    if ($location === '') {
                        return null;
                    }
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
                    continue;
                }
                if (! in_array($status, [200, 206], true)) {
                    return null;
                }
                $type = $response->getHeaderLine('Content-Type');
                $body = '';
                if ($manifest) {
                    if (! in_array(strtolower(trim(explode(';', $type)[0])), ['application/vnd.apple.mpegurl', 'application/x-mpegurl'], true)) {
                        return null;
                    }
                    $body = Utils::copyToString($response->getBody(), 65537);
                    if (strlen($body) > 65536) {
                        return null;
                    }
                }

                return ['url' => $url, 'type' => $type, 'length' => (int) $response->getHeaderLine('Content-Length'), 'body' => $body];
            } finally {
                $response->getBody()->close();
            }
        }

        return null;
    }

    private static function allowed(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && (bool) preg_match('/^(?:[a-z0-9-]+\.)*odycdn\.com$/D', $parts['host'] ?? '')
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! isset($parts['fragment'])
            && strlen($url) <= 4096 && ! preg_match('/[\x00-\x20\x7f]/', $url);
    }
}
