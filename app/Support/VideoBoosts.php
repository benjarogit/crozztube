<?php

namespace App\Support;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoBoostCampaign;
use App\Services\Api\V1\VideoAccessService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

class VideoBoosts
{
    public static function inject(LengthAwarePaginator $videos, Request $request, ?User $viewer, string $sort, string $type): LengthAwarePaginator
    {
        $surface = $sort === 'trending' ? 'trending' : 'home';
        if (! self::canPlace($request, $sort, $type) || ! MonetizationSettings::videoBoostSettings()['enabled']) {
            return $videos;
        }

        VideoBoostCampaign::query()->where('status', 'active')->where('billing_model', '!=', 'daily')
            ->whereColumn('spent_cents', '>=', 'budget_cents')->update(['status' => 'completed', 'updated_at' => now()]);
        VideoBoostCampaign::query()->where('status', 'active')->where('billing_model', 'daily')
            ->whereColumn('spent_cents', '>=', 'budget_cents')->where(fn ($query) => $query->whereNull('last_daily_charge_on')->orWhere('last_daily_charge_on', '<', now()->toDateString()))
            ->update(['status' => 'completed', 'updated_at' => now()]);

        $campaigns = VideoBoostCampaign::query()
            ->with(['video.channel.user', 'video.user', 'video.category', 'video.language', 'video.liveStream'])
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->when($viewer, fn ($query) => $query->where('user_id', '!=', $viewer->id))
            ->whereHas('video', fn ($query) => $query->public()->standardVideos())
            ->orderByDesc('budget_cents')
            ->limit(100)
            ->get();

        if ($campaigns->isEmpty()) {
            return $videos;
        }

        $today = now()->toDateString();
        $dailySpend = DB::table('video_boost_events')
            ->whereIn('campaign_id', $campaigns->pluck('id'))
            ->where('occurred_on', $today)
            ->groupBy('campaign_id')
            ->pluck(DB::raw('SUM(cost_cents)'), 'campaign_id');
        $campaigns = $campaigns->filter(function (VideoBoostCampaign $campaign) use ($dailySpend, $today): bool {
            if ($campaign->billing_model === 'daily' && $campaign->last_daily_charge_on?->toDateString() === $today) {
                return true;
            }

            return $campaign->budget_cents - $campaign->spent_cents >= $campaign->bid_cents
                && $campaign->daily_budget_cents - (int) ($dailySpend[$campaign->id] ?? 0) >= $campaign->bid_cents;
        })->values();

        if ($campaigns->isEmpty()) {
            return $videos;
        }

        $affinity = self::viewerAffinity($viewer, $videos->getCollection());
        $maxBid = max(1, (int) $campaigns->max('bid_cents'));
        $maxBudget = max(1, (int) $campaigns->max('budget_cents'));
        $hourProgress = max(0.04, (now()->hour + (now()->minute / 60)) / 24);
        $seed = ($viewer?->id ?: $request->ip()).'|'.$surface.'|'.now()->format('Y-m-d-H');

        $ranked = $campaigns->map(function (VideoBoostCampaign $campaign) use ($affinity, $dailySpend, $hourProgress, $maxBid, $maxBudget, $seed): array {
            $video = $campaign->video;
            $bid = log1p($campaign->bid_cents) / log1p($maxBid);
            $investment = log1p($campaign->budget_cents) / log1p($maxBudget);
            $spentRatio = (int) ($dailySpend[$campaign->id] ?? 0) / max(1, $campaign->daily_budget_cents);
            $pacing = 1 - min(1, max(0, $spentRatio - $hourProgress) / max(0.05, 1 - $hourProgress));
            $relevance = max(
                isset($affinity['channels'][(int) $video->channel_id]) ? 1 : 0,
                isset($affinity['categories'][(int) $video->category_id]) ? .75 : 0
            );
            $quality = min(1, log1p($video->views_count + ($video->likes_count * 6) + ($video->comments_count * 12)) / log(100001));
            $rotation = unpack('N', substr(hash('sha256', $seed.'|'.$campaign->id, true), 0, 4))[1] / 4294967295;

            return [
                'campaign' => $campaign,
                'score' => ($bid * .38) + ($investment * .22) + ($relevance * .18) + ($pacing * .12) + ($quality * .07) + ($rotation * .03),
            ];
        })->sortByDesc('score')->values();

        $limit = $videos->perPage() < 8 ? 1 : 2;
        $selected = collect();
        foreach ($ranked as $item) {
            if ($selected->contains(fn ($selectedItem) => $selectedItem['campaign']->user_id === $item['campaign']->user_id)) {
                continue;
            }
            $selected->push($item);
            if ($selected->count() >= $limit) {
                break;
            }
        }

        $collection = $videos->getCollection();
        foreach ($selected as $offset => $item) {
            $campaign = $item['campaign'];
            $video = $campaign->video;
            $video->boost_campaign = self::resourcePayload($campaign, $surface);
            $collection = $collection->reject(fn (Video $organic) => (int) $organic->id === (int) $video->id)->values();
            $collection->splice($offset === 0 ? min(1, $collection->count()) : min(7, $collection->count()), 0, [$video]);
        }
        $videos->setCollection($collection->values());

        return $videos;
    }

    public static function record(VideoBoostCampaign $campaign, Request $request, string $eventType): array
    {
        $surface = strtolower(trim((string) $request->input('surface')));
        abort_unless(in_array($surface, ['home', 'trending'], true), 422, 'Invalid boost surface.');
        $trackingToken = (string) $request->input('token');
        abort_unless(
            hash_equals(self::token($campaign, $surface), $trackingToken)
                || hash_equals(self::token($campaign, $surface, now()->subDay()->toDateString()), $trackingToken),
            403,
            'Invalid boost tracking token.'
        );

        $viewer = $request->user();
        if (! $viewer && $request->bearerToken()) {
            $viewer = PersonalAccessToken::findToken($request->bearerToken())?->tokenable;
        }
        if ($viewer && (int) $viewer->id === (int) $campaign->user_id) {
            return ['tracked' => false, 'billed' => false, 'reason' => 'owner'];
        }

        $visitorHash = hash('sha256', $viewer
            ? 'user:'.$viewer->id
            : 'guest:'.hash_hmac('sha256', $request->ip().'|'.mb_substr((string) $request->userAgent(), 0, 500), self::key()));
        $today = now()->toDateString();
        $dedupeKey = hash('sha256', $campaign->uuid.'|'.$eventType.'|'.$visitorHash.'|'.$today);

        return DB::transaction(function () use ($campaign, $eventType, $surface, $viewer, $visitorHash, $today, $dedupeKey): array {
            $locked = VideoBoostCampaign::query()->with('video')->lockForUpdate()->findOrFail($campaign->id);
            if (DB::table('video_boost_events')->where('dedupe_key', $dedupeKey)->exists()) {
                return ['tracked' => false, 'billed' => false, 'reason' => 'duplicate'];
            }
            if (! self::campaignIsDeliverable($locked)) {
                return ['tracked' => false, 'billed' => false, 'reason' => 'inactive'];
            }

            $cost = 0;
            $billable = ($eventType === 'click' && $locked->billing_model === 'click')
                || ($eventType === 'impression' && $locked->billing_model === 'view')
                || ($eventType === 'impression' && $locked->billing_model === 'daily' && $locked->last_daily_charge_on?->toDateString() !== $today);
            if ($billable) {
                $spentToday = (int) DB::table('video_boost_events')->where('campaign_id', $locked->id)->where('occurred_on', $today)->sum('cost_cents');
                if ($locked->budget_cents - $locked->spent_cents < $locked->bid_cents
                    || $locked->daily_budget_cents - $spentToday < $locked->bid_cents) {
                    if ($locked->billing_model !== 'daily') {
                        $locked->update(['status' => 'completed']);
                    }

                    return ['tracked' => false, 'billed' => false, 'reason' => 'budget'];
                }
                $cost = $locked->bid_cents;
            }

            $inserted = DB::table('video_boost_events')->insertOrIgnore([
                'campaign_id' => $locked->id,
                'viewer_id' => $viewer?->id,
                'event_type' => $eventType,
                'surface' => $surface,
                'visitor_hash' => $visitorHash,
                'dedupe_key' => $dedupeKey,
                'cost_cents' => $cost,
                'occurred_on' => $today,
                'created_at' => now(),
            ]);
            if (! $inserted) {
                return ['tracked' => false, 'billed' => false, 'reason' => 'duplicate'];
            }

            $updates = $eventType === 'click' ? ['clicks_count' => DB::raw('clicks_count + 1')] : [
                'impressions_count' => DB::raw('impressions_count + 1'),
                'views_count' => DB::raw('views_count + 1'),
            ];
            if ($cost > 0) {
                $updates['spent_cents'] = DB::raw('spent_cents + '.$cost);
                if ($locked->billing_model === 'daily') {
                    $updates['last_daily_charge_on'] = $today;
                }
                if ($locked->billing_model !== 'daily' && $locked->spent_cents + $cost >= $locked->budget_cents) {
                    $updates['status'] = 'completed';
                }
            }
            VideoBoostCampaign::query()->whereKey($locked->id)->update($updates);

            if ($cost > 0) {
                DB::table('wallet_transactions')->insert([
                    'user_id' => $locked->user_id,
                    'type' => 'spend',
                    'amount_cents' => $cost,
                    'currency' => $locked->currency,
                    'status' => 'completed',
                    'provider' => 'video_boost',
                    'provider_reference' => $dedupeKey,
                    'note' => 'Video boost '.$locked->billing_model.' charge',
                    'payload' => json_encode(['event' => 'video_boost_'.$eventType, 'campaign_id' => $locked->id, 'video_id' => $locked->video_id, 'surface' => $surface], JSON_UNESCAPED_SLASHES),
                    'processed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return ['tracked' => true, 'billed' => $cost > 0];
        });
    }

    private static function canPlace(Request $request, string $sort, string $type): bool
    {
        return $request->integer('page', 1) === 1
            && in_array($sort, ['recommended', 'trending'], true)
            && ! in_array($type, ['short', 'shorts'], true)
            && ! $request->filled('q')
            && ! $request->filled('channel')
            && ! $request->boolean('featured')
            && Schema::hasTable('video_boost_campaigns')
            && Schema::hasTable('video_boost_events');
    }

    private static function viewerAffinity(?User $viewer, Collection $organic): array
    {
        $channels = $organic->pluck('channel_id')->filter()->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        $categories = $organic->pluck('category_id')->filter()->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        if (! $viewer) {
            return compact('channels', 'categories');
        }

        foreach (DB::table('subscriptions')->where('user_id', $viewer->id)->pluck('channel_id') as $id) {
            $channels[(int) $id] = true;
        }
        $history = DB::table('video_watch_progress')->join('videos', 'videos.id', '=', 'video_watch_progress.video_id')
            ->where('video_watch_progress.user_id', $viewer->id)->latest('video_watch_progress.updated_at')->limit(100)
            ->get(['videos.channel_id', 'videos.category_id']);
        foreach ($history as $item) {
            if ($item->channel_id) {
                $channels[(int) $item->channel_id] = true;
            }
            if ($item->category_id) {
                $categories[(int) $item->category_id] = true;
            }
        }

        return compact('channels', 'categories');
    }

    private static function resourcePayload(VideoBoostCampaign $campaign, string $surface): array
    {
        return [
            'campaign_id' => $campaign->uuid,
            'label' => 'Promoted',
            'surface' => $surface,
            'tracking_token' => self::token($campaign, $surface),
        ];
    }

    private static function campaignIsDeliverable(VideoBoostCampaign $campaign): bool
    {
        return MonetizationSettings::videoBoostSettings()['enabled']
            && $campaign->status === 'active'
            && $campaign->starts_at?->lte(now())
            && (! $campaign->ends_at || $campaign->ends_at->gte(now()))
            && $campaign->video
            && app(VideoAccessService::class)->canView($campaign->video, null, false)
            && ! $campaign->video->isShort()
            && $campaign->video->source_type !== 'live_replay';
    }

    private static function token(VideoBoostCampaign $campaign, string $surface, ?string $date = null): string
    {
        return hash_hmac('sha256', $campaign->uuid.'|'.$campaign->video_id.'|'.$surface.'|'.($date ?: now()->toDateString()), self::key());
    }

    private static function key(): string
    {
        return (string) config('app.key', 'playhub-video-boosts');
    }
}
