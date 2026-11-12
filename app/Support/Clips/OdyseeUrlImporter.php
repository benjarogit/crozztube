<?php

namespace App\Support\Clips;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use JsonSerializable;
use Throwable;

/** Registers a sealed Oracle object. All media transfer is Oracle → Odysee. */
final class OdyseeUrlImporter implements JsonSerializable
{
    private const AUTHORIZE = 'https://api.na-backend.odysee.com/api/v1/asynqueries/urls/';

    private const UPLOAD_LOCATIONS = [
        'https://uploads-v4.cluster-alpha.odysee.com/v1/urls/',
        'https://uploads-v4.na-backend.odysee.com/v1/urls/',
    ];

    private string $token;

    public function __construct(#[\SensitiveParameter] array $settings, private ?ClientInterface $http = null)
    {
        if ((new OdyseePublishingConnection($settings))->problems()) {
            throw new InvalidArgumentException('Odysee publishing configuration is incomplete or invalid.');
        }
        $this->token = trim($settings['auth_token']);
    }

    /**
     * Caller holds the job lease and persists the returned reference before
     * publishing. Never blindly repeat a registration with an uncertain result.
     * A URL cannot be supplied by an end user: it is minted for this sealed job.
     */
    public function register(OracleStagingStore $store, ClipStagingObject $object): OdyseeRemoteUpload
    {
        $url = $store->downloadGrant($object, new DateTimeImmutable('+30 minutes'));
        $auth = $this->request(self::AUTHORIZE, ['X-Lbry-Auth-Token' => $this->token], null, 'authorization');
        $token = $auth['payload']['token'] ?? null;
        $location = $auth['payload']['location'] ?? null;
        if (($auth['status'] ?? null) !== 'upload_token_created'
            || ! is_string($token) || $token === '' || strlen($token) > 4096 || preg_match('/[^\x21-\x7e]/', $token)
            || ! in_array($location, self::UPLOAD_LOCATIONS, true)) {
            throw new OdyseeImportFailure('authorization');
        }

        // The account token never goes to the upload service, only its scoped token.
        $registered = $this->request($location, ['Authorization' => 'Bearer '.$token], ['url' => $url], 'registration');
        $id = $registered['payload']['upload_id'] ?? null;
        if (($registered['status'] ?? null) !== 'url_created' || ! is_string($id)
            || ! preg_match('/^[a-zA-Z0-9+_.-]{32,256}$/D', $id)) {
            throw new OdyseeImportFailure('registration');
        }

        return new OdyseeRemoteUpload($location.$id);
    }

    private function request(string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?array $payload, string $stage): array
    {
        $response = null;
        $status = null;
        try {
            $response = ($this->http ??= new Client())->request('POST', $url, [
                'headers' => [...$headers, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
                ...($payload === null ? [] : ['json' => $payload]),
                'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
                'stream' => true, 'connect_timeout' => 5, 'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            if (! in_array($status, [200, 201], true)) {
                throw new OdyseeImportFailure($stage, $status);
            }
            $body = Utils::copyToString($response->getBody(), 65537);
            if (strlen($body) > 65536) {
                throw new OdyseeImportFailure($stage, $status);
            }
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data) || ! empty($data['error'])) {
                throw new OdyseeImportFailure($stage, $status);
            }

            return $data;
        } catch (Throwable) {
            // No automatic retry or exception chaining: requests contain capabilities.
            throw new OdyseeImportFailure($stage, $status);
        } finally {
            $response?->getBody()->close();
        }
    }

    public function jsonSerialize(): array
    {
        return ['provider' => 'odysee', 'purpose' => 'sealed-url-import'];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
