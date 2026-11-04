<?php

namespace App\Plugins;

abstract class PluginLifecycle
{
    public function enable(PluginContext $context): void
    {
    }

    public function disable(PluginContext $context): void
    {
    }

    public function settingsUpdated(PluginContext $context, array $previousSettings): void
    {
    }

    public function uninstall(PluginContext $context): void
    {
    }
}
