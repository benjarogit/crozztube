<?php

namespace App\Support\Thumbnails;

use RuntimeException;

class Hosters
{
    public function __construct(private RemoteImages $http) {}

    public function upload(string $provider, array $image, string $identity, float $deadline): array
    {
        $key = (string) config('thumbnails.providers.'.$provider.'.key');
        if ($key === '' || $provider === 'directupload') {
            throw new RuntimeException('provider-unconfigured');
        }
        $this->reserveQuota($provider);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif'][$image['type']];
        $name = 'crz-'.substr($identity, 0, 24);
        if ($provider === 'fastpic') {
            $boundary = 'crz'.bin2hex(random_bytes(16));
            $body = '--'.$boundary."\r\nContent-Disposition: form-data; name=\"file1\"; filename=\"".$name.'.'.$extension."\"\r\nContent-Type: ".$image['type']."\r\n\r\n".$image['bytes']."\r\n--".$boundary."--\r\n";
            $response = $this->http->request('https://new.fastpic.org/upload', 'POST', $deadline, 131072,
                ['Authorization: Bearer '.$key, 'Content-Type: multipart/form-data; boundary='.$boundary], $body);
        } elseif ($provider === 'postimages') {
            // Own account key; public compatibility fields match the ShareX example, not a shared browser token.
            $body = http_build_query(['key' => $key, 'name' => $name, 'type' => $extension, 'image' => base64_encode($image['bytes']),
                'o' => config('thumbnails.providers.postimages.client_o'), 'm' => config('thumbnails.providers.postimages.client_m'), 'version' => '1.0.1', 'portable' => '1']);
            $response = $this->http->request('https://api.postimage.org/1/upload', 'POST', $deadline, 131072,
                ['Content-Type: application/x-www-form-urlencoded'], $body);
        } else {
            throw new RuntimeException('unsupported-provider');
        }
        if ($response['error'] !== '' || $response['code'] < 200 || $response['code'] >= 300) {
            return ['url' => null, 'result' => $response['error'] ?: 'http:'.$response['code'],
                'server_failure' => $response['code'] === 0 || $response['code'] >= 500];
        }
        return ['url' => $this->parse($provider, $response['body']), 'result' => 'uploaded', 'server_failure' => false];
    }

    private function reserveQuota(string $provider): void
    {
        foreach (['minute' => [now()->format('YmdHi'), 60, now()->addMinutes(2)], 'day' => [now()->format('Ymd'), 5000, now()->addDays(2)], 'month' => [now()->format('Ym'), 1000, now()->addMonths(2)]] as $period => [$bucket, $limit, $expiry]) {
            if ($period === 'month' && config('thumbnails.providers.'.$provider.'.month_limit') === null) continue;
            $key = 'thumbnails:quota:'.$provider.':'.$period.':'.$bucket;
            \Illuminate\Support\Facades\Cache::add($key, 0, $expiry);
            $count = \Illuminate\Support\Facades\Cache::increment($key);
            if ($count > (int) config('thumbnails.providers.'.$provider.'.'.$period.'_limit', $limit)) {
                throw new RuntimeException('quota-limit');
            }
        }
    }

    public function parse(string $provider, string $body): string
    {
        if ($provider === 'fastpic') {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            // The host explicitly reports whether full-size hotlinking is permitted.
            $hotlinked = filter_var($data['hotlinked'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $url = $hotlinked ? ($data['big_link'] ?? null) : ($data['thumb_link'] ?? null);

        } else {
            if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
                throw new RuntimeException('invalid-provider-response');
            }
            $previous = libxml_use_internal_errors(true);
            try {
                $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
                $url = $xml && (string) $xml['success'] === '1' ? (string) $xml->links->hotlink : null;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }

        }
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        $allowed = $provider === 'fastpic'
            ? preg_match('/(^|\.)fastpic\.(org|ru)$/', $host)
            : $host === 'i.postimg.cc';
        if (! RemoteImages::urlAllowed($url) || ! $allowed || ! str_starts_with((string) $url, 'https://')) {
            throw new RuntimeException('invalid-provider-response');
        }
        return $url;
    }
}
