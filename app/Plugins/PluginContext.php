<?php

namespace App\Plugins;

use App\Models\Plugin;
use RuntimeException;

final class PluginContext
{
    public function __construct(
        private readonly Plugin $plugin,
        private readonly PluginManifest $manifest,
        private readonly string $basePath,
        private readonly PluginHooks $hooks,
    ) {
    }

    public function id(): string
    {
        return $this->plugin->getKey();
    }

    public function version(): string
    {
        return $this->manifest->version();
    }

    public function manifest(): array
    {
        return $this->manifest->toArray();
    }

    public function settings(): array
    {
        return is_array($this->plugin->settings) ? $this->plugin->settings : [];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings()[$key] ?? $default;
    }

    public function path(string $relativePath = ''): string
    {
        if ($relativePath === '') {
            return $this->basePath;
        }
        $relativePath = trim($relativePath, '/');
        if (! PluginManifest::safeRelativePath($relativePath)) {
            throw new RuntimeException('Plugin paths must remain inside the plugin directory.');
        }

        return $this->basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->hooks->addAction($hook, $callback, $priority, $this->id());
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->hooks->addFilter($hook, $callback, $priority, $this->id());
    }

    public function action(string $hook, mixed ...$arguments): void
    {
        $this->hooks->action($hook, ...$arguments);
    }

    public function filter(string $hook, mixed $value, mixed ...$arguments): mixed
    {
        return $this->hooks->filter($hook, $value, ...$arguments);
    }
}
