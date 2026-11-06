<?php

namespace CrzAuf1;

use App\Plugins\PluginContext;
use App\Plugins\PluginLifecycle;
use App\Support\MediaSourceMetadata;
use RuntimeException;

final class Lifecycle extends PluginLifecycle
{
    public function enable(PluginContext $context): void
    {
        if ((new \ReflectionMethod(MediaSourceMetadata::class, 'embedUrl'))->getNumberOfParameters() < 4
            || ! method_exists(\CrzSourceResolver\Resolver::class, 'supportsSourceCandidates')) {
            throw new RuntimeException('Update the CrozzTube core and Source Resolver before enabling AUF1.');
        }
    }
}
