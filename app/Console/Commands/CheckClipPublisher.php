<?php

namespace App\Console\Commands;

use App\Support\Clips\OdyseePublishingConnection;
use App\Support\MediaProviderSettings;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class CheckClipPublisher extends Command
{
    protected $signature = 'crozztube:clips:publisher-check
        {--connect : Read the authenticated account channel list; never publish}
        {--list-channels : Show owned public channel IDs to help configure the target}';

    protected $description = 'Check server-side Odysee publishing credentials without exposing the token.';

    public function handle(): int
    {
        $connection = new OdyseePublishingConnection(MediaProviderSettings::current()->effective('odysee'));
        $this->line(json_encode($connection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (! $this->option('connect')) {
            $this->info('No network request was made. This does not enable or verify clip publishing.');

            return $connection->problems() ? self::FAILURE : self::SUCCESS;
        }
        if ($connection->problems(! $this->option('list-channels'))) {
            return self::FAILURE;
        }
        try {
            $channels = $connection->ownedChannels();
        } catch (Throwable) {
            $this->error('Odysee account check failed. Check the token and provider availability locally.');

            return self::FAILURE;
        }
        if ($this->option('list-channels')) {
            // Public IDs only; never print tokens, response bodies or SDK errors.
            foreach ($channels as $channel) {
                $this->line($channel['id'].'  '.OutputFormatter::escape($channel['name']));
            }
            $this->info('Only account channel discovery was checked; no upload or publication was attempted.');

            return self::SUCCESS;
        }
        if (! array_filter($channels, static fn (array $channel): bool => $channel['selected'])) {
            $this->error('The configured channel was not found in this account.');

            return self::FAILURE;
        }
        $this->info('Odysee account access and target channel ownership confirmed. Import, visibility and playback still need their live tests.');

        return self::SUCCESS;
    }
}
