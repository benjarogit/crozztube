<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\V1\PaginationPayload;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class MessageController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($data['q'] ?? ''));
        $users = User::query()
            ->with('channel')
            ->whereKeyNot($request->user()->id)
            ->when(isset($data['user_id']), fn ($query) => $query->whereKey($data['user_id']))
            ->when($search !== '', fn ($query) => $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhereHas('channel', fn ($channel) => $channel
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%'));
            }))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->filter(fn (User $user) => $this->messagePermission($user, $request->user())[0])
            ->take(12)
            ->map(fn (User $user) => $this->userPayload($user, $request->user(), true))
            ->values()
            ->all();

        return response()->json(['users' => $users])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $userId = (int) $request->user()->id;
        $conversations = DB::table('private_conversations')
            ->where(fn ($query) => $query->where('user_one_id', $userId)->orWhere('user_two_id', $userId))
            ->whereNotIn('user_one_id', DB::table('user_blocks')->where('user_id', $userId)->select('blocked_user_id'))
            ->whereNotIn('user_two_id', DB::table('user_blocks')->where('user_id', $userId)->select('blocked_user_id'))
            ->whereNotIn('user_one_id', DB::table('user_blocks')->where('blocked_user_id', $userId)->select('user_id'))
            ->whereNotIn('user_two_id', DB::table('user_blocks')->where('blocked_user_id', $userId)->select('user_id'))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 50));
        $items = $conversations->getCollection()
            ->map(fn ($conversation) => $this->conversationPayload($conversation, $request->user()))
            ->all();
        $unreadCount = DB::table('private_messages as messages')
            ->join('private_conversations as conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where(fn ($query) => $query->where('conversations.user_one_id', $userId)->orWhere('conversations.user_two_id', $userId))
            ->where('messages.sender_id', '!=', $userId)
            ->whereNotIn('messages.sender_id', DB::table('user_blocks')->where('user_id', $userId)->select('blocked_user_id'))
            ->whereNotIn('messages.sender_id', DB::table('user_blocks')->where('blocked_user_id', $userId)->select('user_id'))
            ->whereNull('messages.read_at')
            ->count();

        return response()->json([
            'data' => ['conversations' => array_values($items), 'unread_count' => $unreadCount],
            'meta' => ['pagination' => [
                'page' => $conversations->currentPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
                'last_page' => $conversations->lastPage(),
                'has_more' => $conversations->hasMorePages(),
            ]],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $row = $this->findConversation($conversation, (int) $request->user()->id);

        return response()->json(['conversation' => $this->conversationPayload($row, $request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function messages(Request $request, int $conversation): JsonResponse
    {
        $this->findConversation($conversation, (int) $request->user()->id);
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $messages = DB::table('private_messages')
            ->where('conversation_id', $conversation)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->integer('per_page', 30), 1), 50));

        return PaginationPayload::lengthAware(
            $messages,
            $messages->getCollection()
                ->map(fn ($message) => $this->messagePayload($message, $request->user()))
                ->all(),
        )->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedMessage($request, true);
        $recipient = User::query()->with('channel')->findOrFail($data['recipient_id']);
        [$allowed, $reason] = $this->messagePermission($recipient, $request->user());
        abort_unless($allowed, 403, $reason);

        [$first, $second] = collect([(int) $request->user()->id, (int) $recipient->id])->sort()->values()->all();
        $message = DB::transaction(function () use ($first, $second, $data, $request): object {
            DB::table('private_conversations')->insertOrIgnore([
                'user_one_id' => $first,
                'user_two_id' => $second,
                'last_message_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $conversation = DB::table('private_conversations')
                ->where('user_one_id', $first)->where('user_two_id', $second)->first();

            return $this->insertMessage($conversation, $request->user(), $data, $request);
        });
        $conversation = DB::table('private_conversations')->find($message->conversation_id);

        return response()->json([
            'conversation' => $this->conversationPayload($conversation, $request->user()),
            'chat_message' => $this->messagePayload($message, $request->user()),
        ], 201)->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, int $conversation): JsonResponse
    {
        $row = $this->findConversation($conversation, (int) $request->user()->id);
        $recipientId = (int) $row->user_one_id === (int) $request->user()->id
            ? (int) $row->user_two_id : (int) $row->user_one_id;
        $recipient = User::query()->with('channel')->find($recipientId);
        [$allowed, $reason] = $this->messagePermission($recipient, $request->user());
        abort_unless($allowed, 403, $reason);
        $data = $this->validatedMessage($request);
        $message = DB::transaction(fn () => $this->insertMessage($row, $request->user(), $data, $request));
        $row = DB::table('private_conversations')->find($conversation);

        return response()->json([
            'conversation' => $this->conversationPayload($row, $request->user()),
            'chat_message' => $this->messagePayload($message, $request->user()),
        ], 201)->header('Cache-Control', 'private, no-store');
    }

    public function markRead(Request $request, int $conversation): JsonResponse
    {
        $row = $this->findConversation($conversation, (int) $request->user()->id);
        DB::table('private_messages')
            ->where('conversation_id', $row->id)
            ->where('sender_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
        $unreadCount = DB::table('private_messages as messages')
            ->join('private_conversations as conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where(fn ($query) => $query
                ->where('conversations.user_one_id', $request->user()->id)
                ->orWhere('conversations.user_two_id', $request->user()->id))
            ->where('messages.sender_id', '!=', $request->user()->id)
            ->whereNotIn('messages.sender_id', DB::table('user_blocks')->where('user_id', $request->user()->id)->select('blocked_user_id'))
            ->whereNotIn('messages.sender_id', DB::table('user_blocks')->where('blocked_user_id', $request->user()->id)->select('user_id'))
            ->whereNull('messages.read_at')
            ->count();

        return response()->json([
            'conversation' => $this->conversationPayload($row, $request->user()),
            'unread_count' => $unreadCount,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function validatedMessage(Request $request, bool $newConversation = false): array
    {
        $data = $request->validate([
            'recipient_id' => [$newConversation ? 'required' : 'prohibited', 'integer', 'exists:users,id'],
            'body' => ['nullable', 'string', 'max:5000'],
            'images' => ['nullable', 'array', 'max:4'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);
        $data['body'] = trim((string) ($data['body'] ?? ''));
        abort_if($data['body'] === '' && ! $request->hasFile('images'), 422, 'Message cannot be empty.');

        return $data;
    }

    private function insertMessage(object $conversation, User $sender, array $data, Request $request): object
    {
        $other = (int) $conversation->user_one_id === (int) $sender->id ? (int) $conversation->user_two_id : (int) $conversation->user_one_id;
        \App\Support\UserBlocks::lockPair((int) $sender->id, $other);
        \App\Support\UserBlocks::assertInteraction($sender, $other);
        $attachments = collect($request->file('images', []))
            ->map(function ($image): array {
                $directory = 'messages/'.now()->format('Y/m');
                if (MediaStorage::isLocal()) {
                    MediaStorage::ensureLocalDirectory($directory);
                }
                $path = $image->store($directory, MediaStorage::diskName());

                return [
                    'type' => 'image', 'path' => $path, 'name' => $image->getClientOriginalName(),
                    'mime' => $image->getMimeType(), 'size' => $image->getSize(),
                ];
            })->all();
        $id = DB::table('private_messages')->insertGetId([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => $data['body'],
            'attachments' => $attachments ? json_encode($attachments) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('private_conversations')->where('id', $conversation->id)->update([
            'last_message_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('private_messages')->find($id);
    }

    private function findConversation(int $id, int $userId): object
    {
        $conversation = DB::table('private_conversations')->where('id', $id)
            ->where(fn ($query) => $query->where('user_one_id', $userId)->orWhere('user_two_id', $userId))
            ->first();
        abort_unless($conversation, 404, 'Conversation not found.');
        $other = (int) $conversation->user_one_id === $userId ? (int) $conversation->user_two_id : (int) $conversation->user_one_id;
        abort_if(\App\Support\UserBlocks::between($userId, $other), 404, 'Conversation not found.');

        return $conversation;
    }

    private function conversationPayload(object $conversation, User $viewer): array
    {
        $recipientId = (int) $conversation->user_one_id === (int) $viewer->id
            ? (int) $conversation->user_two_id : (int) $conversation->user_one_id;
        $recipient = User::query()->with('channel')->find($recipientId);
        $latest = DB::table('private_messages')->where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $unread = DB::table('private_messages')->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $viewer->id)->whereNull('read_at')->count();
        $hasOutbound = DB::table('private_messages')->where('conversation_id', $conversation->id)
            ->where('sender_id', $viewer->id)->exists();
        $section = $latest && ! $hasOutbound && (int) $latest->sender_id !== (int) $viewer->id
            ? 'requests' : 'primary';
        if ($latest && filled($viewer->username) && str_contains(strtolower((string) $latest->body), '@'.strtolower($viewer->username))) {
            $section = 'mentions';
        }
        $attachments = $latest ? json_decode((string) ($latest->attachments ?? '[]'), true) : [];
        $preview = $latest ? (trim((string) $latest->body) ?: ($attachments ? 'Image attachment' : '')) : '';

        return [
            'id' => (int) $conversation->id,
            'recipient' => $this->userPayload($recipient, $viewer, false, (int) $conversation->id),
            'preview' => mb_strimwidth($preview, 0, 120, '…'),
            'last_message_at' => $latest?->created_at ? Carbon::parse($latest->created_at)->toIso8601String() : null,
            'unread' => $unread,
            'section' => $section,
        ];
    }

    private function messagePayload(object $message, User $viewer): array
    {
        $sender = User::query()->with('channel')->find($message->sender_id);
        $attachments = collect(json_decode((string) ($message->attachments ?? '[]'), true) ?: [])
            ->map(function ($attachment, int $index) use ($message): array {
                if (! is_array($attachment) || ($attachment['type'] ?? null) !== 'image' || empty($attachment['path'])) {
                    return [];
                }

                return [
                    'type' => 'image',
                    'url' => URL::temporarySignedRoute('media.private-message-attachment', now()->addHours(6), [
                        'message' => $message->id, 'index' => $index,
                    ]),
                    'name' => $attachment['name'] ?? null,
                    'mime_type' => $attachment['mime'] ?? null,
                    'size_bytes' => isset($attachment['size']) ? (int) $attachment['size'] : null,
                ];
            })->filter()->values()->all();

        return [
            'id' => (int) $message->id,
            'body' => (string) $message->body,
            'attachments' => $attachments,
            'own' => (int) $message->sender_id === (int) $viewer->id,
            'sender' => $this->userPayload($sender, $viewer),
            'created_at' => Carbon::parse($message->created_at)->toIso8601String(),
            'read_at' => $message->read_at ? Carbon::parse($message->read_at)->toIso8601String() : null,
        ];
    }

    private function userPayload(?User $user, User $viewer, bool $lookupConversation = false, ?int $conversationId = null): array
    {
        if (! $user) {
            return ['id' => null, 'name' => 'Unknown user', 'username' => null, 'avatar_url' => null,
                'channel_slug' => null, 'conversation_id' => null, 'can_message' => false];
        }
        $channel = $user->channel;
        [$allowed, $reason] = $this->messagePermission($user, $viewer);
        $avatar = $channel?->avatar_path ?: $user->avatar_path;
        if ($lookupConversation) {
            [$first, $second] = collect([(int) $viewer->id, (int) $user->id])->sort()->values()->all();
            $conversationId = DB::table('private_conversations')
                ->where('user_one_id', $first)->where('user_two_id', $second)->value('id');
        }

        return [
            'id' => (int) $user->id,
            'name' => $channel?->name ?: $user->name,
            'username' => $user->username,
            'avatar_url' => $avatar ? MediaStorage::url($avatar) : null,
            'channel_slug' => $channel?->slug,
            'conversation_id' => $conversationId ? (int) $conversationId : null,
            'can_message' => $allowed,
            'message_blocked_reason' => $reason,
        ];
    }

    private function messagePermission(?User $recipient, User $sender): array
    {
        if (! $recipient || (int) $recipient->id === (int) $sender->id) {
            return [false, 'This user cannot receive private messages.'];
        }
        if (\App\Support\UserBlocks::between((int) $recipient->id, (int) $sender->id)) {
            return [false, 'Private messages are unavailable for this person.'];
        }
        $status = strtolower((string) data_get($recipient->settings, 'status', 'active'));
        if (in_array($status, ['suspended', 'deleted'], true)) {
            return [false, 'This user cannot receive private messages.'];
        }
        $requested = data_get($recipient->settings, 'preferences.privateMessages', 'anyone');
        $policy = in_array($requested, ['anyone', 'subscribers', 'disabled'], true) ? $requested : 'anyone';
        if ($policy === 'disabled') {
            return [false, 'This user has disabled private messages.'];
        }
        if ($policy === 'subscribers') {
            $recipient->loadMissing('channel');
            $subscriber = $recipient->channel && DB::table('subscriptions')
                ->where('user_id', $sender->id)->where('channel_id', $recipient->channel->id)->exists();
            if (! $subscriber) {
                return [false, 'This user only accepts private messages from subscribers.'];
            }
        }

        return [true, null];
    }
}
