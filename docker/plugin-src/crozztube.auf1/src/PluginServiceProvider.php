<?php

namespace CrzAuf1;

use App\Plugins\PluginServiceProvider as PlayHubPluginServiceProvider;

final class PluginServiceProvider extends PlayHubPluginServiceProvider
{
    protected function pluginId(): string { return 'crozztube.auf1'; }

    public function boot(): void
    {
        $this->plugin()->addFilter('playhub.video_import.providers', static function (array $providers): array {
            $providers['auf1'] = Auf1Provider::definition();

            return $providers;
        });
    }
}
