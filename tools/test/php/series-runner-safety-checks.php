<?php

declare(strict_types=1);

require __DIR__.'/series-runner-preflight.php';

$valid = [
    'acknowledged' => '1', 'environment' => 'testing', 'cached' => false,
    'connection' => 'mysql',
    'database' => ['driver' => 'mysql', 'database' => 'crozztube_series_test', 'host' => '127.0.0.1'],
];
$checks = 0;
$check = function (array $settings, bool $accepted, string $case) use (&$checks): void {
    if ((seriesRunnerConfigurationError($settings) === null) !== $accepted) {
        throw new RuntimeException('Runner safety check failed: '.$case);
    }
    $checks++;
};
$check($valid, true, 'explicit disposable configuration');
$check(array_replace_recursive($valid, ['database' => ['database' => 'crozztube_series_test_job_123']]), true, 'isolated job suffix');
foreach ([
    ['acknowledged' => '0'], ['environment' => 'production'], ['cached' => true],
    ['connection' => 'sqlite'], ['database' => ['driver' => 'pgsql']],
    ['database' => ['database' => 'crozztube']],
    ['database' => ['database' => 'crozztube_series_test/other']],
    ['database' => ['host' => 'production.example.test']],
    ['database' => ['host' => ['127.0.0.1', 'other.example.test']]],
    ['database' => ['port' => '3306;dbname=other']],
    ['database' => ['port' => 0]],
    ['database' => ['port' => 65536]],
    ['database' => ['charset' => 'utf8mb4;dbname=other']],
    ['database' => ['url' => 'mysql://fixture.invalid/other']],
    ['database' => ['unix_socket' => '/tmp/fixture.sock']],
    ['database' => ['prefix' => 'live_']],
    ['database' => ['read' => ['host' => 'other.example.test']]],
    ['database' => ['write' => ['database' => 'crozztube']]],
    ['database' => ['options' => [1002 => 'fixture-only-init-command']]],
] as $i => $override) {
    $check(array_replace_recursive($valid, $override), false, 'unsafe configuration '.($i + 1));
}
$check([], false, 'missing configuration');
echo "Series runner configuration safety: $checks checks passed; no database access.\n";
