<?php

namespace App\Updates;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class UpdateRepositoryClient
{
    public function releasesFor(string $currentVersion): array
    {
        if (! config('playhub-updates.enabled', true)) {
            throw new RuntimeException('Automatic updates are disabled on this installation.');
        }

        $maximumManifestBytes = max(1, (int) config('playhub-updates.max_manifest_mb', 16)) * 1024 * 1024;
        $response = $this->request((string) config('playhub-updates.endpoint'), ['version' => $currentVersion], maximumBytes: $maximumManifestBytes);
        if (strlen($response->body()) > $maximumManifestBytes) {
            throw new RuntimeException('The PlayHub update repository response is too large.');
        }
        $data = $response->json();
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 1 || ($data['product'] ?? null) !== 'playhub' || ! is_array($data['updates'] ?? null) || ! array_is_list($data['updates'])) {
            throw new RuntimeException('The PlayHub update server returned an invalid repository response.');
        }
        if (($data['current_version'] ?? $currentVersion) !== $currentVersion) {
            throw new RuntimeException('The PlayHub update server returned a path for a different installed version.');
        }

        $publicKey = $this->publicKey();
        $required = $this->signatureRequired();
        $manifests = [];
        $manifestIds = [];
        $cursor = $currentVersion;
        foreach ($data['updates'] as $release) {
            if (count($manifests) >= 50) {
                throw new RuntimeException('The update path contains too many sequential releases.');
            }
            $manifest = UpdateManifest::fromArray(is_array($release) ? $release : []);
            $manifest->verifySignature($publicKey, $required);
            if (isset($manifestIds[$manifest->id()])) {
                throw new RuntimeException('The update path contains a duplicate release identifier.');
            }
            $manifestIds[$manifest->id()] = true;
            if ($manifest->fromVersion() !== $cursor) {
                throw new RuntimeException("The update path is discontinuous at PlayHub {$cursor}.");
            }
            if (version_compare($cursor, $manifest->toArray()['minimum_supported_version'], '<')) {
                throw new RuntimeException("PlayHub {$cursor} is below the minimum version supported by this update.");
            }
            $this->assertAllowedUrl($manifest->package()['url']);
            $manifests[] = $manifest;
            $cursor = $manifest->toVersion();
        }

        $latest = (string) ($data['latest_version'] ?? $cursor);
        if (! UpdateManifest::isValidVersion($latest) || $latest !== $cursor) {
            throw new RuntimeException('The update server returned an incomplete or invalid upgrade path.');
        }
        if (! $manifests && $latest !== $currentVersion) {
            throw new RuntimeException('No compatible update path is available for this PlayHub version.');
        }

        return [
            'current_version' => $currentVersion,
            'latest_version' => $latest,
            'up_to_date' => count($manifests) === 0,
            'updates' => $manifests,
        ];
    }

    public function download(UpdateManifest $manifest, string $destination, ?callable $progress = null): void
    {
        $package = $manifest->package();
        $url = $package['url'];
        File::ensureDirectoryExists(dirname($destination), 0770, true);
        @unlink($destination);

        try {
            $response = $this->request($url, [], $destination, (int) config('playhub-updates.download_timeout', 1800), (int) $package['size_bytes'], $progress);
        } catch (Throwable $exception) {
            @unlink($destination);
            throw $exception;
        }
        if (! is_file($destination)) {
            $body = $response->body();
            if ($body === '' || file_put_contents($destination, $body, LOCK_EX) === false) {
                throw new RuntimeException('The update package could not be written to staging storage.');
            }
        }

        $size = (int) (filesize($destination) ?: 0);
        if ($size !== (int) $package['size_bytes']) {
            @unlink($destination);
            throw new RuntimeException('The downloaded update package size does not match its signed manifest.');
        }
        $checksum = hash_file('sha256', $destination);
        if (! is_string($checksum) || ! hash_equals($package['sha256'], $checksum)) {
            @unlink($destination);
            throw new RuntimeException('The downloaded update package failed SHA-256 verification.');
        }
    }

    public function publicKey(): ?string
    {
        $key = config('playhub-updates.public_key');
        if (is_string($key) && trim($key) !== '') {
            return str_replace('\\n', "\n", trim($key));
        }

        $path = config('playhub-updates.public_key_path');
        if (is_string($path) && $path !== '' && is_readable($path)) {
            return (string) file_get_contents($path);
        }

        return null;
    }

    public function signatureRequired(): bool
    {
        return app()->environment('production') || (bool) config('playhub-updates.signature_required', true);
    }

    public function configurationIssues(): array
    {
        $issues = [];
        if (! config('playhub-updates.enabled', true)) {
            $issues[] = 'Automatic updates are disabled by configuration.';
        }
        try {
            $this->assertAllowedUrl((string) config('playhub-updates.endpoint'));
        } catch (Throwable $exception) {
            $issues[] = $exception->getMessage();
        }
        if (! extension_loaded('openssl')) {
            $issues[] = 'The OpenSSL PHP extension is required.';
        }
        if (! class_exists(\ZipArchive::class)) {
            $issues[] = 'The PHP ZIP extension is required.';
        }
        if ($this->signatureRequired()) {
            $key = $this->publicKey();
            $resource = $key ? @openssl_pkey_get_public($key) : false;
            $details = $resource ? openssl_pkey_get_details($resource) : false;
            if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($details['bits'] ?? 0) < 2048) {
                $issues[] = 'A valid PlayHub update verification public key is required.';
            }
        }

        return array_values(array_unique($issues));
    }

    public function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $allowedHosts = array_map(fn ($item) => strtolower(rtrim((string) $item, '.')), (array) config('playhub-updates.allowed_hosts', []));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $insecureLocal = app()->environment(['local', 'testing']) && config('playhub-updates.allow_insecure_local', false) && $local;

        if (($scheme !== 'https' && ! ($scheme === 'http' && $insecureLocal)) || $host === '' || ! in_array($host, $allowedHosts, true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('The update URL is not an approved HTTPS PlayHub update source.');
        }
    }

    private function request(string $url, array $query = [], ?string $sink = null, ?int $timeout = null, ?int $maximumBytes = null, ?callable $progress = null): Response
    {
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $this->assertAllowedUrl($url);
            $options = ['allow_redirects' => false];
            if ($sink !== null) {
                $options['sink'] = $sink;
            }
            if ($maximumBytes !== null) {
                $options['progress'] = function (int $downloadTotal, int $downloadedBytes) use ($maximumBytes, $progress): void {
                    if ($downloadTotal > $maximumBytes || $downloadedBytes > $maximumBytes) {
                        throw new RuntimeException('The update package exceeded its signed size while downloading.');
                    }
                    if ($progress !== null) {
                        $progress($downloadedBytes, $maximumBytes);
                    }
                };
            }
            $response = Http::accept($sink === null ? 'application/json' : 'application/octet-stream')
                ->connectTimeout(10)
                ->timeout($timeout ?? (int) config('playhub-updates.request_timeout', 30))
                ->withOptions($options)
                ->get($url, $query);

            if ($response->redirect()) {
                $location = $response->header('Location');
                if (! is_string($location) || ! filter_var($location, FILTER_VALIDATE_URL)) {
                    throw new RuntimeException('The update server returned an unsafe redirect.');
                }
                $url = $location;
                $query = [];
                if ($sink !== null) {
                    @unlink($sink);
                }

                continue;
            }
            if (! $response->successful()) {
                if ($sink !== null) {
                    @unlink($sink);
                }
                throw new RuntimeException('The PlayHub update server returned HTTP '.$response->status().'.');
            }

            return $response;
        }

        throw new RuntimeException('The update server returned too many redirects.');
    }
}
