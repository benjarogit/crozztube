<?php

namespace App\Support\Clips;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use JsonSerializable;
use Throwable;

/** Metadata-only transport. Callers persist each phase before advancing. */
final class OdyseePublisher implements JsonSerializable
{
    private const API = 'https://api.na-backend.odysee.com/api/v1/';

    private string $token;

    private string $channelId;

    public function __construct(#[\SensitiveParameter] array $settings, private ?ClientInterface $http = null)
    {
        if ((new OdyseePublishingConnection($settings))->problems()) {
            throw new InvalidArgumentException('Odysee publishing configuration is incomplete or invalid.');
        }
        $this->token = trim($settings['auth_token']);
        $this->channelId = $settings['channel_id'];
    }

    public static function name(string $jobId): string
    {
        // Reuse the strict server UUID validator, without accepting arbitrary slugs.
        new ClipStagingObject($jobId, 1, str_repeat('0', 64));

        return 'crozztube-clip-'.str_replace('-', '', $jobId);
    }

    /** Initial publication is always unlisted until the queue confirms readiness. */
    public function create(ClipStagingObject $object, OdyseeRemoteUpload $upload, string $title): string
    {
        if (trim($title) === '' || mb_strlen($title) > 200 || preg_match('/[\x00-\x1f\x7f]/u', $title)) {
            throw new InvalidArgumentException('Invalid clip title.');
        }
        $data = $this->request('POST', 'asynqueries/', [
            // Odysee's Go JSON-RPC decoder requires a numeric request ID.
            // Provider idempotency is bound to the upload ID, not this RPC ID.
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'stream_create', 'params' => [
                'name' => self::name($object->jobId), 'title' => $title,
                'description' => 'CrozzTube clip', 'channel_id' => $this->channelId,
                'file_path' => $upload->filePath(), 'bid' => '0.001',
                'tags' => ['c:unlisted', 'crozztube-clip'], 'release_time' => 2147483647,
                'blocking' => true, 'preview' => false,
            ],
        ], 'create');
        $id = $data['payload']['query_id'] ?? null;
        if (($data['status'] ?? null) !== 'query_created' || ! self::validQuery($id)) {
            throw new OdyseePublishingFailure('create', 'response');
        }

        return $id;
    }

    /** Missing/failed/accepted query states must never be interpreted as ready. */
    public function status(string $queryId, ClipStagingObject $object): array
    {
        if (! self::validQuery($queryId)) {
            throw new InvalidArgumentException('Invalid Odysee query reference.');
        }
        $data = $this->request('GET', 'asynqueries/'.$queryId, null, 'status', true);
        if (isset($data['_pending'])) {
            return ['state' => $data['_pending']];
        }
        $outputs = $data['result']['outputs'] ?? null;
        if (! is_array($outputs) || ! array_is_list($outputs) || count($outputs) > 100) {
            throw new OdyseePublishingFailure('status', 'response');
        }
        // SDK transactions also contain change outputs; only one claim may
        // match our job. Transaction outputs do not carry is_my_output.
        $matches = array_values(array_filter($outputs, static fn ($row): bool => is_array($row)
            && ($row['name'] ?? null) === self::name($object->jobId)));
        if (count($matches) !== 1) {
            throw new OdyseePublishingFailure('status', 'response');
        }
        $claim = $matches[0];
        $this->assertClip($claim, $object->jobId, false);
        if ((int) ($claim['value']['source']['size'] ?? 0) !== $object->bytes
            || ($claim['value']['source']['media_type'] ?? null) !== 'video/mp4'
            || ! isset($claim['value']['video'])) {
            throw new OdyseePublishingFailure('status', 'media');
        }
        if ($this->ownedClip($object->jobId, $claim['claim_id']) === null) {
            throw new OdyseePublishingFailure('status', 'identity');
        }

        return ['state' => 'published', 'claim_id' => $claim['claim_id'], 'name' => $claim['name']];
    }

    /** Owner-only account list also permits reconciliation after a lost response. */
    public function ownedClip(string $jobId, ?string $claimId = null): ?array
    {
        $name = self::name($jobId);
        if ($claimId !== null && ! preg_match('/^[a-f0-9]{40}$/D', $claimId)) {
            throw new InvalidArgumentException('Invalid Odysee clip reference.');
        }
        $data = $this->rpc('stream_list', ['name' => [$name], ...($claimId ? ['claim_id' => [$claimId]] : []),
            'page' => 1, 'page_size' => 2], 'ownership');
        $items = $data['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || count($items) > 1) {
            throw new OdyseePublishingFailure('ownership', 'response');
        }
        if ($items === []) {
            return null;
        }
        $this->assertClip($items[0], $jobId);
        if ($claimId !== null && $items[0]['claim_id'] !== $claimId) {
            throw new OdyseePublishingFailure('ownership', 'identity');
        }

        return $items[0];
    }

    public function visibility(string $jobId, string $claimId, bool $public): void
    {
        $claim = $this->ownedClip($jobId, $claimId);
        if ($claim === null) {
            throw new OdyseePublishingFailure('visibility', 'identity');
        }
        $tags = $claim['value']['tags'] ?? [];
        if (! is_array($tags) || ! array_is_list($tags) || count($tags) > 100
            || array_filter($tags, static fn ($tag): bool => ! is_string($tag) || strlen($tag) > 512)) {
            throw new OdyseePublishingFailure('visibility', 'response');
        }
        if (in_array('c:unlisted', $tags, true) !== $public && ! in_array('c:private', $tags, true)) {
            return;
        }
        $tags = array_values(array_diff($tags, ['c:unlisted', 'c:private', 'c:scheduled:show', 'c:scheduled:hide']));
        if (! $public) {
            $tags[] = 'c:unlisted';
        }
        $this->rpc('stream_update', ['claim_id' => $claimId, 'channel_id' => $this->channelId,
            'clear_tags' => true, 'tags' => $tags, 'release_time' => $public ? time() : 2147483647,
            'blocking' => true, 'preview' => false], 'visibility');
        $confirmed = $this->ownedClip($jobId, $claimId);
        if ($confirmed === null || in_array('c:unlisted', $confirmed['value']['tags'] ?? [], true) === $public) {
            throw new OdyseePublishingFailure('visibility', 'unconfirmed');
        }
    }

    /** Wait for a durable provider confirmation, separately from processing. */
    public function confirmed(ClipStagingObject $object, string $claimId): bool
    {
        $claim = $this->ownedClip($object->jobId, $claimId);
        if ($claim === null) {
            return false;
        }
        if ((int) ($claim['value']['source']['size'] ?? 0) !== $object->bytes
            || ($claim['value']['source']['media_type'] ?? null) !== 'video/mp4'
            || ! isset($claim['value']['video'])) {
            throw new OdyseePublishingFailure('confirmation', 'media');
        }

        return is_int($claim['confirmations'] ?? null) && $claim['confirmations'] >= 1;
    }

    /** Obtain provider playback metadata, never download media into PHP. */
    public function playback(string $jobId, string $claimId): string
    {
        $claim = $this->ownedClip($jobId, $claimId);
        if ($claim === null) {
            throw new OdyseePublishingFailure('playback', 'identity');
        }
        $access = $this->access($claim, $claimId);
        $data = $this->rpc('get', ['uri' => 'lbry://'.self::name($jobId).'#'.$claimId, 'save_file' => false, ...$access], 'playback');
        $url = $data['streaming_url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! preg_match('/^(?:[a-z0-9-]+\.)*odycdn\.com$/D', $parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new OdyseePublishingFailure('playback', 'response');
        }

        return $url;
    }

    public function embed(string $jobId, string $claimId): string
    {
        $claim = $this->ownedClip($jobId, $claimId);
        if ($claim === null) {
            throw new OdyseePublishingFailure('playback', 'identity');
        }
        $access = $this->access($claim, $claimId);

        return 'https://odysee.com/$/embed/'.rawurlencode(self::name($jobId).':'.$claimId)
            .($access ? '?'.http_build_query($access, '', '&', PHP_QUERY_RFC3986) : '');
    }

    private function access(array $claim, string $claimId): array
    {
        if (! in_array('c:unlisted', $claim['value']['tags'] ?? [], true)) {
            return [];
        }
        // Match the official webclient's owned unlisted-claim access flow.
        $signed = $this->rpc('channel_sign', ['channel_id' => $this->channelId, 'hexdata' => bin2hex($claimId)], 'playback');
        $signature = $signed['signature'] ?? null;
        $timestamp = $signed['signing_ts'] ?? null;
        if (! is_string($signature) || ! preg_match('/^[a-f0-9]{128}$/D', $signature)
            || ! is_string($timestamp) || ! preg_match('/^[0-9]{10,16}$/D', $timestamp)) {
            throw new OdyseePublishingFailure('playback', 'response');
        }

        return ['signature' => $signature, 'signature_ts' => $timestamp];
    }

    /** Deletes only this job's own claim; never arbitrary account content. */
    public function abandon(string $jobId, string $claimId): void
    {
        if ($this->ownedClip($jobId, $claimId) !== null) {
            $this->rpc('stream_abandon', ['claim_id' => $claimId, 'blocking' => true, 'preview' => false], 'abandon');
        }
        if ($this->ownedClip($jobId, $claimId) !== null) {
            throw new OdyseePublishingFailure('abandon', 'unconfirmed');
        }
    }

    private function assertClip(mixed $claim, string $jobId, bool $requireOwnerFlag = true): void
    {
        if (! is_array($claim) || ! is_string($claim['claim_id'] ?? null)
            || ! preg_match('/^[a-f0-9]{40}$/D', $claim['claim_id'])
            || ($claim['name'] ?? null) !== self::name($jobId)
            || ($claim['signing_channel']['claim_id'] ?? null) !== $this->channelId
            || ($requireOwnerFlag && ($claim['is_my_output'] ?? null) !== true)) {
            throw new OdyseePublishingFailure('ownership', 'identity');
        }
    }

    private static function validQuery(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^[a-f0-9]{32}[0-9]{10,16}$/D', $id);
    }

    private function rpc(string $method, array $params, string $operation): array
    {
        $data = $this->request('POST', 'proxy?m='.$method, ['jsonrpc' => '2.0', 'id' => 1,
            'method' => $method, 'params' => $params], $operation);
        if (! is_array($data['result'] ?? null)) {
            throw new OdyseePublishingFailure($operation, 'response');
        }

        return $data['result'];
    }

    private function request(string $method, string $path, #[\SensitiveParameter] ?array $body, string $operation, bool $pending = false): array
    {
        $response = null;
        $status = null;
        try {
            $response = ($this->http ??= new Client())->request($method, self::API.$path, [
                'headers' => ['X-Lbry-Auth-Token' => $this->token, 'Accept' => 'application/json'],
                ...($body === null ? [] : ['json' => $body]),
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'stream' => true, 'connect_timeout' => 5, 'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            if ($pending && in_array($status, [204, 404], true)) {
                return ['_pending' => $status === 204 ? 'pending' : 'missing'];
            }
            if (! in_array($status, [200, 201], true)) {
                throw new OdyseePublishingFailure($operation, 'http', $status);
            }
            $json = Utils::copyToString($response->getBody(), 262145);
            if (strlen($json) > 262144) {
                throw new OdyseePublishingFailure($operation, 'response', $status);
            }
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) {
                throw new OdyseePublishingFailure($operation, 'response', $status);
            }
            if (! empty($data['error'])) {
                $code = $data['error']['code'] ?? null;
                throw new OdyseePublishingFailure($operation,
                    in_array($code, [-32700, -32600, -32601, -32602], true) ? 'request' : 'provider', $status);
            }

            return $data;
        } catch (OdyseePublishingFailure $error) {
            throw $error;
        } catch (Throwable) {
            throw new OdyseePublishingFailure($operation, 'transport', $status);
        } finally {
            $response?->getBody()->close();
        }
    }

    public function jsonSerialize(): array
    {
        return ['provider' => 'odysee', 'purpose' => 'clip-publication'];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
