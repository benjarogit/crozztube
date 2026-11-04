<?php

namespace App\Plugins;

use Closure;
use InvalidArgumentException;

final class PluginHooks
{
    private array $actions = [];

    private array $filters = [];

    private int $sequence = 0;

    public function addAction(string $hook, callable $callback, int $priority = 10, string $pluginId = 'application'): void
    {
        $this->actions[$this->hookName($hook)][] = $this->listener($callback, $priority, $pluginId);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, string $pluginId = 'application'): void
    {
        $this->filters[$this->hookName($hook)][] = $this->listener($callback, $priority, $pluginId);
    }

    public function action(string $hook, mixed ...$arguments): void
    {
        foreach ($this->ordered($this->actions[$this->hookName($hook)] ?? []) as $listener) {
            try {
                ($listener['callback'])(...$arguments);
            } catch (\Throwable $exception) {
                report($exception);
                logger()->error('PlayHub plugin action failed.', ['hook' => $hook, 'plugin' => $listener['plugin'], 'error' => $exception->getMessage()]);
            }
        }
    }

    public function filter(string $hook, mixed $value, mixed ...$arguments): mixed
    {
        foreach ($this->ordered($this->filters[$this->hookName($hook)] ?? []) as $listener) {
            try {
                $value = ($listener['callback'])($value, ...$arguments);
            } catch (\Throwable $exception) {
                report($exception);
                logger()->error('PlayHub plugin filter failed.', ['hook' => $hook, 'plugin' => $listener['plugin'], 'error' => $exception->getMessage()]);
            }
        }

        return $value;
    }

    public function removePlugin(string $pluginId): void
    {
        foreach (['actions', 'filters'] as $type) {
            foreach ($this->{$type} as $hook => $listeners) {
                $this->{$type}[$hook] = array_values(array_filter($listeners, fn (array $listener): bool => $listener['plugin'] !== $pluginId));
            }
        }
    }

    public function diagnostics(): array
    {
        return [
            'actions' => collect($this->actions)->map->count()->all(),
            'filters' => collect($this->filters)->map->count()->all(),
        ];
    }

    private function listener(callable $callback, int $priority, string $pluginId): array
    {
        return [
            'callback' => $callback instanceof Closure ? $callback : Closure::fromCallable($callback),
            'priority' => max(-1000, min(1000, $priority)),
            'plugin' => $pluginId,
            'sequence' => $this->sequence++,
        ];
    }

    private function ordered(array $listeners): array
    {
        usort($listeners, fn (array $left, array $right): int => [$left['priority'], $left['sequence']] <=> [$right['priority'], $right['sequence']]);

        return $listeners;
    }

    private function hookName(string $hook): string
    {
        $hook = trim($hook);
        if (! preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $hook)) {
            throw new InvalidArgumentException('Plugin hook names must use lowercase letters, numbers, dots, colons, dashes, or underscores.');
        }

        return $hook;
    }
}
