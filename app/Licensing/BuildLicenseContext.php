<?php

namespace App\Licensing;

use InvalidArgumentException;

/** Non-secret build binding. This is not an activation or a mobile entitlement. */
final class BuildLicenseContext
{
    public static function configured(): array
    {
        $website = self::normalizeHttpsUrl((string) config('app.url'));

        return [
            'domain' => (string) parse_url($website, PHP_URL_HOST),
            'api_base_url' => $website.'/api/v1',
        ];
    }

    public static function normalizeHttpsUrl(string $value): string
    {
        if ($value !== trim($value) || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            throw new InvalidArgumentException('The API binding is invalid.');
        }
        $parts = parse_url($value);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            throw new InvalidArgumentException('The API binding is invalid.');
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii)) {
                $host = strtolower($ascii);
            }
        }
        if (strlen($host) > 253 || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)
            || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
            throw new InvalidArgumentException('The API binding is invalid.');
        }
        foreach (['localhost', 'local', 'lan', 'internal', 'test', 'example', 'invalid'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                throw new InvalidArgumentException('The API binding is invalid.');
            }
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if (preg_match('#(?:^|/)\.{1,2}(?:/|$)#', rawurldecode($path)) || str_contains($path, '//')) {
            throw new InvalidArgumentException('The API binding is invalid.');
        }
        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '';

        return 'https://'.$host.$port.$path;
    }
}
