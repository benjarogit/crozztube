<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Api\V1\PaginationPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    private const PREFERENCE_KEYS = [
        'comments', 'replies', 'mentions', 'liveReminders', 'uploads', 'subscriptions', 'newsletter',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'max:80', 'regex:/\\A[a-z0-9_:-]+\\z/'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $notifications = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->when((bool) ($data['unread_only'] ?? false), fn ($query) => $query->whereNull('read_at'))
            ->when(filled($data['type'] ?? null), fn ($query) => $query->where('type', $data['type']))
            ->with(['actor.channel', 'video.liveStream', 'comment', 'communityPost.channel'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        $payload = PaginationPayload::lengthAware(
            $notifications,
            NotificationResource::collection($notifications->getCollection())->resolve($request),
        );
        $body = $payload->getData(true);
        $unreadCount = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data' => ['data' => $body['data'], 'unread_count' => $unreadCount],
            'meta' => $body['meta'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function markRead(Request $request, UserNotification $notification): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 404, 'Notification not found.');
        $notification->update(['read_at' => $notification->read_at ?: now()]);

        $unreadCount = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['id' => (int) $notification->id, 'unread' => false, 'unread_count' => $unreadCount])
            ->header('Cache-Control', 'private, no-store');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $unreadCount = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['updated_count' => $updated, 'unread_count' => $unreadCount])
            ->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, UserNotification $notification): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 404, 'Notification not found.');
        $notification->delete();

        return response()->json([], 204);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['notifications' => $this->preferences($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $rules = collect(self::PREFERENCE_KEYS)->mapWithKeys(fn (string $key): array => [
            $key => ['sometimes', 'boolean'],
        ])->all();
        $data = $request->validate($rules);
        DB::transaction(function () use ($request, $data): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $settings = is_array($user->settings) ? $user->settings : [];
            $preferences = is_array($settings['preferences'] ?? null) ? $settings['preferences'] : [];

            foreach ($data as $key => $value) {
                $preferences[$key] = (bool) $value;
            }

            $settings['preferences'] = $preferences;
            $user->update(['settings' => $settings]);
        }, 3);

        return response()->json(['notifications' => $this->preferences($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function preferences(Request $request): array
    {
        $preferences = data_get($request->user()->fresh(), 'settings.preferences', []);

        return collect(self::PREFERENCE_KEYS)->mapWithKeys(fn (string $key): array => [
            $key => (bool) data_get($preferences, $key, $key !== 'newsletter'),
        ])->all();
    }
}
