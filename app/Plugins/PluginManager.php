<?php

namespace App\Plugins;

use App\Models\Plugin;
use App\Models\User;
use App\Updates\VersionRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class PluginManager
{
    private array $autoload = [];

    private bool $autoloadRegistered = false;

    private array $registeredProviders = [];

    private array $bootErrors = [];

    public function __construct(
        private readonly Application $app,
        private readonly PluginPackageInstaller $packages,
        private readonly PluginHooks $hooks,
        private readonly VersionRepository $versions,
    ) {}

    public function registerEnabledPlugins(): void
    {
        if (! $this->tableReady() || ! (bool) config('playhub.plugins_enabled', true)) {
            return;
        }

        $enabledPlugins = collect();
        try {
            $enabledPlugins = Plugin::query()->where('enabled', true)->get();
            $plugins = $this->dependencyOrder($enabledPlugins);
        } catch (\Throwable $exception) {
            if ($enabledPlugins->isEmpty()) {
                report($exception);
            } else {
                foreach ($enabledPlugins as $plugin) {
                    $this->recordBootError($plugin, $exception);
                }
            }

            return;
        }

        foreach ($plugins as $plugin) {
            try {
                $manifest = $this->manifest($plugin);
                $this->assertCompatible($manifest, true);
                foreach (array_keys($manifest->dependencies()) as $dependencyId) {
                    if (isset($this->bootErrors[$dependencyId])) {
                        throw new RuntimeException("Required plugin failed during bootstrap: {$dependencyId}.");
                    }
                }
                $this->registerAutoload($manifest, $this->path($plugin->getKey()));
                $providers = [];
                foreach ($manifest->providers() as $providerClass) {
                    if (! class_exists($providerClass) || ! is_subclass_of($providerClass, ServiceProvider::class)) {
                        throw new RuntimeException("Plugin provider {$providerClass} must extend Laravel ServiceProvider.");
                    }
                    $provider = new $providerClass($this->app);
                    $provider->register();
                    $providers[] = ['plugin' => $plugin->getKey(), 'provider' => $provider];
                }
                array_push($this->registeredProviders, ...$providers);
                if ($plugin->last_error) {
                    Plugin::query()->whereKey($plugin->getKey())->update(['last_error' => null]);
                }
            } catch (\Throwable $exception) {
                $this->recordBootError($plugin, $exception);
            }
        }
    }

    public function bootEnabledPlugins(): void
    {
        $booted = [];
        foreach ($this->registeredProviders as $registered) {
            $pluginId = $registered['plugin'];
            if (isset($this->bootErrors[$pluginId])) {
                continue;
            }
            $provider = $registered['provider'];
            try {
                $provider->callBootingCallbacks();
                if (method_exists($provider, 'boot')) {
                    $this->app->call([$provider, 'boot']);
                }
                $provider->callBootedCallbacks();
                $booted[$pluginId] = true;
            } catch (\Throwable $exception) {
                unset($booted[$pluginId]);
                $plugin = Plugin::query()->find($pluginId);
                if ($plugin) {
                    $this->recordBootError($plugin, $exception);
                }
            }
        }

        $this->hooks->action('playhub.plugins.booted', array_keys($booted));
    }

    public function install(UploadedFile $package, ?int $adminId = null): array
    {
        $this->assertTableReady();
        $staged = $this->packages->stage($package->getRealPath());
        /** @var PluginManifest $manifest */
        $manifest = $staged['manifest'];

        try {
            return $this->locked(function () use ($staged, $manifest, $adminId): array {
                $existing = Plugin::query()->find($manifest->id());
                if ($existing?->enabled) {
                    throw new RuntimeException('Disable this plugin before uploading an updated package.');
                }
                $this->assertCompatible($manifest, false);
                $promotion = $this->packages->promote($staged);

                try {
                    $plugin = DB::transaction(function () use ($existing, $manifest, $staged, $adminId): Plugin {
                        $settings = $this->settingsForManifest($manifest, is_array($existing?->settings) ? $existing->settings : []);
                        $values = [
                            'name' => $manifest->name(),
                            'version' => $manifest->version(),
                            'description' => $manifest->toArray()['description'] ?: null,
                            'author' => $manifest->authorName(),
                            'manifest' => $manifest->toArray(),
                            'settings' => $settings,
                            'checksum' => $staged['checksum'],
                            'enabled' => false,
                            'enabled_at' => null,
                            'last_error' => null,
                            'updated_by' => $adminId,
                        ];
                        if ($existing) {
                            $existing->update($values);

                            return $existing->fresh();
                        }

                        return Plugin::query()->create(array_merge($values, [
                            'id' => $manifest->id(),
                            'installed_by' => $adminId,
                            'installed_at' => now(),
                        ]));
                    });
                } catch (\Throwable $exception) {
                    $this->packages->rollbackPromotion($promotion);
                    throw $exception;
                }

                $this->packages->finishPromotion($promotion);
                logger()->info($existing ? 'PlayHub plugin updated.' : 'PlayHub plugin installed.', ['plugin' => $plugin->getKey(), 'version' => $plugin->version, 'admin_id' => $adminId]);

                return [
                    'message' => $existing ? "{$plugin->name} updated to version {$plugin->version}. Enable it when ready." : "{$plugin->name} installed in disabled state.",
                    'plugin' => $this->payload($plugin),
                    'updated' => (bool) $existing,
                ];
            });
        } catch (\Throwable $exception) {
            $this->packages->discard($staged);
            throw $exception;
        }
    }

    public function enable(string $pluginId, ?int $adminId = null): array
    {
        return $this->locked(function () use ($pluginId, $adminId): array {
            $plugin = Plugin::query()->findOrFail($pluginId);
            if ($plugin->enabled && ! $plugin->last_error) {
                return ['message' => "{$plugin->name} is already enabled.", 'plugin' => $this->payload($plugin)];
            }

            try {
                $manifest = $this->manifest($plugin);
                $this->assertCompatible($manifest, true);
                $this->validatedSettings($manifest, [], is_array($plugin->settings) ? $plugin->settings : []);
                $this->registerAutoload($manifest, $this->path($pluginId));
                $this->validateRuntimeClasses($manifest);
                $this->runMigrations($manifest, $this->path($pluginId));
                $this->invokeLifecycle($plugin, $manifest, 'enable');
                $plugin->update(['enabled' => true, 'enabled_at' => now(), 'last_error' => null, 'updated_by' => $adminId]);
                logger()->info('PlayHub plugin enabled.', ['plugin' => $pluginId, 'admin_id' => $adminId]);
            } catch (\Throwable $exception) {
                $this->hooks->removePlugin($pluginId);
                $plugin->update(['enabled' => false, 'last_error' => $this->errorMessage($exception), 'updated_by' => $adminId]);
                throw $exception;
            }

            return ['message' => "{$plugin->name} enabled. It will load on the next request.", 'plugin' => $this->payload($plugin->fresh())];
        });
    }

    public function disable(string $pluginId, ?int $adminId = null): array
    {
        return $this->locked(function () use ($pluginId, $adminId): array {
            $plugin = Plugin::query()->findOrFail($pluginId);
            $this->assertNoEnabledDependents($pluginId);
            $warning = $this->disableRecord($plugin, $adminId);

            return [
                'message' => "{$plugin->name} disabled. The change takes effect on the next request.".($warning ? ' Its shutdown hook reported an error.' : ''),
                'plugin' => $this->payload($plugin->fresh()),
            ];
        });
    }

    public function remove(string $pluginId, ?int $adminId = null): array
    {
        return $this->locked(function () use ($pluginId, $adminId): array {
            $plugin = Plugin::query()->findOrFail($pluginId);
            $this->assertNoEnabledDependents($pluginId);
            if ($plugin->enabled) {
                $this->disableRecord($plugin, $adminId);
            }

            if ($plugin->enabled_at) {
                try {
                    $manifest = $this->manifest($plugin);
                    $this->invokeLifecycle($plugin->fresh(), $manifest, 'uninstall');
                } catch (\Throwable $exception) {
                    report($exception);
                    logger()->warning('Plugin uninstall hook failed; removal continued.', ['plugin' => $pluginId, 'error' => $exception->getMessage()]);
                }
            }

            $removal = $this->packages->moveToTrash($pluginId);
            try {
                DB::transaction(fn () => Plugin::query()->whereKey($pluginId)->delete());
            } catch (\Throwable $exception) {
                $this->packages->restoreTrash($removal);
                throw $exception;
            }
            $this->packages->finishRemoval($removal);
            $this->hooks->removePlugin($pluginId);
            logger()->info('PlayHub plugin removed.', ['plugin' => $pluginId, 'admin_id' => $adminId]);

            return ['message' => "{$plugin->name} and its package files were removed."];
        });
    }

    public function updateSettings(string $pluginId, array $incoming, ?int $adminId = null): array
    {
        return $this->locked(function () use ($pluginId, $incoming, $adminId): array {
            $plugin = Plugin::query()->findOrFail($pluginId);
            $manifest = $this->manifest($plugin);
            $previous = is_array($plugin->settings) ? $plugin->settings : [];
            $settings = $this->validatedSettings($manifest, $incoming, $previous);
            $plugin->update(['settings' => $settings, 'updated_by' => $adminId, 'last_error' => null]);

            if ($plugin->enabled) {
                try {
                    $this->invokeLifecycle($plugin->fresh(), $manifest, 'settingsUpdated', [$previous]);
                } catch (\Throwable $exception) {
                    $plugin->update(['settings' => $previous, 'last_error' => $this->errorMessage($exception)]);
                    throw $exception;
                }
            }
            logger()->info('PlayHub plugin settings updated.', ['plugin' => $pluginId, 'admin_id' => $adminId]);

            return ['message' => "{$plugin->name} settings saved.", 'plugin' => $this->payload($plugin->fresh())];
        });
    }

    public function indexPayload(): array
    {
        $this->assertTableReady();
        $plugins = Plugin::query()->orderBy('name')->get()->map(fn (Plugin $plugin): array => $this->payload($plugin))->all();

        return [
            'plugins' => $plugins,
            'summary' => [
                'installed' => count($plugins),
                'enabled' => collect($plugins)->where('enabled', true)->count(),
                'disabled' => collect($plugins)->where('enabled', false)->count(),
                'issues' => collect($plugins)->whereIn('status', ['error', 'incompatible', 'missing'])->count(),
            ],
            'environment' => [
                'system_enabled' => (bool) config('playhub.plugins_enabled', true),
                'core_version' => $this->coreVersion(),
                'plugin_api_version' => PluginManifest::API_VERSION,
                'php_version' => PHP_VERSION,
                'directory' => $this->packages->pluginsPath(),
                'directory_writable' => $this->pluginsDirectoryWritable(),
                'max_upload_mb' => (int) config('playhub.plugin_max_archive_mb', 25),
                'zip_available' => class_exists(\ZipArchive::class),
            ],
        ];
    }

    public function payload(Plugin $plugin): array
    {
        $path = $this->path($plugin->getKey());
        $manifest = null;
        $errors = [];
        try {
            $manifest = $this->manifest($plugin);
            $errors = $this->compatibilityErrors($manifest, false);
        } catch (\Throwable $exception) {
            $errors[] = $this->errorMessage($exception);
            try {
                $manifest = PluginManifest::fromArray(is_array($plugin->manifest) ? $plugin->manifest : []);
            } catch (\Throwable) {
                $manifest = null;
            }
        }
        if ($plugin->last_error && ! in_array($plugin->last_error, $errors, true)) {
            $errors[] = $plugin->last_error;
        }

        $manifestData = $manifest?->toArray() ?? (is_array($plugin->manifest) ? $plugin->manifest : []);
        $status = ! is_dir($path) ? 'missing' : ($errors !== [] ? ($plugin->enabled ? 'error' : 'incompatible') : ($plugin->enabled ? 'enabled' : 'disabled'));
        $capabilities = array_values(array_unique(array_filter(array_merge(
            $manifestData['capabilities'] ?? [],
            ($manifestData['providers'] ?? []) !== [] ? ['Backend provider'] : [],
            ($manifestData['assets']['scripts'] ?? []) !== [] || ($manifestData['assets']['styles'] ?? []) !== [] ? ['Frontend assets'] : [],
            ($manifestData['settings'] ?? []) !== [] ? ['Configurable'] : [],
            ! empty($manifestData['migrations']) ? ['Database migrations'] : [],
            ! empty($manifestData['lifecycle']) ? ['Lifecycle hooks'] : [],
        ))));

        return [
            'id' => $plugin->getKey(),
            'name' => $plugin->name,
            'version' => $plugin->version,
            'description' => $plugin->description,
            'author' => $plugin->author,
            'homepage' => $manifestData['homepage'] ?? '',
            'enabled' => (bool) $plugin->enabled,
            'status' => $status,
            'errors' => $errors,
            'capabilities' => $capabilities,
            'dependencies' => $manifestData['dependencies'] ?? [],
            'requirements' => $manifestData['requires'] ?? [],
            'providers' => $manifestData['providers'] ?? [],
            'settings' => $this->settingsPayload($manifest, is_array($plugin->settings) ? $plugin->settings : []),
            'has_settings' => ($manifestData['settings'] ?? []) !== [],
            'size_bytes' => $this->directorySize($path),
            'checksum' => $plugin->checksum,
            'installed_at' => $plugin->installed_at?->toIso8601String(),
            'updated_at' => $plugin->updated_at?->toIso8601String(),
            'enabled_at' => $plugin->enabled_at?->toIso8601String(),
        ];
    }

    public function context(string $pluginId): PluginContext
    {
        $plugin = Plugin::query()->findOrFail($pluginId);

        return new PluginContext($plugin, $this->manifest($plugin), $this->path($pluginId), $this->hooks);
    }

    public function publicPlugins(): array
    {
        if (! (bool) config('playhub.plugins_enabled', true) || ! $this->tableReady()) {
            return [];
        }

        return Plugin::query()->where('enabled', true)->orderBy('id')->get()->map(function (Plugin $plugin): ?array {
            if (isset($this->bootErrors[$plugin->getKey()])) {
                return null;
            }
            try {
                $manifest = $this->manifest($plugin);
                $assets = $manifest->assets();
                $settings = is_array($plugin->settings) ? $plugin->settings : [];
                $publicSettings = [];
                foreach ($manifest->settings() as $field) {
                    if ($field['public']) {
                        $publicSettings[$field['key']] = $settings[$field['key']] ?? $field['default'];
                    }
                }

                return [
                    'id' => $plugin->getKey(),
                    'name' => $plugin->name,
                    'version' => $plugin->version,
                    'settings' => $publicSettings,
                    'styles' => array_map(fn (string $path): string => $this->assetUrl($plugin, $path), $assets['styles']),
                    'scripts' => array_map(fn (string $path): string => $this->assetUrl($plugin, $path), $assets['scripts']),
                ];
            } catch (\Throwable $exception) {
                report($exception);

                return null;
            }
        })->filter()->values()->all();
    }

    /** Mobile receives only availability; plugin settings, assets and license data stay private. */
    public function mobileCapabilities(?User $user = null): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $capabilities = [];
        foreach (Plugin::query()->orderBy('id')->get() as $plugin) {
            $installed = false;
            $supported = false;
            $permitted = false;
            try {
                $manifest = $this->manifest($plugin);
                $installed = true;
                $mobile = $manifest->mobile();
                $supported = $mobile['supported'];
                $permitted = $user !== null && collect($mobile['permissions'])
                    ->every(fn (string $permission): bool => Gate::forUser($user)->allows($permission));
            } catch (\Throwable) {
                // An incomplete or invalid installation cannot advertise a working feature.
            }
            $enabled = $installed && (bool) config('playhub.plugins_enabled', true)
                && $plugin->enabled && ! $plugin->last_error && ! isset($this->bootErrors[$plugin->getKey()]);
            $capabilities[$plugin->getKey()] = [
                'installed' => $installed,
                'enabled' => $enabled,
                'mobile_supported' => $supported,
                'permitted' => $enabled && $supported && $permitted,
            ];
        }

        return $capabilities;
    }

    public function assetPath(string $pluginId, string $asset): string
    {
        if (! (bool) config('playhub.plugins_enabled', true)) {
            abort(404);
        }
        $plugin = Plugin::query()->whereKey($pluginId)->where('enabled', true)->firstOrFail();
        if (! PluginManifest::safeRelativePath($asset)) {
            abort(404);
        }
        $manifest = $this->manifest($plugin);
        $allowed = array_merge($manifest->assets()['styles'], $manifest->assets()['scripts'], $manifest->assets()['files']);
        if (! in_array($asset, $allowed, true)) {
            abort(404);
        }

        $root = realpath($this->path($pluginId));
        $file = realpath($this->path($pluginId).'/'.$asset);
        if (! $root || ! $file || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        return $file;
    }

    public function coreVersion(): string
    {
        return $this->versions->current();
    }

    private function manifest(Plugin $plugin): PluginManifest
    {
        $manifest = PluginManifest::fromFile($this->path($plugin->getKey()).'/plugin.json');
        if (! hash_equals($plugin->getKey(), $manifest->id())) {
            throw new RuntimeException('Plugin directory and manifest ids do not match.');
        }
        if (! hash_equals((string) $plugin->version, $manifest->version())) {
            throw new RuntimeException('Plugin files do not match the installed database version. Reinstall the package.');
        }

        return $manifest;
    }

    private function path(string $pluginId): string
    {
        return $this->packages->pluginPath($pluginId);
    }

    private function registerAutoload(PluginManifest $manifest, string $basePath): void
    {
        foreach ($manifest->autoload() as $namespace => $relativePath) {
            $this->autoload[$namespace] = $basePath.'/'.rtrim($relativePath, '/');
        }
        uksort($this->autoload, fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        if ($this->autoloadRegistered) {
            return;
        }

        spl_autoload_register(function (string $class): void {
            foreach ($this->autoload as $namespace => $directory) {
                if (! str_starts_with($class, $namespace)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
                $file = realpath($directory.'/'.$relative);
                $root = realpath($directory);
                if ($file && $root && is_file($file) && ($file === $root || str_starts_with($file, $root.DIRECTORY_SEPARATOR))) {
                    require_once $file;
                }

                return;
            }
        }, true, true);
        $this->autoloadRegistered = true;
    }

    private function validateRuntimeClasses(PluginManifest $manifest): void
    {
        foreach ($manifest->providers() as $provider) {
            if (! class_exists($provider) || ! is_subclass_of($provider, ServiceProvider::class)) {
                throw new RuntimeException("Plugin provider {$provider} must extend Laravel ServiceProvider.");
            }
        }
        if ($manifest->lifecycle() && (! class_exists($manifest->lifecycle()) || ! is_subclass_of($manifest->lifecycle(), PluginLifecycle::class))) {
            throw new RuntimeException('The declared plugin lifecycle class must extend PlayHub PluginLifecycle.');
        }
    }

    private function invokeLifecycle(Plugin $plugin, PluginManifest $manifest, string $method, array $arguments = []): void
    {
        if (! $manifest->lifecycle()) {
            return;
        }
        $this->registerAutoload($manifest, $this->path($plugin->getKey()));
        $class = $manifest->lifecycle();
        if (! class_exists($class) || ! is_subclass_of($class, PluginLifecycle::class)) {
            throw new RuntimeException('The declared plugin lifecycle class is invalid.');
        }
        $lifecycle = $this->app->make($class);
        $context = new PluginContext($plugin, $manifest, $this->path($plugin->getKey()), $this->hooks);
        $lifecycle->{$method}($context, ...$arguments);
    }

    private function runMigrations(PluginManifest $manifest, string $path): void
    {
        if (! $manifest->migrations()) {
            return;
        }
        $migrationPath = $path.'/'.$manifest->migrations();
        $exit = Artisan::call('migrate', ['--path' => $migrationPath, '--realpath' => true, '--force' => true, '--no-interaction' => true]);
        if ($exit !== 0) {
            throw new RuntimeException('Plugin database migrations failed: '.trim(Artisan::output()));
        }
    }

    private function compatibilityErrors(PluginManifest $manifest, bool $requireEnabledDependencies): array
    {
        $errors = [];
        $requires = $manifest->requires();
        if (! $this->satisfies($this->coreVersion(), $requires['playhub'])) {
            $errors[] = "Requires PlayHub {$requires['playhub']}; installed version is {$this->coreVersion()}.";
        }
        if (! $this->satisfies(PHP_VERSION, $requires['php'])) {
            $errors[] = "Requires PHP {$requires['php']}; installed version is ".PHP_VERSION.'.';
        }
        foreach ($requires['extensions'] as $extension) {
            if (! extension_loaded($extension)) {
                $errors[] = "Required PHP extension is missing: {$extension}.";
            }
        }
        if ($this->tableReady()) {
            foreach ($manifest->dependencies() as $pluginId => $constraint) {
                $dependency = Plugin::query()->find($pluginId);
                if (! $dependency) {
                    $errors[] = "Required plugin is not installed: {$pluginId} {$constraint}.";
                } elseif (! $this->satisfies($dependency->version, $constraint)) {
                    $errors[] = "Plugin {$pluginId} must match {$constraint}; installed version is {$dependency->version}.";
                } elseif ($requireEnabledDependencies && ! $dependency->enabled) {
                    $errors[] = "Required plugin is disabled: {$pluginId}.";
                }
            }
        }

        return $errors;
    }

    private function assertCompatible(PluginManifest $manifest, bool $requireEnabledDependencies): void
    {
        $errors = $this->compatibilityErrors($manifest, $requireEnabledDependencies);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
    }

    private function satisfies(string $version, string $constraint): bool
    {
        $version = $this->normalizedVersion($version);
        $parts = preg_split('/(?:\s*,\s*|\s+)/', trim($constraint), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            preg_match('/^(\^|~|>=|<=|>|<|=)?(.+)$/', $part, $matches);
            $operator = $matches[1] ?: '=';
            $required = $this->normalizedVersion($matches[2]);
            if ($operator === '^') {
                $segments = array_map('intval', explode('.', preg_replace('/-.*/', '', $required)));
                $upper = $segments[0] > 0
                    ? ($segments[0] + 1).'.0.0'
                    : ($segments[1] > 0 ? '0.'.($segments[1] + 1).'.0' : '0.0.'.($segments[2] + 1));
                if (! version_compare($version, $required, '>=') || ! version_compare($version, $upper, '<')) {
                    return false;
                }
            } elseif ($operator === '~') {
                $segments = array_map('intval', explode('.', preg_replace('/-.*/', '', $required)));
                $upper = $segments[0].'.'.($segments[1] + 1).'.0';
                if (! version_compare($version, $required, '>=') || ! version_compare($version, $upper, '<')) {
                    return false;
                }
            } elseif (! version_compare($version, $required, $operator === '=' ? '==' : $operator)) {
                return false;
            }
        }

        return true;
    }

    private function normalizedVersion(string $version): string
    {
        $version = ltrim(trim($version), 'vV');
        $base = preg_replace('/[-+].*/', '', $version);
        $suffix = substr($version, strlen($base));
        $segments = explode('.', $base);
        while (count($segments) < 3) {
            $segments[] = '0';
        }

        return implode('.', array_slice($segments, 0, 3)).$suffix;
    }

    private function validatedSettings(PluginManifest $manifest, array $incoming, array $current): array
    {
        $definitions = collect($manifest->settings())->keyBy('key');
        $unknown = array_diff(array_keys($incoming), $definitions->keys()->all());
        if ($unknown !== []) {
            throw new RuntimeException('Unknown plugin setting: '.implode(', ', $unknown).'.');
        }

        $settings = $this->settingsForManifest($manifest, $current);
        foreach ($definitions as $key => $field) {
            if (! array_key_exists($key, $incoming)) {
                continue;
            }
            $value = $incoming[$key];
            if ($field['type'] === 'password' && ($value === null || $value === '')) {
                continue;
            }
            if ($field['type'] === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    throw new RuntimeException("{$field['label']} must be true or false.");
                }
            } elseif ($field['type'] === 'number') {
                if (! is_numeric($value)) {
                    throw new RuntimeException("{$field['label']} must be a number.");
                }
                $value = (float) $value;
                if ($field['min'] !== null && $value < $field['min']) {
                    throw new RuntimeException("{$field['label']} must be at least {$field['min']}.");
                }
                if ($field['max'] !== null && $value > $field['max']) {
                    throw new RuntimeException("{$field['label']} cannot exceed {$field['max']}.");
                }
            } elseif ($field['type'] === 'select') {
                $value = is_scalar($value) ? (string) $value : '';
                if (! collect($field['options'])->pluck('value')->containsStrict($value)) {
                    throw new RuntimeException("{$field['label']} has an invalid option.");
                }
            } else {
                if (! is_string($value)) {
                    throw new RuntimeException("{$field['label']} must be text.");
                }
                $value = trim($value);
                if (mb_strlen($value) > $field['max_length']) {
                    throw new RuntimeException("{$field['label']} exceeds {$field['max_length']} characters.");
                }
            }
            if ($field['required'] && ($value === '' || $value === null)) {
                throw new RuntimeException("{$field['label']} is required.");
            }
            $settings[$key] = $value;
        }
        foreach ($definitions as $key => $field) {
            if ($field['required'] && (($settings[$key] ?? null) === '' || ($settings[$key] ?? null) === null)) {
                throw new RuntimeException("{$field['label']} is required.");
            }
        }

        return $settings;
    }

    private function settingsForManifest(PluginManifest $manifest, array $settings): array
    {
        $normalized = [];
        foreach ($manifest->settings() as $field) {
            $normalized[$field['key']] = array_key_exists($field['key'], $settings) ? $settings[$field['key']] : $field['default'];
        }

        return $normalized;
    }

    private function settingsPayload(?PluginManifest $manifest, array $settings): array
    {
        if (! $manifest) {
            return [];
        }

        return array_map(function (array $field) use ($settings): array {
            $value = $settings[$field['key']] ?? $field['default'];
            $configured = ! ($value === null || $value === '');
            if ($field['type'] === 'password') {
                $value = '';
            }

            return array_merge($field, ['value' => $value, 'configured' => $configured]);
        }, $manifest->settings());
    }

    private function disableRecord(Plugin $plugin, ?int $adminId): ?string
    {
        if (! $plugin->enabled) {
            $plugin->update(['updated_by' => $adminId]);

            return null;
        }
        $plugin->update(['enabled' => false, 'updated_by' => $adminId]);
        $warning = null;
        try {
            $manifest = $this->manifest($plugin->fresh());
            $this->invokeLifecycle($plugin->fresh(), $manifest, 'disable');
            $plugin->update(['last_error' => null]);
        } catch (\Throwable $exception) {
            $warning = $this->errorMessage($exception);
            $plugin->update(['last_error' => $warning]);
            report($exception);
        }
        $this->hooks->removePlugin($plugin->getKey());
        logger()->info('PlayHub plugin disabled.', ['plugin' => $plugin->getKey(), 'admin_id' => $adminId, 'warning' => $warning]);

        return $warning;
    }

    private function assertNoEnabledDependents(string $pluginId): void
    {
        $dependents = Plugin::query()->where('enabled', true)->get()->filter(function (Plugin $plugin) use ($pluginId): bool {
            try {
                return array_key_exists($pluginId, PluginManifest::fromArray($plugin->manifest)->dependencies());
            } catch (\Throwable) {
                return false;
            }
        })->pluck('name')->all();
        if ($dependents !== []) {
            throw new RuntimeException('Disable dependent plugins first: '.implode(', ', $dependents).'.');
        }
    }

    private function dependencyOrder($plugins): array
    {
        $records = $plugins->keyBy(fn (Plugin $plugin): string => $plugin->getKey())->all();
        $ordered = [];
        $visiting = [];
        $visited = [];
        $visit = function (Plugin $plugin) use (&$visit, &$ordered, &$visiting, &$visited, $records): void {
            $id = $plugin->getKey();
            if (isset($visited[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                throw new RuntimeException("Circular plugin dependency detected at {$id}.");
            }
            $visiting[$id] = true;
            $manifest = PluginManifest::fromArray($plugin->manifest);
            foreach (array_keys($manifest->dependencies()) as $dependencyId) {
                if (isset($records[$dependencyId])) {
                    $visit($records[$dependencyId]);
                }
            }
            unset($visiting[$id]);
            $visited[$id] = true;
            $ordered[] = $plugin;
        };
        foreach ($records as $plugin) {
            $visit($plugin);
        }

        return $ordered;
    }

    private function locked(callable $callback): mixed
    {
        $lock = Cache::lock('playhub-plugin-operation', 180);
        if (! $lock->get()) {
            throw new RuntimeException('Another plugin operation is already running.');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function recordBootError(Plugin $plugin, \Throwable $exception): void
    {
        $message = $this->errorMessage($exception);
        $this->bootErrors[$plugin->getKey()] = $message;
        $this->hooks->removePlugin($plugin->getKey());
        try {
            Plugin::query()->whereKey($plugin->getKey())->update(['last_error' => $message]);
        } catch (\Throwable) {
        }
        report($exception);
        logger()->error('PlayHub plugin failed during bootstrap.', ['plugin' => $plugin->getKey(), 'error' => $message]);
    }

    private function errorMessage(\Throwable $exception): string
    {
        return mb_substr(trim($exception->getMessage()) ?: $exception::class, 0, 2000);
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('playhub_plugins');
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertTableReady(): void
    {
        if (! $this->tableReady()) {
            throw new RuntimeException('Plugin storage is not installed. Run the PlayHub database migrations.');
        }
    }

    private function pluginsDirectoryWritable(): bool
    {
        $path = $this->packages->pluginsPath();

        return is_dir($path) ? is_writable($path) : is_writable(dirname($path));
    }

    private function directorySize(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }
        $bytes = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    $bytes += (int) $file->getSize();
                }
            }
        } catch (\Throwable) {
        }

        return $bytes;
    }

    private function assetUrl(Plugin $plugin, string $path): string
    {
        return '/plugin-assets/'.rawurlencode($plugin->getKey()).'?asset='.rawurlencode($path).'&v='.rawurlencode($plugin->version);
    }
}
