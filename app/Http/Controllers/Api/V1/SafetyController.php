<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserPublicResource;
use App\Models\User;
use App\Services\Api\V1\SafetyTargetResolver;
use App\Support\PublishingAccess;
use App\Support\UgcConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SafetyController extends Controller
{
    private function response(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function policy(): JsonResponse { return $this->response(['policy' => UgcConsent::policy()]); }
    public function show(Request $request): JsonResponse { return $this->response(UgcConsent::status($request->user())); }

    public function consent(Request $request): JsonResponse
    {
        $data = $request->validate(['policy_version' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'], 'accepted' => ['required', 'accepted']]);
        // JSON false, absent values and arbitrary strings never create a receipt.
        abort_unless($request->input('accepted') === true, 422, 'Explicit acceptance is required.');
        return $this->response(UgcConsent::accept($request->user(), $data['policy_version']));
    }

    public function blocks(Request $request): JsonResponse
    {
        $users = User::query()->whereIn('id', DB::table('user_blocks')->where('user_id', $request->user()->id)->select('blocked_user_id'))
            ->with(['channel' => fn ($q) => $q->withoutGlobalScope('blocked_authors')])->orderBy('name')->get();
        return $this->response(['users' => UserPublicResource::collection($users)->resolve($request)]);
    }

    public function block(Request $request, SafetyTargetResolver $targets): JsonResponse
    {
        $data = $request->validate(['target_type' => ['required', Rule::in(['user', 'channel', 'video', 'community_post', 'comment', 'message', 'live_stream'])], 'target_id' => ['required', 'integer', 'min:1']]);
        $target = $targets->user($data['target_type'], (int) $data['target_id'], $request->user());
        abort_if((int) $target->id === (int) $request->user()->id, 422, 'You cannot block yourself.');
        DB::transaction(function () use ($request, $target): void {
            \App\Support\UserBlocks::lockPair((int) $request->user()->id, (int) $target->id);
            DB::table('user_blocks')->insertOrIgnore(['user_id' => $request->user()->id, 'blocked_user_id' => $target->id, 'created_at' => now(), 'updated_at' => now()]);
        });
        return $this->response(['blocked' => true, 'blocked_user_id' => (int) $target->id]);
    }

    public function unblock(Request $request, int $user): JsonResponse
    {
        DB::table('user_blocks')->where('user_id', $request->user()->id)->where('blocked_user_id', $user)->delete();
        return $this->response(['blocked' => false, 'blocked_user_id' => $user]);
    }

    public function reportPost(Request $request, int $id, SafetyTargetResolver $targets): JsonResponse { return $this->report($request, 'community_post', $id, $targets); }
    public function reportMessage(Request $request, int $id, SafetyTargetResolver $targets): JsonResponse { return $this->report($request, 'message', $id, $targets); }
    public function reportLive(Request $request, int $id, SafetyTargetResolver $targets): JsonResponse { return $this->report($request, 'live_stream', $id, $targets); }
    public function reportUser(Request $request, int $id, SafetyTargetResolver $targets): JsonResponse { return $this->report($request, 'user', $id, $targets); }

    private function report(Request $request, string $type, int $id, SafetyTargetResolver $targets): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $target = $targets->user($type, $id, $request->user());
        abort_if((int) $target->id === (int) $request->user()->id, 422, 'You cannot report your own content.');
        DB::table('content_safety_reports')->insertOrIgnore(['user_id' => $request->user()->id, 'reported_user_id' => $target->id,
            'target_type' => $type, 'target_id' => $id, 'reason' => $data['reason'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        return $this->response(['reported' => true]);
    }

    public function reports(Request $request): JsonResponse
    {
        abort_unless(in_array(PublishingAccess::role($request->user()), ['admin', 'moderator'], true), 403, 'Moderation access required.');
        return $this->response(['reports' => DB::table('content_safety_reports')->latest('id')->limit(100)->get()->all()]);
    }
}
