<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Models\VideoBoostCampaign;
use App\Support\IntegrationSettings;
use App\Support\MonetizationSettings;
use App\Support\VideoBoosts;
use App\Support\WalletLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VideoBoostController extends Controller
{
    public function show(Request $request, Video $video): JsonResponse
    {
        $this->authorizeVideo($request, $video);
        $this->normalizeCampaigns($request->user()->id);

        return response()->json($this->managementPayload($request, $video));
    }

    public function store(Request $request): JsonResponse
    {
        $settings = MonetizationSettings::videoBoostSettings();
        abort_unless($settings['enabled'], 403, 'Video boosts are disabled by the administrator.');
        $this->normalizeCampaigns($request->user()->id);
        VideoBoostCampaign::query()->where('user_id', $request->user()->id)
            ->whereIn('status', ['active', 'paused'])->whereNotNull('ends_at')->where('ends_at', '<', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
        $data = $request->validate([
            'video_id' => ['required', 'integer', 'exists:videos,id'],
            'billing_model' => ['required', Rule::in(['daily', 'click', 'view'])],
            'bid' => ['required', 'numeric', 'min:'.($settings['minimum_bid_cents'] / 100), 'max:'.($settings['maximum_bid_cents'] / 100)],
            'daily_budget' => ['required', 'numeric', 'min:0.01', 'max:'.($settings['maximum_budget_cents'] / 100)],
            'budget' => ['required', 'numeric', 'min:'.($settings['minimum_budget_cents'] / 100), 'max:'.($settings['maximum_budget_cents'] / 100)],
            'ends_at' => ['nullable', 'date', 'after:now'],
        ]);
        $video = Video::query()->findOrFail($data['video_id']);
        $this->authorizeVideo($request, $video);
        $this->authorizeBoostable($video);

        $bid = (int) round(((float) $data['bid']) * 100);
        $dailyBudget = (int) round(((float) $data['daily_budget']) * 100);
        $budget = (int) round(((float) $data['budget']) * 100);
        if ($data['billing_model'] === 'daily') $dailyBudget = $bid;
        abort_if($dailyBudget < $bid, 422, 'Daily budget must cover at least one billing event.');
        abort_if($budget < $dailyBudget, 422, 'Total budget must be at least the daily budget.');

        $user = $request->user();
        $campaign = DB::transaction(function () use ($user, $video, $data, $bid, $dailyBudget, $budget, $settings): VideoBoostCampaign {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_if(VideoBoostCampaign::query()->where('video_id', $video->id)->whereIn('status', ['active', 'paused'])->exists(), 422, 'This video already has an active or paused boost.');
            abort_if(VideoBoostCampaign::query()->where('user_id', $user->id)->whereIn('status', ['active', 'paused'])->count() >= $settings['max_active_campaigns'], 422, 'You reached the active boost campaign limit.');
            abort_if(WalletLedger::totals($user)['balance_cents'] < $budget, 422, 'Insufficient wallet balance for this campaign budget.');

            return VideoBoostCampaign::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'video_id' => $video->id,
                'billing_model' => $data['billing_model'],
                'bid_cents' => $bid,
                'daily_budget_cents' => $dailyBudget,
                'budget_cents' => $budget,
                'currency' => IntegrationSettings::currency(),
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => $data['ends_at'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Video boost started.',
            'campaign' => $this->campaignPayload($campaign),
            'wallet' => $this->walletPayload($user),
        ], 201);
    }

    public function update(Request $request, VideoBoostCampaign $campaign): JsonResponse
    {
        abort_unless((int) $campaign->user_id === (int) $request->user()->id, 403);
        $data = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'cancel'])]]);
        $action = $data['action'];
        if ($action === 'pause') {
            abort_unless($campaign->status === 'active', 422, 'Only active campaigns can be paused.');
            $campaign->update(['status' => 'paused']);
        } elseif ($action === 'resume') {
            abort_unless($campaign->status === 'paused', 422, 'Only paused campaigns can be resumed.');
            abort_unless(MonetizationSettings::videoBoostSettings()['enabled'], 403, 'Video boosts are disabled by the administrator.');
            abort_if($campaign->ends_at && $campaign->ends_at->isPast(), 422, 'This campaign has expired.');
            $this->authorizeBoostable($campaign->video);
            $campaign->update(['status' => 'active']);
        } else {
            abort_unless(in_array($campaign->status, ['active', 'paused'], true), 422, 'This campaign can no longer be cancelled.');
            $campaign->update(['status' => 'cancelled']);
        }

        return response()->json([
            'message' => 'Video boost '.($action === 'cancel' ? 'cancelled' : $action.'d').'.',
            'campaign' => $this->campaignPayload($campaign->fresh()),
            'wallet' => $this->walletPayload($request->user()),
        ]);
    }

    public function impression(Request $request, VideoBoostCampaign $campaign): JsonResponse
    {
        return response()->json(VideoBoosts::record($campaign, $request, 'impression'));
    }

    public function click(Request $request, VideoBoostCampaign $campaign): JsonResponse
    {
        return response()->json(VideoBoosts::record($campaign, $request, 'click'));
    }

    private function managementPayload(Request $request, Video $video): array
    {
        $campaigns = VideoBoostCampaign::query()->where('user_id', $request->user()->id)->where('video_id', $video->id)->latest()->limit(20)->get();

        return [
            'video' => (new VideoResource($video->load(['channel.user', 'user', 'category', 'language'])))->resolve($request),
            'campaigns' => $campaigns->map(fn (VideoBoostCampaign $campaign) => $this->campaignPayload($campaign))->all(),
            'settings' => $this->settingsPayload(),
            'wallet' => $this->walletPayload($request->user()),
        ];
    }

    private function campaignPayload(VideoBoostCampaign $campaign): array
    {
        $remaining = max(0, $campaign->budget_cents - $campaign->spent_cents);

        return [
            'id' => $campaign->uuid,
            'billing_model' => $campaign->billing_model,
            'bid_cents' => $campaign->bid_cents,
            'daily_budget_cents' => $campaign->daily_budget_cents,
            'budget_cents' => $campaign->budget_cents,
            'spent_cents' => $campaign->spent_cents,
            'remaining_cents' => $remaining,
            'currency' => $campaign->currency,
            'status' => $campaign->status,
            'impressions_count' => $campaign->impressions_count,
            'views_count' => $campaign->views_count,
            'clicks_count' => $campaign->clicks_count,
            'starts_at' => $campaign->starts_at?->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }

    private function settingsPayload(): array
    {
        $settings = MonetizationSettings::videoBoostSettings();

        return [
            'enabled' => $settings['enabled'],
            'currency' => $settings['currency'],
            'minimum_bid' => $settings['minimum_bid_cents'] / 100,
            'maximum_bid' => $settings['maximum_bid_cents'] / 100,
            'minimum_budget' => $settings['minimum_budget_cents'] / 100,
            'maximum_budget' => $settings['maximum_budget_cents'] / 100,
        ];
    }

    private function walletPayload($user): array
    {
        $totals = WalletLedger::totals($user);

        return [
            'balance_cents' => $totals['balance_cents'],
            'reserved_boosts_cents' => $totals['reserved_boosts_cents'],
            'currency' => $totals['currency'],
        ];
    }

    private function authorizeVideo(Request $request, Video $video): void
    {
        abort_unless((int) $video->user_id === (int) $request->user()->id, 403, 'Only the video owner can manage its boost.');
    }

    private function authorizeBoostable(Video $video): void
    {
        abort_unless($video->visibility === 'public' && $video->status === 'ready', 422, 'Only ready public videos can be boosted.');
        abort_if($video->isShort() || $video->source_type === 'live_replay', 422, 'Shorts and live replays cannot be boosted.');
    }

    private function normalizeCampaigns(int $userId): void
    {
        VideoBoostCampaign::query()->where('user_id', $userId)->whereIn('status', ['active', 'paused'])
            ->whereNotNull('ends_at')->where('ends_at', '<', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
        VideoBoostCampaign::query()->where('user_id', $userId)->whereIn('status', ['active', 'paused'])
            ->where('billing_model', '!=', 'daily')->whereColumn('spent_cents', '>=', 'budget_cents')
            ->update(['status' => 'completed', 'updated_at' => now()]);
        VideoBoostCampaign::query()->where('user_id', $userId)->whereIn('status', ['active', 'paused'])
            ->where('billing_model', 'daily')->whereColumn('spent_cents', '>=', 'budget_cents')
            ->where(fn ($query) => $query->whereNull('last_daily_charge_on')->orWhere('last_daily_charge_on', '<', now()->toDateString()))
            ->update(['status' => 'completed', 'updated_at' => now()]);
    }
}
