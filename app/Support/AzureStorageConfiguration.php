<?php

namespace App\Support;

use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\Common\Helpers\ConnectionStringHelper;

class AzureStorageConfiguration
{
    public static function validateConnectionString(string $connectionString): void
    {
        try {
            if ($connectionString !== 'UseDevelopmentStorage=true') {
                $segments = [];
                foreach (explode(';', $connectionString) as $segment) {
                    if ($segment === '') continue;
                    $pair = explode('=', $segment, 2);
                    if (count($pair) !== 2 || $pair[0] === '' || $pair[1] === '' || isset($segments[$pair[0]])) {
                        throw new \InvalidArgumentException;
                    }
                    $segments[$pair[0]] = $pair[1];
                }
                if (isset($segments['DefaultEndpointsProtocol']) && ! in_array($segments['DefaultEndpointsProtocol'], ['http', 'https'], true)) throw new \InvalidArgumentException;
                if (isset($segments['BlobEndpoint'])) {
                    $parts = parse_url($segments['BlobEndpoint']);
                    if (! filter_var($segments['BlobEndpoint'], FILTER_VALIDATE_URL) || ! is_array($parts)
                        || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new \InvalidArgumentException;
                }
                if (isset($segments['SharedAccessSignature'])) {
                    parse_str(ltrim($segments['SharedAccessSignature'], '?'), $sas);
                    if (empty($sas['sig']) || empty($sas['sv']) || (empty($sas['sp']) && empty($sas['si']))) throw new \InvalidArgumentException;
                } else {
                    if (empty($segments['AccountName']) || empty($segments['AccountKey'])
                        || ! base64_decode($segments['AccountKey'], true)) throw new \InvalidArgumentException;
                }
            }
            $endpoint = ConnectionStringHelper::getBlobEndpoint($connectionString);
            if ($endpoint === null || ! in_array(strtolower($endpoint->getScheme()), ['http', 'https'], true)
                || $endpoint->getHost() === '' || $endpoint->getUserInfo() !== ''
                || $endpoint->getQuery() !== '' || $endpoint->getFragment() !== '') throw new \InvalidArgumentException;
            // Construction only: validates credentials and URI without any Azure request.
            BlobServiceClient::fromConnectionString($connectionString);
        } catch (\Throwable) {
            // SDK diagnostics can include the full connection string. Never forward them.
            throw new \InvalidArgumentException('Invalid Azure connection string. Use an account-key or SAS connection string with a valid Blob endpoint.');
        }
    }
}
