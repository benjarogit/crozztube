<?php

namespace App\Console\Commands;

use App\Support\Clips\OdyseeAccountLogin;
use App\Support\Clips\OdyseeLoginFailure;
use App\Support\Clips\OdyseePublishingConnection;
use App\Support\MediaProviderSettings;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class SetupClipPublisher extends Command
{
    protected $signature = 'crozztube:clips:publisher-setup
        {--check-session : Check the provider client session without account credentials or local changes}';

    protected $description = 'Sign in interactively to Odysee and store only the verified publishing token and channel locally.';

    public function handle(): int
    {
        if ($this->option('check-session')) {
            try {
                (new OdyseeAccountLogin())->checkSession();
            } catch (OdyseeLoginFailure $error) {
                $this->error($error->getMessage());

                return self::FAILURE;
            } catch (Throwable) {
                $this->error('Odysee client session check failed. No credentials were requested or saved.');

                return self::FAILURE;
            }
            $this->info('Odysee accepted the client session. No account sign-in, channel access, publishing or local saving was attempted.');

            return self::SUCCESS;
        }
        if (! $this->input->isInteractive() || ! defined('STDIN') || ! stream_isatty(STDIN)) {
            $this->error('Run this setup yourself in an interactive terminal. Never pass passwords as command arguments.');

            return self::FAILURE;
        }
        $this->info('Odysee clip publisher setup. Your password goes directly to api.odysee.com over HTTPS and is not saved.');
        $this->line('The resulting account token and selected channel are saved only in encrypted, server-side provider settings. No upload or publication is performed.');
        $existing = MediaProviderSettings::current()->effective('odysee');
        if (! empty($existing['auth_token']) && ! $this->confirm('Replace the currently configured publishing login?', false)) {
            return self::SUCCESS;
        }
        $email = trim((string) $this->ask('Odysee account email'));
        // Disable Symfony's visible fallback when terminal echo cannot be hidden.
        $password = $this->secret('Odysee password (hidden)', false);
        if (! is_string($password) || $password === '') {
            $this->error('No password entered; nothing was changed.');

            return self::FAILURE;
        }
        try {
            $token = (new OdyseeAccountLogin())->authenticate($email, $password);
        } catch (OdyseeLoginFailure $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Odysee sign-in failed or needs additional confirmation. Nothing was saved.');

            return self::FAILURE;
        } finally {
            unset($password);
        }
        try {
            $channels = (new OdyseePublishingConnection(['auth_token' => $token]))->ownedChannels();
            if ($channels === []) {
                $this->error('This account has no accessible channels. Create the intended clip channel in Odysee first. Nothing was saved.');

                return self::FAILURE;
            }
            // IDs, not provider-controlled names, are terminal choices.
            $ids = array_column($channels, 'id');
            foreach ($channels as $index => $channel) {
                $this->line('['.$index.'] '.OutputFormatter::escape($channel['name']).' — '.$channel['id']);
            }
            $selected = $this->choice('Select the Odysee channel for CrozzTube clips', $ids);
            if (! in_array($selected, $ids, true)) {
                return self::FAILURE;
            }
            if (! $this->confirm('Save this account token and channel in encrypted provider settings?', false)) {
                $this->info('Setup cancelled. Nothing was saved.');

                return self::SUCCESS;
            }
            MediaProviderSettings::current()->save('odysee', ['auth_token' => $token, 'channel_id' => $selected]);
        } catch (Throwable) {
            $this->error('Channel verification or local saving failed. Check the account and environment file locally; no provider details are printed.');

            return self::FAILURE;
        } finally {
            unset($token);
        }
        $this->info('Verified account token and channel saved in encrypted provider settings with file mode 0600. Password not saved. Clip import still requires its live integration test.');

        return self::SUCCESS;
    }
}
