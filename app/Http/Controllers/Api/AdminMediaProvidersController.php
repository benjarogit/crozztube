<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Clips\OdyseeAccountLogin;
use App\Support\Clips\OdyseeLoginFailure;
use App\Support\Clips\OdyseePublishingConnection;
use App\Support\Clips\OracleStagingConnection;
use App\Support\MediaProviderSettings;
use App\Support\Thumbnails\Hosters;
use App\Support\Thumbnails\RemoteImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class AdminMediaProvidersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);
        try {
            return $this->response(['providers' => MediaProviderSettings::current()->adminSettings()]);
        } catch (Throwable) {
            return $this->response(['message' => 'Media provider settings could not be read. Check the server encryption configuration.'], 503);
        }
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $this->authorizeAdministrator($request);
        abort_unless(isset(MediaProviderSettings::FIELDS[$provider]), 404);
        $data = $request->validate([
            'changes' => ['present', 'array'], 'clear_secrets' => ['sometimes', 'array'],
            'clear_secrets.*' => ['string', 'max:64'],
            'connection_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $changes = $data['changes'];
        $clear = $data['clear_secrets'] ?? [];
        abort_if($provider !== 'odysee' && ! empty($data['connection_id']), 422, 'The pending Odysee sign-in can only be used for Odysee.');
        try {
            if ($provider === 'odysee' && ! empty($data['connection_id'])) {
                $key = $this->connectionKey($request, $data['connection_id']);
                Cache::lock($key.':lock', 15)->block(5, function () use ($key, $changes, $clear): void {
                    $pending = Cache::get($key);
                    abort_unless(is_string($pending), 422, 'This Odysee sign-in has expired. Sign in again.');
                    $pending = json_decode(Crypt::decryptString($pending), true, 16, JSON_THROW_ON_ERROR);
                    abort_unless(in_array($changes['channel_id'] ?? '', $pending['channel_ids'] ?? [], true), 422, 'Choose a channel from this Odysee account.');
                    abort_if(! empty($changes['auth_token']) || in_array('auth_token', $clear, true), 422, 'Use either the Odysee sign-in or a new token, not both.');
                    MediaProviderSettings::current()->save('odysee', [...$changes, 'auth_token' => $pending['auth_token']], $clear);
                    Cache::forget($key);
                });
            } else {
                MediaProviderSettings::current()->save($provider, $changes, $clear);
            }
        } catch (InvalidArgumentException) {
            abort(422, 'Check the media provider fields and try again.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            return $this->response(['message' => 'Media provider settings could not be saved. Try again shortly.'], 503);
        }
        // Field names only. Never log submitted values, tokens or passwords.
        Log::notice('Media provider settings updated', ['admin_id' => $request->user()->id, 'provider' => $provider,
            'changed_fields' => array_keys($changes), 'cleared_fields' => $clear]);

        return $this->response(['message' => 'Media provider settings saved.', 'providers' => MediaProviderSettings::current()->adminSettings()]);
    }

    public function connectOdysee(Request $request, OdyseeAccountLogin $login): JsonResponse
    {
        $this->authorizeAdministrator($request);
        abort_unless($request->isSecure(), 422, 'Open the admin panel over HTTPS before signing in to Odysee.');
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:4096']]);
        try {
            $token = $login->authenticate($data['email'], $data['password']);
            unset($data);
            $channels = $this->odyseeConnection(['auth_token' => $token])->ownedChannels();
            if ($channels === []) {
                return $this->response(['message' => 'Create a channel in Odysee, then sign in again.'], 422);
            }
            $id = (string) Str::uuid();
            $pending = Crypt::encryptString(json_encode(['auth_token' => $token, 'channel_ids' => array_column($channels, 'id')], JSON_THROW_ON_ERROR));
            if (Cache::put($this->connectionKey($request, $id), $pending, now()->addMinutes(5)) === false) {
                throw new \RuntimeException();
            }

            return $this->response(['connection_id' => $id, 'expires_in' => 300,
                'channels' => array_map(static fn (array $channel): array => ['id' => $channel['id'], 'name' => $channel['name']], $channels)]);
        } catch (OdyseeLoginFailure) {
            return $this->response(['message' => 'Odysee sign-in failed or needs additional confirmation. Your saved connection is unchanged.'], 422);
        } catch (Throwable) {
            return $this->response(['message' => 'Odysee is unavailable right now. Your saved connection is unchanged.'], 503);
        }
    }

    public function test(Request $request, string $provider, Hosters $hosters): JsonResponse
    {
        $this->authorizeAdministrator($request);
        abort_unless(isset(MediaProviderSettings::FIELDS[$provider]), 404);
        $settings = MediaProviderSettings::current()->effective($provider);
        try {
            if ($provider === 'oracle') {
                $connection = new OracleStagingConnection($settings);
                if ($connection->problems()) {
                    return $this->response(['message' => 'Save the complete Oracle connection first.'], 422);
                }
                $this->checkOracleAccess($connection);

                return $this->response(['message' => 'Oracle bucket access confirmed.']);
            }
            if ($provider === 'odysee') {
                $connection = $this->odyseeConnection($settings);
                if ($connection->problems()) {
                    return $this->response(['message' => 'Save the Odysee connection and channel first.'], 422);
                }
                $owned = $connection->ownedChannels();
                if (! array_filter($owned, static fn (array $channel): bool => $channel['selected'])) {
                    return $this->response(['message' => 'This Odysee account does not have access to the selected channel.'], 422);
                }

                return $this->response(['message' => 'Odysee account and channel access confirmed.']);
            }
            if (! ($settings['enabled'] ?? false) || ($provider !== 'directupload' && empty($settings['key']))) {
                return $this->response(['message' => 'Save and enable the image provider first.'], 422);
            }
            // Explicit admin test: a synthetic public 1px image, never user content.
            $image = RemoteImages::inspect(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
            $result = $hosters->upload($provider, $image, hash('sha256', (string) Str::uuid()), microtime(true) + 10, true);
            if (! is_string($result['url'] ?? null)) {
                return $this->response(['message' => 'The image provider did not accept the test image. Check its key and upload limits.'], 422);
            }

            return $this->response(['message' => 'Test image uploaded successfully.', 'test_image_url' => $result['url']]);
        } catch (Throwable) {
            return $this->response(['message' => 'The connection test failed. Check the saved settings and provider availability.'], 422);
        }
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }

    protected function odyseeConnection(#[\SensitiveParameter] array $settings): OdyseePublishingConnection
    {
        return new OdyseePublishingConnection($settings);
    }

    protected function checkOracleAccess(OracleStagingConnection $connection): void
    {
        $connection->client()->headBucket(['Bucket' => $connection->bucket()]);
    }

    private function connectionKey(Request $request, string $id): string
    {
        return 'admin-media-odysee:'.$request->user()->id.':'.$id;
    }

    private function authorizeAdministrator(Request $request): void
    {
        $settings = (array) ($request->user()?->settings ?? []);
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $enabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;
        abort_unless($request->user() && ($enabled || ($settings['is_admin'] ?? false) || in_array($role, ['admin', 'super admin', 'owner'], true)), 403, 'Administrator access required.');
    }
}
