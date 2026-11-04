<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WalletLedger
{
    public static function totals(User $user): array
    {
        $currency = IntegrationSettings::currency();
        if (! Schema::hasTable('wallet_transactions')) {
            return ['balance_cents' => 0, 'pending_withdrawals_cents' => 0, 'earnings_cents' => 0, 'reserved_boosts_cents' => 0, 'currency' => $currency];
        }

        $credits = (int) DB::table('wallet_transactions')
            ->where('user_id', $user->id)->whereIn('type', ['topup', 'earning'])
            ->where('status', 'completed')->sum('amount_cents');
        $earnings = (int) DB::table('wallet_transactions')
            ->where('user_id', $user->id)->where('type', 'earning')
            ->where('status', 'completed')->sum('amount_cents');
        $reservedWithdrawals = (int) DB::table('wallet_transactions')
            ->where('user_id', $user->id)->where('type', 'withdraw')
            ->whereIn('status', ['pending', 'completed'])->sum('amount_cents');
        $spending = (int) DB::table('wallet_transactions')
            ->where('user_id', $user->id)->where('type', 'spend')
            ->where('status', 'completed')->sum('amount_cents');
        $pendingWithdrawals = (int) DB::table('wallet_transactions')
            ->where('user_id', $user->id)->where('type', 'withdraw')
            ->where('status', 'pending')->sum('amount_cents');
        $reservedBoosts = Schema::hasTable('video_boost_campaigns')
            ? (int) DB::table('video_boost_campaigns')
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'paused'])
                ->where(function ($query): void {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->selectRaw('COALESCE(SUM(budget_cents - spent_cents), 0) as reserved')
                ->value('reserved')
            : 0;

        return [
            'balance_cents' => max(0, $credits - $reservedWithdrawals - $spending - $reservedBoosts),
            'pending_withdrawals_cents' => $pendingWithdrawals,
            'earnings_cents' => $earnings,
            'reserved_boosts_cents' => $reservedBoosts,
            'currency' => $currency,
        ];
    }
}
