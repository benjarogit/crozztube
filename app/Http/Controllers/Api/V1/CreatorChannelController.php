<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\User;
use App\Support\MediaStorage;
use App\Support\Slugs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CreatorChannelController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();
        $channel = $user->channel;

        return response()->json([
            'name' => $channel?->name ?? $user->name, 'slug' => $channel?->slug ?? '',
            'bio' => $channel?->bio ?? '', 'about' => $channel?->about ?? '',
            'avatar_url' => MediaStorage::url($channel?->avatar_path), 'cover_url' => MediaStorage::url($channel?->cover_path),
            'website' => data_get($user->settings, 'profile.website', ''),
            'socials' => (object) data_get($user->settings, 'profile.socials', []),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $channel = $this->channel($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'min:3', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('channels', 'slug')->ignore($channel->id)],
            'bio' => ['nullable', 'string', 'max:160'], 'about' => ['nullable', 'string', 'max:10000'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'socials' => ['sometimes', 'array:youtube,instagram,tiktok,x,twitter,facebook,linkedin'],
            'socials.*' => ['nullable', 'url:http,https', 'max:255'],
        ]);
        DB::transaction(function () use ($request, $data, $channel): void {
            $channel->update(collect($data)->only(['name', 'slug', 'bio', 'about'])->all());
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $settings = $user->settings ?? [];
            $settings['profile'] = array_replace((array) ($settings['profile'] ?? []), collect($data)->only(['website', 'socials'])->all());
            $user->update(['settings' => $settings]);
        });

        return $this->show($request);
    }

    public function artwork(Request $request, AccountController $account): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['avatar', 'cover'])], 'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8192,max_height=8192']]);
        if ($data['kind'] === 'avatar') {
            // Validation has already cached converted files on the original request.
            // Adapt the field on a fresh request before AccountController reads it.
            $avatarRequest = Request::createFrom($request);
            $avatarRequest->files->set('avatar', $request->file('image'));
            $account->updateAvatar($avatarRequest);
        } else {
            $channel = $this->channel($request);
            $path = $request->file('image')->store('covers/'.now()->format('Y/m'), MediaStorage::diskName());
            abort_unless($path, 500, 'The cover could not be saved.');
            $old = $channel->cover_path;
            $channel->update(['cover_path' => $path]);
            if ($old && ! preg_match('#^https?://#i', $old)) {
                MediaStorage::delete($old);
            }
        }

        return $this->show($request);
    }

    private function channel(Request $request): Channel
    {
        return $request->user()->channel()->firstOrCreate(['user_id' => $request->user()->id], ['name' => $request->user()->name, 'slug' => Slugs::unique($request->user()->username, Channel::class)]);
    }
}
