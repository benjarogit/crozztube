<?php

namespace CrzSourceResolver;

use App\Plugins\PluginServiceProvider as PlayHubPluginServiceProvider;
use Illuminate\Support\Facades\Route;

final class PluginServiceProvider extends PlayHubPluginServiceProvider
{
    protected function pluginId(): string
    {
        return 'crozztube.source-resolver';
    }

    public function register(): void
    {
        if (! Resolver::available()) {
            return;
        }

        $this->app->singleton(Resolver::class, function ($app): Resolver {
            $manager = $app->make(\App\Plugins\PluginManager::class);
            $context = $manager->context('crozztube.source-resolver');

            return new Resolver($context);
        });
    }

    public function boot(): void
    {
        if (! Resolver::available()) {
            return;
        }

        $context = $this->plugin();

        Route::prefix('api/source-resolver')
            ->group($context->path('routes/api.php'));
    }
}
