<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\CommunityPost;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Video;
use App\Support\IntegrationSettings;
use App\Support\CommunityPhotoSales;
use App\Support\LiveStreamingSettings;
use App\Support\MediaStorage;
use App\Support\MonetizationSettings;
use App\Support\PlayerAdSettings;
use App\Support\VideoSales;
use App\Support\WalletLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($data['limit'] ?? 50);

        if (! Schema::hasTable('payment_transactions')) {
            return response()->json(['transactions' => [], 'summary' => ['count' => 0, 'total_cents' => 0]]);
        }

        $query = DB::table('payment_transactions')->where('user_id', $request->user()->id);
        $summary = (clone $query)
            ->where('status', 'paid')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(amount_cents), 0) as aggregate_total')
            ->first();
        $transactions = $query
            ->orderByRaw('COALESCE(paid_at, created_at) DESC')
            ->limit($limit)
            ->get()
            ->map(fn ($transaction): array => [
                'id' => (int) $transaction->id,
                'provider' => strtolower((string) $transaction->provider),
                'reference' => (string) $transaction->provider_reference,
                'membership' => (string) $transaction->membership,
                'description' => MediaStorage::membershipLabel((string) $transaction->membership).' plan',
                'amount_cents' => (int) $transaction->amount_cents,
                'amount' => IntegrationSettings::formatMoney((int) $transaction->amount_cents, (string) $transaction->currency),
                'currency' => strtolower((string) $transaction->currency),
                'status' => strtolower((string) $transaction->status),
                'paid_at' => optional($transaction->paid_at ? \Illuminate\Support\Carbon::parse($transaction->paid_at) : null)->toIso8601String(),
                'created_at' => optional($transaction->created_at ? \Illuminate\Support\Carbon::parse($transaction->created_at) : null)->toIso8601String(),
            ])
            ->all();

        return response()->json([
            'transactions' => $transactions,
            'summary' => [
                'count' => (int) ($summary->aggregate_count ?? 0),
                'total_cents' => (int) ($summary->aggregate_total ?? 0),
            ],
        ]);
    }

    public function billingInformation(Request $request): JsonResponse
    {
        return response()->json([
            'billing_information' => $this->billingInformationPayload($request->user()),
        ]);
    }

    public function updateBillingInformation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'company' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line_1' => ['required', 'string', 'max:180'],
            'address_line_2' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:32'],
            'country' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
        ]);

        /** @var User $user */
        $user = DB::transaction(function () use ($request, $data): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $settings = is_array($user->settings) ? $user->settings : [];
            $settings['billing_information'] = [
                'name' => trim($data['name']),
                'email' => strtolower(trim($data['email'])),
                'company' => trim((string) ($data['company'] ?? '')),
                'phone' => trim((string) ($data['phone'] ?? '')),
                'address_line_1' => trim($data['address_line_1']),
                'address_line_2' => trim((string) ($data['address_line_2'] ?? '')),
                'city' => trim($data['city']),
                'region' => trim((string) ($data['region'] ?? '')),
                'postal_code' => strtoupper(trim($data['postal_code'])),
                'country' => strtoupper($data['country']),
                'updated_at' => now()->toIso8601String(),
            ];
            $user->update(['settings' => $settings]);

            return $user;
        });

        return response()->json([
            'message' => 'Billing information updated successfully.',
            'billing_information' => $this->billingInformationPayload($user),
            'user' => new UserResource($user->fresh()->load('channel')),
        ]);
    }

    public function wallet(Request $request): JsonResponse
    {
        return response()->json($this->walletPayload($request->user()));
    }

    public function creatorEarnings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['nullable', Rule::in(['all', 'vast_ad', 'creator_tip', 'video_purchase', 'community_photo_purchase', 'live_contribution', 'live_ad'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
        ]);
        $user = $request->user();
        $channel = $user->channel()->first();
        abort_unless($channel, 403, 'Creator earnings are available after creating a channel.');
        abort_unless(Schema::hasTable('wallet_transactions'), 422, 'Wallet storage is not ready.');

        $source = (string) ($data['source'] ?? 'all');
        $perPage = (int) ($data['per_page'] ?? 20);
        $earnings = DB::table('wallet_transactions')
            ->where('user_id', $user->id)
            ->where('type', 'earning')
            ->where('status', 'completed');
        $filtered = clone $earnings;
        if ($source !== 'all') {
            $filtered->where('provider', $source);
        }

        $paginator = $filtered->latest('created_at')->latest('id')->paginate($perPage);
        $rows = collect($paginator->items());
        $payloads = $rows->mapWithKeys(fn ($row): array => [(int) $row->id => json_decode((string) $row->payload, true) ?: []]);
        $tipIds = $payloads->pluck('creator_tip_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $purchaseIds = $payloads->pluck('video_purchase_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $photoPurchaseIds = $payloads->pluck('community_photo_purchase_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $contributionIds = $payloads->pluck('contribution_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $tips = $tipIds && Schema::hasTable('creator_tips')
            ? DB::table('creator_tips')->whereIn('id', $tipIds)->get()->keyBy('id')
            : collect();
        $purchases = $purchaseIds && Schema::hasTable('video_purchases')
            ? DB::table('video_purchases')->whereIn('id', $purchaseIds)->get()->keyBy('id')
            : collect();
        $photoPurchases = $photoPurchaseIds && Schema::hasTable('community_photo_purchases')
            ? DB::table('community_photo_purchases')->whereIn('id', $photoPurchaseIds)->get()->keyBy('id')
            : collect();
        $contributions = $contributionIds && Schema::hasTable('live_stream_contributions')
            ? DB::table('live_stream_contributions')->whereIn('id', $contributionIds)->get()->keyBy('id')
            : collect();
        $actorIds = $tips->pluck('sender_id')->merge($purchases->pluck('buyer_id'))->merge($photoPurchases->pluck('buyer_id'))->merge($contributions->pluck('sender_id'))->filter()->unique()->all();
        $actors = $actorIds
            ? User::query()->with('channel')->whereIn('id', $actorIds)->get()->keyBy('id')
            : collect();
        $videoIds = $payloads->pluck('video_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
        $videos = $videoIds ? Video::query()->whereIn('id', $videoIds)->get(['id', 'title', 'slug'])->keyBy('id') : collect();
        $communityPostIds = $payloads->pluck('community_post_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
        $communityPosts = $communityPostIds ? CommunityPost::query()->with('channel')->whereIn('id', $communityPostIds)->get()->keyBy('id') : collect();
        $liveStreamIds = $payloads->pluck('live_stream_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
        $liveStreams = $liveStreamIds && Schema::hasTable('live_streams')
            ? DB::table('live_streams')->whereIn('id', $liveStreamIds)->get(['id', 'title', 'slug'])->keyBy('id')
            : collect();

        $transactions = $rows->map(function ($transaction) use ($payloads, $tips, $purchases, $photoPurchases, $contributions, $actors, $videos, $communityPosts, $liveStreams): array {
            $payload = $payloads->get((int) $transaction->id, []);
            $provider = (string) ($transaction->provider ?: 'earning');
            $tip = $tips->get((int) ($payload['creator_tip_id'] ?? 0));
            $purchase = $purchases->get((int) ($payload['video_purchase_id'] ?? 0));
            $photoPurchase = $photoPurchases->get((int) ($payload['community_photo_purchase_id'] ?? 0));
            $contribution = $contributions->get((int) ($payload['contribution_id'] ?? 0));
            $actorId = (int) ($tip?->sender_id ?? $purchase?->buyer_id ?? $photoPurchase?->buyer_id ?? $contribution?->sender_id ?? 0);
            $actor = $actors->get($actorId);
            $video = $videos->get((int) ($payload['video_id'] ?? 0));
            $communityPost = $communityPosts->get((int) ($payload['community_post_id'] ?? 0));
            $liveStream = $liveStreams->get((int) ($payload['live_stream_id'] ?? 0));
            $grossCents = isset($payload['gross_cents'])
                ? (int) $payload['gross_cents']
                : (isset($payload['gross_micros']) ? intdiv((int) $payload['gross_micros'], 10000) : (int) $transaction->amount_cents);
            $feeCents = isset($payload['fee_cents'])
                ? (int) $payload['fee_cents']
                : max(0, $grossCents - (int) $transaction->amount_cents);
            $event = (string) ($payload['event'] ?? $provider);
            $eventLabel = match ($event) {
                'tip' => 'Tip',
                'video_sale' => 'Video sale',
                'photo_sale' => 'Photo sale',
                'vast_ad' => 'VAST ad revenue',
                'live_ad' => 'Live VAST ad revenue',
                'super_chat' => 'Super Chat',
                'super_sticker' => 'Super Sticker',
                'gift' => 'Live gift',
                default => ucfirst(str_replace('_', ' ', $event)),
            };
            $content = $video ? [
                'type' => 'video',
                'id' => (int) $video->id,
                'title' => (string) $video->title,
                'to' => '/watch/'.$video->slug,
            ] : ($communityPost ? [
                'type' => 'community_post',
                'id' => (int) $communityPost->id,
                'title' => (string) ($communityPost->body ?: 'Paid photo post'),
                'to' => $communityPost->channel?->slug ? '/channel/'.$communityPost->channel->slug.'/community?post='.$communityPost->id.'#post-'.$communityPost->id : '/communities',
            ] : ($liveStream ? [
                'type' => 'live_stream',
                'id' => (int) $liveStream->id,
                'title' => (string) ($liveStream->title ?: 'Live stream'),
                'to' => '/live/'.$liveStream->slug,
            ] : null));

            return [
                'id' => (int) $transaction->id,
                'reference' => (string) ($transaction->provider_reference ?? ''),
                'source' => $provider,
                'source_label' => $this->creatorEarningSourceLabel($provider),
                'event' => $event,
                'title' => $eventLabel,
                'detail' => (string) ($tip?->message ?? $contribution?->message ?? ''),
                'actor' => $actor ? [
                    'id' => (int) $actor->id,
                    'name' => (string) ($actor->channel?->name ?: $actor->name),
                    'username' => (string) $actor->username,
                    'avatar_url' => MediaStorage::url($actor->channel?->avatar_path ?: $actor->avatar_path),
                    'to' => $actor->channel?->slug ? '/channel/'.$actor->channel->slug : null,
                ] : null,
                'content' => $content,
                'gross_cents' => $grossCents,
                'gross' => $this->formatWalletMoney($grossCents, (string) $transaction->currency),
                'fee_cents' => $feeCents,
                'fee' => $this->formatWalletMoney($feeCents, (string) $transaction->currency),
                'net_cents' => (int) $transaction->amount_cents,
                'net' => $this->formatWalletMoney((int) $transaction->amount_cents, (string) $transaction->currency),
                'currency' => strtolower((string) $transaction->currency),
                'status' => (string) $transaction->status,
                'created_at' => optional($transaction->created_at ? \Illuminate\Support\Carbon::parse($transaction->created_at) : null)->toIso8601String(),
            ];
        })->values()->all();

        $sourceStats = (clone $earnings)
            ->select('provider')
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(amount_cents), 0) as amount_cents')
            ->groupBy('provider')
            ->get()
            ->keyBy(fn ($row): string => (string) ($row->provider ?: 'earning'));
        $sourceOptions = collect(['vast_ad', 'creator_tip', 'video_purchase', 'community_photo_purchase', 'live_contribution', 'live_ad'])->map(function (string $sourceId) use ($sourceStats): array {
            $stat = $sourceStats->get($sourceId);
            $amountCents = (int) ($stat->amount_cents ?? 0);

            return [
                'id' => $sourceId,
                'label' => $this->creatorEarningSourceLabel($sourceId),
                'transaction_count' => (int) ($stat->transaction_count ?? 0),
                'amount_cents' => $amountCents,
                'amount' => $this->formatWalletMoney($amountCents, IntegrationSettings::currency()),
            ];
        })->all();
        $totals = $this->walletTotals($user);
        $lastThirtyDaysCents = (int) (clone $earnings)->where('created_at', '>=', now()->subDays(30))->sum('amount_cents');

        return response()->json([
            'summary' => [
                'available_balance_cents' => $totals['balance_cents'],
                'available_balance' => $this->formatWalletMoney($totals['balance_cents'], $totals['currency']),
                'lifetime_earnings_cents' => $totals['earnings_cents'],
                'lifetime_earnings' => $this->formatWalletMoney($totals['earnings_cents'], $totals['currency']),
                'last_30_days_cents' => $lastThirtyDaysCents,
                'last_30_days' => $this->formatWalletMoney($lastThirtyDaysCents, $totals['currency']),
                'pending_withdrawals_cents' => $totals['pending_withdrawals_cents'],
                'pending_withdrawals' => $this->formatWalletMoney($totals['pending_withdrawals_cents'], $totals['currency']),
                'subscribers_count' => (int) $channel->subscribers_count,
                'transaction_count' => (int) (clone $earnings)->count(),
                'currency' => strtolower((string) $totals['currency']),
                'sources' => $sourceOptions,
            ],
            'transactions' => $transactions,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function walletTopup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(['stripe', 'paypal'])],
            'amount' => ['required', 'numeric', 'min:1', 'max:5000'],
        ]);

        $amountCents = (int) round(((float) $data['amount']) * 100);
        $walletUrl = rtrim($request->getSchemeAndHttpHost(), '/').'/wallet';

        $checkout = $data['provider'] === 'stripe'
            ? IntegrationSettings::createStripeWalletTopup(
                $request->user(),
                $amountCents,
                $walletUrl.'?wallet_provider=stripe&session_id={CHECKOUT_SESSION_ID}',
                $walletUrl.'?wallet=cancelled'
            )
            : IntegrationSettings::createPaypalWalletTopup(
                $request->user(),
                $amountCents,
                $walletUrl.'?wallet_provider=paypal',
                $walletUrl.'?wallet=cancelled'
            );

        return response()->json($checkout);
    }

    public function confirmStripeWalletTopup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
        ]);

        $result = IntegrationSettings::confirmStripeWalletTopup($request->user(), $data['session_id']);

        return response()->json(array_merge([
            'message' => $result['message'],
        ], $this->walletPayload($request->user())));
    }

    public function capturePaypalWalletTopup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:255'],
        ]);

        $result = IntegrationSettings::capturePaypalWalletTopup($request->user(), $data['order_id']);

        return response()->json(array_merge([
            'message' => $result['message'],
        ], $this->walletPayload($request->user())));
    }

    public function walletWithdraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:50000'],
            'method' => ['required', 'string', 'max:80'],
            'account' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless(Schema::hasTable('wallet_transactions'), 422, 'Wallet storage is not ready.');

        $amountCents = (int) round(((float) $data['amount']) * 100);
        $user = $request->user();

        DB::transaction(function () use ($user, $amountCents, $data): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_if($amountCents > $this->walletTotals($user)['balance_cents'], 422, 'Insufficient wallet balance.');

            DB::table('wallet_transactions')->insert([
                'user_id' => $user->id,
                'type' => 'withdraw',
                'amount_cents' => $amountCents,
                'currency' => IntegrationSettings::currency(),
                'status' => 'pending',
                'method' => trim($data['method']),
                'account' => trim($data['account']),
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(array_merge([
            'message' => 'Withdrawal request submitted.',
        ], $this->walletPayload($user)));
    }

    public function tipCreator(Request $request, Channel $channel): JsonResponse
    {
        abort_unless(Schema::hasTable('creator_tips') && Schema::hasTable('wallet_transactions'), 422, 'Creator tip storage is not ready.');
        abort_unless(LiveStreamingSettings::creatorTipSettings($channel->user)['enabled'], 403, 'This creator is not accepting tips.');
        abort_if((int) $request->user()->id === (int) $channel->user_id, 422, 'You cannot tip your own channel.');

        $settings = LiveStreamingSettings::settings();
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'video_id' => ['nullable', 'integer'],
            'message' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:12', 'max:100'],
        ]);
        $amountCents = (int) round(((float) $data['amount']) * 100);
        $minimum = (int) $settings['monetization']['tip_min_cents'];
        $maximum = (int) $settings['monetization']['tip_max_cents'];
        abort_if($amountCents < $minimum || $amountCents > $maximum, 422, 'Tip amount is outside the allowed range.');

        $video = null;
        if (! empty($data['video_id'])) {
            $video = Video::query()->whereKey($data['video_id'])->where('channel_id', $channel->id)->public()->first();
            abort_unless($video, 422, 'The selected video does not belong to this creator.');
        }

        $sender = $request->user();
        $existing = DB::table('creator_tips')->where('sender_id', $sender->id)->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return response()->json(['message' => 'Tip already processed.', 'tip' => $this->creatorTipPayload($existing)]);
        }

        [$tip, $created] = DB::transaction(function () use ($sender, $channel, $video, $data, $amountCents, $settings): array {
            DB::table('users')->whereIn('id', [$sender->id, $channel->user_id])->orderBy('id')->lockForUpdate()->get();
            $existing = DB::table('creator_tips')->where('sender_id', $sender->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) return [$existing, false];

            abort_if($this->walletTotals($sender)['balance_cents'] < $amountCents, 422, 'Insufficient wallet balance.');
            $feeCents = (int) round($amountCents * ((float) $settings['monetization']['contribution_fee_percent'] / 100));
            $netCents = max(0, $amountCents - $feeCents);
            abort_if($netCents <= 0, 422, 'Tip amount is too small after fees.');

            $uuid = (string) Str::uuid();
            $currency = IntegrationSettings::currency();
            $tipId = DB::table('creator_tips')->insertGetId([
                'uuid' => $uuid,
                'channel_id' => $channel->id,
                'video_id' => $video?->id,
                'sender_id' => $sender->id,
                'recipient_id' => $channel->user_id,
                'gross_cents' => $amountCents,
                'fee_cents' => $feeCents,
                'net_cents' => $netCents,
                'currency' => $currency,
                'idempotency_key' => $data['idempotency_key'],
                'message' => trim((string) ($data['message'] ?? '')) ?: null,
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $payload = json_encode(['event' => 'tip', 'creator_tip_id' => $tipId, 'channel_id' => $channel->id, 'video_id' => $video?->id, 'gross_cents' => $amountCents, 'fee_cents' => $feeCents], JSON_UNESCAPED_SLASHES);
            DB::table('wallet_transactions')->insert([
                ['user_id' => $sender->id, 'type' => 'spend', 'amount_cents' => $amountCents, 'currency' => $currency, 'status' => 'completed', 'provider' => 'creator_tip', 'provider_reference' => 'sent:'.$uuid, 'note' => 'Creator tip sent', 'payload' => $payload, 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['user_id' => $channel->user_id, 'type' => 'earning', 'amount_cents' => $netCents, 'currency' => $currency, 'status' => 'completed', 'provider' => 'creator_tip', 'provider_reference' => 'received:'.$uuid, 'note' => 'Creator tip received', 'payload' => $payload, 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            return [DB::table('creator_tips')->find($tipId), true];
        });

        if ($created) {
            UserNotification::query()->create([
                'user_id' => $channel->user_id,
                'actor_id' => $sender->id,
                'video_id' => $video?->id,
                'channel_id' => $channel->id,
                'type' => 'creator_tip',
                'data' => [
                    'detail' => IntegrationSettings::formatMoney((int) $tip->gross_cents, (string) $tip->currency).($tip->message ? ' - '.$tip->message : ''),
                    'to' => $video ? '/watch/'.$video->slug : '/channel/'.$channel->slug,
                ],
            ]);
        }

        return response()->json([
            'message' => $created ? 'Tip sent to '.$channel->name.'.' : 'Tip already processed.',
            'tip' => $this->creatorTipPayload($tip),
        ], $created ? 201 : 200);
    }

    public function purchaseVideo(Request $request, Video $video): JsonResponse
    {
        abort_unless(Schema::hasTable('video_purchases') && Schema::hasTable('wallet_transactions'), 422, 'Paid video storage is not ready.');
        $settings = MonetizationSettings::paidVideoSettings();
        abort_unless($settings['enabled'], 403, 'Paid video sales are disabled by the administrator.');
        abort_unless($video->visibility === 'public' && $video->status === 'ready' && VideoSales::isPaid($video), 422, 'This video is not available for purchase.');

        $buyer = $request->user();
        abort_if((int) $buyer->id === (int) $video->user_id, 422, 'You already own this video.');
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:12', 'max:100'],
        ]);

        $existing = DB::table('video_purchases')->where('buyer_id', $buyer->id)->where('video_id', $video->id)->first();
        $idempotentPurchase = DB::table('video_purchases')->where('buyer_id', $buyer->id)->where('idempotency_key', $data['idempotency_key'])->first();
        abort_if($idempotentPurchase && (int) $idempotentPurchase->video_id !== (int) $video->id, 409, 'This purchase request key was already used for another video.');
        $existing ??= $idempotentPurchase;
        if ($existing) {
            return response()->json([
                'message' => 'Video already purchased.',
                'purchase' => $this->videoPurchasePayload($existing),
                'video' => new \App\Http\Resources\VideoResource($video->load(['channel.user', 'user', 'category', 'language'])),
                'player_ads' => PlayerAdSettings::publicSettings(),
            ]);
        }

        [$purchase, $created] = DB::transaction(function () use ($buyer, $video, $data, $settings): array {
            DB::table('users')->whereIn('id', [$buyer->id, $video->user_id])->orderBy('id')->lockForUpdate()->get();
            $lockedVideo = Video::query()->lockForUpdate()->findOrFail($video->id);
            abort_unless($lockedVideo->visibility === 'public' && $lockedVideo->status === 'ready' && VideoSales::isPaid($lockedVideo), 422, 'This video is not available for purchase.');

            $existing = DB::table('video_purchases')->where('buyer_id', $buyer->id)->where('video_id', $video->id)->first();
            $idempotentPurchase = DB::table('video_purchases')->where('buyer_id', $buyer->id)->where('idempotency_key', $data['idempotency_key'])->first();
            abort_if($idempotentPurchase && (int) $idempotentPurchase->video_id !== (int) $video->id, 409, 'This purchase request key was already used for another video.');
            $existing ??= $idempotentPurchase;
            if ($existing) return [$existing, false];

            $grossCents = (int) $lockedVideo->price_cents;
            $tax = VideoSales::taxBreakdown($grossCents, $settings);
            $totalCents = (int) $tax['total_cents'];
            abort_if($this->walletTotals($buyer)['balance_cents'] < $totalCents, 422, 'Insufficient wallet balance.');
            $feeCents = (int) round($grossCents * ((float) $settings['commission_percent'] / 100));
            $netCents = max(0, $grossCents - $feeCents);
            abort_if($netCents <= 0, 422, 'Video price is too small after fees.');

            $uuid = (string) Str::uuid();
            $currency = IntegrationSettings::currency();
            $purchaseData = [
                'uuid' => $uuid,
                'video_id' => $lockedVideo->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $lockedVideo->user_id,
                'gross_cents' => $grossCents,
                'fee_cents' => $feeCents,
                'net_cents' => $netCents,
                'currency' => $currency,
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('video_purchases', 'tax_cents')) {
                $purchaseData = array_merge($purchaseData, [
                    'tax_cents' => $tax['amount_cents'],
                    'tax_rate' => $tax['rate_percent'],
                    'tax_type' => $tax['type'],
                ]);
            }
            $purchaseId = DB::table('video_purchases')->insertGetId($purchaseData);
            $payload = json_encode(['event' => 'video_sale', 'video_purchase_id' => $purchaseId, 'video_id' => $lockedVideo->id, 'gross_cents' => $grossCents, 'tax_cents' => $tax['amount_cents'], 'tax_rate' => $tax['rate_percent'], 'tax_type' => $tax['type'], 'total_cents' => $totalCents, 'fee_cents' => $feeCents, 'net_cents' => $netCents], JSON_UNESCAPED_SLASHES);
            DB::table('wallet_transactions')->insert([
                ['user_id' => $buyer->id, 'type' => 'spend', 'amount_cents' => $totalCents, 'currency' => $currency, 'status' => 'completed', 'provider' => 'video_purchase', 'provider_reference' => 'buyer:'.$uuid, 'note' => 'Video purchase', 'payload' => $payload, 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['user_id' => $lockedVideo->user_id, 'type' => 'earning', 'amount_cents' => $netCents, 'currency' => $currency, 'status' => 'completed', 'provider' => 'video_purchase', 'provider_reference' => 'seller:'.$uuid, 'note' => 'Paid video sale', 'payload' => $payload, 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            return [DB::table('video_purchases')->find($purchaseId), true];
        });

        if ($created) {
            UserNotification::query()->create([
                'user_id' => $video->user_id,
                'actor_id' => $buyer->id,
                'video_id' => $video->id,
                'channel_id' => $video->channel_id,
                'type' => 'video_purchase',
                'data' => [
                    'detail' => $video->title.' · '.IntegrationSettings::formatMoney((int) $purchase->gross_cents, (string) $purchase->currency),
                    'to' => '/watch/'.$video->slug,
                ],
            ]);
        }

        return response()->json([
            'message' => $created ? 'Video purchased. You can now watch it.' : 'Video already purchased.',
            'purchase' => $this->videoPurchasePayload($purchase),
            'video' => new \App\Http\Resources\VideoResource($video->load(['channel.user', 'user', 'category', 'language'])),
            'player_ads' => PlayerAdSettings::publicSettings(),
        ], $created ? 201 : 200);
    }

    public function purchaseCommunityPhotos(Request $request, CommunityPost $communityPost): JsonResponse
    {
        $communityPost->loadMissing('channel');
        abort_unless(Schema::hasTable('community_photo_purchases') && Schema::hasTable('wallet_transactions'), 422, 'Paid photo storage is not ready.');
        $settings = MonetizationSettings::paidCommunityPhotoSettings();
        abort_unless($settings['enabled'], 403, 'Paid photo sales are disabled by the administrator.');
        abort_unless(CommunityPhotoSales::isPaid($communityPost), 422, 'These photos are not available for purchase.');

        $buyer = $request->user();
        abort_if((int) $buyer->id === (int) $communityPost->user_id, 422, 'You already own these photos.');
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:12', 'max:100'],
        ]);

        $existing = DB::table('community_photo_purchases')->where('buyer_id', $buyer->id)->where('community_post_id', $communityPost->id)->first();
        $idempotentPurchase = DB::table('community_photo_purchases')->where('buyer_id', $buyer->id)->where('idempotency_key', $data['idempotency_key'])->first();
        abort_if($idempotentPurchase && (int) $idempotentPurchase->community_post_id !== (int) $communityPost->id, 409, 'This purchase request key was already used for another photo post.');
        $existing ??= $idempotentPurchase;
        if ($existing) {
            return response()->json([
                'message' => 'Photos already purchased.',
                'purchase' => $this->communityPhotoPurchasePayload($existing),
                'post' => (new \App\Http\Resources\CommunityPostResource($communityPost->load(['user', 'channel'])))->resolve($request),
            ]);
        }

        [$purchase, $created] = DB::transaction(function () use ($buyer, $communityPost, $data, $settings): array {
            DB::table('users')->whereIn('id', [$buyer->id, $communityPost->user_id])->orderBy('id')->lockForUpdate()->get();
            $lockedPost = CommunityPost::query()->lockForUpdate()->findOrFail($communityPost->id);
            abort_unless(CommunityPhotoSales::isPaid($lockedPost), 422, 'These photos are not available for purchase.');

            $existing = DB::table('community_photo_purchases')->where('buyer_id', $buyer->id)->where('community_post_id', $lockedPost->id)->first();
            $idempotentPurchase = DB::table('community_photo_purchases')->where('buyer_id', $buyer->id)->where('idempotency_key', $data['idempotency_key'])->first();
            abort_if($idempotentPurchase && (int) $idempotentPurchase->community_post_id !== (int) $lockedPost->id, 409, 'This purchase request key was already used for another photo post.');
            $existing ??= $idempotentPurchase;
            if ($existing) return [$existing, false];

            $grossCents = (int) $lockedPost->price_cents;
            $tax = VideoSales::taxBreakdown($grossCents, $settings);
            $totalCents = (int) $tax['total_cents'];
            abort_if($this->walletTotals($buyer)['balance_cents'] < $totalCents, 422, 'Insufficient wallet balance.');
            $feeCents = (int) round($grossCents * ((float) $settings['commission_percent'] / 100));
            $netCents = max(0, $grossCents - $feeCents);
            abort_if($netCents <= 0, 422, 'Photo price is too small after fees.');

            $uuid = (string) Str::uuid();
            $currency = IntegrationSettings::currency();
            $purchaseId = DB::table('community_photo_purchases')->insertGetId([
                'uuid' => $uuid,
                'community_post_id' => $lockedPost->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $lockedPost->user_id,
                'gross_cents' => $grossCents,
                'tax_cents' => $tax['amount_cents'],
                'tax_rate' => $tax['rate_percent'],
                'tax_type' => $tax['type'],
                'fee_cents' => $feeCents,
                'net_cents' => $netCents,
                'currency' => $currency,
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $payload = json_encode(['event' => 'photo_sale', 'community_photo_purchase_id' => $purchaseId, 'community_post_id' => $lockedPost->id, 'gross_cents' => $grossCents, 'tax_cents' => $tax['amount_cents'], 'tax_rate' => $tax['rate_percent'], 'tax_type' => $tax['type'], 'total_cents' => $totalCents, 'fee_cents' => $feeCents, 'net_cents' => $netCents], JSON_UNESCAPED_SLASHES);
            DB::table('wallet_transactions')->insert([
                ['user_id' => $buyer->id, 'type' => 'spend', 'amount_cents' => $totalCents, 'currency' => $currency, 'status' => 'completed', 'provider' => 'community_photo_purchase', 'provider_reference' => 'buyer:'.$uuid, 'note' => 'Community photo purchase', 'payload' => $payload, 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['user_id' => $lockedPost->user_id, 'type' => 'earning', 'amount_cents' => $netCents, 'currency' => $currency, 'status' => 'completed', 'provider' => 'community_photo_purchase', 'provider_reference' => 'seller:'.$uuid, 'note' => 'Community photo sale', 'payload' => $payload, 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            return [DB::table('community_photo_purchases')->find($purchaseId), true];
        });

        if ($created) {
            UserNotification::query()->create([
                'user_id' => $communityPost->user_id,
                'actor_id' => $buyer->id,
                'channel_id' => $communityPost->channel_id,
                'community_post_id' => $communityPost->id,
                'type' => 'community_photo_purchase',
                'data' => [
                    'detail' => 'Photo post · '.IntegrationSettings::formatMoney((int) $purchase->gross_cents, (string) $purchase->currency),
                    'to' => '/channel/'.$communityPost->channel?->slug.'/community?post='.$communityPost->id.'#post-'.$communityPost->id,
                ],
            ]);
        }

        return response()->json([
            'message' => $created ? 'Photos purchased. You now have permanent access.' : 'Photos already purchased.',
            'purchase' => $this->communityPhotoPurchasePayload($purchase),
            'post' => (new \App\Http\Resources\CommunityPostResource($communityPost->load(['user', 'channel'])))->resolve($request),
        ], $created ? 201 : 200);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(['stripe', 'paypal'])],
            'membership' => ['required', 'string', Rule::in(['starter', 'plus', 'premium', 'pro', 'vip'])],
        ]);

        $membership = MediaStorage::normalizeMembership($data['membership']);
        abort_if($membership === MediaStorage::membershipFor($request->user()), 422, 'This plan is already active.');
        abort_if(MediaStorage::membershipPriceUsd($membership) <= 0, 422, 'Payment is not required for this plan.');

        $plansUrl = rtrim($request->getSchemeAndHttpHost(), '/').'/plans';

        $checkout = $data['provider'] === 'stripe'
            ? IntegrationSettings::createStripeCheckout(
                $request->user(),
                $membership,
                $plansUrl.'?payment_provider=stripe&session_id={CHECKOUT_SESSION_ID}',
                $plansUrl.'?payment=cancelled'
            )
            : IntegrationSettings::createPaypalOrder(
                $request->user(),
                $membership,
                $plansUrl.'?payment_provider=paypal',
                $plansUrl.'?payment=cancelled'
            );

        return response()->json($checkout);
    }

    public function confirmStripe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
        ]);

        $result = IntegrationSettings::confirmStripeCheckout($request->user(), $data['session_id']);

        return response()->json([
            'message' => $result['message'],
            'membership' => $result['membership'],
            'user' => new UserResource($result['user']),
        ]);
    }

    public function capturePaypal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:255'],
        ]);

        $result = IntegrationSettings::capturePaypalOrder($request->user(), $data['order_id']);

        return response()->json([
            'message' => $result['message'],
            'membership' => $result['membership'],
            'user' => new UserResource($result['user']),
        ]);
    }

    private function walletPayload($user): array
    {
        $totals = $this->walletTotals($user);
        $transactions = Schema::hasTable('wallet_transactions')
            ? DB::table('wallet_transactions')
                ->where('user_id', $user->id)
                ->latest()
                ->limit(30)
                ->get()
                ->map(function ($transaction): array {
                    $payload = json_decode((string) $transaction->payload, true) ?: [];

                    return [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'status' => $transaction->status,
                        'provider' => $transaction->provider,
                        'method' => $transaction->method,
                        'event' => $payload['event'] ?? null,
                        'video_id' => $payload['video_id'] ?? null,
                        'amount_cents' => (int) $transaction->amount_cents,
                        'amount' => $this->formatWalletMoney((int) $transaction->amount_cents, $transaction->currency),
                        'currency' => $transaction->currency,
                        'created_at' => optional($transaction->created_at ? \Illuminate\Support\Carbon::parse($transaction->created_at) : null)->toIso8601String(),
                        'processed_at' => optional($transaction->processed_at ? \Illuminate\Support\Carbon::parse($transaction->processed_at) : null)->toIso8601String(),
                    ];
                })
                ->all()
            : [];
        $paymentSettings = IntegrationSettings::publicSettings()['payments'] ?? [];

        return [
            'wallet' => [
                'balance_cents' => $totals['balance_cents'],
                'pending_withdrawals_cents' => $totals['pending_withdrawals_cents'],
                'earnings_cents' => $totals['earnings_cents'],
                'reserved_boosts_cents' => $totals['reserved_boosts_cents'],
                'balance' => $this->formatWalletMoney($totals['balance_cents'], $totals['currency']),
                'pending_withdrawals' => $this->formatWalletMoney($totals['pending_withdrawals_cents'], $totals['currency']),
                'earnings' => $this->formatWalletMoney($totals['earnings_cents'], $totals['currency']),
                'reserved_boosts' => $this->formatWalletMoney($totals['reserved_boosts_cents'], $totals['currency']),
                'currency' => $totals['currency'],
                'transactions' => $transactions,
            ],
            'payments' => $paymentSettings,
        ];
    }

    private function billingInformationPayload(User $user): array
    {
        $billing = is_array(data_get($user->settings, 'billing_information'))
            ? data_get($user->settings, 'billing_information')
            : [];
        $storedCountry = data_get($user->settings, 'registration_location.country_iso')
            ?: data_get($user->settings, 'registration_phone.country_iso')
            ?: 'US';

        return [
            'name' => (string) ($billing['name'] ?? $user->name),
            'email' => (string) ($billing['email'] ?? $user->email),
            'company' => (string) ($billing['company'] ?? ''),
            'phone' => (string) ($billing['phone'] ?? ''),
            'address_line_1' => (string) ($billing['address_line_1'] ?? ''),
            'address_line_2' => (string) ($billing['address_line_2'] ?? ''),
            'city' => (string) ($billing['city'] ?? ''),
            'region' => (string) ($billing['region'] ?? ''),
            'postal_code' => (string) ($billing['postal_code'] ?? ''),
            'country' => strtoupper((string) ($billing['country'] ?? $storedCountry)),
            'updated_at' => $billing['updated_at'] ?? null,
        ];
    }

    private function walletTotals($user): array
    {
        return WalletLedger::totals($user);
    }

    private function formatWalletMoney(int $amountCents, string $currency): string
    {
        return IntegrationSettings::formatMoney($amountCents, $currency);
    }

    private function creatorEarningSourceLabel(string $source): string
    {
        return match ($source) {
            'vast_ad' => 'VAST ads',
            'creator_tip' => 'Tips',
            'video_purchase' => 'Video sales',
            'community_photo_purchase' => 'Photo sales',
            'live_contribution' => 'Live contributions',
            'live_ad' => 'Live ads',
            default => 'Creator earnings',
        };
    }

    private function creatorTipPayload(object $tip): array
    {
        return [
            'id' => (int) $tip->id,
            'channel_id' => (int) $tip->channel_id,
            'video_id' => $tip->video_id ? (int) $tip->video_id : null,
            'gross_cents' => (int) $tip->gross_cents,
            'net_cents' => (int) $tip->net_cents,
            'currency' => strtolower((string) $tip->currency),
            'amount' => $this->formatWalletMoney((int) $tip->gross_cents, (string) $tip->currency),
            'message' => $tip->message,
            'created_at' => optional($tip->created_at ? \Illuminate\Support\Carbon::parse($tip->created_at) : null)->toIso8601String(),
        ];
    }

    private function videoPurchasePayload(object $purchase): array
    {
        $taxCents = (int) ($purchase->tax_cents ?? 0);
        $totalCents = (int) $purchase->gross_cents + $taxCents;
        $taxType = (string) ($purchase->tax_type ?? '');
        $taxLabel = match ($taxType) {
            'gst' => 'GST',
            'sales_tax' => 'Sales Tax',
            default => $taxCents > 0 ? 'VAT' : null,
        };

        return [
            'id' => (int) $purchase->id,
            'video_id' => (int) $purchase->video_id,
            'gross_cents' => (int) $purchase->gross_cents,
            'tax_cents' => $taxCents,
            'tax_rate' => (float) ($purchase->tax_rate ?? 0),
            'tax_type' => $taxType ?: null,
            'tax_label' => $taxLabel,
            'total_cents' => $totalCents,
            'fee_cents' => (int) $purchase->fee_cents,
            'net_cents' => (int) $purchase->net_cents,
            'currency' => strtolower((string) $purchase->currency),
            'amount' => $this->formatWalletMoney($totalCents, (string) $purchase->currency),
            'created_at' => optional($purchase->created_at ? \Illuminate\Support\Carbon::parse($purchase->created_at) : null)->toIso8601String(),
        ];
    }

    private function communityPhotoPurchasePayload(object $purchase): array
    {
        $taxCents = (int) ($purchase->tax_cents ?? 0);
        $totalCents = (int) $purchase->gross_cents + $taxCents;
        $taxType = (string) ($purchase->tax_type ?? '');

        return [
            'id' => (int) $purchase->id,
            'community_post_id' => (int) $purchase->community_post_id,
            'gross_cents' => (int) $purchase->gross_cents,
            'tax_cents' => $taxCents,
            'tax_rate' => (float) ($purchase->tax_rate ?? 0),
            'tax_type' => $taxType ?: null,
            'tax_label' => match ($taxType) {
                'gst' => 'GST',
                'sales_tax' => 'Sales Tax',
                default => $taxCents > 0 ? 'VAT' : null,
            },
            'total_cents' => $totalCents,
            'fee_cents' => (int) $purchase->fee_cents,
            'net_cents' => (int) $purchase->net_cents,
            'currency' => strtolower((string) $purchase->currency),
            'amount' => $this->formatWalletMoney($totalCents, (string) $purchase->currency),
            'created_at' => optional($purchase->created_at ? \Illuminate\Support\Carbon::parse($purchase->created_at) : null)->toIso8601String(),
        ];
    }
}
