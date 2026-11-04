<?php

namespace App\Plugins;

use InvalidArgumentException;

final class PluginManifest
{
    public const API_VERSION = 1;

    private function __construct(private readonly array $data) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The plugin.json manifest is missing or unreadable.');
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('The plugin.json manifest contains invalid JSON: '.$exception->getMessage());
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('The plugin.json manifest must contain a JSON object.');
        }

        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        $id = self::requiredString($data, 'id', 100);
        if (! preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $id)) {
            throw new InvalidArgumentException('Plugin id must use lowercase letters, numbers, dots, dashes, or underscores.');
        }
        if (in_array($id, ['admin', 'core', 'playhub', 'system'], true)) {
            throw new InvalidArgumentException('This plugin id is reserved by PlayHub.');
        }

        $name = self::requiredString($data, 'name', 120);
        $version = self::requiredString($data, 'version', 40);
        if (! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version)) {
            throw new InvalidArgumentException('Plugin version must use semantic versioning, for example 1.0.0.');
        }

        $api = (int) ($data['api'] ?? self::API_VERSION);
        if ($api !== self::API_VERSION) {
            throw new InvalidArgumentException('This plugin targets an unsupported PlayHub plugin API version.');
        }

        $description = self::optionalString($data['description'] ?? '', 1000);
        $homepage = self::optionalUrl($data['homepage'] ?? '');
        $author = self::author($data['author'] ?? '');
        $requires = self::normalizeRequires($data['requires'] ?? []);
        $dependencies = self::normalizeDependencies($data['dependencies'] ?? []);
        $autoload = self::normalizeAutoload($data['autoload'] ?? []);
        $providers = self::classList($data['providers'] ?? [], 'providers', 10);
        $lifecycle = self::optionalClass($data['lifecycle'] ?? null, 'lifecycle');
        $migrations = self::optionalPath($data['migrations'] ?? null, 'migrations');
        $assets = self::normalizeAssets($data['assets'] ?? []);
        $settings = self::normalizeSettings($data['settings'] ?? []);
        $capabilities = self::stringList($data['capabilities'] ?? [], 'capabilities', 30, 80);
        $mobile = $data['mobile'] ?? [];
        if (! is_array($mobile) || (isset($mobile['supported']) && ! is_bool($mobile['supported']))) {
            throw new InvalidArgumentException('Plugin mobile support must be an object with a boolean supported field.');
        }
        $mobile = [
            'supported' => ($mobile['supported'] ?? false) === true,
            'permissions' => self::stringList($mobile['permissions'] ?? [], 'mobile.permissions', 30, 80),
        ];

        foreach (array_merge($providers, $lifecycle ? [$lifecycle] : []) as $class) {
            if ($autoload !== [] && ! collect(array_keys($autoload))->contains(fn (string $namespace): bool => str_starts_with($class.'\\', $namespace))) {
                throw new InvalidArgumentException("Class {$class} is not covered by the manifest PSR-4 autoload map.");
            }
        }

        return new self([
            'id' => $id,
            'name' => $name,
            'version' => $version,
            'api' => $api,
            'description' => $description,
            'homepage' => $homepage,
            'author' => $author,
            'requires' => $requires,
            'dependencies' => $dependencies,
            'autoload' => ['psr-4' => $autoload],
            'providers' => $providers,
            'lifecycle' => $lifecycle,
            'migrations' => $migrations,
            'assets' => $assets,
            'settings' => $settings,
            'capabilities' => $capabilities,
            'mobile' => $mobile,
        ]);
    }

    public function id(): string
    {
        return $this->data['id'];
    }

    public function name(): string
    {
        return $this->data['name'];
    }

    public function version(): string
    {
        return $this->data['version'];
    }

    public function authorName(): ?string
    {
        return $this->data['author']['name'] ?: null;
    }

    public function providers(): array
    {
        return $this->data['providers'];
    }

    public function lifecycle(): ?string
    {
        return $this->data['lifecycle'];
    }

    public function migrations(): ?string
    {
        return $this->data['migrations'];
    }

    public function autoload(): array
    {
        return $this->data['autoload']['psr-4'];
    }

    public function assets(): array
    {
        return $this->data['assets'];
    }

    public function settings(): array
    {
        return $this->data['settings'];
    }

    public function requires(): array
    {
        return $this->data['requires'];
    }

    public function mobile(): array
    {
        return $this->data['mobile'];
    }

    public function dependencies(): array
    {
        return $this->data['dependencies'];
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public static function safeRelativePath(string $path): bool
    {
        if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path) || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }

        $segments = explode('/', $path);

        return ! collect($segments)->contains(fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..');
    }

    private static function requiredString(array $data, string $key, int $max): string
    {
        $value = self::optionalString($data[$key] ?? '', $max);
        if ($value === '') {
            throw new InvalidArgumentException("Plugin manifest field {$key} is required.");
        }

        return $value;
    }

    private static function optionalString(mixed $value, int $max): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Plugin manifest text fields must be strings.');
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("A plugin manifest text field exceeds {$max} characters.");
        }

        return $value;
    }

    private static function optionalUrl(mixed $value): string
    {
        $url = self::optionalString($value, 1000);
        if ($url !== '' && (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
            throw new InvalidArgumentException('Plugin homepage and author URLs must use http or https.');
        }

        return $url;
    }

    private static function author(mixed $value): array
    {
        if (is_string($value)) {
            return ['name' => self::optionalString($value, 160), 'url' => ''];
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Plugin author must be a name or an object.');
        }

        return [
            'name' => self::optionalString($value['name'] ?? '', 160),
            'url' => self::optionalUrl($value['url'] ?? ''),
        ];
    }

    private static function normalizeRequires(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Plugin requires must be an object.');
        }

        $playhub = self::optionalString($value['playhub'] ?? '>=0.7.6', 80);
        $php = self::optionalString($value['php'] ?? '>=8.3.0', 80);
        self::validateConstraint($playhub, 'requires.playhub');
        self::validateConstraint($php, 'requires.php');

        return [
            'playhub' => $playhub,
            'php' => $php,
            'extensions' => self::stringList($value['extensions'] ?? [], 'requires.extensions', 30, 60, '/^[a-z0-9_-]+$/i'),
        ];
    }

    private static function normalizeDependencies(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Plugin dependencies must be an object keyed by plugin id.');
        }

        $dependencies = [];
        foreach ($value as $id => $constraint) {
            if (! is_string($id) || ! preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $id)) {
                throw new InvalidArgumentException('A plugin dependency has an invalid plugin id.');
            }
            $constraint = self::optionalString($constraint, 80);
            self::validateConstraint($constraint, "dependencies.{$id}");
            $dependencies[$id] = $constraint;
        }

        return $dependencies;
    }

    private static function validateConstraint(string $constraint, string $field): void
    {
        $parts = preg_split('/(?:\s*,\s*|\s+)/', trim($constraint), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === [] || count($parts) > 4) {
            throw new InvalidArgumentException("Plugin manifest field {$field} contains an invalid version constraint.");
        }
        foreach ($parts as $part) {
            if (! preg_match('/^(?:\^|~|>=|<=|>|<|=)?\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.-]+)?$/', $part)) {
                throw new InvalidArgumentException("Plugin manifest field {$field} contains an invalid version constraint.");
            }
        }
    }

    private static function normalizeAutoload(mixed $value): array
    {
        if ($value === [] || $value === null) {
            return [];
        }
        if (is_array($value) && ($value['psr-4'] ?? null) === []) {
            return [];
        }
        if (! is_array($value) || ! is_array($value['psr-4'] ?? null) || array_is_list($value['psr-4'])) {
            throw new InvalidArgumentException('Plugin autoload.psr-4 must be an object.');
        }

        $autoload = [];
        foreach ($value['psr-4'] as $namespace => $path) {
            if (! is_string($namespace) || ! preg_match('/^(?:[A-Z_a-z\x80-\xff][A-Z_a-z0-9\x80-\xff]*\\\\)+$/', $namespace)) {
                throw new InvalidArgumentException('Plugin PSR-4 namespaces must be valid and end with a backslash.');
            }
            $path = self::optionalPath($path, "autoload.psr-4.{$namespace}");
            $autoload[$namespace] = rtrim($path, '/').'/';
        }

        return $autoload;
    }

    private static function classList(mixed $value, string $field, int $max): array
    {
        $classes = self::stringList($value, $field, $max, 255);
        foreach ($classes as $class) {
            self::validateClass($class, $field);
        }

        return $classes;
    }

    private static function optionalClass(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("Plugin manifest field {$field} must be a class name.");
        }
        $value = trim($value, " \\t\n\r\0\x0B\\");
        self::validateClass($value, $field);

        return $value;
    }

    private static function validateClass(string $class, string $field): void
    {
        if (! preg_match('/^(?:[A-Z_a-z\x80-\xff][A-Z_a-z0-9\x80-\xff]*\\\\)*[A-Z_a-z\x80-\xff][A-Z_a-z0-9\x80-\xff]*$/', $class)) {
            throw new InvalidArgumentException("Plugin manifest field {$field} contains an invalid class name.");
        }
    }

    private static function optionalPath(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("Plugin manifest field {$field} must be a relative path.");
        }
        $value = trim($value, '/');
        if (! self::safeRelativePath($value) || mb_strlen($value) > 240) {
            throw new InvalidArgumentException("Plugin manifest field {$field} must be a safe relative path.");
        }

        return $value;
    }

    private static function normalizeAssets(mixed $value): array
    {
        if ($value === null || $value === []) {
            return ['styles' => [], 'scripts' => [], 'files' => []];
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Plugin assets must be an object.');
        }

        return [
            'styles' => self::pathList($value['styles'] ?? [], 'assets.styles', ['css']),
            'scripts' => self::pathList($value['scripts'] ?? [], 'assets.scripts', ['js', 'mjs']),
            'files' => self::pathList($value['files'] ?? [], 'assets.files', ['css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'json', 'wasm']),
        ];
    }

    private static function pathList(mixed $value, string $field, array $extensions): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 30) {
            throw new InvalidArgumentException("Plugin manifest field {$field} must be a list with at most 30 files.");
        }

        return array_map(function (mixed $path) use ($field, $extensions): string {
            $path = self::optionalPath($path, $field);
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
                throw new InvalidArgumentException("Plugin manifest field {$field} contains an unsupported file type.");
            }

            return $path;
        }, $value);
    }

    private static function normalizeSettings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 100) {
            throw new InvalidArgumentException('Plugin settings must be a list with at most 100 fields.');
        }

        $settings = [];
        $keys = [];
        foreach ($value as $index => $field) {
            if (! is_array($field)) {
                throw new InvalidArgumentException("Plugin setting {$index} must be an object.");
            }
            $key = self::requiredString($field, 'key', 100);
            if (! preg_match('/^[a-z][a-z0-9_.-]*$/', $key) || isset($keys[$key])) {
                throw new InvalidArgumentException("Plugin setting key {$key} is invalid or duplicated.");
            }
            $keys[$key] = true;
            $type = self::optionalString($field['type'] ?? 'text', 20);
            if (! in_array($type, ['text', 'password', 'textarea', 'boolean', 'number', 'select'], true)) {
                throw new InvalidArgumentException("Plugin setting {$key} uses an unsupported field type.");
            }
            $options = $type === 'select' ? self::settingOptions($field['options'] ?? [], $key) : [];
            $public = (bool) ($field['public'] ?? false);
            if ($type === 'password' && $public) {
                throw new InvalidArgumentException("Password setting {$key} cannot be public.");
            }
            if ((array_key_exists('required', $field) && ! is_bool($field['required'])) || (array_key_exists('public', $field) && ! is_bool($field['public']))) {
                throw new InvalidArgumentException("Plugin setting {$key} required and public flags must be booleans.");
            }
            if ((array_key_exists('min', $field) && $field['min'] !== null && ! is_numeric($field['min'])) || (array_key_exists('max', $field) && $field['max'] !== null && ! is_numeric($field['max']))) {
                throw new InvalidArgumentException("Plugin setting {$key} min and max values must be numbers.");
            }
            $min = isset($field['min']) ? (float) $field['min'] : null;
            $max = isset($field['max']) ? (float) $field['max'] : null;
            if ($min !== null && $max !== null && $min > $max) {
                throw new InvalidArgumentException("Plugin setting {$key} has a minimum greater than its maximum.");
            }
            $maxLength = max(1, min(20000, (int) ($field['max_length'] ?? ($type === 'textarea' ? 5000 : 1000))));
            $default = $field['default'] ?? ($type === 'boolean' ? false : null);
            if ($default !== null) {
                if ($type === 'boolean' && ! is_bool($default)) {
                    throw new InvalidArgumentException("Plugin setting {$key} default must be a boolean.");
                }
                if ($type === 'number') {
                    if (! is_int($default) && ! is_float($default)) {
                        throw new InvalidArgumentException("Plugin setting {$key} default must be a number.");
                    }
                    $default = (float) $default;
                    if (($min !== null && $default < $min) || ($max !== null && $default > $max)) {
                        throw new InvalidArgumentException("Plugin setting {$key} default is outside its allowed range.");
                    }
                } elseif ($type === 'select') {
                    if (! is_string($default) || ! collect($options)->pluck('value')->containsStrict($default)) {
                        throw new InvalidArgumentException("Plugin setting {$key} default is not one of its options.");
                    }
                } elseif ($type !== 'boolean') {
                    if (! is_string($default) || mb_strlen($default) > $maxLength) {
                        throw new InvalidArgumentException("Plugin setting {$key} default must be valid text.");
                    }
                }
            }
            $settings[] = [
                'key' => $key,
                'label' => self::requiredString($field, 'label', 120),
                'type' => $type,
                'description' => self::optionalString($field['description'] ?? '', 300),
                'placeholder' => self::optionalString($field['placeholder'] ?? '', 200),
                'default' => $default,
                'required' => (bool) ($field['required'] ?? false),
                'public' => $public,
                'min' => $min,
                'max' => $max,
                'max_length' => $maxLength,
                'options' => $options,
            ];
        }

        return $settings;
    }

    private static function settingOptions(mixed $value, string $key): array
    {
        if (! is_array($value) || count($value) < 1 || count($value) > 100) {
            throw new InvalidArgumentException("Select setting {$key} must define between 1 and 100 options.");
        }

        $options = [];
        $seen = [];
        foreach ($value as $optionKey => $option) {
            if (is_array($option)) {
                $optionValue = self::optionalString($option['value'] ?? '', 200);
                $optionLabel = self::optionalString($option['label'] ?? $optionValue, 200);
            } else {
                $optionValue = is_int($optionKey) ? self::optionalString($option, 200) : self::optionalString($optionKey, 200);
                $optionLabel = is_int($optionKey) ? $optionValue : self::optionalString($option, 200);
            }
            if ($optionValue === '') {
                throw new InvalidArgumentException("Select setting {$key} contains an empty option.");
            }
            if (isset($seen[$optionValue])) {
                throw new InvalidArgumentException("Select setting {$key} contains duplicate option values.");
            }
            $seen[$optionValue] = true;
            $options[] = ['value' => $optionValue, 'label' => $optionLabel];
        }

        return $options;
    }

    private static function stringList(mixed $value, string $field, int $maxItems, int $maxLength, ?string $pattern = null): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maxItems) {
            throw new InvalidArgumentException("Plugin manifest field {$field} must be a list with at most {$maxItems} values.");
        }

        $items = [];
        foreach ($value as $item) {
            $item = self::optionalString($item, $maxLength);
            if ($item === '' || ($pattern && ! preg_match($pattern, $item))) {
                throw new InvalidArgumentException("Plugin manifest field {$field} contains an invalid value.");
            }
            $items[] = $item;
        }

        return array_values(array_unique($items));
    }
}
