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

        if ($this->app->runningInConsole()) {
            $this->commands([
                \CrzSourceResolver\Commands\RefreshExpiringSources::class,
                \CrzSourceResolver\Commands\ProbeSources::class,
            ]);
        }

        \App\Models\Video::deleted(static function ($video): void {
            \CrzSourceResolver\Models\CrozztubeImportSource::where('video_id', $video->id)->delete();
        });

        $context = $this->plugin();

        Route::prefix('api/source-resolver')
            ->group($context->path('routes/api.php'));

        $this->app->booted(function (): void {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            // Keep direct URLs alive shortly before they expire. Small batches
            // and staggered dispatches keep yt-dlp off the main thread.
            $schedule->command('crozztube:sources:refresh-expiring --window=15 --limit=10 --stagger=15 --scheduled')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer();

            // Night window only: validate stored URLs, renew what broke.
            $schedule->command('crozztube:sources:probe --limit=20 --stagger=10 --scheduled')
                ->hourly()
                ->between('02:00', '05:00')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
