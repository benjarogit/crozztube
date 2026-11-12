<?php

namespace CrzRumble;

use App\Plugins\PluginContext;
use App\Plugins\PluginLifecycle;
use App\Support\VideoCaptions;
use App\Support\VideoImportSettings;
use RuntimeException;

final class Lifecycle extends PluginLifecycle
{
    public function enable(PluginContext $context): void
    {
        if (! class_exists(VideoCaptions::class) || ! method_exists(VideoImportSettings::class, 'detectProvider')) {
            throw new RuntimeException('Update the CrozzTube core with automatic provider and caption import support before enabling Rumble 1.1.');
        }
    }
}
