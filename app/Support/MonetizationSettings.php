<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MonetizationSettings
{
    public static function settings(): array
    {
        $path = self::settingsPath();
        $stored = is_file($path) && is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];

        return self::normalize(array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []));
    }

    public static function adminSettings(): array
    {
        $settings = self::settings();

        return [
            'enabled' => (bool) $settings['enabled'],
            'currency' => IntegrationSettings::currency(),
            'vast_ads' => [
                'creator_share_percent' => (float) $settings['vast_ads']['creator_share_percent'],
            ],
            'paid_videos' => [
                'enabled' => (bool) $settings['paid_videos']['enabled'],
                'commission_percent' => (float) $settings['paid_videos']['commission_percent'],
                'minimum_price' => number_format($settings['paid_videos']['minimum_price_cents'] / 100, 2, '.', ''),
                'maximum_price' => number_format($settings['paid_videos']['maximum_price_cents'] / 100, 2, '.', ''),
                'sales_tax' => $settings['paid_videos']['sales_tax'],
            ],
            'video_boosts' => [
                'enabled' => (bool) $settings['video_boosts']['enabled'],
                'minimum_bid' => number_format($settings['video_boosts']['minimum_bid_cents'] / 100, 2, '.', ''),
                'maximum_bid' => number_format($settings['video_boosts']['maximum_bid_cents'] / 100, 2, '.', ''),
                'minimum_budget' => number_format($settings['video_boosts']['minimum_budget_cents'] / 100, 2, '.', ''),
                'maximum_budget' => number_format($settings['video_boosts']['maximum_budget_cents'] / 100, 2, '.', ''),
                'max_active_campaigns' => (int) $settings['video_boosts']['max_active_campaigns'],
            ],
        ];
    }

    public static function videoBoostSettings(): array
    {
        $settings = self::settings()['video_boosts'];
        $storageReady = Schema::hasTable('video_boost_campaigns')
            && Schema::hasTable('video_boost_events')
            && Schema::hasTable('wallet_transactions');

        return array_merge($settings, [
            'enabled' => (bool) $settings['enabled'] && $storageReady,
            'currency' => IntegrationSettings::currency(),
        ]);
    }

    public static function vastAdSettings(): array
    {
        $settings = self::settings();

        return [
            'enabled' => (bool) $settings['enabled']
                && Schema::hasTable('video_ad_impressions')
                && Schema::hasTable('creator_ad_accruals')
                && Schema::hasTable('wallet_transactions'),
            'currency' => IntegrationSettings::currency(),
            'creator_share_percent' => (float) $settings['vast_ads']['creator_share_percent'],
        ];
    }

    public static function paidVideoSettings(): array
    {
        $settings = self::settings()['paid_videos'];
        static $storageReady;

        $storageReady ??= Schema::hasTable('video_purchases')
            && Schema::hasTable('wallet_transactions')
            && Schema::hasColumn('videos', 'sale_enabled');

        return [
            'enabled' => (bool) $settings['enabled'] && $storageReady,
            'currency' => IntegrationSettings::currency(),
            'commission_percent' => (float) $settings['commission_percent'],
            'minimum_price_cents' => (int) $settings['minimum_price_cents'],
            'maximum_price_cents' => (int) $settings['maximum_price_cents'],
            'minimum_price' => number_format($settings['minimum_price_cents'] / 100, 2, '.', ''),
            'maximum_price' => number_format($settings['maximum_price_cents'] / 100, 2, '.', ''),
            'sales_tax' => [
                'enabled' => (bool) $settings['sales_tax']['enabled'],
                'type' => $settings['sales_tax']['type'],
                'label' => self::taxLabel($settings['sales_tax']['type']),
                'rate_percent' => (float) $settings['sales_tax']['rate_percent'],
            ],
        ];
    }

    public static function paidCommunityPhotoSettings(): array
    {
        $settings = self::settings()['paid_videos'];
        static $storageReady;

        $storageReady ??= Schema::hasTable('community_photo_purchases')
            && Schema::hasTable('wallet_transactions')
            && Schema::hasColumn('community_posts', 'sale_enabled');

        return [
            'enabled' => (bool) $settings['enabled'] && $storageReady,
            'currency' => IntegrationSettings::currency(),
            'commission_percent' => (float) $settings['commission_percent'],
            'minimum_price_cents' => (int) $settings['minimum_price_cents'],
            'maximum_price_cents' => (int) $settings['maximum_price_cents'],
            'minimum_price' => number_format($settings['minimum_price_cents'] / 100, 2, '.', ''),
            'maximum_price' => number_format($settings['maximum_price_cents'] / 100, 2, '.', ''),
            'sales_tax' => [
                'enabled' => (bool) $settings['sales_tax']['enabled'],
                'type' => $settings['sales_tax']['type'],
                'label' => self::taxLabel($settings['sales_tax']['type']),
                'rate_percent' => (float) $settings['sales_tax']['rate_percent'],
            ],
        ];
    }

    public static function save(array $settings): void
    {
        if (isset($settings['paid_videos']) && is_array($settings['paid_videos'])) {
            if (array_key_exists('minimum_price', $settings['paid_videos'])) {
                $settings['paid_videos']['minimum_price_cents'] = (int) round(((float) $settings['paid_videos']['minimum_price']) * 100);
                unset($settings['paid_videos']['minimum_price']);
            }
            if (array_key_exists('maximum_price', $settings['paid_videos'])) {
                $settings['paid_videos']['maximum_price_cents'] = (int) round(((float) $settings['paid_videos']['maximum_price']) * 100);
                unset($settings['paid_videos']['maximum_price']);
            }
        }
        if (isset($settings['video_boosts']) && is_array($settings['video_boosts'])) {
            foreach (['minimum_bid', 'maximum_bid', 'minimum_budget', 'maximum_budget'] as $field) {
                if (array_key_exists($field, $settings['video_boosts'])) {
                    $settings['video_boosts'][$field.'_cents'] = (int) round(((float) $settings['video_boosts'][$field]) * 100);
                    unset($settings['video_boosts'][$field]);
                }
            }
        }

        $settings = self::normalize(array_replace_recursive(self::settings(), $settings));
        $path = self::settingsPath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    public static function userEarnings(int $userId): array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return ['total_cents' => 0, 'total' => self::formatMoney(0), 'events' => []];
        }

        $rows = DB::table('wallet_transactions')
            ->where('user_id', $userId)
            ->where('type', 'earning')
            ->where('status', 'completed')
            ->get(['amount_cents', 'payload']);

        $events = [];
        $total = 0;
        foreach ($rows as $row) {
            $payload = self::payload($row->payload);
            $event = (string) ($payload['event'] ?? 'earning');
            $total += (int) $row->amount_cents;
            $events[$event] = ($events[$event] ?? 0) + (int) $row->amount_cents;
        }

        return [
            'total_cents' => $total,
            'total' => self::formatMoney($total),
            'events' => collect($events)->map(fn (int $cents): string => self::formatMoney($cents))->all(),
        ];
    }

    public static function videoEarningsForUser(int $userId, array $videoIds): array
    {
        $videoIds = array_values(array_filter(array_map('intval', $videoIds)));
        if (! $videoIds || ! Schema::hasTable('wallet_transactions')) {
            return [];
        }

        $stats = [];
        $rows = DB::table('wallet_transactions')
            ->where('user_id', $userId)
            ->where('type', 'earning')
            ->where('status', 'completed')
            ->whereIn('provider', ['monetization', 'vast_ad', 'video_purchase'])
            ->get(['amount_cents', 'payload']);

        foreach ($rows as $row) {
            $payload = self::payload($row->payload);
            $videoId = (int) ($payload['video_id'] ?? 0);
            if (! in_array($videoId, $videoIds, true)) {
                continue;
            }

            $event = (string) ($payload['event'] ?? 'earning');
            $stats[$videoId] ??= ['total_cents' => 0, 'events' => []];
            $stats[$videoId]['total_cents'] += (int) $row->amount_cents;
            $stats[$videoId]['events'][$event] = ($stats[$videoId]['events'][$event] ?? 0) + 1;
        }

        foreach ($stats as &$stat) {
            $stat['total'] = self::formatMoney($stat['total_cents']);
        }

        return $stats;
    }

    public static function earningSummary(Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return ['total_cents' => 0, 'commission_cents' => 0, 'vast_gross_cents' => 0, 'vast_impressions' => 0, 'count' => 0, 'series' => [], 'transactions' => []];
        }

        $rows = DB::table('wallet_transactions')
            ->leftJoin('users', 'users.id', '=', 'wallet_transactions.user_id')
            ->select('wallet_transactions.*', 'users.name')
            ->where('wallet_transactions.type', 'earning')
            ->where('wallet_transactions.status', 'completed')
            ->whereIn('wallet_transactions.provider', ['monetization', 'vast_ad', 'video_purchase', 'community_photo_purchase'])
            ->get();

        $total = 0;
        $commission = 0;
        $daily = [];
        foreach ($rows as $row) {
            $payload = self::payload($row->payload);
            $createdAt = $row->created_at ? Carbon::parse($row->created_at) : null;
            $total += (int) $row->amount_cents;
            $commission += (int) ($payload['commission_cents'] ?? $payload['fee_cents'] ?? 0);
            if ($createdAt && $createdAt->betweenIncluded($start, $end)) {
                $key = $createdAt->toDateString();
                $daily[$key] = ($daily[$key] ?? 0) + (int) $row->amount_cents;
            }
        }

        $vastGrossMicros = 0;
        $vastCreatorMicros = 0;
        $vastImpressions = 0;
        if (Schema::hasTable('video_ad_impressions')) {
            $vast = DB::table('video_ad_impressions')
                ->where('status', 'completed')
                ->selectRaw('COALESCE(SUM(gross_micros), 0) as gross_micros, COALESCE(SUM(creator_amount_micros), 0) as creator_micros, COUNT(*) as impressions')
                ->first();
            $vastGrossMicros = (int) ($vast->gross_micros ?? 0);
            $vastCreatorMicros = (int) ($vast->creator_micros ?? 0);
            $vastImpressions = (int) ($vast->impressions ?? 0);
            $commission += intdiv(max(0, $vastGrossMicros - $vastCreatorMicros), 10000);
        }

        $series = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $series[] = (int) round(((int) ($daily[$date->toDateString()] ?? 0)) / 100);
        }

        $transactions = $rows
            ->sortByDesc('created_at')
            ->take(6)
            ->map(function ($row): array {
                $payload = self::payload($row->payload);
                return [
                    ucfirst(str_replace('_', ' ', (string) ($payload['event'] ?? 'earning'))).' earning',
                    $row->name ?: 'Creator',
                    self::formatMoney((int) $row->amount_cents),
                    $row->created_at ? Carbon::parse($row->created_at)->diffForHumans() : '',
                    'monetization',
                ];
            })
            ->values()
            ->all();

        return [
            'total_cents' => $total,
            'commission_cents' => $commission,
            'vast_gross_cents' => intdiv($vastGrossMicros, 10000),
            'vast_impressions' => $vastImpressions,
            'count' => $rows->count(),
            'series' => $series,
            'transactions' => $transactions,
        ];
    }

    public static function formatMoney(int $cents): string
    {
        return IntegrationSettings::formatMoney($cents);
    }

    private static function normalize(array $settings): array
    {
        $minimumVideoPrice = max(1, (int) data_get($settings, 'paid_videos.minimum_price_cents', 99));
        $maximumVideoPrice = max($minimumVideoPrice, (int) data_get($settings, 'paid_videos.maximum_price_cents', 100000));
        $taxType = strtolower((string) data_get($settings, 'paid_videos.sales_tax.type', 'vat'));
        if (! in_array($taxType, ['vat', 'gst', 'sales_tax'], true)) $taxType = 'vat';
        $minimumBoostBid = max(1, (int) data_get($settings, 'video_boosts.minimum_bid_cents', 1));
        $maximumBoostBid = max($minimumBoostBid, (int) data_get($settings, 'video_boosts.maximum_bid_cents', 10000));
        $minimumBoostBudget = max($minimumBoostBid, (int) data_get($settings, 'video_boosts.minimum_budget_cents', 500));
        $maximumBoostBudget = max($minimumBoostBudget, (int) data_get($settings, 'video_boosts.maximum_budget_cents', 10000000));

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'vast_ads' => [
                'creator_share_percent' => max(0, min(100, (float) data_get($settings, 'vast_ads.creator_share_percent', 70))),
            ],
            'paid_videos' => [
                'enabled' => (bool) data_get($settings, 'paid_videos.enabled', true),
                'commission_percent' => max(0, min(100, (float) data_get($settings, 'paid_videos.commission_percent', 10))),
                'minimum_price_cents' => $minimumVideoPrice,
                'maximum_price_cents' => $maximumVideoPrice,
                'sales_tax' => [
                    'enabled' => (bool) data_get($settings, 'paid_videos.sales_tax.enabled', false),
                    'type' => $taxType,
                    'rate_percent' => max(0, min(100, (float) data_get($settings, 'paid_videos.sales_tax.rate_percent', 0))),
                    'registration_number' => mb_substr(trim((string) data_get($settings, 'paid_videos.sales_tax.registration_number', '')), 0, 100),
                ],
            ],
            'video_boosts' => [
                'enabled' => (bool) data_get($settings, 'video_boosts.enabled', true),
                'minimum_bid_cents' => $minimumBoostBid,
                'maximum_bid_cents' => $maximumBoostBid,
                'minimum_budget_cents' => $minimumBoostBudget,
                'maximum_budget_cents' => $maximumBoostBudget,
                'max_active_campaigns' => max(1, min(100, (int) data_get($settings, 'video_boosts.max_active_campaigns', 10))),
            ],
        ];
    }

    private static function defaults(): array
    {
        return [
            'enabled' => false,
            'vast_ads' => ['creator_share_percent' => 70],
            'paid_videos' => [
                'enabled' => true,
                'commission_percent' => 10,
                'minimum_price_cents' => 99,
                'maximum_price_cents' => 100000,
                'sales_tax' => [
                    'enabled' => false,
                    'type' => 'vat',
                    'rate_percent' => 0,
                    'registration_number' => '',
                ],
            ],
            'video_boosts' => [
                'enabled' => true,
                'minimum_bid_cents' => 1,
                'maximum_bid_cents' => 10000,
                'minimum_budget_cents' => 500,
                'maximum_budget_cents' => 10000000,
                'max_active_campaigns' => 10,
            ],
        ];
    }

    private static function taxLabel(string $type): string
    {
        return match ($type) {
            'gst' => 'GST',
            'sales_tax' => 'Sales Tax',
            default => 'VAT',
        };
    }

    private static function payload(?string $payload): array
    {
        $decoded = json_decode((string) $payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function settingsPath(): string
    {
        return storage_path('app/playhub-monetization.json');
    }
}
