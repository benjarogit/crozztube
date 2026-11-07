<?php

namespace App\Support\Clips;

use App\Models\ClipStagingAccount;
use App\Models\ClipTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Atomic queue admission/reservations only. No media transport or public routes. */
final class ClipTransferQueue
{
    private const ACTIVE = ['uploading', 'sealing', 'registering', 'publishing', 'processing'];

    public function __construct(private int $globalActive = 3, private int $dailyLimit = 5)
    {
        if ($globalActive < 1 || $globalActive > 30 || $dailyLimit < 1 || $dailyLimit > 100) {
            throw new InvalidArgumentException('Invalid clip queue limits.');
        }
    }

    /** Preserve a bucket-bound encrypted snapshot for every assigned transfer. */
    public function configureOracle(#[\SensitiveParameter] array $settings, int $capacity = 1000000000): string
    {
        $connection = new OracleStagingConnection($settings);
        if ($connection->problems() || $capacity < ClipStagingObject::MAX_BYTES * 2 || $capacity > 1000000000) {
            throw new InvalidArgumentException('Invalid Oracle staging account or capacity.');
        }
        $id = 'oci-'.substr(hash('sha256', $connection->endpoint().'/'.$connection->bucket()), 0, 40);

        return $this->locked(function () use ($id, $settings, $capacity): string {
            // Changing buckets never redirects existing transfers to a new bucket.
            ClipStagingAccount::query()->where('provider', 'oci')->where('id', '!=', $id)->update(['enabled' => false]);
            ClipStagingAccount::query()->updateOrCreate(['id' => $id], [
                'provider' => 'oci', 'connection' => $settings, 'enabled' => true, 'capacity_bytes' => $capacity,
            ]);

            return $id;
        });
    }

    /** Caller must authorize source-video access before calling this service. */
    public function enqueue(int $ownerId, int $videoId, string $requestKey, array $input): ClipTransfer
    {
        if ($ownerId < 1 || $videoId < 1 || ! is_int($input['bytes'] ?? null) || ! is_string($input['sha256'] ?? null)) {
            throw new InvalidArgumentException('Invalid clip transfer identity.');
        }
        $object = new ClipStagingObject((string) Str::uuid(), $input['bytes'], $input['sha256']);
        new ClipStagingObject($requestKey, 1, str_repeat('0', 64));
        $metadata = $this->metadata($input);
        $hash = hash('sha256', json_encode([$videoId, $object->bytes, $object->sha256, $metadata], JSON_THROW_ON_ERROR));

        return $this->locked(function () use ($ownerId, $videoId, $requestKey, $object, $metadata, $hash): ClipTransfer {
            $old = ClipTransfer::query()->where('user_id', $ownerId)->where('request_key', $requestKey)->first();
            if ($old !== null) {
                if (! hash_equals($old->request_hash, $hash)) {
                    throw new ClipQueueRejected('idempotency_conflict');
                }

                return $old;
            }
            if (ClipTransfer::query()->where('user_id', $ownerId)->where('created_at', '>=', now()->startOfDay())->count() >= $this->dailyLimit) {
                throw new ClipQueueRejected('daily_limit');
            }
            if (ClipTransfer::query()->where('user_id', $ownerId)->where('phase', 'queued')->exists()) {
                throw new ClipQueueRejected('waiting_limit');
            }
            $transfer = ClipTransfer::query()->create([
                'uuid' => $object->jobId, 'user_id' => $ownerId, 'video_id' => $videoId,
                'request_key' => $requestKey, 'request_hash' => $hash,
                'bytes' => $object->bytes, 'sha256' => $object->sha256, 'metadata' => $metadata,
                'phase' => 'queued', 'deadline_at' => now()->addHours(24),
            ]);
            $this->promoteLocked();

            return $transfer->fresh();
        });
    }

    public function promote(): void
    {
        $this->locked(fn () => $this->promoteLocked());
    }

    private function promoteLocked(): void
    {
        $active = ClipTransfer::query()->whereIn('phase', self::ACTIVE)->count();
        if ($active >= $this->globalActive) {
            return;
        }
        foreach (ClipTransfer::query()->where('phase', 'queued')->orderBy('id')->cursor() as $transfer) {
            if ($transfer->user_id === null || $transfer->video_id === null || $transfer->deadline_at->isPast()) {
                $transfer->update(['phase' => 'cancelled', 'cancel_requested' => true]);
                continue;
            }
            if (ClipTransfer::query()->where('user_id', $transfer->user_id)->whereIn('phase', self::ACTIVE)->exists()) {
                continue;
            }
            $account = ClipStagingAccount::query()->where('provider', 'oci')->where('enabled', true)
                ->where(fn ($q) => $q->whereNull('suspended_until')->orWhere('suspended_until', '<=', now()))
                ->get()->sortBy(fn ($row) => $this->reserved($row->id))->first(function ($row) use ($transfer): bool {
                    return ! ClipTransfer::query()->where('account_id', $row->id)->whereIn('phase', self::ACTIVE)->exists()
                        && $this->reserved($row->id) + $transfer->stagingObject()->reservedBytes() <= $row->capacity_bytes;
                });
            if ($account === null) {
                // No healthy capacity: preserve FIFO rather than skipping a large job.
                return;
            }
            $until = now()->addMinutes(5);
            $transfer->update(['account_id' => $account->id, 'phase' => 'uploading',
                'reserved_bytes' => $transfer->stagingObject()->reservedBytes(), 'grant_until' => $until,
                // Retain capacity through grant expiry plus an in-flight upload window.
                'cleanup_not_before' => $until->copy()->addMinutes(15),
            ]);
            if (++$active >= $this->globalActive) {
                return;
            }
        }
    }

    public function cancel(int $ownerId, string $uuid): ClipTransfer
    {
        return $this->locked(function () use ($ownerId, $uuid): ClipTransfer {
            $transfer = $this->owned($ownerId, $uuid);
            if (! in_array($transfer->phase, ['queued', ...self::ACTIVE], true)) {
                return $transfer;
            }
            $transfer->cancel_requested = true;
            if ($transfer->phase === 'queued') {
                $transfer->phase = 'cancelled';
            }
            // Never release a still-writable object's reservation on cancellation.
            $transfer->save();

            return $transfer;
        });
    }

    public function uploaded(int $ownerId, string $uuid): ClipTransfer
    {
        return $this->locked(function () use ($ownerId, $uuid): ClipTransfer {
            $transfer = $this->owned($ownerId, $uuid);
            if ($transfer->cancel_requested) {
                throw new ClipQueueRejected('cancelled');
            }
            if ($transfer->phase === 'uploading') {
                if ($transfer->grant_until->isPast()) {
                    throw new ClipQueueRejected('upload_expired');
                }
                $transfer->update(['phase' => 'sealing']);
            }

            return $transfer;
        });
    }

    public function status(int $ownerId, string $uuid): array
    {
        $transfer = $this->owned($ownerId, $uuid);

        return ['id' => $transfer->uuid, 'state' => $transfer->phase,
            'position' => $transfer->phase === 'queued'
                ? ClipTransfer::query()->where('phase', 'queued')->where('id', '<=', $transfer->id)->count() : null,
            'cancel_requested' => $transfer->cancel_requested];
    }

    private function owned(int $ownerId, string $uuid): ClipTransfer
    {
        return ClipTransfer::query()->where('user_id', $ownerId)->where('uuid', $uuid)->first()
            ?? throw new ClipQueueRejected('not_found');
    }

    private function reserved(string $accountId): int
    {
        return (int) ClipTransfer::query()->where('account_id', $accountId)->whereNull('reservation_released_at')->sum('reserved_bytes');
    }

    private function metadata(array $input): array
    {
        $title = $input['title'] ?? null;
        $start = $input['start'] ?? null;
        $end = $input['end'] ?? null;
        $visibility = $input['visibility'] ?? null;
        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 200 || preg_match('/[\x00-\x1f\x7f]/u', $title)
            || (! is_int($start) && ! is_float($start)) || (! is_int($end) && ! is_float($end))
            || ! is_finite((float) $start) || ! is_finite((float) $end)
            || $start < 0 || $end - $start < 5 || $end - $start > 60
            || ! in_array($visibility, ['private', 'public'], true)) {
            throw new InvalidArgumentException('Invalid clip transfer metadata.');
        }

        return ['title' => $title, 'start' => (float) $start, 'end' => (float) $end, 'visibility' => $visibility];
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
}
