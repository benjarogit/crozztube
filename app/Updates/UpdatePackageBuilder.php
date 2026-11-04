<?php

namespace App\Updates;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use ZipArchive;

final class UpdatePackageBuilder
{
    private const ALWAYS_IGNORED = ['.git', '.idea', '.vscode', 'VERSION'];

    public function __construct(private readonly UpdateFilesystem $filesystem) {}

    public function build(string $from, string $to, array $options): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP ZIP extension is required to build update packages.');
        }
        if (! UpdateManifest::isValidVersion($from) || ! UpdateManifest::isValidVersion($to) || version_compare($to, $from, '<=')) {
            throw new InvalidArgumentException('Update package versions must be increasing semantic versions.');
        }
        $unsigned = (bool) ($options['unsigned'] ?? false);
        if ($unsigned && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Unsigned update packages may only be built in local or testing environments.');
        }

        $previous = $this->releaseRoot((string) ($options['previous'] ?? ''));
        $current = $this->releaseRoot((string) ($options['current'] ?? ''));
        $this->assertSnapshotVersion($previous, $from);
        $this->assertSnapshotVersion($current, $to);
        $output = rtrim((string) ($options['output'] ?? ''), DIRECTORY_SEPARATOR);
        if ($output === '') {
            throw new InvalidArgumentException('An update repository output directory is required.');
        }
        $prospectiveOutput = $this->prospectivePath($output);
        if ($this->pathInside($prospectiveOutput, $previous) || $this->pathInside($prospectiveOutput, $current)) {
            throw new InvalidArgumentException('The update repository output must be outside both release snapshots.');
        }
        File::ensureDirectoryExists($output, 0770, true);
        $outputRoot = realpath($output);
        if ($outputRoot === false) {
            throw new InvalidArgumentException('The update repository output must be outside both release snapshots.');
        }
        $privateKeyPath = (string) ($options['private_key'] ?? '');
        if ($privateKeyPath !== '') {
            $privateKeyRealPath = realpath($privateKeyPath);
            if ($privateKeyRealPath === false || ! is_file($privateKeyRealPath) || ! is_readable($privateKeyRealPath) || $this->pathInside($privateKeyRealPath, $previous) || $this->pathInside($privateKeyRealPath, $current)) {
                throw new InvalidArgumentException('The release signing key must be a readable file outside both release snapshots.');
            }
            $privateKeyPath = $privateKeyRealPath;
        }

        $previousFiles = $this->files($previous);
        $currentFiles = $this->files($current);
        $operations = [];
        foreach ($currentFiles as $path => $metadata) {
            if (! isset($previousFiles[$path])) {
                $operations[] = $this->writeOperation('add', $path, $metadata);
            } elseif (! hash_equals($previousFiles[$path]['sha256'], $metadata['sha256']) || $previousFiles[$path]['mode'] !== $metadata['mode']) {
                if (str_starts_with($path, 'database/migrations/')) {
                    throw new RuntimeException("Existing Laravel migration {$path} was modified; migrations must be immutable.");
                }
                $operations[] = $this->writeOperation('replace', $path, $metadata, $previousFiles[$path]);
            }
        }
        foreach ($previousFiles as $path => $metadata) {
            if (! isset($currentFiles[$path])) {
                if (str_starts_with($path, 'database/migrations/')) {
                    throw new RuntimeException("Existing Laravel migration {$path} was deleted; publish a new migration instead.");
                }
                $operations[] = ['action' => 'delete', 'target' => $path, 'previous_sha256' => $metadata['sha256']];
            }
        }
        usort($operations, fn (array $left, array $right): int => [$left['target'], $left['action']] <=> [$right['target'], $right['action']]);

        $composerMode = (string) ($options['composer'] ?? 'none');
        if (! in_array($composerMode, ['none', 'install'], true)) {
            throw new InvalidArgumentException('Composer mode must be none or install.');
        }
        $composerMetadataChanged = $this->pathsChanged($previousFiles, $currentFiles, ['composer.json', 'composer.lock']);
        if ($composerMetadataChanged && $composerMode !== 'install') {
            throw new RuntimeException('Composer metadata changed; rebuild with --composer=install for a deterministic dependency installation.');
        }
        if ($composerMode === 'install' && ! isset($currentFiles['composer.lock'])) {
            throw new RuntimeException('Composer install mode requires composer.lock in the new release snapshot.');
        }

        $frontendMode = (string) ($options['frontend'] ?? 'prebuilt');
        if (! in_array($frontendMode, ['none', 'prebuilt', 'build'], true)) {
            throw new InvalidArgumentException('Frontend mode must be none, prebuilt, or build.');
        }
        $frontendSourceChanged = $this->pathsChanged($previousFiles, $currentFiles, ['resources/js/', 'package.json', 'package-lock.json', 'vite.config.js']);
        $frontendBuildChanged = $this->pathsChanged($previousFiles, $currentFiles, ['build/']);
        if ($frontendSourceChanged && $frontendMode === 'none') {
            throw new RuntimeException('Frontend source changed but the release declares no frontend update.');
        }
        if ($frontendSourceChanged && $frontendMode === 'prebuilt' && (! isset($currentFiles['build/manifest.json']) || ! $frontendBuildChanged)) {
            throw new RuntimeException('Frontend source changed but compiled Vite assets were not updated in the release snapshot.');
        }
        if ($frontendMode === 'build' && ! isset($currentFiles['package-lock.json'])) {
            throw new RuntimeException('Frontend build mode requires package-lock.json.');
        }
        if (! $frontendSourceChanged && ! $frontendBuildChanged && $frontendMode === 'prebuilt') {
            $frontendMode = 'none';
        }
        $frontendChanged = $frontendSourceChanged || $frontendBuildChanged || $frontendMode === 'build';
        if ($frontendMode === 'prebuilt') {
            $this->assertPrebuiltFrontend($current.'/build');
        }

        $changelog = $this->jsonFile((string) ($options['changelog'] ?? ''), 'changelog');
        foreach (['new', 'improved', 'fixed', 'security'] as $section) {
            $changelog[$section] = array_values(array_filter((array) ($changelog[$section] ?? []), 'is_string'));
        }
        $requirements = $this->mergeRequirements($this->optionalJsonFile((string) ($options['requirements'] ?? ''), 'requirements'));
        $postUpdate = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($options['post'] ?? 'optimize_clear,config_cache,view_cache,queue_restart'))))));
        if ($composerMode === 'install' && ! in_array('package_discover', $postUpdate, true)) {
            array_unshift($postUpdate, 'package_discover');
        }
        $healthChecks = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($options['health'] ?? 'application,database,filesystem,files,migrations'))))));
        $migrations = collect($operations)->contains(fn (array $operation): bool => $operation['action'] === 'add' && str_starts_with($operation['target'], 'database/migrations/'));

        $installation = [
            'schema_version' => UpdateManifest::SCHEMA_VERSION,
            'product' => 'playhub',
            'id' => (string) Str::uuid(),
            'from_version' => $from,
            'to_version' => $to,
            'minimum_supported_version' => $from,
            'released_at' => (string) ($options['released_at'] ?? now()->utc()->toIso8601String()),
            'changelog' => $changelog,
            'requirements' => $requirements,
            'maintenance' => ['required' => filter_var($options['maintenance'] ?? true, FILTER_VALIDATE_BOOL)],
            'changes' => [
                'backend' => collect($operations)->contains(fn (array $operation): bool => ! $this->isFrontendPath($operation['target']) && ! str_starts_with($operation['target'], 'database/migrations/')),
                'frontend' => $frontendChanged,
                'database' => $migrations,
            ],
            'operations' => [
                'files' => $operations,
                'migrations' => $migrations,
                'composer' => ['mode' => $composerMode, 'lock_sha256' => $composerMode === 'install' ? $currentFiles['composer.lock']['sha256'] : null],
                'frontend' => ['mode' => $frontendMode],
                'post_update' => $postUpdate,
            ],
            'health_checks' => $healthChecks,
        ];
        UpdateManifest::fromArray($installation, false);

        $this->assertRepositorySuccessor($output, $from, $to);
        $releaseDirectory = $output.'/releases/'.$to;
        $replaceExisting = is_dir($releaseDirectory);
        if ($replaceExisting && ! ($options['force'] ?? false)) {
            throw new RuntimeException("Release directory {$releaseDirectory} already exists. Use --force only when intentionally rebuilding it.");
        }
        $temporary = $output.'/.building-'.$to.'-'.bin2hex(random_bytes(6));
        $previousRelease = null;
        File::ensureDirectoryExists($temporary, 0770, true);
        $archive = $temporary.'/update.zip';
        try {
            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The delta update ZIP could not be created.');
            }
            $zip->addFromString('manifest.json', json_encode($installation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            foreach ($operations as $operation) {
                if ($operation['action'] === 'delete') {
                    continue;
                }
                if (! $zip->addFile($current.'/'.$operation['target'], $operation['source'])) {
                    throw new RuntimeException("Could not add {$operation['target']} to the delta package.");
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('The delta update ZIP could not be finalized.');
            }

            $baseUrl = rtrim((string) ($options['base_url'] ?? 'https://playhub.kontackt.de/updates'), '/');
            $this->assertBaseUrl($baseUrl, $unsigned);
            $remote = $installation;
            $remote['package'] = [
                'url' => $baseUrl.'/releases/'.$to.'/update.zip',
                'size_bytes' => (int) filesize($archive),
                'sha256' => hash_file('sha256', $archive),
                'manifest_sha256' => hash('sha256', UpdateManifest::canonicalJson($installation)),
            ];
            $releaseManifest = UpdateManifest::fromArray($remote);
            $verificationDirectory = $temporary.'/verification';
            try {
                $this->filesystem->extract($releaseManifest, $archive, $verificationDirectory);
            } finally {
                File::deleteDirectory($verificationDirectory);
            }
            if ($privateKeyPath === '') {
                if (! $unsigned) {
                    throw new RuntimeException('A release signing private key is required. Use --unsigned only for local test repositories.');
                }
            } else {
                $privateKey = is_readable($privateKeyPath) ? openssl_pkey_get_private((string) file_get_contents($privateKeyPath)) : false;
                $keyDetails = $privateKey ? openssl_pkey_get_details($privateKey) : false;
                if (! is_array($keyDetails) || ($keyDetails['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($keyDetails['bits'] ?? 0) < 2048 || ! openssl_sign(UpdateManifest::canonicalJson($remote), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                    throw new RuntimeException('The release manifest could not be signed with the supplied private key.');
                }
                $remote['signature'] = ['algorithm' => 'rsa-sha256', 'value' => base64_encode($signature)];
            }
            UpdateManifest::fromArray($remote)->verifySignature($privateKeyPath === '' ? null : $this->publicKeyFromPrivate($privateKey), ! $unsigned);
            $this->writeJson($temporary.'/manifest.json', $remote);
            File::ensureDirectoryExists(dirname($releaseDirectory), 0770, true);
            if ($replaceExisting) {
                $previousRelease = $output.'/.previous-'.$to.'-'.bin2hex(random_bytes(6));
                if (! @rename($releaseDirectory, $previousRelease)) {
                    throw new RuntimeException('The previous generated release could not be moved before replacement.');
                }
            }
            if (! @rename($temporary, $releaseDirectory)) {
                if ($previousRelease) {
                    @rename($previousRelease, $releaseDirectory);
                }
                throw new RuntimeException('The completed release could not be promoted atomically.');
            }
            try {
                $this->updateRepositoryIndex($output, $remote);
            } catch (\Throwable $exception) {
                File::deleteDirectory($releaseDirectory);
                if ($previousRelease) {
                    @rename($previousRelease, $releaseDirectory);
                }
                throw $exception;
            }
            if ($previousRelease) {
                File::deleteDirectory($previousRelease);
            }
        } catch (\Throwable $exception) {
            File::deleteDirectory($temporary);
            throw $exception;
        }

        return [
            'release_directory' => $releaseDirectory,
            'manifest' => $releaseDirectory.'/manifest.json',
            'package' => $releaseDirectory.'/update.zip',
            'from_version' => $from,
            'to_version' => $to,
            'file_operations' => count($operations),
            'package_size_bytes' => (int) filesize($releaseDirectory.'/update.zip'),
            'package_sha256' => hash_file('sha256', $releaseDirectory.'/update.zip'),
        ];
    }

    private function files(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $item) {
            $path = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            if ($this->ignored($path)) {
                continue;
            }
            if ($item->isLink()) {
                throw new RuntimeException("Release snapshot contains unsupported symbolic link {$path}.");
            }
            if (! $item->isFile() || ! $item->isReadable()) {
                throw new RuntimeException("Release snapshot file {$path} is not readable.");
            }
            $files[$path] = ['sha256' => hash_file('sha256', $item->getPathname()), 'size_bytes' => (int) $item->getSize(), 'mode' => $item->getPerms() & 0777];
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function ignored(string $path): bool
    {
        foreach (self::ALWAYS_IGNORED as $ignored) {
            if ($path === $ignored || str_starts_with($path, $ignored.'/')) {
                return true;
            }
        }

        return UpdateManifest::isProtectedPath($path);
    }

    private function writeOperation(string $action, string $path, array $metadata, ?array $previous = null): array
    {
        $operation = [
            'action' => $action,
            'source' => 'files/'.$path,
            'target' => $path,
            'sha256' => $metadata['sha256'],
            'size_bytes' => $metadata['size_bytes'],
            'mode' => max(0600, min(0775, $metadata['mode'])),
        ];
        if ($action === 'replace') {
            $operation['previous_sha256'] = $previous['sha256'] ?? null;
        }

        return $operation;
    }

    private function pathsChanged(array $previous, array $current, array $prefixes): bool
    {
        foreach (array_unique([...array_keys($previous), ...array_keys($current)]) as $path) {
            foreach ($prefixes as $prefix) {
                $matches = str_ends_with($prefix, '/') ? str_starts_with($path, $prefix) : $path === $prefix;
                if ($matches && (($previous[$path]['sha256'] ?? null) !== ($current[$path]['sha256'] ?? null) || ($previous[$path]['mode'] ?? null) !== ($current[$path]['mode'] ?? null))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isFrontendPath(string $path): bool
    {
        return str_starts_with($path, 'build/')
            || str_starts_with($path, 'resources/js/')
            || str_starts_with($path, 'resources/css/')
            || in_array($path, ['package.json', 'package-lock.json', 'vite.config.js'], true);
    }

    private function updateRepositoryIndex(string $output, array $manifest): void
    {
        $path = $output.'/repository.json';
        $index = $this->repositoryIndex($path) ?? ['schema_version' => 1, 'product' => 'playhub', 'latest_version' => $manifest['to_version'], 'releases' => []];
        foreach ((array) $index['releases'] as $release) {
            if (($release['from_version'] ?? null) === $manifest['from_version'] && ($release['to_version'] ?? null) !== $manifest['to_version']) {
                throw new RuntimeException("Repository already contains a different successor for PlayHub {$manifest['from_version']}.");
            }
        }
        $index['releases'] = array_values(array_filter((array) $index['releases'], fn (array $release): bool => ($release['from_version'] ?? null) !== $manifest['from_version'] || ($release['to_version'] ?? null) !== $manifest['to_version']));
        $index['releases'][] = [
            'from_version' => $manifest['from_version'],
            'to_version' => $manifest['to_version'],
            'manifest' => 'releases/'.$manifest['to_version'].'/manifest.json',
        ];
        usort($index['releases'], fn (array $left, array $right): int => version_compare($left['from_version'], $right['from_version']));
        $index['latest_version'] = array_reduce($index['releases'], fn (string $latest, array $release): string => version_compare($release['to_version'], $latest, '>') ? $release['to_version'] : $latest, '0.0.0');
        $index['updated_at'] = now()->utc()->toIso8601String();
        $this->writeJson($path, $index);
        $this->writeRepositoryResponses($output, $index);
    }

    private function assertRepositorySuccessor(string $output, string $from, string $to): void
    {
        $path = $output.'/repository.json';
        if (! is_file($path)) {
            return;
        }
        $index = $this->repositoryIndex($path);
        foreach ((array) ($index['releases'] ?? []) as $release) {
            if (($release['from_version'] ?? null) === $from && ($release['to_version'] ?? null) !== $to) {
                throw new RuntimeException("Repository already contains a different successor for PlayHub {$from}.");
            }
            if (($release['to_version'] ?? null) === $to && ($release['from_version'] ?? null) !== $from) {
                throw new RuntimeException("PlayHub {$to} already has a delta package from a different predecessor.");
            }
        }
    }

    private function writeRepositoryResponses(string $output, array $index): void
    {
        $edges = [];
        foreach ($index['releases'] as $release) {
            $edges[$release['from_version']] = $release;
        }
        $versions = array_values(array_unique([
            ...array_keys($edges),
            ...array_map(fn (array $release): string => (string) $release['to_version'], $index['releases']),
        ]));
        foreach ($versions as $fromVersion) {
            $cursor = $fromVersion;
            $updates = [];
            $visited = [];
            while (isset($edges[$cursor])) {
                if (isset($visited[$cursor]) || count($updates) >= 50) {
                    throw new RuntimeException('The generated update repository contains a cycle or an excessive upgrade path.');
                }
                $visited[$cursor] = true;
                $edge = $edges[$cursor];
                $manifestPath = $output.'/'.UpdateManifest::normalizePath((string) $edge['manifest']);
                $manifest = is_readable($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
                if (! is_array($manifest)) {
                    throw new RuntimeException("Repository manifest {$edge['manifest']} could not be loaded.");
                }
                UpdateManifest::fromArray($manifest);
                $updates[] = $manifest;
                $cursor = $edge['to_version'];
            }
            $this->writeJson($output.'/responses/'.$fromVersion.'.json', [
                'schema_version' => 1,
                'product' => 'playhub',
                'current_version' => $fromVersion,
                'latest_version' => $cursor,
                'updates' => $updates,
            ]);
        }
    }

    private function repositoryIndex(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $index = json_decode((string) file_get_contents($path), true);
        if (! is_array($index)
            || ($index['schema_version'] ?? null) !== 1
            || ($index['product'] ?? null) !== 'playhub'
            || ! UpdateManifest::isValidVersion($index['latest_version'] ?? null)
            || ! is_array($index['releases'] ?? null)
            || ! array_is_list($index['releases'])) {
            throw new RuntimeException('The existing update repository index is invalid.');
        }

        $fromVersions = [];
        $toVersions = [];
        foreach ($index['releases'] as $release) {
            $from = is_array($release) ? ($release['from_version'] ?? null) : null;
            $to = is_array($release) ? ($release['to_version'] ?? null) : null;
            $manifest = is_array($release) ? ($release['manifest'] ?? null) : null;
            if (! UpdateManifest::isValidVersion($from)
                || ! UpdateManifest::isValidVersion($to)
                || version_compare($to, $from, '<=')
                || $manifest !== "releases/{$to}/manifest.json"
                || isset($fromVersions[$from])
                || isset($toVersions[$to])) {
                throw new RuntimeException('The existing update repository index is invalid.');
            }
            $fromVersions[$from] = true;
            $toVersions[$to] = true;
        }

        return $index;
    }

    private function assertPrebuiltFrontend(string $buildDirectory): void
    {
        $manifestPath = $buildDirectory.'/manifest.json';
        $manifest = is_file($manifestPath) && ! is_link($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : null;
        if (! is_array($manifest) || ! $manifest) {
            throw new RuntimeException('Prebuilt frontend mode requires a valid Vite build/manifest.json.');
        }
        foreach ($manifest as $entry) {
            if (! is_array($entry) || ! is_string($entry['file'] ?? null) || (isset($entry['css']) && (! is_array($entry['css']) || ! array_is_list($entry['css'])))) {
                throw new RuntimeException('The prebuilt Vite manifest contains an invalid entry.');
            }
            $assets = [$entry['file'], ...(is_array($entry['css'] ?? null) ? $entry['css'] : [])];
            foreach ($assets as $asset) {
                if (! is_string($asset)) {
                    throw new RuntimeException('The prebuilt Vite manifest contains an invalid asset path.');
                }
                $asset = UpdateManifest::normalizePath($asset);
                if (! is_file($buildDirectory.'/'.$asset) || is_link($buildDirectory.'/'.$asset)) {
                    throw new RuntimeException("Prebuilt frontend asset {$asset} is missing.");
                }
            }
        }
    }

    private function defaultRequirements(): array
    {
        // A publisher's newer patch must not block compatible installed releases.
        // Publishers can declare a stricter minimum through --requirements.
        $laravel = preg_match('/^(\d+)\./', Application::VERSION, $match) ? $match[1].'.0.0' : '13.0.0';

        return [
            'php_min' => '8.3.0',
            'laravel_min' => $laravel,
            'extensions' => ['ctype', 'fileinfo', 'filter', 'json', 'mbstring', 'openssl', 'zip'],
            'environment' => [],
            'database' => [
                'drivers' => ['mysql', 'mariadb', 'pgsql', 'sqlite'],
                'minimum_versions' => ['mysql' => '8.0.0', 'mariadb' => '10.6.0', 'pgsql' => '13.0.0', 'sqlite' => '3.35.0'],
            ],
            'disk_free_bytes' => 0,
        ];
    }

    private function mergeRequirements(array $overrides): array
    {
        if (array_key_exists('database', $overrides) && ! is_array($overrides['database'])) {
            throw new InvalidArgumentException('The release database requirements must be a JSON object.');
        }
        $requirements = array_replace($this->defaultRequirements(), $overrides);
        $requirements['database'] = array_replace(
            $this->defaultRequirements()['database'],
            is_array($overrides['database'] ?? null) ? $overrides['database'] : [],
        );

        return $requirements;
    }

    private function assertBaseUrl(string $url, bool $unsigned): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $insecureLocal = $unsigned && app()->environment(['local', 'testing']) && $local;
        if (($scheme !== 'https' && ! ($scheme === 'http' && $insecureLocal)) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The update repository base URL must be HTTPS and cannot contain credentials, a query, or a fragment.');
        }
    }

    private function assertSnapshotVersion(string $root, string $expected): void
    {
        $path = $root.'/VERSION';
        if (! is_readable($path) || trim((string) file_get_contents($path)) !== $expected) {
            throw new RuntimeException("Release snapshot {$root} does not declare VERSION {$expected}.");
        }
    }

    private function releaseRoot(string $path): string
    {
        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException("Release snapshot {$path} does not exist.");
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function jsonFile(string $path, string $label): array
    {
        if ($path === '' || ! is_readable($path)) {
            throw new InvalidArgumentException("A readable {$label} JSON file is required.");
        }

        return $this->decodeJson((string) file_get_contents($path), $label);
    }

    private function optionalJsonFile(string $path, string $label): array
    {
        return $path === '' ? [] : $this->jsonFile($path, $label);
    }

    private function decodeJson(string $json, string $label): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new InvalidArgumentException("The {$label} file is not a JSON object.");
        }

        return $data;
    }

    private function publicKeyFromPrivate(\OpenSSLAsymmetricKey $privateKey): string
    {
        $details = openssl_pkey_get_details($privateKey);
        if (! is_array($details) || ! is_string($details['key'] ?? null)) {
            throw new RuntimeException('The signing key public component could not be read.');
        }

        return $details['key'];
    }

    private function writeJson(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname($path), 0770, true);
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(4));
        if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write {$path} atomically.");
        }
    }

    private function pathInside(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path.DIRECTORY_SEPARATOR, $directory.DIRECTORY_SEPARATOR);
    }

    private function prospectivePath(string $path): string
    {
        $absolute = Path::canonicalize(Path::makeAbsolute($path, getcwd() ?: base_path()));
        $cursor = $absolute;
        $suffix = [];
        while (! file_exists($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new InvalidArgumentException('The update repository output path is invalid.');
            }
            array_unshift($suffix, basename($cursor));
            $cursor = $parent;
        }
        $real = realpath($cursor);
        if ($real === false) {
            throw new InvalidArgumentException('The update repository output path is invalid.');
        }

        return $suffix ? $real.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $suffix) : $real;
    }
}
