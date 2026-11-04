<?php

namespace App\Plugins;

use Illuminate\Support\ServiceProvider;

abstract class PluginServiceProvider extends ServiceProvider
{
    abstract protected function pluginId(): string;

    protected function plugin(): PluginContext
    {
        return $this->app->make(PluginManager::class)->context($this->pluginId());
    }
}
