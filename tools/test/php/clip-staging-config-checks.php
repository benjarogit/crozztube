<?php

require __DIR__.'/../../../vendor/autoload.php';

use App\Support\Clips\OracleStagingConnection;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$settings = [
    'region' => 'eu-frankfurt-1',
    'namespace' => 'testnamespace',
    'bucket' => 'crozztube-staging-fixture',
    'access_key_id' => 'fixture-access',
    'secret_access_key' => 'fixture-secret-not-real',
];
$empty = new OracleStagingConnection([]);
$check(count($empty->problems()) === 5, 'All missing fields must be diagnosed without networking.');
try {
    $empty->client();
    throw new RuntimeException('An empty connection was accepted.');
} catch (InvalidArgumentException $error) {
    $check(str_contains($error->getMessage(), 'missing'), 'Incomplete configuration must fail before a client is created.');
}
$connection = new OracleStagingConnection($settings);
$check($connection->problems() === [], 'Valid dedicated configuration is accepted.');
$check($connection->endpoint() === 'https://testnamespace.compat.objectstorage.eu-frankfurt-1.oci.customer-oci.com', 'Endpoint stays in the expected Oracle HTTPS domain.');
$check($connection->bucket() === $settings['bucket'], 'The exact private staging bucket is retained.');
ob_start();
var_dump($connection);
$debug = (string) ob_get_clean();
foreach ([json_encode($connection), $debug] as $diagnostic) {
    $check(! str_contains($diagnostic, $settings['access_key_id']) && ! str_contains($diagnostic, $settings['secret_access_key']), 'Diagnostics must not expose credentials.');
}
foreach ([
    ['region', 'https://attacker.invalid'],
    ['namespace', 'localhost/path'],
    ['namespace', 'somewhere.invalid'],
    ['bucket', '../escape'],
    ['secret_access_key', "invalid\ncredential"],
    ['access_key_id', ['not-a-string']],
] as [$field, $value]) {
    $invalid = new OracleStagingConnection([...$settings, $field => $value]);
    $check($invalid->problems() !== [], 'Unsafe or malformed configuration is rejected.');
    try {
        $invalid->client();
        throw new RuntimeException('Unsafe client was constructed.');
    } catch (InvalidArgumentException $error) {
        $check(! str_contains($error->getMessage(), $settings['secret_access_key']), 'Validation errors remain redacted.');
    }
}
$client = $connection->client();
$request = $client->createPresignedRequest($client->getCommand('PutObject', ['Bucket' => $connection->bucket(), 'Key' => 'incoming/test.mp4']), '+1 minute');
$uri = $request->getUri();
$check($request->getMethod() === 'PUT', 'The limited request is for uploading, not arbitrary S3 administration.');
$check($uri->getHost() === 'testnamespace.compat.objectstorage.eu-frankfurt-1.oci.customer-oci.com', 'Signing cannot redirect uploads to another service.');
parse_str($uri->getQuery(), $signedParameters);
$check(str_contains((string) ($signedParameters['X-Amz-Credential'] ?? ''), '/us-east-1/s3/aws4_request'), 'OCI home-region compatibility signing uses the interoperable us-east-1 scope while the endpoint remains in Frankfurt.');
$check($uri->getPath() === '/crozztube-staging-fixture/incoming/test.mp4', 'Signing is scoped to the exact bucket and object.');
$check(! str_contains((string) $uri, $settings['secret_access_key']), 'Signing never transmits the secret key.');
$check(str_contains($uri->getQuery(), 'X-Amz-Signature='), 'The installed AWS SDK signs the Oracle-compatible request.');
echo $checks." Oracle staging configuration checks passed. No network requests or database changes.\n";
