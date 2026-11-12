<?php

namespace CrzBitChute;

use App\Plugins\PluginServiceProvider as PlayHubPluginServiceProvider;

final class PluginServiceProvider extends PlayHubPluginServiceProvider
{
    protected function pluginId(): string { return 'crozztube.bitchute'; }

    public function boot(): void
    {
        $context = $this->plugin();
        $context->addFilter('playhub.video_import.providers', static function (array $providers) use ($context): array {
            $providers['bitchute'] = BitChuteProvider::definition((bool) $context->setting('enrich_metadata', true));

            return $providers;
        });
    }
}
