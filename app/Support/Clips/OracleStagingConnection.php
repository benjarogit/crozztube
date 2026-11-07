<?php

namespace App\Support\Clips;

use Aws\S3\S3Client;
use InvalidArgumentException;
use JsonSerializable;

final class OracleStagingConnection implements JsonSerializable
{
    private const FIELDS = ['region', 'namespace', 'bucket', 'access_key_id', 'secret_access_key'];

    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = [];
        foreach (self::FIELDS as $field) {
            $this->settings[$field] = is_string($settings[$field] ?? null) ? trim($settings[$field]) : '';
        }
    }

    public function problems(): array
    {
        $problems = [];
        foreach (self::FIELDS as $field) {
            if ($this->settings[$field] === '') {
                $problems[] = $field.': missing';
            }
        }
        foreach ([
            'region' => '/^[a-z]{2}(?:-[a-z0-9]+){2,4}$/D',
            'namespace' => '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
            'bucket' => '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}$/D',
        ] as $field => $pattern) {
            if ($this->settings[$field] !== '' && ! preg_match($pattern, $this->settings[$field])) {
                $problems[] = $field.': invalid format';
            }
        }
        foreach (['access_key_id', 'secret_access_key'] as $field) {
            if (preg_match('/[\x00-\x20\x7f]/', $this->settings[$field]) || strlen($this->settings[$field]) > 2048) {
                $problems[] = $field.': invalid format';
            }
        }

        return $problems;
    }

    public function endpoint(): string
    {
        $this->assertConfigured();

        return 'https://'.$this->settings['namespace'].'.compat.objectstorage.'.$this->settings['region'].'.oci.customer-oci.com';
    }

    public function bucket(): string
    {
        $this->assertConfigured();

        return $this->settings['bucket'];
    }

    public function client(): S3Client
    {
        $this->assertConfigured();

        return new S3Client([
            'version' => 'latest',
            // OCI's documented us-east-1 compatibility scope targets the home
            // region. The endpoint still selects the actual OCI region below.
            // Signing this tenancy's home endpoint with its OCI region instead
            // returns SignatureDoesNotMatch even with valid customer secrets.
            'region' => 'us-east-1',
            'signature_version' => 'v4',
            'endpoint' => $this->endpoint(),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $this->settings['access_key_id'],
                'secret' => $this->settings['secret_access_key'],
            ],
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
            'retries' => 0,
        ]);
    }

    private function assertConfigured(): void
    {
        if ($problems = $this->problems()) {
            throw new InvalidArgumentException('Oracle clip staging configuration: '.implode('; ', $problems));
        }
    }

    public function jsonSerialize(): array
    {
        return ['provider' => 'oci', 'configured' => $this->problems() === [], 'problems' => $this->problems()];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
