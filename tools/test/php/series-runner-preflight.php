<?php

declare(strict_types=1);

/** Pure configuration check; deliberately does not open a database connection. */
function seriesRunnerConfigurationError(array $settings): ?string
{
    if (($settings['acknowledged'] ?? null) !== '1') return 'disposable-database acknowledgement missing';
    if (($settings['environment'] ?? null) !== 'testing') return 'testing environment required';
    if (($settings['cached'] ?? true) !== false) return 'cached configuration is not allowed';
    if (($settings['connection'] ?? null) !== 'mysql') return 'MySQL test connection required';
    $db = $settings['database'] ?? [];
    if (($db['driver'] ?? null) !== 'mysql') return 'MySQL driver required';
    if (! is_string($db['database'] ?? null) || ! preg_match('/\Acrozztube_series_test(?:_[a-z0-9_]+)?\z/D', $db['database'])) return 'dedicated test database name required';
    if (! in_array($db['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)) return 'loopback database host required';
    $port = (string) ($db['port'] ?? 3306);
    if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) return 'valid database port required';
    if (($db['charset'] ?? 'utf8mb4') !== 'utf8mb4') return 'test database charset required';
    foreach (['url', 'unix_socket', 'prefix', 'read', 'write', 'options'] as $key) {
        if (! empty($db[$key])) return 'connection overrides are not allowed';
    }
    return null;
}

// Tests may import the pure validator without booting Laravel or accessing data.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

try {
    // These checks precede even application boot, so a normal .env is never
    // enough to opt into a write-capable local runner.
    if (getenv('CROZZTUBE_SERIES_DISPOSABLE_DB') !== '1' || getenv('APP_ENV') !== 'testing') {
        throw new RuntimeException('explicit disposable testing environment required');
    }
    require dirname(__DIR__, 3).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    if ($app->configurationIsCached()) throw new RuntimeException('cached configuration is not allowed');
    // Load configuration without registering/booting application providers:
    // a rejected target must not run provider code that could access data.
    $app->bootstrapWith([
        Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
    ]);
    $database = config('database.connections.mysql');
    $error = seriesRunnerConfigurationError([
        'acknowledged' => getenv('CROZZTUBE_SERIES_DISPOSABLE_DB'),
        'environment' => $app->environment(),
        'cached' => $app->configurationIsCached(),
        'connection' => config('database.default'),
        'database' => $database,
    ]);
    if ($error !== null) throw new RuntimeException($error);
    $connection = new PDO(
        'mysql:host='.$database['host'].';port='.($database['port'] ?? 3306).';dbname='.$database['database'].';charset=utf8mb4',
        $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $query = $connection->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
    $query->execute([$database['database']]);
    if ((int) $query->fetchColumn() !== 0) throw new RuntimeException('test database must be empty');
    echo "Verified empty disposable series test database.\n";
} catch (Throwable $error) {
    // Connection exceptions can contain credentials or host details. Keep them
    // out of terminal output and reports.
    fwrite(STDERR, "Series runner refused: isolated empty testing database verification failed.\n");
    exit(1);
}
