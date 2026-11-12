<?php

namespace CrzOdysee;

use App\Plugins\PluginServiceProvider as PlayHubPluginServiceProvider;

final class PluginServiceProvider extends PlayHubPluginServiceProvider
{
    protected function pluginId(): string { return 'crozztube.odysee'; }

    public function boot(): void
    {
        $context = $this->plugin();
        $context->addFilter('playhub.video_import.providers', static function (array $providers) use ($context): array {
            $providers['odysee'] = OdyseeProvider::definition((bool) $context->setting('enrich_metadata', true));

            return $providers;
        });
    }
}
