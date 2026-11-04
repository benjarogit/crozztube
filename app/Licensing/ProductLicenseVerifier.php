<?php

namespace App\Licensing;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ProductLicenseVerifier
{
    private const LICENSE_SERVER_URL = 'https://licenses.kontackt.de/';

    public function verify(string $licenseKey): array
    {
        $licenseKey = $this->normalize($licenseKey);
        $licenseType = $this->licenseType($licenseKey);
        if ($licenseType === null) {
            throw new InvalidArgumentException('Enter a valid CodeCanyon purchase code or PlayHub store license key.');
        }

        $domain = $this->licenseDomain((string) config('app.url'));
        if ($domain === '') {
            throw new RuntimeException('The PlayHub installation domain could not be determined.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['User-Agent' => 'PlayHub-Updater/'.config('app.version', '1.0')])
                ->post(self::LICENSE_SERVER_URL, [
                    'purchase_code' => $licenseKey,
                    'product_slug' => 'playhub',
                    'domain' => $domain,
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('The license verification service is unavailable.', previous: $exception);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('The license verification service returned an invalid response.');
        }
        if (! $response->successful() || ($payload['valid'] ?? false) !== true) {
            $message = trim((string) ($payload['message'] ?? 'The product license could not be verified.'));

            throw new RuntimeException(substr($message ?: 'The product license could not be verified.', 0, 300));
        }

        $license = $payload['license'] ?? null;
        $receipt = trim((string) ($payload['receipt'] ?? ''));
        if (! is_array($license)
            || ! hash_equals('playhub', (string) ($license['product_slug'] ?? ''))
            || ! hash_equals($domain, (string) ($license['domain'] ?? ''))
            || ! preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $receipt)) {
            throw new RuntimeException('The license verification service returned incomplete activation data.');
        }

        return [
            'license' => $license,
            'receipt' => $receipt,
            'license_type' => $licenseType,
        ];
    }

    private function normalize(string $licenseKey): string
    {
        $licenseKey = trim($licenseKey);

        return preg_match('/^KNL-(?:[A-F0-9]{4}-){7}[A-F0-9]{4}$/i', $licenseKey)
            ? strtoupper($licenseKey)
            : strtolower($licenseKey);
    }

    private function licenseType(string $licenseKey): ?string
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $licenseKey)) {
            return 'envato';
        }
        if (preg_match('/^KNL-(?:[A-F0-9]{4}-){7}[A-F0-9]{4}$/', $licenseKey)) {
            return 'knl';
        }

        return null;
    }

    private function licenseDomain(string $appUrl): string
    {
        $domain = strtolower(rtrim(trim((string) parse_url($appUrl, PHP_URL_HOST), '[]'), '.'));
        if (function_exists('idn_to_ascii') && ! filter_var($domain, FILTER_VALIDATE_IP)) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii)) {
                $domain = strtolower($ascii);
            }
        }

        return $domain;
    }
}
