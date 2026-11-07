<?php

namespace App\Support\Clips;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;
use Throwable;

/** Account discovery only. No media upload, publication or wallet transaction. */
final class OdyseePublishingConnection implements JsonSerializable
{
    private const PROXY = 'https://api.na-backend.odysee.com/api/v1/proxy';

    private string $token;

    private string $channelId;

    public function __construct(array $settings, private ?ClientInterface $http = null)
    {
        $this->token = is_string($settings['auth_token'] ?? null) ? trim($settings['auth_token']) : '';
        $this->channelId = is_string($settings['channel_id'] ?? null) ? trim($settings['channel_id']) : '';
    }

    public function problems(bool $requireChannel = true): array
    {
        $problems = [];
        if ($this->token === '') {
            $problems[] = 'auth_token: missing';
        } elseif (strlen($this->token) > 2048 || preg_match('/[^\x21-\x7e]/', $this->token)) {
            $problems[] = 'auth_token: invalid format';
        }
        if ($this->channelId === '') {
            if ($requireChannel) {
                $problems[] = 'channel_id: missing';
            }
        } elseif (! preg_match('/^[a-f0-9]{40}$/D', $this->channelId)) {
            $problems[] = 'channel_id: invalid format';
        }

        return $problems;
    }

    /** @return array<int, array{id: string, name: string, selected: bool}> */
    public function ownedChannels(): array
    {
        if ($this->problems(false)) {
            throw new InvalidArgumentException('Odysee publishing configuration is incomplete or invalid.');
        }
        $http = $this->http ?? new Client();
        $channels = [];
        // Bounded discovery also handles accounts with more than one SDK page.
        for ($page = 1; $page <= 10; $page++) {
            $response = null;
            try {
                $response = $http->request('POST', self::PROXY.'?m=channel_list', [
                    'headers' => ['X-Lbry-Auth-Token' => $this->token, 'Accept' => 'application/json'],
                    'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'channel_list', 'params' => [
                        'page' => $page, 'page_size' => 100,
                    ]],
                    // Never forward the account token to a redirected host.
                    'allow_redirects' => false, 'http_errors' => false,
                    'verify' => true, 'stream' => true,
                    'connect_timeout' => 5, 'timeout' => 20,
                ]);
                if ($response->getStatusCode() !== 200) {
                    throw new RuntimeException();
                }
                $body = $response->getBody()->read(1048577);
                if (strlen($body) > 1048576) {
                    throw new RuntimeException();
                }
                $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                $items = $data['result']['items'] ?? null;
                if (! empty($data['error']) || ! is_array($items) || ! array_is_list($items) || count($items) > 100) {
                    throw new RuntimeException();
                }
                foreach ($items as $item) {
                    $id = $item['claim_id'] ?? null;
                    $name = $item['name'] ?? null;
                    if (! is_string($id) || ! preg_match('/^[a-f0-9]{40}$/D', $id)
                        || ! is_string($name) || ! str_starts_with($name, '@') || strlen($name) > 512
                        || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                        throw new RuntimeException();
                    }
                    $channels[$id] = ['id' => $id, 'name' => $name, 'selected' => $id === $this->channelId];
                }
                if (count($items) < 100) {
                    return array_values($channels);
                }
            } catch (Throwable) {
                // Raw HTTP errors can contain authentication headers and bodies.
                throw new RuntimeException('Odysee account discovery failed. Check the token and provider availability locally.');
            } finally {
                $response?->getBody()->close();
            }
        }

        throw new RuntimeException('Odysee channel discovery exceeded its bounded page limit.');
    }

    public function jsonSerialize(): array
    {
        return ['provider' => 'odysee', 'configured' => $this->problems() === [], 'problems' => $this->problems()];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
