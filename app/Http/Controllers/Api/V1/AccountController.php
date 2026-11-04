<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\Channel;
use App\Models\NativeDeviceSession;
use App\Models\User;
use App\Services\Api\V1\AccountDeletionService;
use App\Support\MediaStorage;
use App\Support\Slugs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['account' => $this->resource($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $channel = $user->channel()->first();
        $channelSlugRule = Rule::unique('channels', 'slug');
        if ($channel) {
            $channelSlugRule->ignore($channel->id);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:80'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_.]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'gender' => ['sometimes', 'nullable', Rule::in(['male', 'female'])],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'channel_name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'channel_slug' => ['sometimes', 'required', 'string', 'min:3', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $channelSlugRule],
            'bio' => ['sometimes', 'nullable', 'string', 'max:160'],
            'about' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);

        $emailChanged = isset($data['email']) && strtolower(trim($data['email'])) !== strtolower((string) $user->email);
        $userData = collect($data)->only(['name', 'username', 'email', 'gender', 'date_of_birth'])->all();
        foreach (['name', 'username', 'email'] as $field) {
            if (isset($userData[$field])) {
                $userData[$field] = trim((string) $userData[$field]);
            }
        }
        if ($emailChanged) {
            $userData['email_verified_at'] = null;
        }
        $channelData = [];
        foreach (['channel_name' => 'name', 'channel_slug' => 'slug', 'bio' => 'bio', 'about' => 'about'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $value = is_string($data[$input]) ? trim($data[$input]) : $data[$input];
                $channelData[$column] = $value === '' ? null : $value;
            }
        }
        DB::transaction(function () use ($user, $userData, $channelData): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($userData !== []) {
                $lockedUser->update($userData);
            }

            $lockedChannel = Channel::query()->where('user_id', $lockedUser->id)->lockForUpdate()->first();
            if (! $lockedChannel) {
                $lockedChannel = Channel::query()->create([
                    'user_id' => $lockedUser->id,
                    'name' => $lockedUser->name,
                    'slug' => Slugs::unique($lockedUser->username, Channel::class),
                    'bio' => null,
                ]);
            }
            if ($channelData !== []) {
                $lockedChannel->update($channelData);
            }
        }, 3);

        return response()->json(['account' => $this->resource($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profile' => ['sometimes', 'array:website,location,socials'],
            'profile.website' => ['nullable', 'url:http,https', 'max:255'],
            'profile.location' => ['nullable', 'string', 'max:120'],
            'profile.socials' => ['nullable', 'array:youtube,instagram,tiktok,x,twitter,facebook,linkedin'],
            'profile.socials.*' => ['nullable', 'url:http,https', 'max:255'],
            'preferences' => ['sometimes', 'array:publicProfile,showSubscriptions,showEmail,acceptTips,privateMessages,comments,replies,mentions,liveReminders,uploads,subscriptions,newsletter,autoplay,reducedMotion'],
            'preferences.publicProfile' => ['sometimes', 'boolean'],
            'preferences.showSubscriptions' => ['sometimes', 'boolean'],
            'preferences.showEmail' => ['sometimes', 'boolean'],
            'preferences.acceptTips' => ['sometimes', 'boolean'],
            'preferences.privateMessages' => ['sometimes', Rule::in(['anyone', 'subscribers', 'disabled'])],
            'preferences.comments' => ['sometimes', 'boolean'],
            'preferences.replies' => ['sometimes', 'boolean'],
            'preferences.mentions' => ['sometimes', 'boolean'],
            'preferences.liveReminders' => ['sometimes', 'boolean'],
            'preferences.uploads' => ['sometimes', 'boolean'],
            'preferences.subscriptions' => ['sometimes', 'boolean'],
            'preferences.newsletter' => ['sometimes', 'boolean'],
            'preferences.autoplay' => ['sometimes', 'boolean'],
            'preferences.reducedMotion' => ['sometimes', 'boolean'],
            'playback' => ['sometimes', 'array:defaultQuality,captions,language'],
            'playback.defaultQuality' => ['sometimes', Rule::in(['Auto', '720p', '1080p', '1440p', '2160p'])],
            'playback.captions' => ['sometimes', Rule::in(['On', 'Off', 'Auto'])],
            'playback.language' => ['sometimes', 'string', 'max:80'],
            'appearance' => ['sometimes', 'array:themeMode,accent'],
            'appearance.themeMode' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'appearance.accent' => ['sometimes', 'string', 'max:40'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $settings = is_array($user->settings) ? $user->settings : [];
            foreach (['profile', 'preferences', 'playback', 'appearance'] as $section) {
                if (array_key_exists($section, $data)) {
                    $settings[$section] = array_replace_recursive(
                        is_array($settings[$section] ?? null) ? $settings[$section] : [],
                        $data[$section],
                    );
                }
            }
            $user->update(['settings' => $settings]);
        }, 3);

        return response()->json(['account' => $this->resource($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $user = $request->user();
        $channel = $user->channel()->firstOrCreate(
            ['user_id' => $user->id],
            ['name' => $user->name, 'slug' => Slugs::unique($user->username, Channel::class), 'bio' => null],
        );
        $directory = 'avatars/'.now()->format('Y/m');
        if (MediaStorage::isLocal()) {
            MediaStorage::ensureLocalDirectory($directory);
        }
        $path = $request->file('avatar')->store($directory, MediaStorage::diskName());
        abort_unless($path, 500, 'Could not store the avatar.');
        $oldPaths = collect([$user->avatar_path, $channel->avatar_path])
            ->filter(fn ($old) => is_string($old) && $old !== '' && ! preg_match('/^(https?:)?\/\//', $old))
            ->unique()->all();
        $user->update(['avatar_path' => $path]);
        $channel->update(['avatar_path' => $path]);
        MediaStorage::delete($oldPaths);

        return response()->json(['account' => $this->resource($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('channel');
        $paths = collect([$user->avatar_path, $user->channel?->avatar_path])
            ->filter(fn ($path) => is_string($path) && $path !== '' && ! preg_match('/^(https?:)?\/\//', $path))
            ->unique()->all();
        $user->update(['avatar_path' => null]);
        $user->channel?->update(['avatar_path' => null]);
        MediaStorage::delete($paths);

        return response()->json(['account' => $this->resource($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function updateCover(Request $request): JsonResponse
    {
        $request->validate([
            'cover' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8192,max_height=8192'],
        ]);

        $user = $request->user();
        $channel = $user->channel()->firstOrCreate(
            ['user_id' => $user->id],
            ['name' => $user->name, 'slug' => Slugs::unique($user->username, Channel::class), 'bio' => null],
        );
        $directory = 'covers/'.now()->format('Y/m');
        if (MediaStorage::isLocal()) {
            MediaStorage::ensureLocalDirectory($directory);
        }
        $path = $request->file('cover')->store($directory, MediaStorage::diskName());
        abort_unless($path, 500, 'Could not store the cover.');

        $oldPath = $channel->cover_path;
        $channel->update(['cover_path' => $path]);
        if (is_string($oldPath) && $oldPath !== '' && ! preg_match('/^(https?:)?\/\//', $oldPath)) {
            MediaStorage::delete($oldPath);
        }

        return response()->json(['account' => $this->resource($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function removeCover(Request $request): JsonResponse
    {
        $channel = $request->user()->channel()->first();
        if ($channel) {
            $oldPath = $channel->cover_path;
            $channel->update(['cover_path' => null]);
            if (is_string($oldPath) && $oldPath !== '' && ! preg_match('/^(https?:)?\/\//', $oldPath)) {
                MediaStorage::delete($oldPath);
            }
        }

        return response()->json(['account' => $this->resource($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, AccountDeletionService $deletion): JsonResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string', Rule::in(['DELETE'])],
            'current_password' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $request->user();
        $provider = strtolower(trim((string) $user->registration_provider));
        $passwordRequired = $provider === '' || in_array($provider, ['password', 'email', 'local', 'installer'], true);

        if (($passwordRequired || filled($data['current_password'] ?? null))
            && ! Hash::check((string) ($data['current_password'] ?? ''), (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        if (! $passwordRequired && blank($data['current_password'] ?? null)) {
            $session = $request->attributes->get(NativeDeviceSession::REQUEST_ATTRIBUTE);
            $reauthenticationMinutes = max(1, min(
                (int) config('native_auth.passwordless_delete_reauthentication_minutes', 15),
                1440,
            ));

            if (! $session instanceof NativeDeviceSession
                || ! $session->created_at
                || $session->created_at->lt(now()->subMinutes($reauthenticationMinutes))) {
                throw ValidationException::withMessages([
                    'reauthentication' => ['Please sign in again before permanently deleting this account.'],
                ]);
            }
        }

        $deletion->permanentlyDelete($user);

        return response()->json([], 204);
    }

    private function resource(Request $request): array
    {
        return (new AccountResource($request->user()->fresh()->load('channel.user')))->resolve($request);
    }
}
