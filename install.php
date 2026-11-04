<?php

declare(strict_types=1);

$root = __DIR__;
$lock = $root.'/storage/app/installed.lock';
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string) ($_GET['action'] ?? ''));
$installerEventFile = null;
const PLAYHUB_LICENSE_SERVER_URL = 'https://licenses.kontackt.de/';
const PLAYHUB_WINDOWS_BINARIES = [
    'php' => 'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe',
    'node' => 'C:/Program Files/nodejs/node.exe',
    'npm' => 'C:/Program Files/nodejs/node_modules/npm/bin/npm-cli.js',
    'ffmpeg' => 'D:/laragon/ffmpeg-master-latest-win64-gpl/bin/ffmpeg.exe',
    'ffprobe' => 'D:/laragon/ffmpeg-master-latest-win64-gpl/bin/ffprobe.exe',
    'docker' => 'C:/Program Files/Docker/Docker/resources/bin/docker.exe',
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function begin_event_stream(): void
{
    @set_time_limit(0);
    ignore_user_abort(true);
    ini_set('display_errors', '0');
    ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Accel-Buffering: no');
    header('X-Content-Type-Options: nosniff');

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    ob_implicit_flush(true);
}

function installer_event(string $type, string $message, array $context = []): void
{
    global $installerEventFile;

    $payload = array_merge(['type' => $type, 'message' => $message], $context);
    $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    if (is_string($installerEventFile) && $installerEventFile !== '') {
        file_put_contents($installerEventFile, $line, FILE_APPEND | LOCK_EX);
        return;
    }

    echo $line;
    flush();
}

function env_value(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9_:\/\.\-@]+$/', $value)) {
        return $value;
    }

    return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
}

function set_env_value(string $content, string $key, string $value): string
{
    $line = $key.'='.env_value($value);

    if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $content)) {
        return preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $content) ?? $content;
    }

    return rtrim($content).PHP_EOL.$line.PHP_EOL;
}

function env_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function command_execution_available(): bool
{
    return function_exists('exec') && is_callable('exec');
}

function is_windows_host(): bool
{
    return DIRECTORY_SEPARATOR === '\\';
}

function normalize_binary_path(string $path): string
{
    return str_replace('\\', '/', trim($path));
}

function configured_binary(string $key): ?string
{
    $envKey = match ($key) {
        'php' => 'PLAYHUB_PHP_CLI',
        'node' => 'PLAYHUB_NODE',
        'npm' => 'PLAYHUB_NPM',
        'ffmpeg' => 'PLAYHUB_FFMPEG_BINARY',
        'ffprobe' => 'PLAYHUB_FFPROBE_BINARY',
        'docker' => 'PLAYHUB_DOCKER',
        default => '',
    };

    if ($envKey !== '') {
        $envBinary = getenv($envKey);
        if (is_string($envBinary) && trim($envBinary) !== '') {
            return normalize_binary_path($envBinary);
        }
    }

    $configured = PLAYHUB_WINDOWS_BINARIES[$key] ?? '';
    return is_string($configured) && trim($configured) !== '' ? normalize_binary_path($configured) : null;
}

function laragon_root(): ?string
{
    foreach (['LARAGON_ROOT', 'LARAGON_HOME'] as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '' && is_dir($value)) {
            return rtrim(normalize_binary_path($value), '/');
        }
    }

    $root = normalize_binary_path(__DIR__);
    $position = stripos($root, '/www/');
    if ($position === false) {
        return null;
    }

    $candidate = substr($root, 0, $position);
    return $candidate !== '' && is_dir($candidate) ? rtrim($candidate, '/') : null;
}

function laragon_glob(string $pattern): array
{
    $root = laragon_root();
    if ($root === null) {
        return [];
    }

    return @glob($root.'/'.$pattern) ?: [];
}

function existing_binary_path(string $path): ?string
{
    $path = normalize_binary_path($path);
    $candidates = [$path];
    if (is_windows_host() && pathinfo($path, PATHINFO_EXTENSION) === '') {
        foreach (['.exe', '.cmd', '.bat', '.com'] as $extension) {
            $candidates[] = $path.$extension;
        }
    }

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function windows_cmd_arg(string $value): string
{
    return '"'.str_replace(['"', '%'], ['\"', '%%'], $value).'"';
}

function binary_command(string $binary, string $arguments = ''): string
{
    $binary = normalize_binary_path($binary);
    $command = escapeshellarg($binary).($arguments !== '' ? ' '.$arguments : '');
    if (! is_windows_host()) {
        return $command;
    }

    $extension = strtolower(pathinfo($binary, PATHINFO_EXTENSION));
    if ($extension === 'js') {
        $node = configured_binary('node') ?? command_exists('node');
        if ($node !== null) {
            return escapeshellarg($node).' '.escapeshellarg($binary).($arguments !== '' ? ' '.$arguments : '');
        }
    }

    if (! in_array($extension, ['cmd', 'bat'], true)) {
        return $command;
    }

    return 'cmd /D /S /C '.windows_cmd_arg(windows_cmd_arg($binary).($arguments !== '' ? ' '.$arguments : ''));
}

function run_command(string $command, string $cwd): array
{
    if (! command_execution_available()) {
        return [127, 'PHP command execution is disabled.'];
    }

    $output = [];
    $code = 0;
    $cd = is_windows_host() ? 'cd /D '.escapeshellarg($cwd) : 'cd '.escapeshellarg($cwd);
    exec($cd.' && '.$command.' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
}

function command_exists(string $command): ?string
{
    if (! command_execution_available() || $command === '') {
        return null;
    }

    if (preg_match('/[\/\\\\]/', $command)) {
        return existing_binary_path($command);
    }

    $output = [];
    $code = 0;
    $lookup = is_windows_host()
        ? 'where.exe '.escapeshellarg($command).' 2>NUL'
        : 'command -v '.escapeshellarg($command).' 2>/dev/null';
    exec($lookup, $output, $code);

    return $code === 0 && ! empty($output[0]) ? trim($output[0]) : null;
}

function find_binary(string $name, array $knownPaths = []): ?string
{
    $candidates = array_merge([$name], $knownPaths);
    foreach (array_unique(array_filter($candidates)) as $candidate) {
        $resolved = command_exists((string) $candidate);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    return null;
}

function is_cli_php(string $binary): bool
{
    if ($binary === '' || ! command_execution_available()) {
        return false;
    }

    $output = [];
    $code = 0;
    $nullDevice = is_windows_host() ? 'NUL' : '/dev/null';
    exec(escapeshellarg($binary).' -r '.escapeshellarg('echo PHP_SAPI;').' 2>'.$nullDevice, $output, $code);

    return $code === 0 && isset($output[0]) && trim($output[0]) === 'cli';
}

function find_cli_php(): ?string
{
    $candidates = [];
    $configured = configured_binary('php');
    if ($configured !== null) {
        $candidates[] = $configured;
    }
    if (defined('PHP_BINDIR')) {
        $candidates[] = PHP_BINDIR.'/php';
        $candidates[] = dirname(PHP_BINDIR).'/bin/php';
    }
    if (defined('PHP_BINARY')) {
        $candidates[] = PHP_BINARY;
        $candidates[] = dirname(dirname(PHP_BINARY)).'/bin/php';
    }
    $candidates = array_merge($candidates, [
        '/www/server/php/85/bin/php',
        '/www/server/php/84/bin/php',
        '/www/server/php/83/bin/php',
        '/usr/bin/php8.5',
        '/usr/bin/php8.4',
        '/usr/bin/php8.3',
        '/usr/local/bin/php',
        '/usr/bin/php',
        command_exists('php'),
    ], laragon_glob('bin/php/*/php.exe'));

    foreach (array_unique(array_filter($candidates)) as $candidate) {
        if (is_cli_php((string) $candidate)) {
            return (string) $candidate;
        }
    }

    return null;
}

function find_npm(): ?string
{
    $candidates = [];
    $configured = configured_binary('npm');
    if ($configured !== null) {
        $candidates[] = $configured;
    }
    $candidates = array_merge($candidates, [
        command_exists('npm'),
        'C:/Program Files/nodejs/npm.cmd',
        'C:/Program Files/nodejs/npm',
        '/usr/local/bin/npm',
        '/usr/bin/npm',
    ], laragon_glob('bin/nodejs/*/npm.cmd'), laragon_glob('bin/nodejs/*/npm'), @glob('/www/server/nodejs/*/bin/npm') ?: []);

    foreach (array_unique(array_filter($candidates)) as $candidate) {
        $resolved = command_exists((string) $candidate);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    return null;
}

function find_node(): ?string
{
    $candidates = [
        command_exists('node'),
        'C:/Program Files/nodejs/node.exe',
        '/usr/local/bin/node',
        '/usr/bin/node',
    ];
    $configured = configured_binary('node');
    if ($configured !== null) {
        array_unshift($candidates, $configured);
    }
    $candidates = array_merge($candidates, laragon_glob('bin/nodejs/*/node.exe'), @glob('/www/server/nodejs/*/bin/node') ?: []);

    foreach (array_unique(array_filter($candidates)) as $candidate) {
        $resolved = command_exists((string) $candidate);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    return null;
}

function command_first_line(?string $binary, string $arguments, string $root): ?string
{
    if ($binary === null) {
        return null;
    }

    [$code, $output] = run_command(binary_command($binary, $arguments), $root);
    if ($code !== 0 || trim($output) === '') {
        return null;
    }

    return trim(strtok($output, "\n") ?: $output);
}

function npm_package_version(?string $npm): ?string
{
    if ($npm === null) {
        return null;
    }

    $npm = normalize_binary_path($npm);
    $packageFiles = [];
    $name = strtolower(basename($npm));
    if ($name === 'npm-cli.js') {
        $packageFiles[] = dirname(dirname($npm)).'/package.json';
    }
    $packageFiles[] = dirname($npm).'/node_modules/npm/package.json';

    foreach (array_unique($packageFiles) as $packageFile) {
        if (! is_file($packageFile)) {
            continue;
        }

        $package = json_decode((string) file_get_contents($packageFile), true);
        $version = is_array($package) ? trim((string) ($package['version'] ?? '')) : '';
        if ($version !== '') {
            return $version;
        }
    }

    return null;
}

function npm_version(?string $npm, string $root): ?string
{
    $version = command_first_line($npm, '--version', $root);
    return $version !== null ? trim($version) : npm_package_version($npm);
}

function cli_php_info(?string $binary, string $root): array
{
    if ($binary === null) {
        return ['version' => null, 'extensions' => [], 'disabled' => []];
    }

    [$versionCode, $versionOutput] = run_command(escapeshellarg($binary).' -v', $root);
    [$modulesCode, $modulesOutput] = run_command(escapeshellarg($binary).' -m', $root);
    if ($versionCode !== 0 || $modulesCode !== 0) {
        return ['version' => null, 'extensions' => [], 'disabled' => []];
    }

    $version = null;
    if (preg_match('/^PHP\s+([0-9]+(?:\.[0-9]+){1,2})/m', $versionOutput, $matches)) {
        $version = $matches[1];
    }

    $extensions = [];
    foreach (preg_split('/\R/', $modulesOutput) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '[')) {
            continue;
        }
        $extensions[] = strtolower($line);
    }

    return ['version' => $version, 'extensions' => $extensions, 'disabled' => []];
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return $value === '-1' ? PHP_INT_MAX : 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    return match ($unit) {
        'g' => (int) ($number * 1024 * 1024 * 1024),
        'm' => (int) ($number * 1024 * 1024),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

function format_bytes(int|float $bytes): string
{
    if ($bytes >= 1024 ** 4) return number_format($bytes / (1024 ** 4), 1).' TB';
    if ($bytes >= 1024 ** 3) return number_format($bytes / (1024 ** 3), 1).' GB';
    if ($bytes >= 1024 ** 2) return number_format($bytes / (1024 ** 2), 1).' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1).' KB';

    return (string) $bytes.' B';
}

function add_requirement(array &$items, string $id, string $group, string $label, string $value, string $status, bool $required, string $detail): void
{
    $items[] = compact('id', 'group', 'label', 'value', 'status', 'required', 'detail');
}

function installer_requirements(string $root, ?string $cliPhp, ?string $node, ?string $npm, string $defaultUrl): array
{
    $items = [];
    $cliInfo = cli_php_info($cliPhp, $root);
    $webVersionReady = version_compare(PHP_VERSION, '8.3.0', '>=');
    $cliVersion = (string) ($cliInfo['version'] ?? '');
    $cliVersionReady = $cliVersion !== '' && version_compare($cliVersion, '8.3.0', '>=');

    add_requirement($items, 'php_web', 'PHP runtime', 'Web PHP 8.3+', PHP_VERSION, $webVersionReady ? 'pass' : 'fail', true, 'Laravel 13 requires PHP 8.3 or newer.');
    add_requirement($items, 'php_cli', 'PHP runtime', 'PHP CLI 8.3+', $cliVersion !== '' ? $cliVersion : 'Not detected', $cliVersionReady ? 'pass' : 'fail', true, $cliPhp ? 'CLI binary: '.$cliPhp : 'Set PLAYHUB_PHP_CLI to the full PHP CLI path.');

    $requiredExtensions = [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'intl', 'json',
        'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'zip', 'zlib',
    ];
    foreach ($requiredExtensions as $extension) {
        $webLoaded = extension_loaded($extension);
        $cliLoaded = in_array(strtolower($extension), (array) ($cliInfo['extensions'] ?? []), true);
        $loaded = $webLoaded && $cliLoaded;
        $value = $loaded ? 'Web + CLI' : (implode(', ', array_filter([$webLoaded ? 'Web only' : null, $cliLoaded ? 'CLI only' : null])) ?: 'Missing');
        add_requirement($items, 'ext_'.$extension, 'PHP extensions', 'ext-'.$extension, $value, $loaded ? 'pass' : 'fail', true, 'Required by the application or its production media workflow.');
    }

    $imagick = extension_loaded('imagick') && in_array('imagick', (array) ($cliInfo['extensions'] ?? []), true);
    add_requirement($items, 'ext_imagick', 'PHP extensions', 'ext-imagick', $imagick ? 'Web + CLI' : 'Optional', $imagick ? 'pass' : 'warning', false, 'Recommended for higher-quality image processing; GD remains available as fallback.');

    $execReady = command_execution_available();
    $procReady = function_exists('proc_open') && is_callable('proc_open');
    $nohup = is_windows_host()
        ? 'Windows background process launcher'
        : find_binary('nohup', ['/usr/bin/nohup', '/bin/nohup']);
    add_requirement($items, 'shell_exec', 'PHP runtime', 'Command execution', $execReady ? 'Enabled' : 'Disabled', $execReady ? 'pass' : 'fail', true, 'The installer and media jobs require PHP exec().');
    add_requirement($items, 'proc_open', 'PHP runtime', 'Streaming process output', $procReady ? 'Enabled' : 'Disabled', $procReady ? 'pass' : 'fail', true, 'The AJAX installation console requires PHP proc_open().');
    add_requirement($items, 'background_worker', 'PHP runtime', 'Background installer worker', $nohup ?: 'Not detected', $nohup ? 'pass' : 'fail', true, 'Required to keep installation running beyond web-server request timeouts.');

    $memory = ini_get('memory_limit') ?: '0';
    $memoryReady = ini_bytes($memory) >= 256 * 1024 * 1024;
    add_requirement($items, 'memory', 'PHP runtime', 'PHP memory limit', $memory, $memoryReady ? 'pass' : 'warning', false, '256 MB or more is recommended for video metadata and image processing.');
    $uploadLimit = min(ini_bytes((string) ini_get('upload_max_filesize')), ini_bytes((string) ini_get('post_max_size')));
    add_requirement($items, 'upload_limit', 'PHP runtime', 'HTTP upload limit', format_bytes($uploadLimit), $uploadLimit >= 16 * 1024 * 1024 ? 'pass' : 'fail', true, 'Must exceed the default 8 MB upload chunk size.');

    $vendorReady = is_file($root.'/vendor/autoload.php');
    add_requirement($items, 'composer', 'Application', 'Composer dependencies', $vendorReady ? 'Installed' : 'Missing', $vendorReady ? 'pass' : 'fail', true, 'Run composer install --no-dev --optimize-autoloader before opening this installer.');
    $packageReady = is_file($root.'/package.json') && is_file($root.'/package-lock.json');
    add_requirement($items, 'package_files', 'Application', 'Frontend package lock', $packageReady ? 'Available' : 'Missing', $packageReady ? 'pass' : 'fail', true, 'package.json and package-lock.json are required for deterministic installation.');

    $nodeLine = command_first_line($node, '--version', $root);
    $nodeVersion = $nodeLine ? ltrim($nodeLine, 'vV') : '';
    $nodeReady = $nodeVersion !== '' && version_compare($nodeVersion, '20.19.0', '>=');
    add_requirement($items, 'node', 'Frontend tooling', 'Node.js 20.19+', $nodeVersion !== '' ? $nodeVersion : 'Not detected', $nodeReady ? 'pass' : 'fail', true, $node ? 'Binary: '.$node : 'Install a current Node.js LTS release or set PLAYHUB_NODE.');
    $npmLine = npm_version($npm, $root);
    $npmReady = $npmLine !== null && version_compare(trim($npmLine), '9.0.0', '>=');
    add_requirement($items, 'npm', 'Frontend tooling', 'npm 9+', $npmLine ?: 'Not detected', $npmReady ? 'pass' : 'fail', true, $npm ? 'Binary: '.$npm : 'Install npm or set PLAYHUB_NPM.');

    $configuredFfmpeg = configured_binary('ffmpeg');
    $configuredFfprobe = configured_binary('ffprobe');
    $ffmpegPaths = array_merge(
        $configuredFfmpeg !== null ? [$configuredFfmpeg] : [],
        [
            'C:/ffmpeg/bin/ffmpeg.exe',
            'C:/Program Files/ffmpeg/bin/ffmpeg.exe',
            'C:/ProgramData/chocolatey/bin/ffmpeg.exe',
            '/usr/local/bin/ffmpeg',
            '/usr/bin/ffmpeg',
        ],
        laragon_glob('bin/ffmpeg/*/bin/ffmpeg.exe'),
        laragon_glob('bin/ffmpeg/*/ffmpeg.exe')
    );
    $ffprobePaths = array_merge(
        $configuredFfprobe !== null ? [$configuredFfprobe] : [],
        [
            'C:/ffmpeg/bin/ffprobe.exe',
            'C:/Program Files/ffmpeg/bin/ffprobe.exe',
            'C:/ProgramData/chocolatey/bin/ffprobe.exe',
            '/usr/local/bin/ffprobe',
            '/usr/bin/ffprobe',
        ],
        laragon_glob('bin/ffmpeg/*/bin/ffprobe.exe'),
        laragon_glob('bin/ffmpeg/*/ffprobe.exe')
    );
    $ffmpeg = find_binary('ffmpeg', $ffmpegPaths);
    $ffprobe = find_binary('ffprobe', $ffprobePaths);
    add_requirement($items, 'ffmpeg', 'Media and streaming', 'FFmpeg', command_first_line($ffmpeg, '-version', $root) ?: 'Not detected', $ffmpeg ? 'pass' : 'fail', true, 'Required for video renditions, thumbnails, clips, and replay processing.');
    add_requirement($items, 'ffprobe', 'Media and streaming', 'FFprobe', command_first_line($ffprobe, '-version', $root) ?: 'Not detected', $ffprobe ? 'pass' : 'fail', true, 'Required to inspect uploaded and recorded media.');

    $configuredDocker = configured_binary('docker');
    $dockerPaths = array_merge(
        $configuredDocker !== null ? [$configuredDocker] : [],
        [
            'C:/Program Files/Docker/Docker/resources/bin/docker.exe',
            'C:/ProgramData/chocolatey/bin/docker.exe',
            '/usr/bin/docker',
            '/usr/local/bin/docker',
        ]
    );
    $docker = find_binary('docker', $dockerPaths);
    $dockerLine = command_first_line($docker, '--version', $root);
    add_requirement($items, 'docker', 'Media and streaming', 'Docker CLI', $dockerLine ?: 'Not detected', $docker ? 'pass' : 'fail', true, 'Required for the LiveKit, ingress, egress, and Redis runtime.');
    $composeReady = false;
    $composeLine = null;
    if ($docker !== null) {
        [$composeCode, $composeOutput] = run_command(escapeshellarg($docker).' compose version', $root);
        $composeReady = $composeCode === 0;
        $composeLine = $composeReady ? trim(strtok($composeOutput, "\n") ?: $composeOutput) : null;
    }
    add_requirement($items, 'docker_compose', 'Media and streaming', 'Docker Compose v2', $composeLine ?: 'Not detected', $composeReady ? 'pass' : 'fail', true, 'The bundled streaming runtime uses Docker Compose v2.');

    $engineReady = false;
    $engineDetail = 'Docker daemon is not available to the website process.';
    if ($docker !== null && $composeReady) {
        $dockerInfoCommand = escapeshellarg($docker).' info --format '.escapeshellarg('{{.ServerVersion}}');
        $timeout = is_windows_host() ? null : find_binary('timeout', ['/usr/bin/timeout']);
        if ($timeout !== null) $dockerInfoCommand = escapeshellarg($timeout).' 5s '.$dockerInfoCommand;
        [$engineCode, $engineOutput] = run_command($dockerInfoCommand, $root);
        $engineReady = $engineCode === 0 && trim($engineOutput) !== '';
        if ($engineReady) {
            $engineDetail = 'Docker Engine '.trim($engineOutput).' is reachable.';
        } elseif (trim($engineOutput) !== '') {
            $engineDetail = trim(strtok($engineOutput, "\n") ?: $engineOutput);
        }
    }
    add_requirement($items, 'docker_engine', 'Media and streaming', 'Docker Engine access', $engineReady ? 'Reachable' : 'Root setup required', $engineReady ? 'pass' : 'warning', false, $engineReady ? $engineDetail : $engineDetail.' After the website install, run livekit/install.sh as root.');

    $redisSocket = @fsockopen('127.0.0.1', 6379, $redisError, $redisErrorText, 0.25);
    $redisReady = is_resource($redisSocket);
    if ($redisReady) fclose($redisSocket);
    $runtimeFilesReady = is_file($root.'/livekit/docker-compose.yml') && is_file($root.'/livekit/install.sh');
    add_requirement($items, 'redis_runtime', 'Media and streaming', 'Redis streaming runtime', $redisReady ? 'Listening on 127.0.0.1:6379' : 'Configure in step 2', $redisReady ? 'pass' : 'warning', false, $redisReady ? 'The default Redis endpoint is reachable; credentials are verified in step 2.' : 'Enter an existing Redis endpoint and credentials in step 2.');
    add_requirement($items, 'livekit_runtime', 'Media and streaming', 'LiveKit runtime package', $runtimeFilesReady ? 'Available' : 'Missing', $runtimeFilesReady ? 'pass' : 'fail', true, 'Includes server, ingress, egress, and runtime manager configuration.');

    $cron = find_binary('crontab', ['/usr/bin/crontab']);
    add_requirement($items, 'cron', 'Background services', 'Cron scheduler', $cron ?: 'Not detected', $cron ? 'pass' : 'warning', false, 'Required for scheduled publishing, replay finalization, pruning, and automatic backups.');

    $paths = [
        'storage' => $root.'/storage',
        'storage/app' => $root.'/storage/app',
        'storage/logs' => $root.'/storage/logs',
        'bootstrap/cache' => $root.'/bootstrap/cache',
        'uploads' => $root.'/uploads',
    ];
    foreach ($paths as $label => $path) {
        $writable = is_dir($path) && is_writable($path);
        add_requirement($items, 'writable_'.str_replace(['/', '.'], '_', $label), 'Filesystem', $label, $writable ? 'Writable' : 'Not writable', $writable ? 'pass' : 'fail', true, 'The web server must be able to create and update files in this directory.');
    }
    $languageDirectories = @glob($root.'/lang/*', GLOB_ONLYDIR) ?: [];
    $languagesWritable = is_dir($root.'/lang') && $languageDirectories !== [] && count(array_filter($languageDirectories, fn (string $path): bool => ! is_writable($path))) === 0;
    add_requirement($items, 'writable_languages', 'Filesystem', 'Language files', $languagesWritable ? count($languageDirectories).' packs writable' : 'Not writable', $languagesWritable ? 'pass' : 'fail', true, 'Required for administrators to update installed translations from the dashboard.');
    $rootWritable = is_writable($root);
    add_requirement($items, 'root_writable', 'Filesystem', 'Application root', $rootWritable ? 'Writable for installation' : 'Not writable', $rootWritable ? 'pass' : 'fail', true, 'Required temporarily to create .env, node_modules, and production assets.');
    $freeBytes = (int) (@disk_free_space($root) ?: 0);
    $diskStatus = $freeBytes >= 10 * 1024 ** 3 ? 'pass' : ($freeBytes >= 2 * 1024 ** 3 ? 'warning' : 'fail');
    add_requirement($items, 'disk', 'Filesystem', 'Available disk space', format_bytes($freeBytes), $diskStatus, true, 'At least 2 GB is required; 10 GB or more is recommended before storing videos and replays.');

    $https = str_starts_with(strtolower($defaultUrl), 'https://');
    add_requirement($items, 'https', 'Network', 'HTTPS', $https ? 'Enabled' : 'Not detected', $https ? 'pass' : 'warning', false, 'HTTPS is required for camera, microphone, secure cookies, and production WebRTC.');

    $failed = count(array_filter($items, fn (array $item): bool => $item['required'] && $item['status'] === 'fail'));
    $warnings = count(array_filter($items, fn (array $item): bool => $item['status'] === 'warning'));
    $passed = count(array_filter($items, fn (array $item): bool => $item['status'] === 'pass'));

    return [
        'ready' => $failed === 0,
        'summary' => compact('passed', 'warnings', 'failed'),
        'items' => $items,
        'runtime_ready' => $engineReady && $redisReady,
        'checked_at' => date(DATE_ATOM),
    ];
}

function request_payload(): array
{
    $decoded = json_decode((string) file_get_contents('php://input'), true);

    return is_array($decoded) ? $decoded : $_POST;
}

function validate_csrf(array $payload): bool
{
    $provided = (string) ($_SERVER['HTTP_X_INSTALLER_TOKEN'] ?? $payload['_token'] ?? '');
    $stored = (string) ($_SESSION['installer_csrf'] ?? '');

    return $provided !== '' && $stored !== '' && hash_equals($stored, $provided);
}

function validate_installer_payload(array $payload): array
{
    $purchaseCode = trim((string) ($payload['purchase_code'] ?? ''));
    $purchaseCode = preg_match('/^KNL-(?:[A-F0-9]{4}-){7}[A-F0-9]{4}$/i', $purchaseCode)
        ? strtoupper($purchaseCode)
        : strtolower($purchaseCode);
    $data = [
        'purchase_code' => $purchaseCode,
        'site_name' => trim((string) ($payload['site_name'] ?? '')),
        'app_url' => rtrim(trim((string) ($payload['app_url'] ?? '')), '/'),
        'timezone' => trim((string) ($payload['timezone'] ?? 'UTC')),
        'db_host' => trim((string) ($payload['db_host'] ?? '127.0.0.1')),
        'db_port' => trim((string) ($payload['db_port'] ?? '3306')),
        'db_name' => trim((string) ($payload['db_name'] ?? '')),
        'db_user' => trim((string) ($payload['db_user'] ?? '')),
        'db_pass' => (string) ($payload['db_pass'] ?? ''),
        'redis_host' => trim((string) ($payload['redis_host'] ?? '127.0.0.1')),
        'redis_port' => trim((string) ($payload['redis_port'] ?? '6379')),
        'redis_username' => trim((string) ($payload['redis_username'] ?? 'default')),
        'redis_password' => (string) ($payload['redis_password'] ?? ''),
        'redis_database' => trim((string) ($payload['redis_database'] ?? '2')),
        'admin_name' => trim((string) ($payload['admin_name'] ?? '')),
        'admin_username' => strtolower(trim((string) ($payload['admin_username'] ?? ''))),
        'admin_email' => strtolower(trim((string) ($payload['admin_email'] ?? ''))),
        'admin_password' => (string) ($payload['admin_password'] ?? ''),
        'admin_password_confirmation' => (string) ($payload['admin_password_confirmation'] ?? ''),
    ];
    $errors = [];

    if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $data['purchase_code'])
        && ! preg_match('/^KNL-(?:[A-F0-9]{4}-){7}[A-F0-9]{4}$/', $data['purchase_code'])) {
        $errors['purchase_code'] = 'Enter a valid CodeCanyon purchase code or PlayHub store license key.';
    }

    if (strlen($data['site_name']) < 2 || strlen($data['site_name']) > 80) {
        $errors['site_name'] = 'Website name must be between 2 and 80 characters.';
    }

    $url = filter_var($data['app_url'], FILTER_VALIDATE_URL);
    $parts = $url ? parse_url($data['app_url']) : false;
    if (! $url || ! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
        $errors['app_url'] = 'Enter a complete website URL beginning with http:// or https://.';
    } elseif (! empty($parts['user']) || ! empty($parts['pass']) || ! empty($parts['query']) || ! empty($parts['fragment']) || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
        $errors['app_url'] = 'Use the website root URL without credentials, a path, query, or fragment.';
    }

    if (! in_array($data['timezone'], DateTimeZone::listIdentifiers(), true) && $data['timezone'] !== 'UTC') {
        $errors['timezone'] = 'Select a valid timezone.';
    }
    if ($data['db_host'] === '' || ! preg_match('/^[A-Za-z0-9._:\-]+$/', $data['db_host'])) {
        $errors['db_host'] = 'Enter a valid database hostname or IP address.';
    }
    if (! ctype_digit($data['db_port']) || (int) $data['db_port'] < 1 || (int) $data['db_port'] > 65535) {
        $errors['db_port'] = 'Database port must be between 1 and 65535.';
    }
    if ($data['db_name'] === '' || strlen($data['db_name']) > 64 || ! preg_match('/^[A-Za-z0-9_$\-]+$/', $data['db_name'])) {
        $errors['db_name'] = 'Database name contains unsupported characters.';
    }
    if ($data['db_user'] === '' || strlen($data['db_user']) > 128 || str_contains($data['db_user'], ';')) {
        $errors['db_user'] = 'Enter a valid database username.';
    }
    if ($data['redis_host'] === '' || ! preg_match('/^[A-Za-z0-9._:\-]+$/', $data['redis_host'])) {
        $errors['redis_host'] = 'Enter a valid Redis hostname or IP address.';
    }
    if (! ctype_digit($data['redis_port']) || (int) $data['redis_port'] < 1 || (int) $data['redis_port'] > 65535) {
        $errors['redis_port'] = 'Redis port must be between 1 and 65535.';
    }
    if (strlen($data['redis_username']) > 128 || preg_match('/[\x00-\x1F\x7F]/', $data['redis_username'])) {
        $errors['redis_username'] = 'Redis username contains unsupported characters.';
    }
    if ($data['redis_password'] === '') {
        $errors['redis_password'] = 'Redis password is required for the live streaming runtime.';
    } elseif (strlen($data['redis_password']) > 512 || str_contains($data['redis_password'], "\0")) {
        $errors['redis_password'] = 'Redis password must not exceed 512 characters.';
    }
    if (! ctype_digit($data['redis_database']) || (int) $data['redis_database'] < 0 || (int) $data['redis_database'] > 255) {
        $errors['redis_database'] = 'Redis database must be between 0 and 255.';
    }
    if (strlen($data['admin_name']) < 2 || strlen($data['admin_name']) > 80) {
        $errors['admin_name'] = 'Administrator name must be between 2 and 80 characters.';
    }
    if (! preg_match('/^[A-Za-z0-9_-]{4,30}$/', $data['admin_username'])) {
        $errors['admin_username'] = 'Username must be 4 to 30 characters using letters, numbers, dashes, or underscores.';
    }
    if (! filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL) || strlen($data['admin_email']) > 190) {
        $errors['admin_email'] = 'Enter a valid administrator email address.';
    }
    if (strlen($data['admin_password']) < 8) {
        $errors['admin_password'] = 'Administrator password must contain at least 8 characters.';
    } elseif (! hash_equals($data['admin_password'], $data['admin_password_confirmation'])) {
        $errors['admin_password_confirmation'] = 'Password confirmation does not match.';
    }

    return [$data, $errors];
}

function database_probe(array $data): array
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $data['db_host'], (int) $data['db_port'], $data['db_name']);
    $pdo = new PDO($dsn, $data['db_user'], $data['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $tables = count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));

    return ['pdo' => $pdo, 'version' => $version, 'tables' => $tables];
}

function redis_command($socket, array $arguments): mixed
{
    $command = '*'.count($arguments)."\r\n";
    foreach ($arguments as $argument) {
        $argument = (string) $argument;
        $command .= '$'.strlen($argument)."\r\n".$argument."\r\n";
    }
    $written = 0;
    while ($written < strlen($command)) {
        $bytes = fwrite($socket, substr($command, $written));
        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException('Unable to write to Redis.');
        }
        $written += $bytes;
    }

    $line = fgets($socket);
    if ($line === false || $line === '') {
        throw new RuntimeException('Redis closed the connection without a response.');
    }
    $type = $line[0];
    $response = rtrim(substr($line, 1), "\r\n");
    if ($type === '-') {
        throw new RuntimeException('Redis rejected the request: '.$response);
    }
    if ($type === '+' || $type === ':') {
        return $response;
    }
    if ($type === '$') {
        $length = (int) $response;
        if ($length < 0) return null;
        $payload = '';
        while (strlen($payload) < $length + 2 && ! feof($socket)) {
            $chunk = fread($socket, $length + 2 - strlen($payload));
            if ($chunk === false) break;
            $payload .= $chunk;
        }
        if (strlen($payload) < $length + 2) {
            throw new RuntimeException('Redis returned an incomplete response.');
        }

        return substr($payload, 0, $length);
    }

    throw new RuntimeException('Redis returned an unsupported response.');
}

function redis_probe(array $data): array
{
    $host = $data['redis_host'];
    $port = (int) $data['redis_port'];
    $targetHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$host.']' : $host;
    $socket = @stream_socket_client('tcp://'.$targetHost.':'.$port, $errorCode, $errorMessage, 3, STREAM_CLIENT_CONNECT);
    if (! is_resource($socket)) {
        throw new RuntimeException('Unable to connect to '.$host.':'.$port.'. '.trim((string) $errorMessage));
    }

    try {
        stream_set_timeout($socket, 3);
        if ($data['redis_password'] !== '') {
            $auth = $data['redis_username'] !== '' && strtolower($data['redis_username']) !== 'default'
                ? ['AUTH', $data['redis_username'], $data['redis_password']]
                : ['AUTH', $data['redis_password']];
            if (strtoupper((string) redis_command($socket, $auth)) !== 'OK') {
                throw new RuntimeException('Redis authentication failed.');
            }
        }
        if (strtoupper((string) redis_command($socket, ['SELECT', $data['redis_database']])) !== 'OK') {
            throw new RuntimeException('Redis database selection failed.');
        }
        if (strtoupper((string) redis_command($socket, ['PING'])) !== 'PONG') {
            throw new RuntimeException('Redis did not return PONG.');
        }

        return ['endpoint' => $host.':'.$port, 'database' => (int) $data['redis_database']];
    } finally {
        fclose($socket);
    }
}

function license_domain(string $appUrl): string
{
    $domain = strtolower(rtrim(trim((string) parse_url($appUrl, PHP_URL_HOST), '[]'), '.'));
    if (function_exists('idn_to_ascii') && ! filter_var($domain, FILTER_VALIDATE_IP)) {
        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($ascii)) {
            $domain = strtolower($ascii);
        }
    }

    return $domain;
}

function verify_product_license(array $data): array
{
    if (! function_exists('curl_init')) {
        throw new RuntimeException('The cURL PHP extension is required for license verification.');
    }

    $request = json_encode([
        'purchase_code' => $data['purchase_code'],
        'product_slug' => 'playhub',
        'domain' => license_domain($data['app_url']),
    ], JSON_UNESCAPED_SLASHES);
    if (! is_string($request)) {
        throw new RuntimeException('Unable to prepare the license verification request.');
    }

    $handle = curl_init(PLAYHUB_LICENSE_SERVER_URL);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the license verification request.');
    }
    $options = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $request,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: PlayHub-Installer/1.0',
        ],
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if (! is_string($body)) {
        throw new RuntimeException('License verification service is unavailable'.($error !== '' ? ': '.$error : '.'));
    }
    $response = json_decode($body, true);
    if (! is_array($response)) {
        throw new RuntimeException('License verification service returned an invalid response.');
    }
    if ($status < 200 || $status >= 300 || ($response['valid'] ?? false) !== true) {
        throw new RuntimeException(trim((string) ($response['message'] ?? 'The product license could not be verified.')));
    }
    $license = $response['license'] ?? null;
    $receipt = trim((string) ($response['receipt'] ?? ''));
    if (! is_array($license) || ! hash_equals('playhub', (string) ($license['product_slug'] ?? '')) || ! hash_equals(license_domain($data['app_url']), (string) ($license['domain'] ?? '')) || ! preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $receipt)) {
        throw new RuntimeException('License verification service returned incomplete activation data.');
    }

    return ['license' => $license, 'receipt' => $receipt];
}

function validated_license_job(mixed $value, array $data): array
{
    if (! is_array($value) || ! is_array($value['license'] ?? null)) {
        throw new RuntimeException('The verified license receipt is missing. Return to configuration and verify the product license again.');
    }
    $receipt = trim((string) ($value['receipt'] ?? ''));
    $license = $value['license'];
    if (strlen($receipt) > 16384 || ! preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $receipt)) {
        throw new RuntimeException('The verified license receipt is invalid.');
    }
    if (! hash_equals('playhub', (string) ($license['product_slug'] ?? '')) || ! hash_equals(license_domain($data['app_url']), (string) ($license['domain'] ?? ''))) {
        throw new RuntimeException('The verified license does not match the installation domain.');
    }

    return ['license' => $license, 'receipt' => $receipt];
}

function build_environment(string $root, array $data): string
{
    $template = $root.'/.env.example';
    if (! is_file($template)) {
        throw new RuntimeException('.env.example is missing.');
    }

    $env = (string) file_get_contents($template);
    $host = (string) parse_url($data['app_url'], PHP_URL_HOST);
    $urlHost = str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;
    $secure = strtolower((string) parse_url($data['app_url'], PHP_URL_SCHEME)) === 'https';
    $values = [
        'APP_NAME' => $data['site_name'],
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_URL' => $data['app_url'],
        'APP_TIMEZONE' => $data['timezone'],
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $data['db_host'],
        'DB_PORT' => $data['db_port'],
        'DB_DATABASE' => $data['db_name'],
        'DB_USERNAME' => $data['db_user'],
        'DB_PASSWORD' => $data['db_pass'],
        'SESSION_DRIVER' => 'database',
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'FILESYSTEM_DISK' => 'public',
        'LIVEKIT_URL' => ($secure ? 'wss://' : 'ws://').$urlHost.'/livekit',
        'LIVEKIT_API_KEY' => bin2hex(random_bytes(8)),
        'LIVEKIT_API_SECRET' => bin2hex(random_bytes(24)),
        'LIVEKIT_KEY_FILE' => env_path($root.'/livekit/runtime/keys.yaml'),
        'LIVEKIT_RTMP_URL' => 'rtmp://'.$urlHost.':1936/live',
        'LIVEKIT_REDIS_HOST' => $data['redis_host'],
        'LIVEKIT_REDIS_PORT' => $data['redis_port'],
        'LIVEKIT_REDIS_USERNAME' => $data['redis_username'],
        'LIVEKIT_REDIS_PASSWORD' => $data['redis_password'],
        'LIVEKIT_REDIS_DB' => $data['redis_database'],
        'PLAYHUB_LICENSE_SERVER_URL' => PLAYHUB_LICENSE_SERVER_URL,
    ];

    $phpBinary = find_cli_php();
    $ffmpegBinary = find_binary('ffmpeg', array_filter([
        configured_binary('ffmpeg'),
        'C:/ffmpeg/bin/ffmpeg.exe',
        'C:/Program Files/ffmpeg/bin/ffmpeg.exe',
        'C:/ProgramData/chocolatey/bin/ffmpeg.exe',
    ]));
    $ffprobeBinary = find_binary('ffprobe', array_filter([
        configured_binary('ffprobe'),
        'C:/ffmpeg/bin/ffprobe.exe',
        'C:/Program Files/ffmpeg/bin/ffprobe.exe',
        'C:/ProgramData/chocolatey/bin/ffprobe.exe',
    ]));
    if ($phpBinary !== null) {
        $values['PLAYHUB_PHP_BINARY'] = env_path($phpBinary);
    }
    if ($ffmpegBinary !== null) {
        $values['PLAYHUB_FFMPEG_BINARY'] = env_path($ffmpegBinary);
    }
    if ($ffprobeBinary !== null) {
        $values['PLAYHUB_FFPROBE_BINARY'] = env_path($ffprobeBinary);
    }

    foreach ($values as $key => $value) {
        $env = set_env_value($env, $key, (string) $value);
    }

    return $env;
}

function clean_process_output(string $output): string
{
    $output = str_replace(["\r\n", "\r"], "\n", $output);

    return preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output;
}

function emit_process_output(array &$buffers, int $index, string $chunk = '', bool $flush = false): void
{
    if ($chunk !== '') {
        $buffers[$index] .= clean_process_output($chunk);
    }

    while (($position = strpos($buffers[$index], "\n")) !== false) {
        $line = substr($buffers[$index], 0, $position);
        $buffers[$index] = substr($buffers[$index], $position + 1);
        installer_event('output', $line."\n", ['stream' => $index === 2 ? 'stderr' : 'stdout']);
    }

    while (strlen($buffers[$index]) > 16384) {
        $line = substr($buffers[$index], 0, 8192);
        $buffers[$index] = substr($buffers[$index], 8192);
        installer_event('output', $line, ['stream' => $index === 2 ? 'stderr' : 'stdout']);
    }

    if ($flush && $buffers[$index] !== '') {
        installer_event('output', $buffers[$index]."\n", ['stream' => $index === 2 ? 'stderr' : 'stdout']);
        $buffers[$index] = '';
    }
}

function prepare_npm_environment(): void
{
    foreach ([
        'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'FTP_PROXY',
        'http_proxy', 'https_proxy', 'all_proxy', 'ftp_proxy',
        'GIT_HTTP_PROXY', 'GIT_HTTPS_PROXY',
        'NPM_CONFIG_PROXY', 'NPM_CONFIG_HTTPS_PROXY',
        'npm_config_proxy', 'npm_config_https_proxy',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    foreach ([
        'NPM_CONFIG_OFFLINE' => 'false',
        'npm_config_offline' => 'false',
        'NPM_CONFIG_PREFER_OFFLINE' => 'false',
        'npm_config_prefer_offline' => 'false',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function stream_command(string $label, string $command, string $cwd, int $progress): bool
{
    installer_event('status', $label, ['progress' => $progress]);
    if (! function_exists('proc_open') || ! is_callable('proc_open')) {
        installer_event('error', 'PHP proc_open() is unavailable.');
        return false;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes, $cwd);
    if (! is_resource($process)) {
        installer_event('error', 'Unable to start: '.$label);
        return false;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $buffers = [1 => '', 2 => ''];
    $exitCode = null;

    while (true) {
        $read = [];
        foreach ([1, 2] as $index) {
            if (! feof($pipes[$index])) $read[] = $pipes[$index];
        }

        if ($read !== []) {
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200000);
            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk !== false && $chunk !== '') {
                    $index = $stream === $pipes[2] ? 2 : 1;
                    emit_process_output($buffers, $index, $chunk);
                }
            }
        } else {
            usleep(50000);
        }

        $status = proc_get_status($process);
        if (! $status['running']) {
            $exitCode = (int) $status['exitcode'];
            foreach ([1, 2] as $index) {
                $remaining = stream_get_contents($pipes[$index]);
                if ($remaining !== false && $remaining !== '') {
                    emit_process_output($buffers, $index, $remaining);
                }
                emit_process_output($buffers, $index, '', true);
            }
            break;
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    if ($exitCode === null || $exitCode < 0) $exitCode = $closeCode;

    if ($exitCode !== 0) {
        installer_event('error', $label.' failed with exit code '.$exitCode.'.');
        return false;
    }

    installer_event('success', $label.' completed.');
    return true;
}

function fail_install(string $message): never
{
    throw new RuntimeException($message);
}

function installation_job_paths(string $root, string $jobId): array
{
    $base = $root.'/storage/app/private/install-'.$jobId;

    return [
        'payload' => $base.'.json',
        'log' => $base.'.ndjson',
        'done' => $base.'.done',
    ];
}

function launch_install_worker(string $php, string $root, string $jobId): bool
{
    if (! function_exists('proc_open') || ! is_callable('proc_open')) {
        return false;
    }

    if (is_windows_host()) {
        $command = 'cmd /C start "" /B '.escapeshellarg($php).' '.escapeshellarg($root.'/install.php').' --install-worker '.escapeshellarg($jobId).' >NUL 2>&1';
    } else {
        $nohup = find_binary('nohup', ['/usr/bin/nohup', '/bin/nohup']);
        if ($nohup === null) {
            return false;
        }

        $command = escapeshellarg($nohup).' '.escapeshellarg($php).' '.escapeshellarg($root.'/install.php').' --install-worker '.escapeshellarg($jobId).' >/dev/null 2>&1 &';
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes, $root);
    if (! is_resource($process)) {
        return false;
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return proc_close($process) === 0;
}

function perform_installation(array $data, string $root, string $lock, ?string $detectedCliPhp, ?string $detectedNode, ?string $detectedNpm): bool
{
    @set_time_limit(0);
    $requirements = installer_requirements($root, $detectedCliPhp, $detectedNode, $detectedNpm, $data['app_url']);
    if (! $requirements['ready']) {
        installer_event('error', 'Required server checks are failing. Return to step 1 and recheck.');
        return false;
    }

    @mkdir($root.'/storage/app', 0775, true);
    $mutexPath = $root.'/storage/app/installing.lock';
    $mutex = @fopen($mutexPath, 'c+');
    if (! is_resource($mutex) || ! flock($mutex, LOCK_EX | LOCK_NB)) {
        installer_event('error', 'Another installation process is already running.');
        if (is_resource($mutex)) fclose($mutex);
        return false;
    }

    $credentialsFile = null;
    try {
        if (file_exists($lock)) fail_install('PlayHub is already installed.');
        installer_event('status', 'Verifying database connection', ['progress' => 3]);
        $database = database_probe($data);
        installer_event('success', 'Connected to MySQL '.$database['version'].'.');

        installer_event('status', 'Verifying Redis connection', ['progress' => 5]);
        $redis = redis_probe($data);
        installer_event('success', 'Connected to Redis at '.$redis['endpoint'].' using database '.$redis['database'].'.');

        installer_event('status', 'Creating production environment', ['progress' => 7]);
        $env = build_environment($root, $data);
        if (file_put_contents($root.'/.env', $env, LOCK_EX) === false) {
            fail_install('Unable to write .env. Check application root permissions.');
        }
        @chmod($root.'/.env', 0640);
        installer_event('success', 'Environment and application keys created.');

        if ($detectedCliPhp === null || $detectedNode === null || $detectedNpm === null) fail_install('PHP CLI, Node.js, or npm is unavailable.');
        $npmCache = $root.'/storage/app/npm-cache';
        if ((! is_dir($npmCache) && ! @mkdir($npmCache, 0775, true)) || ! is_writable($npmCache)) {
            fail_install('Unable to create a writable npm cache in storage/app/npm-cache.');
        }
        $npmOptions = ' --cache '.escapeshellarg($npmCache).' --registry https://registry.npmjs.org/ --replace-registry-host always --offline=false --prefer-offline=false --no-update-notifier';

        if (! stream_command('Clear configuration cache', binary_command($detectedCliPhp, 'artisan config:clear --no-interaction'), $root, 12)) fail_install('Unable to clear the configuration cache.');
        prepare_npm_environment();
        if (! stream_command('Install frontend modules', binary_command($detectedNpm, 'ci --include=dev --bin-links=true --ignore-scripts --no-audit --no-fund --loglevel warn --progress=false'.$npmOptions), $root, 18)) fail_install('npm dependency installation failed.');
        $vite = $root.'/node_modules/vite/bin/vite.js';
        if (! is_file($vite)) fail_install('Vite was not installed in node_modules. Review the npm output above.');
        if (! stream_command('Build production frontend', binary_command($detectedNode, escapeshellarg($vite).' build'), $root, 42)) fail_install('Frontend production build failed.');
        if (! is_file($root.'/build/manifest.json')) fail_install('Frontend build completed without creating build/manifest.json.');
        if (! stream_command('Import database schema and native data', binary_command($detectedCliPhp, 'artisan migrate --force --no-interaction -vvv'), $root, 60)) fail_install('Database migration failed.');

        installer_event('status', 'Verify gift catalog', ['progress' => 78]);
        $database = database_probe($data);
        $giftCount = (int) $database['pdo']->query('SELECT COUNT(*) FROM gifts')->fetchColumn();
        if ($giftCount < 1) fail_install('Gift catalog was not imported correctly.');
        installer_event('success', $giftCount.' gifts imported. Stickers use KLIPY; configure its API key in the admin panel.');

        if (! stream_command('Cache production configuration', binary_command($detectedCliPhp, 'artisan config:cache --no-interaction'), $root, 84)) fail_install('Unable to cache production configuration.');

        installer_event('status', 'Create administrator account', ['progress' => 91]);
        $credentialsFile = $root.'/storage/app/.install-admin-'.bin2hex(random_bytes(8)).'.json';
        $credentials = json_encode([
            'name' => $data['admin_name'],
            'username' => $data['admin_username'],
            'email' => $data['admin_email'],
            'password' => $data['admin_password'],
        ], JSON_UNESCAPED_SLASHES);
        $previousUmask = umask(0077);
        $written = $credentials !== false && file_put_contents($credentialsFile, $credentials, LOCK_EX) !== false;
        umask($previousUmask);
        if (! $written) fail_install('Unable to create the protected administrator credentials file.');
        @chmod($credentialsFile, 0600);
        if (! stream_command('Create verified owner account', binary_command($detectedCliPhp, 'artisan playhub:install-admin '.escapeshellarg($credentialsFile).' --no-interaction'), $root, 93)) fail_install('Administrator account creation failed.');
        @unlink($credentialsFile);
        $credentialsFile = null;

        installer_event('status', 'Save verified license receipt', ['progress' => 96]);
        $verifiedLicense = validated_license_job($data['_license'] ?? null, $data);
        $licensePayload = json_encode([
            'license_server' => PLAYHUB_LICENSE_SERVER_URL,
            'receipt' => $verifiedLicense['receipt'],
            'license' => $verifiedLicense['license'],
            'installed_at' => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $licenseFile = $root.'/storage/app/license.json';
        if (! is_string($licensePayload) || file_put_contents($licenseFile, $licensePayload, LOCK_EX) === false) {
            fail_install('Unable to save the verified license receipt.');
        }
        @chmod($licenseFile, 0640);
        installer_event('success', 'Product license receipt saved securely.');

        installer_event('status', 'Finalize secure installation', ['progress' => 98]);
        $lockPayload = json_encode([
            'installed_at' => date(DATE_ATOM),
            'app_url' => $data['app_url'],
            'license_id' => $verifiedLicense['license']['license_id'] ?? null,
        ], JSON_UNESCAPED_SLASHES);
        if ($lockPayload === false || file_put_contents($lock, $lockPayload, LOCK_EX) === false) {
            fail_install('Installation completed, but the installation lock could not be written.');
        }
        @chmod($lock, 0640);
        installer_event('success', 'Installation lock enabled.');
        if (! $requirements['runtime_ready']) {
            installer_event('notice', 'Base platform installed. To enable live streaming and register the queue worker required for media processing, run livekit/install.sh as root after closing this installer.');
        }
        installer_event('complete', 'PlayHub installation completed successfully.', [
            'progress' => 100,
            'website_url' => $data['app_url'],
            'admin_url' => $data['app_url'].'/admin',
            'runtime_ready' => $requirements['runtime_ready'],
            'runtime_command' => 'sudo '.$root.'/livekit/install.sh',
            'catalogs' => ['gifts' => $giftCount, 'sticker_provider' => 'KLIPY'],
        ]);

        return true;
    } catch (Throwable $exception) {
        installer_event('error', $exception->getMessage());
        return false;
    } finally {
        if ($credentialsFile !== null) @unlink($credentialsFile);
        flock($mutex, LOCK_UN);
        fclose($mutex);
        @unlink($mutexPath);
    }
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--install-worker') {
    $jobId = (string) ($argv[2] ?? '');
    if (! preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        exit(2);
    }

    $paths = installation_job_paths($root, $jobId);
    $installerEventFile = $paths['log'];
    $workerFinished = false;
    register_shutdown_function(function () use (&$workerFinished, $paths): void {
        if ($workerFinished) return;
        installer_event('error', 'The installer worker stopped unexpectedly. Review the server PHP log.');
        @file_put_contents($paths['done'], json_encode(['success' => false, 'finished_at' => date(DATE_ATOM)]), LOCK_EX);
        @chmod($paths['done'], 0600);
    });

    $success = false;
    try {
        $payload = json_decode((string) @file_get_contents($paths['payload']), true);
        if (! is_array($payload)) {
            throw new RuntimeException('The protected installer job could not be loaded.');
        }
        [$data, $errors] = validate_installer_payload($payload);
        @unlink($paths['payload']);
        if ($errors !== []) {
            throw new RuntimeException(reset($errors) ?: 'Installer details are invalid.');
        }
        $data['_license'] = validated_license_job($payload['_license'] ?? null, $data);

        $success = perform_installation($data, $root, $lock, find_cli_php(), find_node(), find_npm());
    } catch (Throwable $exception) {
        installer_event('error', $exception->getMessage());
    } finally {
        @unlink($paths['payload']);
        @file_put_contents($paths['done'], json_encode(['success' => $success, 'finished_at' => date(DATE_ATOM)]), LOCK_EX);
        @chmod($paths['done'], 0600);
        $workerFinished = true;
    }

    exit($success ? 0 : 1);
}

$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$defaultUrl = $scheme.'://'.$host;
$detectedCliPhp = find_cli_php();
$detectedNode = find_node();
$detectedNpm = find_npm();

if (file_exists($lock) && $action !== 'install-status') {
    if ($action === 'install') {
        json_response(['ok' => false, 'message' => 'PlayHub is already installed.'], 409);
    }
    if ($action !== '') {
        json_response(['ok' => false, 'message' => 'PlayHub is already installed.'], 409);
    }
    http_response_code(403);
    exit('PlayHub is already installed. Remove storage/app/installed.lock only if you need to reinstall.');
}

if (function_exists('session_start')) {
    $secureCookie = $scheme === 'https';
    session_name('playhub_installer');
    session_set_cookie_params(['httponly' => true, 'secure' => $secureCookie, 'samesite' => 'Strict', 'path' => '/']);
    @session_start();
}
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) ($_SESSION['installer_csrf'] ?? '');

if ($action === 'install-status' && $requestMethod === 'GET') {
    if (! validate_csrf([])) {
        json_response(['ok' => false, 'message' => 'Installer session expired. Reload the page and try again.'], 419);
    }

    $jobId = strtolower(trim((string) ($_GET['job'] ?? '')));
    $sessionJob = (string) ($_SESSION['installer_job'] ?? '');
    if (! preg_match('/^[a-f0-9]{32}$/', $jobId) || $sessionJob === '' || ! hash_equals($sessionJob, $jobId)) {
        json_response(['ok' => false, 'message' => 'Installer job not found for this session.'], 404);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $paths = installation_job_paths($root, $jobId);
    if (! is_file($paths['log']) && ! is_file($paths['done'])) {
        json_response(['ok' => false, 'message' => 'Installer job log is unavailable.'], 404);
    }

    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $chunk = '';
    $size = 0;
    if (($handle = @fopen($paths['log'], 'rb')) !== false) {
        if (flock($handle, LOCK_SH)) {
            clearstatcache(true, $paths['log']);
            $size = (int) filesize($paths['log']);
            if ($offset > $size) $offset = 0;
            if ($size > $offset) {
                fseek($handle, $offset);
                $chunk = (string) fread($handle, min(262144, $size - $offset));
                if ($offset + strlen($chunk) < $size) {
                    $lastNewline = strrpos($chunk, "\n");
                    $chunk = $lastNewline === false ? '' : substr($chunk, 0, $lastNewline + 1);
                }
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
    $offset += strlen($chunk);
    clearstatcache(true, $paths['log']);
    $size = is_file($paths['log']) ? (int) filesize($paths['log']) : $offset;

    json_response([
        'ok' => true,
        'chunk' => $chunk,
        'offset' => $offset,
        'size' => $size,
        'finished' => is_file($paths['done']),
    ]);
}

if ($action === 'requirements' && $requestMethod === 'GET') {
    json_response(installer_requirements($root, $detectedCliPhp, $detectedNode, $detectedNpm, $defaultUrl));
}

if ($action === 'validate' && $requestMethod === 'POST') {
    $payload = request_payload();
    if (! validate_csrf($payload)) {
        json_response(['ok' => false, 'message' => 'Installer session expired. Reload the page and try again.'], 419);
    }
    [$data, $errors] = validate_installer_payload($payload);
    if ($errors !== []) {
        json_response(['ok' => false, 'message' => 'Review the highlighted fields.', 'errors' => $errors], 422);
    }
    $requirements = installer_requirements($root, $detectedCliPhp, $detectedNode, $detectedNpm, $defaultUrl);
    if (! $requirements['ready']) {
        json_response(['ok' => false, 'message' => 'Required server checks are failing. Return to step 1 and recheck.'], 422);
    }
    try {
        $verifiedLicense = verify_product_license($data);
    } catch (Throwable $exception) {
        json_response([
            'ok' => false,
            'message' => 'Product license verification failed: '.$exception->getMessage(),
            'errors' => ['purchase_code' => $exception->getMessage()],
        ], 422);
    }
    try {
        $database = database_probe($data);
    } catch (Throwable $exception) {
        json_response(['ok' => false, 'message' => 'Database connection failed: '.$exception->getMessage()], 422);
    }
    try {
        $redis = redis_probe($data);
    } catch (Throwable $exception) {
        json_response(['ok' => false, 'message' => 'Redis connection failed: '.$exception->getMessage()], 422);
    }
    $_SESSION['verified_license'] = [
        'purchase_code_hash' => hash('sha256', $data['purchase_code']),
        'domain' => license_domain($data['app_url']),
        'expires_at' => time() + 900,
        'verification' => $verifiedLicense,
    ];
    json_response([
        'ok' => true,
        'message' => 'Product license, database, and Redis connections verified.',
        'license' => $verifiedLicense['license'],
        'database' => ['version' => $database['version'], 'tables' => $database['tables']],
        'redis' => $redis,
        'warning' => $database['tables'] > 0 ? 'This database already contains '.$database['tables'].' table(s). Existing tables are not deleted automatically.' : null,
    ]);
}

if ($action === 'install' && $requestMethod === 'POST') {
    $payload = request_payload();
    if (! validate_csrf($payload)) {
        json_response(['ok' => false, 'message' => 'Installer session expired. Reload the page and try again.'], 419);
    }
    [$data, $errors] = validate_installer_payload($payload);
    if ($errors !== []) {
        json_response(['ok' => false, 'message' => reset($errors) ?: 'Installer details are invalid.'], 422);
    }

    $licenseSession = $_SESSION['verified_license'] ?? null;
    $expectedCodeHash = hash('sha256', $data['purchase_code']);
    $expectedDomain = license_domain($data['app_url']);
    if (! is_array($licenseSession)
        || (int) ($licenseSession['expires_at'] ?? 0) < time()
        || ! hash_equals($expectedCodeHash, (string) ($licenseSession['purchase_code_hash'] ?? ''))
        || ! hash_equals($expectedDomain, (string) ($licenseSession['domain'] ?? ''))
        || ! is_array($licenseSession['verification'] ?? null)) {
        json_response(['ok' => false, 'message' => 'Verify the product license again before starting installation.'], 422);
    }
    $data['_license'] = validated_license_job($licenseSession['verification'], $data);

    $requirements = installer_requirements($root, $detectedCliPhp, $detectedNode, $detectedNpm, $defaultUrl);
    if (! $requirements['ready']) {
        json_response(['ok' => false, 'message' => 'Required server checks are failing. Return to step 1 and recheck.'], 422);
    }

    if ($detectedCliPhp === null) {
        json_response(['ok' => false, 'message' => 'PHP CLI is unavailable.'], 422);
    }
    $jobsDirectory = $root.'/storage/app/private';
    if ((! is_dir($jobsDirectory) && ! @mkdir($jobsDirectory, 0700, true)) || ! is_writable($jobsDirectory)) {
        json_response(['ok' => false, 'message' => 'storage/app/private must be writable by the web server.'], 500);
    }

    $jobId = bin2hex(random_bytes(16));
    $paths = installation_job_paths($root, $jobId);
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
    $previousUmask = umask(0077);
    $payloadWritten = $encoded !== false && file_put_contents($paths['payload'], $encoded, LOCK_EX) !== false;
    $logWritten = file_put_contents($paths['log'], '', LOCK_EX) !== false;
    umask($previousUmask);
    if (! $payloadWritten || ! $logWritten) {
        @unlink($paths['payload']);
        @unlink($paths['log']);
        json_response(['ok' => false, 'message' => 'Unable to create the protected installer job.'], 500);
    }
    @chmod($paths['payload'], 0600);
    @chmod($paths['log'], 0600);

    $_SESSION['installer_job'] = $jobId;
    unset($_SESSION['verified_license']);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (! launch_install_worker($detectedCliPhp, $root, $jobId)) {
        @unlink($paths['payload']);
        @unlink($paths['log']);
        json_response(['ok' => false, 'message' => 'Unable to start the background installer worker.'], 500);
    }

    json_response(['ok' => true, 'job_id' => $jobId]);
}

if ($action !== '') {
    json_response(['ok' => false, 'message' => 'Unsupported installer action.'], 404);
}

$initialRequirements = installer_requirements($root, $detectedCliPhp, $detectedNode, $detectedNpm, $defaultUrl);
$timezones = DateTimeZone::listIdentifiers();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:;");

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install PlayHub</title>
<link rel="icon" href="/branding/favicon.svg">
<style>
:root{color-scheme:light;--primary:#b68a2d;--primary-dark:#8e6817;--ink:#101828;--muted:#667085;--line:#dce2eb;--soft:#f5f7fa;--white:#fff;--success:#087443;--success-bg:#ecfdf3;--warning:#a15c07;--warning-bg:#fffaeb;--danger:#c1152f;--danger-bg:#fff1f3;--terminal:#080a0d;--terminal-line:#22262d}*{box-sizing:border-box;letter-spacing:0}html{background:var(--soft)}body{min-width:320px;margin:0;background:var(--soft);color:var(--ink);font-family:Inter,Arial,sans-serif;font-size:14px;overflow-x:hidden}.installer{width:min(1080px,calc(100% - 32px));max-width:100%;margin:32px auto 56px}.installer-header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:22px}.brand{display:flex;align-items:center;gap:12px;min-width:0}.brand-mark{display:grid;flex:0 0 40px;width:40px;height:40px;place-items:center;border-radius:8px;background:#080a0d;color:#fff;font-size:18px;font-weight:900}.brand h1{margin:0;font-size:24px;line-height:1.1}.brand p{margin:4px 0 0;color:var(--muted)}.secure-label{padding:7px 10px;border:1px solid var(--line);border-radius:6px;background:var(--white);color:var(--muted);font-size:12px;font-weight:700}.stepper{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:18px;border:1px solid var(--line);border-radius:8px;background:var(--white);overflow:hidden}.step-indicator{display:flex;align-items:center;gap:10px;min-width:0;padding:14px 16px;border-right:1px solid var(--line);color:var(--muted)}.step-indicator:last-child{border-right:0}.step-number{display:grid;flex:0 0 28px;width:28px;height:28px;place-items:center;border:1px solid var(--line);border-radius:50%;font-size:12px;font-weight:800}.step-copy{min-width:0}.step-copy strong,.step-copy span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.step-copy strong{font-size:13px;color:inherit}.step-copy span{margin-top:2px;font-size:11.5px}.step-indicator.active{color:var(--primary-dark);background:#fffaf0}.step-indicator.active .step-number{border-color:var(--primary);background:var(--primary);color:#fff}.step-indicator.complete{color:var(--success)}.step-indicator.complete .step-number{border-color:var(--success);background:var(--success-bg)}.panel{display:none;min-width:0;border:1px solid var(--line);border-radius:8px;background:var(--white);padding:28px}.panel.active{display:block}.panel-header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:22px}.panel-header>div{min-width:0}.panel-header h2{margin:0;font-size:25px;line-height:1.2}.panel-header p{max-width:680px;margin:7px 0 0;color:var(--muted);line-height:1.55;overflow-wrap:anywhere}.summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:18px}.summary-item{min-width:0;padding:14px;border:1px solid var(--line);border-radius:7px;background:var(--soft)}.summary-item span{display:block;color:var(--muted);font-size:12px;font-weight:700;overflow-wrap:anywhere}.summary-item strong{display:block;margin-top:5px;font-size:24px}.summary-item.pass strong{color:var(--success)}.summary-item.warning strong{color:var(--warning)}.summary-item.fail strong{color:var(--danger)}.requirements{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;min-width:0}.requirement-group{min-width:0;overflow:hidden;border:1px solid var(--line);border-radius:7px;background:var(--white)}.requirement-group h3{margin:0;padding:12px 14px;border-bottom:1px solid var(--line);background:var(--soft);font-size:13px}.requirement-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;min-width:0;padding:11px 14px;border-bottom:1px solid #edf0f4}.requirement-row:last-child{border-bottom:0}.requirement-main{min-width:0}.requirement-main strong{display:block;font-size:13px}.requirement-main p{margin:3px 0 0;color:var(--muted);font-size:11.5px;line-height:1.4;overflow-wrap:anywhere}.requirement-value{margin-top:4px;color:#344054;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:11.5px;overflow-wrap:anywhere}.status{align-self:start;padding:4px 7px;border-radius:999px;font-size:11.5px;font-weight:900;text-transform:uppercase}.status.pass{background:var(--success-bg);color:var(--success)}.status.warning{background:var(--warning-bg);color:var(--warning)}.status.fail{background:var(--danger-bg);color:var(--danger)}.notice{display:none;margin:0 0 18px;padding:12px 14px;border:1px solid var(--line);border-radius:7px;line-height:1.45;overflow-wrap:anywhere}.notice.show{display:block}.notice.success{border-color:#abe7c6;background:var(--success-bg);color:var(--success)}.notice.warning{border-color:#fedf89;background:var(--warning-bg);color:var(--warning)}.notice.error{border-color:#fda4af;background:var(--danger-bg);color:var(--danger)}.form-section{padding:0 0 24px;margin:0 0 24px;border-bottom:1px solid var(--line)}.form-section:last-of-type{margin-bottom:0;border-bottom:0}.form-section h3{margin:0 0 5px;font-size:17px}.form-section>p{margin:0 0 16px;color:var(--muted)}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.field{display:grid;gap:7px;min-width:0}.field.full{grid-column:1/-1}.field label{font-size:12px;font-weight:800}.field input,.field select{width:100%;min-width:0;height:44px;border:1px solid #ccd4df;border-radius:7px;background:#fff;color:var(--ink);padding:0 12px;font:inherit;outline:none}.field input:focus,.field select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(182,138,45,.12)}.field input.invalid,.field select.invalid{border-color:var(--danger)}.field-error{min-height:15px;color:var(--danger);font-size:11.5px}.actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:24px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:1px solid var(--primary);border-radius:7px;background:var(--primary);color:#fff;padding:0 17px;font:inherit;font-weight:800;text-decoration:none;cursor:pointer}.button:hover{background:var(--primary-dark);border-color:var(--primary-dark)}.button.secondary{border-color:var(--line);background:#fff;color:#344054}.button.secondary:hover{border-color:#aab4c3;background:var(--soft)}.button:disabled{cursor:not-allowed;opacity:.5}.terminal-progress{height:7px;margin-bottom:14px;border-radius:999px;background:#e9edf2;overflow:hidden}.terminal-progress span{display:block;width:0;height:100%;background:var(--primary);transition:width .25s ease}.terminal{height:420px;overflow:auto;border:1px solid #1e232b;border-radius:8px;background:var(--terminal);color:#c9d1d9;padding:16px;font:12px/1.6 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-wrap;overflow-wrap:anywhere}.terminal-line{display:block}.terminal-line.status{padding:0;border-radius:0;color:#f4c85a;font-size:12px;font-weight:700;text-transform:none}.terminal-line.success{color:#51d88a}.terminal-line.error{color:#ff7b8b}.terminal-line.notice{display:block;margin:0;padding:0;border:0;color:#f4c85a}.terminal-line.output{color:#c9d1d9}.install-state{margin:14px 0 0;color:var(--muted);font-size:12px}.complete-panel{text-align:center;padding-block:46px}.complete-mark{display:grid;width:64px;height:64px;margin:0 auto 18px;place-items:center;border:2px solid var(--success);border-radius:50%;background:var(--success-bg);color:var(--success);font-size:26px;font-weight:900}.complete-panel h2{margin:0;font-size:30px}.complete-panel>p{max-width:600px;margin:10px auto 24px;color:var(--muted);line-height:1.55}.complete-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));max-width:680px;margin:0 auto 22px;border:1px solid var(--line);border-radius:8px;overflow:hidden;text-align:left}.complete-details div{min-width:0;padding:14px;border-right:1px solid var(--line)}.complete-details div:last-child{border-right:0}.complete-details span,.complete-details strong{display:block}.complete-details span{color:var(--muted);font-size:11.5px}.complete-details strong{margin-top:5px}.runtime-note{max-width:680px;margin:0 auto 22px;padding:13px;border:1px solid #fedf89;border-radius:7px;background:var(--warning-bg);color:#7a4a09;text-align:left;line-height:1.45}.runtime-note code{display:block;margin-top:7px;overflow-wrap:anywhere;color:#503000}.complete-actions{display:flex;justify-content:center;gap:10px}@media(max-width:800px){.installer{width:min(100% - 20px,1080px);margin-top:14px}.installer-header{align-items:flex-start}.secure-label{display:none}.stepper{grid-template-columns:repeat(2,minmax(0,1fr))}.step-indicator:nth-child(2){border-right:0}.step-indicator:nth-child(-n+2){border-bottom:1px solid var(--line)}.requirements,.form-grid{grid-template-columns:minmax(0,1fr)}.field.full{grid-column:auto}.complete-details{grid-template-columns:minmax(0,1fr)}.complete-details div{border-right:0;border-bottom:1px solid var(--line)}.complete-details div:last-child{border-bottom:0}}@media(max-width:520px){.installer{width:100%;margin:0}.installer-header{padding:16px 14px;margin:0}.brand h1{font-size:20px}.brand p{font-size:12px}.stepper,.panel{border-left:0;border-right:0;border-radius:0}.stepper{margin-bottom:0}.step-indicator{padding:11px 10px}.step-copy span{display:none}.panel{padding:20px 14px}.panel-header{display:block}.panel-header .button{margin-top:14px}.summary{grid-template-columns:repeat(3,minmax(0,1fr))}.summary-item{padding:10px}.summary-item strong{font-size:20px}.actions,.complete-actions{flex-direction:column-reverse}.actions .button,.complete-actions .button{width:100%}.terminal{height:52vh;min-height:320px}.complete-panel{padding-block:32px}}
.requirements{display:block;column-count:2;column-gap:14px}
.requirement-group{display:inline-block;width:100%;margin:0 0 14px;break-inside:avoid;page-break-inside:avoid;vertical-align:top}
@media(max-width:800px){.requirements{column-count:1}}
</style>
</head>
<body>
<main class="installer">
<header class="installer-header">
<div class="brand"><div class="brand-mark" aria-hidden="true">P</div><div><h1>PlayHub Installer</h1><p>Production environment setup</p></div></div>
<div class="secure-label">Secure four-step installation</div>
</header>

<nav class="stepper" aria-label="Installation progress">
<div class="step-indicator active" data-step-indicator="1"><span class="step-number">1</span><span class="step-copy"><strong>Requirements</strong><span>Server readiness</span></span></div>
<div class="step-indicator" data-step-indicator="2"><span class="step-number">2</span><span class="step-copy"><strong>Configuration</strong><span>Website and account</span></span></div>
<div class="step-indicator" data-step-indicator="3"><span class="step-number">3</span><span class="step-copy"><strong>Installation</strong><span>Live console</span></span></div>
<div class="step-indicator" data-step-indicator="4"><span class="step-number">4</span><span class="step-copy"><strong>Complete</strong><span>Open PlayHub</span></span></div>
</nav>

<section class="panel active" data-panel="1">
<div class="panel-header"><div><h2>Server requirements</h2><p>PlayHub checks the web and CLI PHP runtimes, extensions, media tools, frontend toolchain, streaming services, and writable storage before installation.</p></div><button type="button" class="button secondary" id="recheckRequirements">Recheck server</button></div>
<div class="summary" aria-live="polite"><div class="summary-item pass"><span>Passed</span><strong id="passedCount">0</strong></div><div class="summary-item warning"><span>Advisories</span><strong id="warningCount">0</strong></div><div class="summary-item fail"><span>Required failures</span><strong id="failedCount">0</strong></div></div>
<div class="notice" id="requirementsNotice"></div>
<div class="requirements" id="requirementsList"></div>
<div class="actions"><button type="button" class="button" id="requirementsContinue" disabled>Continue to configuration</button></div>
</section>

<section class="panel" data-panel="2">
<div class="panel-header"><div><h2>Website configuration</h2><p>Enter the production URL, an existing empty MySQL database, and the first verified owner account.</p></div></div>
<div class="notice" id="configurationNotice" role="alert"></div>
<form id="configurationForm" novalidate>
<div class="form-section"><div class="form-grid">
<div class="field full"><label for="purchase_code">License key or purchase code</label><input id="purchase_code" name="purchase_code" required maxlength="48" autocomplete="off" spellcheck="false" placeholder="KNL-XXXX-... or Envato purchase code"><span class="field-error" data-error-for="purchase_code"></span></div>
</div></div>
<div class="form-section"><h3>Website details</h3><p>These values become the production identity and timezone.</p><div class="form-grid">
<div class="field"><label for="site_name">Website name</label><input id="site_name" name="site_name" required minlength="2" maxlength="80" value="PlayHub"><span class="field-error" data-error-for="site_name"></span></div>
<div class="field"><label for="app_url">Website URL</label><input id="app_url" name="app_url" type="url" required value="<?= e($defaultUrl) ?>"><span class="field-error" data-error-for="app_url"></span></div>
<div class="field full"><label for="timezone">Timezone</label><select id="timezone" name="timezone" required><?php foreach ($timezones as $timezone): ?><option value="<?= e($timezone) ?>"<?= $timezone === 'UTC' ? ' selected' : '' ?>><?= e($timezone) ?></option><?php endforeach; ?></select><span class="field-error" data-error-for="timezone"></span></div>
</div></div>
<div class="form-section"><h3>Database</h3><p>The installer tests the connection before writing any files.</p><div class="form-grid">
<div class="field"><label for="db_host">Database host</label><input id="db_host" name="db_host" required value="127.0.0.1"><span class="field-error" data-error-for="db_host"></span></div>
<div class="field"><label for="db_port">Database port</label><input id="db_port" name="db_port" required inputmode="numeric" value="3306"><span class="field-error" data-error-for="db_port"></span></div>
<div class="field"><label for="db_name">Database name</label><input id="db_name" name="db_name" required value="playhub"><span class="field-error" data-error-for="db_name"></span></div>
<div class="field"><label for="db_user">Database user</label><input id="db_user" name="db_user" required autocomplete="username" value="playhub"><span class="field-error" data-error-for="db_user"></span></div>
<div class="field full"><label for="db_pass">Database password</label><input id="db_pass" name="db_pass" type="password" autocomplete="new-password"><span class="field-error" data-error-for="db_pass"></span></div>
</div></div>
<div class="form-section"><h3>Live streaming Redis</h3><p>Required by live rooms, ingress, and replay recording. The installer authenticates and tests the selected database before installation.</p><div class="form-grid">
<div class="field"><label for="redis_host">Redis host</label><input id="redis_host" name="redis_host" required value="127.0.0.1"><span class="field-error" data-error-for="redis_host"></span></div>
<div class="field"><label for="redis_port">Redis port</label><input id="redis_port" name="redis_port" required inputmode="numeric" value="6379"><span class="field-error" data-error-for="redis_port"></span></div>
<div class="field"><label for="redis_username">Redis username</label><input id="redis_username" name="redis_username" maxlength="128" autocomplete="off" value="default"><span class="field-error" data-error-for="redis_username"></span></div>
<div class="field"><label for="redis_database">Redis database</label><input id="redis_database" name="redis_database" required inputmode="numeric" min="0" max="255" value="2"><span class="field-error" data-error-for="redis_database"></span></div>
<div class="field full"><label for="redis_password">Redis password</label><input id="redis_password" name="redis_password" type="password" required maxlength="512" autocomplete="new-password"><span class="field-error" data-error-for="redis_password"></span></div>
</div></div>
<div class="form-section"><h3>Administrator account</h3><p>This verified owner can access the website and administration dashboard.</p><div class="form-grid">
<div class="field"><label for="admin_name">Full name</label><input id="admin_name" name="admin_name" required maxlength="80" autocomplete="name"><span class="field-error" data-error-for="admin_name"></span></div>
<div class="field"><label for="admin_username">Username</label><input id="admin_username" name="admin_username" required minlength="4" maxlength="30" pattern="[A-Za-z0-9_\-]+" autocomplete="username"><span class="field-error" data-error-for="admin_username"></span></div>
<div class="field full"><label for="admin_email">Email address</label><input id="admin_email" name="admin_email" type="email" required maxlength="190" autocomplete="email"><span class="field-error" data-error-for="admin_email"></span></div>
<div class="field"><label for="admin_password">Password</label><input id="admin_password" name="admin_password" type="password" required minlength="8" autocomplete="new-password"><span class="field-error" data-error-for="admin_password"></span></div>
<div class="field"><label for="admin_password_confirmation">Confirm password</label><input id="admin_password_confirmation" name="admin_password_confirmation" type="password" required minlength="8" autocomplete="new-password"><span class="field-error" data-error-for="admin_password_confirmation"></span></div>
</div></div>
<div class="actions"><button type="button" class="button secondary" id="configurationBack">Back</button><button type="submit" class="button" id="configurationContinue">Verify and install</button></div>
</form>
</section>

<section class="panel" data-panel="3">
<div class="panel-header"><div><h2>Installing PlayHub</h2><p>Keep this page open while dependencies, production assets, database tables, the gift catalog, and the owner account are created. Stickers are provided by KLIPY after API configuration in the admin panel.</p></div></div>
<div class="terminal-progress" aria-hidden="true"><span id="installProgress"></span></div>
<div class="terminal" id="installTerminal" role="log" aria-live="polite" aria-label="Installation output"></div>
<p class="install-state" id="installState">Preparing installation request...</p>
<div class="actions"><button type="button" class="button secondary" id="installRetry" hidden>Return to configuration</button></div>
</section>

<section class="panel complete-panel" data-panel="4">
<div class="complete-mark" aria-hidden="true">OK</div><h2>PlayHub is ready</h2><p>The database, gift catalog, administrator account, frontend dependencies, and production assets were installed successfully. Configure your KLIPY API key in the admin panel to enable stickers.</p>
<div class="complete-details"><div><span>Gifts</span><strong id="completeGifts">0</strong></div><div><span>Sticker provider</span><strong>KLIPY</strong></div><div><span>Installation</span><strong>Locked</strong></div></div>
<div class="runtime-note" id="runtimeNote" hidden>Live streaming still requires the privileged Docker runtime setup. Run this command as root:<code id="runtimeCommand"></code></div>
<div class="complete-actions"><a class="button secondary" id="adminLink" href="/admin">Open admin</a><a class="button" id="websiteLink" href="/">Open website</a></div>
</section>
</main>

<script>
const csrfToken=<?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let requirementsPayload=<?= json_encode($initialRequirements, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let installationData=null;
let installing=false;
const panels=[...document.querySelectorAll('[data-panel]')];
const indicators=[...document.querySelectorAll('[data-step-indicator]')];
const requirementsList=document.getElementById('requirementsList');
const requirementsContinue=document.getElementById('requirementsContinue');
const requirementsNotice=document.getElementById('requirementsNotice');
const configurationForm=document.getElementById('configurationForm');
const configurationNotice=document.getElementById('configurationNotice');
const configurationContinue=document.getElementById('configurationContinue');
const terminal=document.getElementById('installTerminal');
const progress=document.getElementById('installProgress');
const installState=document.getElementById('installState');
const retryButton=document.getElementById('installRetry');

function setStep(step){panels.forEach(panel=>panel.classList.toggle('active',Number(panel.dataset.panel)===step));indicators.forEach(indicator=>{const value=Number(indicator.dataset.stepIndicator);indicator.classList.toggle('active',value===step);indicator.classList.toggle('complete',value<step)});window.scrollTo({top:0,behavior:'smooth'})}
function setNotice(element,message,type){element.textContent=message||'';element.className='notice'+(message?' show '+type:'')}
function renderRequirements(payload){requirementsPayload=payload;document.getElementById('passedCount').textContent=payload.summary.passed;document.getElementById('warningCount').textContent=payload.summary.warnings;document.getElementById('failedCount').textContent=payload.summary.failed;requirementsContinue.disabled=!payload.ready;setNotice(requirementsNotice,payload.ready?(payload.summary.warnings?'Required checks passed. Review the advisories before continuing.':'All server requirements passed.'):'Resolve the required failures, then recheck the server.',payload.ready?(payload.summary.warnings?'warning':'success'):'error');requirementsList.replaceChildren();const groups=new Map();payload.items.forEach(item=>{if(!groups.has(item.group))groups.set(item.group,[]);groups.get(item.group).push(item)});groups.forEach((items,name)=>{const group=document.createElement('section');group.className='requirement-group';const title=document.createElement('h3');title.textContent=name;group.append(title);items.forEach(item=>{const row=document.createElement('div');row.className='requirement-row';const main=document.createElement('div');main.className='requirement-main';const label=document.createElement('strong');label.textContent=item.label;const value=document.createElement('div');value.className='requirement-value';value.textContent=item.value;const detail=document.createElement('p');detail.textContent=item.detail;main.append(label,value,detail);const status=document.createElement('span');status.className='status '+item.status;status.textContent=item.status==='pass'?'Pass':item.status==='warning'?'Check':'Fail';row.append(main,status);group.append(row)});requirementsList.append(group)})}
function formObject(){return Object.fromEntries(new FormData(configurationForm).entries())}
function clearFieldErrors(){configurationForm.querySelectorAll('.invalid').forEach(field=>field.classList.remove('invalid'));configurationForm.querySelectorAll('[data-error-for]').forEach(node=>node.textContent='')}
function showFieldErrors(errors={}){Object.entries(errors).forEach(([name,message])=>{const field=configurationForm.elements.namedItem(name);if(field)field.classList.add('invalid');const target=configurationForm.querySelector(`[data-error-for="${CSS.escape(name)}"]`);if(target)target.textContent=message})}
function appendTerminal(message,type='output'){const line=document.createElement(type==='output'?'span':'div');line.className='terminal-line '+type;line.textContent=message;if(type==='output')line.classList.add('output');terminal.append(line);terminal.scrollTop=terminal.scrollHeight}
function handleInstallEvent(event){if(Number.isFinite(Number(event.progress)))progress.style.width=Math.max(0,Math.min(100,Number(event.progress)))+'%';if(event.type==='output'){appendTerminal(event.message,'output');return}if(event.type==='status'){installState.textContent=event.message;appendTerminal('\n[STEP] '+event.message+'\n','status');return}if(event.type==='success'){appendTerminal('[OK] '+event.message+'\n','success');return}if(event.type==='notice'){appendTerminal('[NOTICE] '+event.message+'\n','notice');return}if(event.type==='error'){installState.textContent='Installation stopped. Review the console output.';appendTerminal('\n[ERROR] '+event.message+'\n','error');retryButton.hidden=false;installing=false;return}if(event.type==='complete'){progress.style.width='100%';installState.textContent=event.message;document.getElementById('websiteLink').href=event.website_url;document.getElementById('adminLink').href=event.admin_url;document.getElementById('completeGifts').textContent=event.catalogs?.gifts??0;if(!event.runtime_ready){document.getElementById('runtimeNote').hidden=false;document.getElementById('runtimeCommand').textContent=event.runtime_command||''}installing=false;setStep(4)}}
function consumeInstallChunk(chunk,state){state.buffer+=(chunk||'');const lines=state.buffer.split('\n');state.buffer=lines.pop()||'';lines.forEach(line=>{if(!line.trim())return;try{handleInstallEvent(JSON.parse(line))}catch{appendTerminal(line+'\n','output')}})}
function wait(milliseconds){return new Promise(resolve=>setTimeout(resolve,milliseconds))}
async function recheckRequirements(){const button=document.getElementById('recheckRequirements');button.disabled=true;button.textContent='Checking...';try{const response=await fetch('install.php?action=requirements',{headers:{Accept:'application/json'},cache:'no-store'});const payload=await response.json();if(!response.ok)throw new Error(payload.message||'Requirement check failed.');renderRequirements(payload)}catch(error){setNotice(requirementsNotice,error.message,'error')}finally{button.disabled=false;button.textContent='Recheck server'}}
async function startInstallation(data){installationData=data;installing=true;retryButton.hidden=true;terminal.replaceChildren();progress.style.width='0';appendTerminal('PlayHub installer started. Passwords and secrets are never written to this console.\n','status');try{const response=await fetch('install.php?action=install',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-Installer-Token':csrfToken},body:JSON.stringify(data)});const launched=await response.json();if(!response.ok||!launched.ok)throw new Error(launched.message||'Unable to start installation.');let offset=0;let pollFailures=0;const state={buffer:''};while(installing){try{const statusResponse=await fetch(`install.php?action=install-status&job=${encodeURIComponent(launched.job_id)}&offset=${offset}`,{headers:{Accept:'application/json','X-Installer-Token':csrfToken},cache:'no-store'});const status=await statusResponse.json();if(!statusResponse.ok||!status.ok)throw new Error(status.message||'Unable to read installer progress.');offset=Number(status.offset)||offset;consumeInstallChunk(status.chunk,state);pollFailures=0;if(status.finished&&offset>=Number(status.size||0)){if(state.buffer.trim()){try{handleInstallEvent(JSON.parse(state.buffer))}catch{appendTerminal(state.buffer+'\n','output')}state.buffer=''}if(installing)throw new Error('The installer worker stopped before completion.');break}}catch(error){pollFailures+=1;if(pollFailures>=5)throw error;await wait(1000);continue}if(installing)await wait(350)}}catch(error){if(!installing)return;installState.textContent='Installation stopped. Review the console output.';appendTerminal('\n[ERROR] '+error.message+'\n','error');retryButton.hidden=false;installing=false}}

renderRequirements(requirementsPayload);
document.getElementById('recheckRequirements').addEventListener('click',recheckRequirements);
requirementsContinue.addEventListener('click',()=>setStep(2));
document.getElementById('configurationBack').addEventListener('click',()=>setStep(1));
retryButton.addEventListener('click',()=>setStep(2));
configurationForm.addEventListener('submit',async event=>{event.preventDefault();clearFieldErrors();setNotice(configurationNotice,'','');if(!configurationForm.reportValidity())return;configurationContinue.disabled=true;configurationContinue.textContent='Checking services...';const data=formObject();try{const response=await fetch('install.php?action=validate',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-Installer-Token':csrfToken},body:JSON.stringify(data)});const payload=await response.json();if(!response.ok||!payload.ok){showFieldErrors(payload.errors);throw new Error(payload.message||'Configuration validation failed.')}if(payload.warning)setNotice(configurationNotice,payload.warning,'warning');setStep(3);await startInstallation(data)}catch(error){setNotice(configurationNotice,error.message,'error')}finally{configurationContinue.disabled=false;configurationContinue.textContent='Verify and install'}});
window.addEventListener('beforeunload',event=>{if(!installing)return;event.preventDefault();event.returnValue=''})
</script>
</body>
</html>
