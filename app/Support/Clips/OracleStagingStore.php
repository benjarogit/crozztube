<?php

namespace App\Support\Clips;

use Aws\Exception\AwsException;
use Aws\S3\PostObjectV4;
use Aws\S3\S3ClientInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Metadata and provider-internal operations only; never reads/writes video bytes. */
final class OracleStagingStore
{
    public function __construct(private OracleStagingConnection $connection, private ?S3ClientInterface $client = null) {}

    public function __debugInfo(): array
    {
        return ['provider' => 'oci', 'purpose' => 'clip-staging'];
    }

    /** This bearer capability is for its job owner only. Never log the result. */
    public function uploadGrant(ClipStagingObject $object, DateTimeImmutable $expires): array
    {
        $this->assertExpiry($expires, 600);
        $fields = [
            'key' => $object->incomingKey(),
            'Content-Type' => 'video/mp4',
            'success_action_status' => '204',
            'x-amz-checksum-algorithm' => 'SHA256',
            'x-amz-checksum-sha256' => $object->checksumBase64(),
            'x-amz-meta-clip-job' => $object->jobId,
            'x-amz-meta-clip-sha256' => $object->sha256,
        ];
        $conditions = [
            ['bucket' => $this->connection->bucket()],
            ['content-length-range', $object->bytes, $object->bytes],
        ];
        foreach ($fields as $field => $value) {
            $conditions[] = [$field => $value];
        }
        $post = new PostObjectV4($this->client(), $this->connection->bucket(), $fields, $conditions, $expires);

        return [
            'method' => 'POST', 'url' => $post->getFormAttributes()['action'],
            'fields' => $post->getFormInputs(), 'expires_at' => $expires->format(DATE_ATOM),
        ];
    }

    /** The caller must hold its job lease and retain the two-copy reservation. */
    public function seal(ClipStagingObject $object): void
    {
        $head = $this->inspect($object->incomingKey());
        $this->assertObject($object, $head);
        // A concurrent replacement between HEAD and COPY must fail atomically.
        $this->execute('CopyObject', [
            'Bucket' => $this->connection->bucket(), 'Key' => $object->handoffKey(),
            'CopySource' => rawurlencode($this->connection->bucket()).'/'.str_replace('%2F', '/', rawurlencode($object->incomingKey())),
            'CopySourceIfMatch' => $head['ETag'],
            'MetadataDirective' => 'REPLACE', 'ContentType' => 'video/mp4',
            'Metadata' => ['clip-job' => $object->jobId, 'clip-sha256' => $object->sha256],
        ]);
        $this->assertObject($object, $this->inspect($object->handoffKey()));
    }

    /** Short-lived read capability for the hoster, never for an arbitrary key. */
    public function downloadGrant(ClipStagingObject $object, DateTimeImmutable $expires): string
    {
        $this->assertExpiry($expires, 3600);
        $this->assertObject($object, $this->inspect($object->handoffKey()));
        try {
            $client = $this->client();

            return (string) $client->createPresignedRequest($client->getCommand('GetObject', [
                'Bucket' => $this->connection->bucket(), 'Key' => $object->handoffKey(),
            ]), $expires)->getUri();
        } catch (Throwable) {
            throw new RuntimeException('Clip staging: download authorization failed.');
        }
    }

    /**
     * Confirm both deletions. The queue must still keep its reservation/tombstone
     * until upload grants and in-flight requests have expired, then sweep again.
     */
    public function deleteCopies(ClipStagingObject $object): void
    {
        foreach ([$object->incomingKey(), $object->handoffKey()] as $key) {
            $this->execute('DeleteObject', ['Bucket' => $this->connection->bucket(), 'Key' => $key]);
            if ($this->inspect($key) !== null) {
                throw new RuntimeException('Clip staging: deletion is not confirmed.');
            }
        }
    }

    private function inspect(string $key): ?array
    {
        return $this->execute('HeadObject', ['Bucket' => $this->connection->bucket(), 'Key' => $key, 'ChecksumMode' => 'ENABLED'], true);
    }

    private function assertObject(ClipStagingObject $object, ?array $head): void
    {
        $etag = $head['ETag'] ?? null;
        if ($head === null || ($head['ContentLength'] ?? null) !== $object->bytes
            || ($head['ContentType'] ?? null) !== 'video/mp4'
            || ($head['Metadata']['clip-job'] ?? null) !== $object->jobId
            || ($head['Metadata']['clip-sha256'] ?? null) !== $object->sha256
            || ($head['ChecksumSHA256'] ?? null) !== $object->checksumBase64()
            || ! is_string($etag) || $etag === '' || strlen($etag) > 256 || preg_match('/[\x00-\x20\x7f]/', $etag)) {
            throw new RuntimeException('Clip staging: stored object does not match the reserved upload.');
        }
    }

    private function execute(string $operation, array $arguments, bool $allowMissing = false): ?array
    {
        try {
            $client = $this->client();

            return $client->execute($client->getCommand($operation, $arguments))->toArray();
        } catch (AwsException $error) {
            if ($allowMissing && $error->getStatusCode() === 404) {
                return null;
            }
            // No previous exception: it can retain signed requests/credentials.
            throw new RuntimeException('Clip staging: '.$operation.' failed.');
        } catch (Throwable) {
            throw new RuntimeException('Clip staging: '.$operation.' failed.');
        }
    }

    private function assertExpiry(DateTimeImmutable $expires, int $maxSeconds): void
    {
        $seconds = $expires->getTimestamp() - time();
        if ($seconds < 1 || $seconds > $maxSeconds) {
            throw new InvalidArgumentException('Clip staging authorization expiration is outside its allowed range.');
        }
    }

    private function client(): S3ClientInterface
    {
        return $this->client ??= $this->connection->client();
    }
}
