<?php

namespace App\Support\Clips;

use App\Models\ClipTransfer;

/** All methods exchange metadata only; implementations may not relay video. */
interface ClipTransferGateway
{
    public function publisherChannelId(): string;

    public function seal(ClipTransfer $transfer): void;

    public function register(ClipTransfer $transfer): string;

    public function publish(ClipTransfer $transfer): string;

    public function publication(ClipTransfer $transfer): ?string;

    public function confirmed(ClipTransfer $transfer): bool;

    public function available(ClipTransfer $transfer): bool;

    public function makePublic(ClipTransfer $transfer): void;

    public function abandon(ClipTransfer $transfer): void;

    public function cleanup(ClipTransfer $transfer): void;
}
