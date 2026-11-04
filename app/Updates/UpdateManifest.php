<?php

namespace App\Updates;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final class UpdateManifest
{
    public const SCHEMA_VERSION = 1;

    private const SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(?:(?:0|[1-9]\d*)|(?:\d*[A-Za-z-][0-9A-Za-z-]*))(?:\.(?:(?:0|[1-9]\d*)|(?:\d*[A-Za-z-][0-9A-Za-z-]*)))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

    private const CHANGELOG_SECTIONS = ['new', 'improved', 'fixed', 'security'];

    private const HEALTH_CHECKS = ['application', 'database', 'filesystem', 'files', 'migrations'];

    private function __construct(private readonly array $data) {}

    public static function fromArray(array $data, bool $remote = true): self
    {
        self::validate($data, $remote);

        return new self($data);
    }

    public static function fromJson(string $json, bool $remote = true): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The update manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('The update manifest must be a JSON object.');
        }

        return self::fromArray($data, $remote);
    }

    public function id(): string
    {
        return $this->data['id'];
    }

    public function fromVersion(): string
    {
        return $this->data['from_version'];
    }

    public function toVersion(): string
    {
        return $this->data['to_version'];
    }

    public function package(): array
    {
        return $this->data['package'];
    }

    public function requirements(): array
    {
        return $this->data['requirements'];
    }

    public function fileOperations(): array
    {
        return $this->data['operations']['files'];
    }

    public function migrationsRequired(): bool
    {
        return (bool) $this->data['operations']['migrations'];
    }

    public function composer(): array
    {
        return $this->data['operations']['composer'];
    }

    public function frontend(): array
    {
        return $this->data['operations']['frontend'];
    }

    public function postUpdateOperations(): array
    {
        return $this->data['operations']['post_update'];
    }

    public function healthChecks(): array
    {
        return $this->data['health_checks'];
    }

    public function requiresMaintenance(): bool
    {
        return (bool) $this->data['maintenance']['required'];
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function installationData(): array
    {
        $data = $this->data;
        unset($data['package'], $data['signature']);

        return $data;
    }

    public function verifyPackageManifest(self $packageManifest): void
    {
        $expected = $this->package()['manifest_sha256'];
        $actual = hash('sha256', self::canonicalJson($packageManifest->installationData()));
        if (! hash_equals($expected, $actual) || self::canonicalJson($packageManifest->installationData()) !== self::canonicalJson($this->installationData())) {
            throw new InvalidArgumentException('The manifest inside the update package does not match the signed release manifest.');
        }
    }

    public function verifySignature(?string $publicKey, bool $required = true): void
    {
        $signature = $this->data['signature'] ?? null;
        if (! $signature) {
            if ($required) {
                throw new InvalidArgumentException('The update manifest is not signed.');
            }

            return;
        }

        if (! is_string($publicKey) || trim($publicKey) === '') {
            throw new InvalidArgumentException('The PlayHub update verification public key is not configured.');
        }

        $decoded = base64_decode($signature['value'], true);
        if ($decoded === false) {
            throw new InvalidArgumentException('The update manifest signature is malformed.');
        }

        $payload = $this->data;
        unset($payload['signature']);
        $key = @openssl_pkey_get_public($publicKey);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($details['bits'] ?? 0) < 2048) {
            throw new InvalidArgumentException('The PlayHub update verification key must be an RSA public key of at least 2048 bits.');
        }

        $result = openssl_verify(self::canonicalJson($payload), $decoded, $key, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new InvalidArgumentException('The update manifest signature is invalid.');
        }
    }

    public function summary(): array
    {
        return [
            'id' => $this->id(),
            'from_version' => $this->fromVersion(),
            'to_version' => $this->toVersion(),
            'released_at' => $this->data['released_at'],
            'changelog' => $this->data['changelog'],
            'requirements' => $this->requirements(),
            'maintenance_required' => $this->requiresMaintenance(),
            'changes' => $this->data['changes'],
            'package_size_bytes' => $this->package()['size_bytes'],
            'file_operations' => count($this->fileOperations()),
            'database_migrations' => $this->migrationsRequired(),
            'frontend_mode' => $this->frontend()['mode'],
            'composer_mode' => $this->composer()['mode'],
        ];
    }

    public static function canonicalJson(array $data): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $sort($item);
            }

            return $value;
        };

        return json_encode($sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function normalizePath(string $path): string
    {
        if ($path === '' || strlen($path) > 500 || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new InvalidArgumentException('The update manifest contains an unsafe path.');
        }

        $segments = explode('/', $path);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('The update manifest contains an unsafe path.');
        }
        foreach ($segments as $segment) {
            if (preg_match('/[\x00-\x1F\x7F<>:"|?*]/', $segment)
                || rtrim($segment, '. ') !== $segment
                || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $segment)) {
                throw new InvalidArgumentException('The update manifest contains an unsafe path.');
            }
        }

        return implode('/', $segments);
    }

    public static function isValidVersion(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SEMVER_PATTERN, $value) === 1;
    }

    public static function isProtectedPath(string $path): bool
    {
        $path = self::normalizePath($path);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
        }
        foreach ((array) config('playhub-updates.protected_paths', []) as $protected) {
            $protected = trim(str_replace('\\', '/', (string) $protected), '/');
            if (PHP_OS_FAMILY === 'Windows') {
                $protected = strtolower($protected);
            }
            if ($protected === '') {
                continue;
            }
            if (str_ends_with($protected, '.*')) {
                $prefix = substr($protected, 0, -1);
                if (str_starts_with($path, $prefix)) {
                    return true;
                }

                continue;
            }
            if ($path === $protected || str_starts_with($path, $protected.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function validate(array $data, bool $remote): void
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION || ($data['product'] ?? null) !== 'playhub') {
            throw new InvalidArgumentException('The package targets an unsupported product or update schema.');
        }
        if (! is_string($data['id'] ?? null) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $data['id'])) {
            throw new InvalidArgumentException('The update manifest ID must be a valid UUID.');
        }

        foreach (['from_version', 'to_version', 'minimum_supported_version'] as $field) {
            if (! self::isValidVersion($data[$field] ?? null)) {
                throw new InvalidArgumentException("The update manifest field {$field} must use semantic versioning.");
            }
        }
        if (version_compare($data['to_version'], $data['from_version'], '<=') && ! config('playhub-updates.allow_downgrade', false)) {
            throw new InvalidArgumentException('Update manifests must advance the installed PlayHub version.');
        }
        if (! is_string($data['released_at'] ?? null) || ! self::validDate($data['released_at'])) {
            throw new InvalidArgumentException('The update release date is invalid.');
        }

        if (! is_array($data['changelog'] ?? null)) {
            throw new InvalidArgumentException('The update changelog is invalid.');
        }
        foreach (self::CHANGELOG_SECTIONS as $section) {
            if (! array_key_exists($section, $data['changelog']) || ! self::stringList($data['changelog'][$section], 200, 1000)) {
                throw new InvalidArgumentException("The update changelog section {$section} is invalid.");
            }
        }

        self::validateRequirements($data['requirements'] ?? null);
        if (! is_array($data['maintenance'] ?? null) || ! is_bool($data['maintenance']['required'] ?? null)) {
            throw new InvalidArgumentException('The update maintenance mode instruction is invalid.');
        }
        if (! is_array($data['changes'] ?? null)) {
            throw new InvalidArgumentException('The update change summary is invalid.');
        }
        foreach (['backend', 'frontend', 'database'] as $change) {
            if (! is_bool($data['changes'][$change] ?? null)) {
                throw new InvalidArgumentException("The update change flag {$change} is invalid.");
            }
        }

        self::validateOperations($data['operations'] ?? null);
        $requiresMaintenance = $data['operations']['files'] !== []
            || $data['operations']['migrations']
            || $data['operations']['composer']['mode'] === 'install'
            || $data['operations']['frontend']['mode'] !== 'none';
        if ($requiresMaintenance && ! $data['maintenance']['required']) {
            throw new InvalidArgumentException('Updates that change application files, dependencies, frontend assets, or the database must use maintenance mode.');
        }
        if ($data['operations']['migrations'] && ! $data['changes']['database']) {
            throw new InvalidArgumentException('A release with database migrations must declare database changes.');
        }
        if ($data['operations']['composer']['mode'] === 'install' && ! $data['changes']['backend']) {
            throw new InvalidArgumentException('A release with Composer operations must declare backend changes.');
        }
        if ($data['operations']['frontend']['mode'] !== 'none' && ! $data['changes']['frontend']) {
            throw new InvalidArgumentException('A release with frontend operations must declare frontend changes.');
        }
        if (! self::stringList($data['health_checks'] ?? null, count(self::HEALTH_CHECKS), 40)) {
            throw new InvalidArgumentException('The update health check list is invalid.');
        }
        foreach ($data['health_checks'] as $check) {
            if (! in_array($check, self::HEALTH_CHECKS, true)) {
                throw new InvalidArgumentException("The update health check {$check} is not allowed.");
            }
        }

        if ($remote) {
            self::validatePackage($data['package'] ?? null);
            if (isset($data['signature'])) {
                if (! is_array($data['signature']) || ($data['signature']['algorithm'] ?? null) !== 'rsa-sha256' || ! is_string($data['signature']['value'] ?? null) || strlen($data['signature']['value']) > 16384) {
                    throw new InvalidArgumentException('The update manifest signature is invalid.');
                }
            }
        } elseif (isset($data['package']) || isset($data['signature'])) {
            throw new InvalidArgumentException('A packaged installation manifest cannot contain remote package metadata.');
        }
    }

    private static function validateRequirements(mixed $requirements): void
    {
        if (! is_array($requirements) || ! self::isValidVersion($requirements['php_min'] ?? null) || ! self::isValidVersion($requirements['laravel_min'] ?? null)) {
            throw new InvalidArgumentException('The update PHP or Laravel requirement is invalid.');
        }
        if (! self::stringList($requirements['extensions'] ?? null, 100, 80)) {
            throw new InvalidArgumentException('The required PHP extension list is invalid.');
        }
        foreach ($requirements['extensions'] as $extension) {
            if (! preg_match('/^[a-z0-9_-]+$/i', $extension)) {
                throw new InvalidArgumentException('The required PHP extension list contains an invalid name.');
            }
        }
        if (! is_array($requirements['environment'] ?? null) || ! array_is_list($requirements['environment']) || count($requirements['environment']) > 50) {
            throw new InvalidArgumentException('The update environment requirement list is invalid.');
        }
        foreach ($requirements['environment'] as $setting) {
            if (! is_array($setting) || ! is_string($setting['name'] ?? null) || ! preg_match('/^[A-Z][A-Z0-9_]{1,79}$/', $setting['name']) || ! is_string($setting['description'] ?? null) || strlen($setting['description']) > 500 || ! is_bool($setting['required'] ?? null)) {
                throw new InvalidArgumentException('The update contains an invalid environment setting requirement.');
            }
        }
        $database = $requirements['database'] ?? null;
        if (! is_array($database) || ! self::stringList($database['drivers'] ?? null, 10, 20) || ! is_array($database['minimum_versions'] ?? null)) {
            throw new InvalidArgumentException('The update database requirement is invalid.');
        }
        foreach ($database['drivers'] as $driver) {
            if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
                throw new InvalidArgumentException("The database driver {$driver} is not supported by the updater.");
            }
        }
        foreach ($database['minimum_versions'] as $driver => $version) {
            if (! in_array($driver, $database['drivers'], true) || ! self::isValidVersion($version)) {
                throw new InvalidArgumentException('The minimum database version requirement is invalid.');
            }
        }
        if (! is_int($requirements['disk_free_bytes'] ?? null) || $requirements['disk_free_bytes'] < 0) {
            throw new InvalidArgumentException('The update disk space requirement is invalid.');
        }
    }

    private static function validateOperations(mixed $operations): void
    {
        if (! is_array($operations) || ! is_array($operations['files'] ?? null) || count($operations['files']) > (int) config('playhub-updates.max_files', 20000)) {
            throw new InvalidArgumentException('The update file operation list is invalid or too large.');
        }

        $targets = [];
        $totalFileBytes = 0;
        $maximumFileBytes = max(1, (int) config('playhub-updates.max_uncompressed_mb', 1536)) * 1024 * 1024;
        $addsMigration = false;
        foreach ($operations['files'] as $operation) {
            if (! is_array($operation) || ! in_array($operation['action'] ?? null, ['add', 'replace', 'delete'], true) || ! is_string($operation['target'] ?? null)) {
                throw new InvalidArgumentException('The update contains an invalid file operation.');
            }
            $target = self::normalizePath($operation['target']);
            if (self::isProtectedPath($target)) {
                throw new InvalidArgumentException("The update attempted to modify protected path {$target}.");
            }
            if (isset($targets[$target])) {
                throw new InvalidArgumentException("The update contains duplicate operations for {$target}.");
            }
            $targets[$target] = true;
            if (str_starts_with($target, 'database/migrations/')) {
                if ($operation['action'] !== 'add') {
                    throw new InvalidArgumentException("Published Laravel migration {$target} is immutable.");
                }
                $addsMigration = true;
            }

            if ($operation['action'] === 'delete') {
                if (array_key_exists('source', $operation) || array_key_exists('sha256', $operation) || array_key_exists('size_bytes', $operation) || array_key_exists('mode', $operation)) {
                    throw new InvalidArgumentException("The delete operation for {$target} contains invalid source data.");
                }
                if (isset($operation['previous_sha256']) && ! self::validHash($operation['previous_sha256'])) {
                    throw new InvalidArgumentException("The delete precondition for {$target} is invalid.");
                }

                continue;
            }

            if (($operation['source'] ?? null) !== 'files/'.$target || ! self::validHash($operation['sha256'] ?? null) || ! is_int($operation['size_bytes'] ?? null) || $operation['size_bytes'] < 0) {
                throw new InvalidArgumentException("The file operation for {$target} is invalid.");
            }
            if ($operation['size_bytes'] > $maximumFileBytes - $totalFileBytes) {
                throw new InvalidArgumentException('The update file operations exceed the allowed uncompressed size.');
            }
            $totalFileBytes += $operation['size_bytes'];
            if ($operation['action'] === 'add' && array_key_exists('previous_sha256', $operation)) {
                throw new InvalidArgumentException("The add operation for {$target} cannot contain a replacement precondition.");
            }
            if ($operation['action'] === 'replace' && ! self::validHash($operation['previous_sha256'] ?? null)) {
                throw new InvalidArgumentException("The replacement precondition for {$target} is invalid.");
            }
            if (isset($operation['mode']) && (! is_int($operation['mode']) || $operation['mode'] < 0600 || $operation['mode'] > 0775)) {
                throw new InvalidArgumentException("The file mode for {$target} is invalid.");
            }
        }

        foreach (array_keys($targets) as $target) {
            $ancestor = $target;
            while (($separator = strrpos($ancestor, '/')) !== false) {
                $ancestor = substr($ancestor, 0, $separator);
                if (isset($targets[$ancestor])) {
                    throw new InvalidArgumentException('A single update manifest cannot operate on both a path and one of its descendants.');
                }
            }
        }

        if (! is_bool($operations['migrations'] ?? null)) {
            throw new InvalidArgumentException('The update migration instruction is invalid.');
        }
        if ($operations['migrations'] !== $addsMigration) {
            throw new InvalidArgumentException('The update migration instruction must match its newly added Laravel migration files.');
        }
        $composer = $operations['composer'] ?? null;
        if (! is_array($composer) || ! in_array($composer['mode'] ?? null, ['none', 'install'], true)) {
            throw new InvalidArgumentException('The update Composer instruction is invalid.');
        }
        if ($composer['mode'] === 'install' && ! self::validHash($composer['lock_sha256'] ?? null)) {
            throw new InvalidArgumentException('A deterministic Composer install requires the expected composer.lock checksum.');
        }
        $frontend = $operations['frontend'] ?? null;
        if (! is_array($frontend) || ! in_array($frontend['mode'] ?? null, ['none', 'prebuilt', 'build'], true)) {
            throw new InvalidArgumentException('The update frontend instruction is invalid.');
        }
        if (! self::stringList($operations['post_update'] ?? null, 20, 60)) {
            throw new InvalidArgumentException('The update post-operation list is invalid.');
        }
        $allowed = (array) config('playhub-updates.post_update_operations', []);
        foreach ($operations['post_update'] as $operation) {
            if (! in_array($operation, $allowed, true)) {
                throw new InvalidArgumentException("The post-update operation {$operation} is not allowed.");
            }
        }
    }

    private static function validatePackage(mixed $package): void
    {
        $maxBytes = max(1, (int) config('playhub-updates.max_package_mb', 512)) * 1024 * 1024;
        if (! is_array($package) || ! is_string($package['url'] ?? null) || ! filter_var($package['url'], FILTER_VALIDATE_URL) || ! is_int($package['size_bytes'] ?? null) || $package['size_bytes'] < 1 || $package['size_bytes'] > $maxBytes || ! self::validHash($package['sha256'] ?? null) || ! self::validHash($package['manifest_sha256'] ?? null)) {
            throw new InvalidArgumentException('The update package metadata is invalid.');
        }
    }

    private static function validHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private static function validDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            return false;
        }
        try {
            new DateTimeImmutable($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function stringList(mixed $value, int $maxItems, int $maxLength): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maxItems) {
            return false;
        }
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '' || strlen($item) > $maxLength) {
                return false;
            }
        }

        return true;
    }
}
