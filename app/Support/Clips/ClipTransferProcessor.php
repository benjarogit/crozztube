<?php

namespace App\Support\Clips;

use App\Models\ClipTransfer;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Durable, leased metadata workflow. A successful API call is never readiness. */
final class ClipTransferProcessor
{
    private const LIVE = ['uploading', 'sealing', 'registering', 'publishing', 'processing', 'awaiting_confirmation', 'releasing', 'cancelling'];

    public function __construct(private ClipTransferGateway $gateway) {}

    public function run(?string $uuid = null): bool
    {
        $transfer = $this->locked(function () use ($uuid): ?ClipTransfer {
            $row = ClipTransfer::query()->when($uuid !== null, fn ($q) => $q->where('uuid', $uuid))
                ->whereNotNull('account_id')
                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
                ->where(function ($q): void {
                    $q->whereIn('phase', self::LIVE)->orWhere(function ($q): void {
                        $q->whereIn('phase', ['ready', 'failed', 'cancelled'])->whereNull('reservation_released_at');
                    });
                })->orderBy('id')->first();
            if ($row !== null) {
                $row->update(['lease_token' => Str::random(48), 'lease_until' => now()->addMinutes(3)]);
            }

            return $row;
        });
        if ($transfer === null) {
            return false;
        }
        try {
            $this->advance($transfer);
        } catch (Throwable $error) {
            if ($error instanceof OdyseePublishingFailure && $error->operation === 'status'
                && $error->reason === 'provider' && $error->status === 200) {
                $this->save($transfer, ['phase' => 'failed', 'last_error' => 'provider_rejected_upload']);

                return true;
            }
            // Keep requests, tokens and provider messages out of jobs/logs/API.
            $code = $error instanceof OdyseePublishingFailure && $error->reason === 'identity'
                ? 'provider_identity_unconfirmed' : 'provider_temporarily_unavailable';
            $state = $transfer->provider_state ?? [];
            if ($error instanceof OdyseeImportFailure && ! $error->outcomeUncertain()) {
                unset($state['register_started']);
            }
            if ($error instanceof OdyseePublishingFailure && $error->operation === 'create' && ! $error->outcomeUncertain()) {
                unset($state['publish_started']);
            }
            $this->save($transfer, ['last_error' => $code, 'provider_state' => $state], $this->pollDelay($transfer));
        }

        return true;
    }

    private function advance(ClipTransfer $transfer): void
    {
        if ($transfer->user_id === null || $transfer->video_id === null) {
            $transfer->cancel_requested = true;
        }
        if (in_array($transfer->phase, ['ready', 'failed', 'cancelled'], true)) {
            $this->cleanup($transfer);

            return;
        }
        if ($transfer->cancel_requested) {
            $changes = $transfer->reservation_released_at === null ? $this->cleanupChanges($transfer) : [];
            if (! $this->save($transfer, [...$changes, 'phase' => 'cancelling', 'cancel_requested' => true], 0, false)) {
                return;
            }
            $this->gateway->abandon($transfer);
            $this->save($transfer, ['phase' => 'cancelled', 'cancel_requested' => true]);

            return;
        }
        $providerAccepted = in_array($transfer->phase, ['processing', 'awaiting_confirmation', 'releasing'], true)
            || ($transfer->phase === 'publishing' && isset($transfer->provider_state['publish_started']));
        if ($transfer->deadline_at->isPast() && ! $providerAccepted) {
            // The deadline limits abandoned browser/staging work, not provider processing.
            $this->save($transfer, ['phase' => 'failed', 'last_error' => 'staging_transfer_expired']);

            return;
        }
        $state = $transfer->provider_state ?? [];
        switch ($transfer->phase) {
            case 'uploading':
                if ($transfer->grant_until->isPast()) {
                    $this->save($transfer, ['phase' => 'failed', 'last_error' => 'upload_expired']);
                } else {
                    $this->save($transfer, [], max(1, $transfer->grant_until->timestamp - now()->timestamp));
                }
                break;
            case 'sealing':
                $this->gateway->seal($transfer);
                $this->save($transfer, ['phase' => 'registering', 'last_error' => null]);
                break;
            case 'registering':
                if (isset($state['register_started'])) {
                    // Registration has no idempotent key. Never repeat a lost write.
                    $this->save($transfer, ['last_error' => 'registration_outcome_uncertain'], 300);
                    break;
                }
                $state['register_started'] = now()->toIso8601String();
                if (! $this->save($transfer, ['provider_state' => $state,
                    'publisher_channel_id' => $transfer->publisher_channel_id ?? $this->gateway->publisherChannelId()], 0, false)) {
                    break;
                }
                $state['upload'] = $this->gateway->register($transfer);
                $this->save($transfer, ['phase' => 'publishing', 'provider_state' => $state, 'last_error' => null]);
                break;
            case 'publishing':
                if (isset($state['publish_started'])) {
                    $claim = $this->gateway->publication($transfer);
                    if ($claim !== null) {
                        $state['claim'] = $claim;
                        $this->save($transfer, ['phase' => 'awaiting_confirmation', 'provider_state' => $state, 'last_error' => null], 30);
                    } else {
                        $this->save($transfer, ['last_error' => 'publication_outcome_uncertain'], $this->pollDelay($transfer));
                    }
                    break;
                }
                $state['publish_started'] = now()->toIso8601String();
                if (! $this->save($transfer, ['provider_state' => $state], 0, false)) {
                    break;
                }
                $state['query'] = $this->gateway->publish($transfer);
                $this->save($transfer, ['phase' => 'processing', 'provider_state' => $state, 'last_error' => null], 30);
                break;
            case 'processing':
                $claim = $this->gateway->publication($transfer);
                if ($claim !== null) {
                    $state['claim'] = $claim;
                    $this->save($transfer, ['phase' => 'awaiting_confirmation', 'provider_state' => $state, 'last_error' => null], 30);
                } else {
                    $this->save($transfer, [], $this->pollDelay($transfer));
                }
                break;
            case 'awaiting_confirmation':
                if (($transfer->metadata['visibility'] ?? 'private') === 'public') {
                    // Provider confirmation permits the requested public release.
                    // Do not require unlisted access before checking public playback.
                    $confirmed = $this->gateway->confirmed($transfer);
                    $this->save($transfer, $confirmed ? ['phase' => 'releasing', 'last_error' => null]
                        : ['last_error' => 'waiting_for_odysee'], $confirmed ? 0 : $this->pollDelay($transfer));
                } elseif ($this->gateway->available($transfer)) {
                    $this->ready($transfer);
                } else {
                    $this->save($transfer, ['last_error' => 'waiting_for_odysee'], $this->pollDelay($transfer));
                }
                break;
            case 'releasing':
                $this->gateway->makePublic($transfer);
                if ($this->gateway->available($transfer)) {
                    $this->ready($transfer);
                } else {
                    $this->save($transfer, ['last_error' => 'waiting_for_odysee'], $this->pollDelay($transfer));
                }
                break;
        }
    }

    private function ready(ClipTransfer $transfer): void
    {
        $this->locked(function () use ($transfer): void {
            $row = $this->leased($transfer);
            if ($row === null || $row->cancel_requested || $row->user_id === null || $row->video_id === null) {
                $this->save($transfer, [], 0);

                return;
            }
            $row->update(['phase' => 'ready', 'available_at' => now(), 'last_error' => null,
                'lease_token' => null, 'lease_until' => null, 'next_check_at' => now()]);
            if ($row->notification_handled_at === null) {
                UserNotification::query()->firstOrCreate(['clip_transfer_id' => $row->id], [
                    'user_id' => $row->user_id, 'type' => 'clip_ready',
                    'data' => ['clip_id' => $row->uuid, 'title' => $row->metadata['title']],
                ]);
                // Preferences may suppress notification creation; never replay it.
                $row->update(['notification_handled_at' => now()]);
            }
        });
    }

    private function cleanup(ClipTransfer $transfer): void
    {
        $safe = $transfer->cleanup_not_before === null || $transfer->cleanup_not_before->lessThanOrEqualTo(now());
        $this->save($transfer, $this->cleanupChanges($transfer),
            $safe ? 0 : max(1, $transfer->cleanup_not_before->timestamp - now()->timestamp));
    }

    private function cleanupChanges(ClipTransfer $transfer): array
    {
        $this->gateway->cleanup($transfer);
        $safe = $transfer->cleanup_not_before === null || $transfer->cleanup_not_before->lessThanOrEqualTo(now());

        return ['cleanup_checked_at' => now(), ...($safe ? ['reservation_released_at' => now()] : [])];
    }

    private function save(ClipTransfer $transfer, array $changes, int $delay = 0, bool $release = true): bool
    {
        return $this->locked(function () use ($transfer, $changes, $delay, $release): bool {
            $row = $this->leased($transfer);
            if ($row === null) {
                return false;
            }
            // Cancellation set by a concurrent request must never be overwritten.
            if (($changes['cancel_requested'] ?? null) !== true) unset($changes['cancel_requested']);
            $row->fill([...$changes, 'next_check_at' => now()->addSeconds($delay)]);
            if ($release) {
                $row->lease_token = null;
                $row->lease_until = null;
            }
            $row->save();
            $transfer->setRawAttributes($row->getAttributes(), true);

            return true;
        });
    }

    private function leased(ClipTransfer $transfer): ?ClipTransfer
    {
        return ClipTransfer::query()->whereKey($transfer->id)->where('lease_token', $transfer->lease_token)
            ->where('lease_until', '>', now())->lockForUpdate()->first();
    }

    private function locked(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            if (DB::table('clip_transfer_locks')->where('id', 1)->lockForUpdate()->first() === null) {
                throw new ClipQueueRejected('queue_unavailable');
            }

            return $callback();
        }, 3);
    }

    private function pollDelay(ClipTransfer $transfer): int
    {
        return $transfer->created_at->diffInSeconds(now(), true) >= 600 ? 300 : 30;
    }
}
