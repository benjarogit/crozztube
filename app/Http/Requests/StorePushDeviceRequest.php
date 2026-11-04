<?php

namespace App\Http\Requests;

use App\Models\NativeDeviceSession;
use App\Models\PushDevice;
use App\Services\Auth\NativeDeviceSessionManager;
use App\Support\Client\ClientContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePushDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $client = ClientContext::fromRequest($this);

        $this->merge([
            'provider' => strtolower(trim((string) $this->input('provider'))),
            'platform' => strtolower(trim((string) ($this->input('platform') ?: $client->platform?->value))),
            'environment' => strtolower(trim((string) $this->input('environment', PushDevice::ENVIRONMENT_PRODUCTION))),
            'token' => trim((string) $this->input('token')),
            'device_id' => trim((string) ($this->input('device_id') ?: $client->deviceId)),
            'device_name' => $this->nullableTrimmedString('device_name') ?: $client->deviceModel,
            'app_version' => $this->nullableTrimmedString('app_version') ?: $client->appVersion,
            'app_identifier' => $this->nullableTrimmedString('app_identifier'),
            'locale' => $this->nullableTrimmedString('locale') ?: $client->locale,
        ]);
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(PushDevice::PROVIDERS)],
            'platform' => ['required', 'string', Rule::in(PushDevice::PLATFORMS)],
            'environment' => ['required', 'string', Rule::in(PushDevice::ENVIRONMENTS)],
            'token' => ['required', 'string', 'min:16', 'max:4096'],
            'device_id' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'app_identifier' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]+$/'],
            'locale' => ['nullable', 'string', 'max:35', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $provider = (string) $this->string('provider');
                $platform = (string) $this->string('platform');
                $environment = (string) $this->string('environment');
                $appIdentifier = $this->input('app_identifier');
                $allowed = config($provider === 'apns' ? 'push.apns.bundle_ids' : 'push.fcm.packages', []);
                if ($appIdentifier && ! in_array($appIdentifier, $allowed, true)) {
                    $validator->errors()->add('app_identifier', 'This application identifier is not configured for push delivery.');
                }

                if ($provider === PushDevice::PROVIDER_FCM && $platform !== PushDevice::PLATFORM_ANDROID) {
                    $validator->errors()->add('platform', 'FCM push devices must use the android platform.');
                }

                if ($provider === PushDevice::PROVIDER_APNS && $platform !== PushDevice::PLATFORM_IOS) {
                    $validator->errors()->add('platform', 'APNs push devices must use the ios platform.');
                }

                if ($provider === PushDevice::PROVIDER_FCM && $environment !== PushDevice::ENVIRONMENT_PRODUCTION) {
                    $validator->errors()->add('environment', 'FCM push devices must use the production environment.');
                }

                $session = $this->attributes->get(NativeDeviceSession::REQUEST_ATTRIBUTE);
                if ($session instanceof NativeDeviceSession) {
                    $deviceHash = app(NativeDeviceSessionManager::class)
                        ->hashDeviceIdentifier((string) $this->input('device_id'));
                    if ($session->client_type !== 'mobile'
                        || $session->platform !== $platform
                        || ! hash_equals((string) $session->device_identifier_hash, $deviceHash)) {
                        $validator->errors()->add('device_id', 'The push registration must belong to the current mobile device session.');
                    }
                }
            },
        ];
    }

    private function nullableTrimmedString(string $key): ?string
    {
        if (! $this->has($key)) {
            return null;
        }

        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
