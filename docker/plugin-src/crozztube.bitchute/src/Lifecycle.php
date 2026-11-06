<?php

namespace CrzBitChute;

use App\Plugins\PluginContext;
use App\Plugins\PluginLifecycle;
use App\Support\VideoChapters;
use App\Support\VideoImportIdentity;
use RuntimeException;

final class Lifecycle extends PluginLifecycle
{
    public function enable(PluginContext $context): void
    {
        if (! class_exists(VideoImportIdentity::class) || ! method_exists(VideoChapters::class, 'discover')) {
            throw new RuntimeException('Update the CrozzTube core with chapter discovery and duplicate import protection before enabling BitChute.');
        }
    }
}
