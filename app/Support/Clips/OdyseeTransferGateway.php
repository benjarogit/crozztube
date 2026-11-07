<?php

namespace App\Support\Clips;

use App\Models\ClipStagingAccount;
use App\Models\ClipTransfer;
use App\Support\MediaProviderSettings;

final class OdyseeTransferGateway implements ClipTransferGateway
{
    public function publisherChannelId(): string
    {
        return MediaProviderSettings::current()->effective('odysee')['channel_id'];
    }

    public function seal(ClipTransfer $transfer): void
    {
        $this->store($transfer)->seal($transfer->stagingObject());
    }

    public function register(ClipTransfer $transfer): string
    {
        return (new OdyseeUrlImporter($this->settings($transfer)))->register($this->store($transfer), $transfer->stagingObject())->filePath();
    }

    public function publish(ClipTransfer $transfer): string
    {
        return $this->publisher($transfer)->create($transfer->stagingObject(),
            new OdyseeRemoteUpload($transfer->provider_state['upload']), $transfer->metadata['title']);
    }

    public function publication(ClipTransfer $transfer): ?string
    {
        $publisher = $this->publisher($transfer);
        if (! empty($transfer->provider_state['query'])) {
            $result = $publisher->status($transfer->provider_state['query'], $transfer->stagingObject());

            return $result['state'] === 'published' ? $result['claim_id'] : null;
        }
        // Reconcile a lost create response by its server-assigned name, not a new upload.
        $claim = $publisher->ownedClip($transfer->uuid);
        if ($claim === null) {
            return null;
        }
        if ((int) ($claim['value']['source']['size'] ?? 0) !== $transfer->bytes
            || ($claim['value']['source']['media_type'] ?? null) !== 'video/mp4') {
            throw new OdyseePublishingFailure('reconciliation', 'media');
        }

        return $claim['claim_id'];
    }

    public function available(ClipTransfer $transfer): bool
    {
        $publisher = $this->publisher($transfer);
        $claim = $transfer->provider_state['claim'];
        if (! $publisher->confirmed($transfer->stagingObject(), $claim)) {
            return false;
        }

        return (new ClipPlaybackReadiness())->check($publisher->playback($transfer->uuid, $claim));
    }

    public function makePublic(ClipTransfer $transfer): void
    {
        $this->publisher($transfer)->visibility($transfer->uuid, $transfer->provider_state['claim'], true);
    }

    public function abandon(ClipTransfer $transfer): void
    {
        $state = $transfer->provider_state ?? [];
        if (! isset($state['publish_started']) && ! isset($state['query']) && ! isset($state['claim'])) {
            return;
        }
        $publisher = $this->publisher($transfer);
        $claim = $state['claim'] ?? null;
        $rejected = false;
        if ($claim === null && isset($state['query'])) {
            try {
                $result = $publisher->status($state['query'], $transfer->stagingObject());
                if ($result['state'] !== 'published') {
                    throw new OdyseePublishingFailure('cancel', 'pending');
                }
                $claim = $result['claim_id'];
            } catch (OdyseePublishingFailure $error) {
                if ($error->operation !== 'status' || $error->reason !== 'provider' || $error->status !== 200) throw $error;
                $rejected = true;
            }
        }
        $claim ??= $publisher->ownedClip($transfer->uuid)['claim_id'] ?? null;
        if ($claim !== null) {
            $publisher->abandon($transfer->uuid, $claim);
        } elseif (! $rejected) {
            // A lost create response can produce a late claim. Keep reconciling.
            throw new OdyseePublishingFailure('cancel', 'outcome_uncertain');
        }
    }

    public function cleanup(ClipTransfer $transfer): void
    {
        $this->store($transfer)->deleteCopies($transfer->stagingObject());
    }

    private function settings(ClipTransfer $transfer): array
    {
        $settings = MediaProviderSettings::current()->effective('odysee');
        if ($transfer->publisher_channel_id !== null) {
            $settings['channel_id'] = $transfer->publisher_channel_id;
        }

        return $settings;
    }

    private function publisher(ClipTransfer $transfer): OdyseePublisher
    {
        return new OdyseePublisher($this->settings($transfer));
    }

    private function store(ClipTransfer $transfer): OracleStagingStore
    {
        $account = ClipStagingAccount::query()->findOrFail($transfer->account_id);

        return new OracleStagingStore(new OracleStagingConnection($account->connection));
    }
}
