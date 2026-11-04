<?php

namespace App\Support\Client;

use Illuminate\Http\Request;

final readonly class ClientContext
{
    public const REQUEST_ATTRIBUTE = 'playhub.client_context';

    public function __construct(
        public ?ClientPlatform $platform,
        public ?string $appVersion,
        public ?string $operatingSystemVersion,
        public ?string $deviceId,
        public ?string $deviceModel,
        public ?string $locale,
        public ?string $timezone,
        public ClientCapabilities $capabilities,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $context = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if ($context instanceof self) {
            return $context;
        }

        $context = (new ClientContextResolver)->resolve($request);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $context);

        return $context;
    }

    public function isFirstPartyClient(): bool
    {
        return $this->platform !== null;
    }

    public function isTv(): bool
    {
        return $this->platform?->deviceFamily() === 'tv';
    }

    /**
     * Safe public representation. Device identifiers are deliberately omitted.
     */
    public function toPublicArray(): array
    {
        return [
            'type' => $this->platform?->value,
            'device_family' => $this->platform?->deviceFamily(),
            'app_version' => $this->appVersion,
            'os_version' => $this->operatingSystemVersion,
            'device_model' => $this->deviceModel,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'capabilities' => $this->capabilities->toArray(),
        ];
    }
}
