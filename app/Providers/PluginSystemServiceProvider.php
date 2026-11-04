<?php

namespace App\Providers;

use App\Plugins\PluginHooks;
use App\Plugins\PluginManager;
use Illuminate\Support\ServiceProvider;

class PluginSystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginHooks::class);
        $this->app->singleton(PluginManager::class);
    }

    public function boot(PluginManager $plugins): void
    {
        $plugins->registerEnabledPlugins();
        $plugins->bootEnabledPlugins();
    }
}
