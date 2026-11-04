<?php

namespace CrzSourceResolver;

use App\Plugins\PluginContext;
use App\Plugins\PluginLifecycle;

final class Lifecycle extends PluginLifecycle
{
    public function enable(PluginContext $context): void
    {
        logger()->info('CrozzTube Source Resolver aktiviert.', [
            'plugin' => $context->id(),
            'version' => $context->version(),
        ]);
    }

    public function disable(PluginContext $context): void
    {
        logger()->info('CrozzTube Source Resolver deaktiviert.');
    }
}
