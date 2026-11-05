<?php

namespace App\Support\Thumbnails;

use RuntimeException;

/** Bounded, memory-only HTTP. DNS is checked AND pinned for every redirect. */
class RemoteImages
{
    public static function publicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        $blocked = ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        if (str_contains($ip, ':')) {
            return \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, '2000::/3')
                && ! \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, ['2001:db8::/32', '2001::/32', '2002::/16']);
        }
        return ! \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $blocked);
    }

    public static function urlAllowed(?string $url): bool
    {
        $parts = parse_url((string) $url);
        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || in_array($parts['port'], [80, 443], true))
            && ! preg_match('/[\x00-\x20\x7f]/', (string) $url);
    }

    public function address(string $url, ?float $deadline = null): array
    {
        if (! self::urlAllowed($url)) {
            throw new RuntimeException('unsafe-url');
        }
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
        if ($ips === []) {
            $remaining = min(2.0, ($deadline ?? microtime(true) + 2.0) - microtime(true));
            if ($remaining <= 0.05) throw new RuntimeException('timeout');
            $dns = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=16M', '-r',
                '$records = @dns_get_record($argv[1], DNS_A | DNS_AAAA); echo json_encode($records ?: []);', $host], null, null, null, $remaining);
            try { $dns->run(); } catch (\Throwable) { throw new RuntimeException('dns-failure'); }
            foreach (json_decode($dns->getOutput(), true) ?: [] as $record) {
                if (isset($record['ip']) || isset($record['ipv6'])) {
                    $ips[] = $record['ip'] ?? $record['ipv6'];
                }
            }
        }
        if ($ips === []) {
            throw new RuntimeException('dns-failure');
        }
        foreach ($ips as $ip) {
            if (! self::publicIp($ip)) {
                throw new RuntimeException('unsafe-address');
            }
        }
        usort($ips, fn ($a, $b) => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));
        return [$host, $ips[0]];
    }

    /** @return array{code:int,body:string,type:string,location:string,error:string} */
    public function request(string $url, string $method, float $deadline, int $limit, array $headers = [], ?string $body = null, bool $partial = false): array
    {
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            [$host, $ip] = $this->address($url, $deadline);
            $remaining = min(4.0, $deadline - microtime(true));
            if ($remaining <= 0.05) {
                throw new RuntimeException('timeout');
            }
            $port = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
            $data = '';
            $responseHeaders = [];
            $overflow = false;
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_NOBODY => $method === 'HEAD',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => filter_var($host, FILTER_VALIDATE_IP) ? [] : [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
                CURLOPT_CONNECTTIMEOUT_MS => (int) (min(2, $remaining) * 1000),
                CURLOPT_TIMEOUT_MS => (int) ($remaining * 1000),
                CURLOPT_USERAGENT => 'CrozzTube-Thumbnails/1.0',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$data, &$overflow, $limit): int {
                    if (strlen($data) + strlen($chunk) > $limit) {
                        $overflow = true;
                        return 0;
                    }
                    $data .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($body !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }
            if ($partial) {
                curl_setopt($handle, CURLOPT_RANGE, '0-1023');
            }
            curl_exec($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($handle) ? 'network-error' : '';
            curl_close($handle);
            if ($overflow && ! $partial) {
                throw new RuntimeException('size-limit');
            }
            if ($code >= 300 && $code < 400 && isset($responseHeaders['location'])) {
                // Upload redirects are deliberately not followed: do not forward credentials.
                if ($method === 'POST' || $redirect === 3) {
                    throw new RuntimeException('unexpected-redirect');
                }
                $url = \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($responseHeaders['location']))->__toString();
                continue;
            }
            return ['code' => $code, 'body' => $data, 'type' => strtolower(explode(';', $responseHeaders['content-type'] ?? '')[0]),
                'location' => $url, 'error' => $partial && $overflow ? '' : $error];
        }
        throw new RuntimeException('redirect-limit');
    }

    public function image(string $url, float $deadline): array
    {
        $response = $this->request($url, 'GET', $deadline, (int) config('thumbnails.max_bytes'));
        if ($response['code'] !== 200 || $response['error'] !== '') {
            throw new RuntimeException('source-unavailable');
        }
        return self::inspect($response['body']);
    }

    public static function inspect(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > (int) config('thumbnails.max_bytes', 5242880)) {
            throw new RuntimeException('size-limit');
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true)
            || $info[0] <= 0 || $info[1] <= 0 || $info[0] * $info[1] > 25000000) {
            throw new RuntimeException('invalid-image');
        }
        return ['bytes' => $bytes, 'type' => $info['mime'], 'width' => $info[0], 'height' => $info[1]];
    }

    public function probe(string $url, float $deadline): array
    {
        $response = $this->request($url, 'HEAD', $deadline, 0);
        if (in_array($response['code'], [0, 403, 405, 501], true)) {
            $response = $this->request($url, 'GET', $deadline, 16384, partial: true);
        }
        $ok = in_array($response['code'], [200, 206], true) && $response['error'] === '' && str_starts_with($response['type'], 'image/');
        return ['ok' => $ok, 'server_failure' => $response['code'] === 0 || $response['code'] >= 500,
            'result' => $response['error'] ?: 'http:'.$response['code']];
    }
}
