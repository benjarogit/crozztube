<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Services\ApplicationFeatureService;
use App\Support\Client\ClientContext;
use App\Support\IntegrationSettings;
use App\Support\MediaStorage;
use App\Support\MonetizationSettings;
use App\Support\WalletLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CommerceController extends Controller
{
    public function capabilities(Request $request, PluginManager $plugins, ApplicationFeatureService $features): JsonResponse
    {
        $user = $request->user();

        return $this->respond([
            'features' => array_merge($features->publicFeatures($request->ip(), ClientContext::fromRequest($request)), [
                'earnings' => $user->channel()->exists() && Schema::hasTable('wallet_transactions'),
                'wallet' => Schema::hasTable('wallet_transactions'),
                'account_storage' => true,
                'billing' => true,
                'administration' => $this->isAdministrator($user),
                'video_promotions' => (bool) MonetizationSettings::videoBoostSettings()['enabled'],
            ]),
            'plugins' => (object) $plugins->mobileCapabilities($user),
        ]);
    }

    public function plan(Request $request): JsonResponse
    {
        $user = $request->user();
        $storage = MediaStorage::storageSummaryFor($user);
        $plans = MediaStorage::membershipPlans();
        $billing = (array) data_get($user->settings, 'billing', []);
        $providers = $this->providers();

        return $this->respond([
            'current_plan' => collect($plans)->firstWhere('id', $storage['membership']),
            'plans' => $plans,
            'storage' => $storage,
            'billing' => [
                'provider' => $billing['provider'] ?? null,
                'type' => $billing['type'] ?? null,
                'interval' => $billing['interval'] ?? null,
                'paid_at' => $billing['paid_at'] ?? null,
            ],
            'providers' => $providers,
            'manage_url' => url('/plans'),
            'native_checkout_supported' => false,
            'subscription_controls_supported' => false,
        ]);
    }

    public function earnings(Request $request, PaymentController $payments): JsonResponse
    {
        $result = $payments->creatorEarnings($request)->getData(true);
        // Provider references are unnecessary for the native earnings display.
        $result['transactions'] = array_map(function (array $transaction): array {
            unset($transaction['reference']);

            return $transaction;
        }, $result['transactions']);
        $result['next_page'] = $result['meta']['current_page'] < $result['meta']['last_page']
            ? $result['meta']['current_page'] + 1 : null;

        return $this->respond($result);
    }

    public function billingInformation(Request $request, PaymentController $payments): JsonResponse
    {
        return $this->respond($payments->billingInformation($request)->getData(true));
    }

    public function updateBillingInformation(Request $request, PaymentController $payments): JsonResponse
    {
        $result = $payments->updateBillingInformation($request)->getData(true);
        unset($result['user']);

        return $this->respond($result);
    }

    private function isAdministrator(User $user): bool
    {
        // Match the existing admin controller's account settings authorization.
        $settings = is_array($user->settings) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;

        return $adminEnabled || (bool) ($settings['is_admin'] ?? false) || in_array($role, ['admin', 'super admin', 'owner'], true);
    }

    public function wallet(Request $request): JsonResponse
    {
        $totals = WalletLedger::totals($request->user());
        foreach (['balance', 'pending_withdrawals', 'earnings', 'reserved_boosts'] as $field) {
            $totals[$field] = IntegrationSettings::formatMoney($totals[$field.'_cents'], $totals['currency']);
        }

        return $this->respond([
            'wallet' => $totals,
            'providers' => $this->providers(),
            'topup_url' => url('/wallet'),
            'withdrawal' => [
                'available' => Schema::hasTable('wallet_transactions'),
                'minimum_amount' => '1.00',
                'maximum_amount' => '50000.00',
                'minimum_amount_label' => IntegrationSettings::formatMoney(100, $totals['currency']),
                'methods' => ['Bank transfer', 'PayPal', 'Payoneer', 'Other'],
                'processing' => 'admin_review',
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'type' => ['nullable', Rule::in(['all', 'earning', 'withdraw', 'topup', 'spend'])],
        ]);
        abort_unless(Schema::hasTable('wallet_transactions'), 422, 'Wallet storage is not ready.');
        $query = DB::table('wallet_transactions')->where('user_id', $request->user()->id);
        if (($data['type'] ?? 'all') !== 'all') {
            $query->where('type', $data['type']);
        }
        $page = $query->latest('created_at')->latest('id')->paginate($data['per_page'] ?? 20);

        return $this->respond([
            'transactions' => collect($page->items())->map(fn ($row): array => $this->transaction($row))->all(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'total' => $page->total(),
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:50000', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(['Bank transfer', 'PayPal', 'Payoneer', 'Other'])],
            'account' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'note' => ['nullable', 'string', 'max:500'],
            'confirmed' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        abort_unless(Schema::hasTable('wallet_transactions'), 422, 'Wallet storage is not ready.');
        [$whole, $fraction] = array_pad(explode('.', (string) $data['amount'], 2), 2, '');
        $amountCents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        $user = $request->user();
        $reference = $user->id.':'.$data['idempotency_key'];
        $row = DB::transaction(function () use ($user, $data, $reference, $amountCents): object {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('wallet_transactions')->where('provider', 'native_withdrawal')
                ->where('provider_reference', $reference)->where('user_id', $user->id)->first();
            if ($existing) {
                abort_if((int) $existing->amount_cents !== $amountCents || $existing->method !== $data['method']
                    || $existing->account !== trim($data['account']), 409, 'This withdrawal request key was already used.');

                return $existing;
            }
            abort_if($amountCents > WalletLedger::totals($user)['balance_cents'], 422, 'Insufficient wallet balance.');
            $id = DB::table('wallet_transactions')->insertGetId([
                'user_id' => $user->id, 'type' => 'withdraw', 'amount_cents' => $amountCents,
                'currency' => IntegrationSettings::currency(), 'status' => 'pending',
                'provider' => 'native_withdrawal', 'provider_reference' => $reference,
                'method' => $data['method'], 'account' => trim($data['account']),
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('wallet_transactions')->find($id);
        }, 3);

        return $this->respond(['withdrawal' => $this->transaction($row), 'message' => 'Withdrawal request submitted for admin review.']);
    }

    public function billingHistory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        if (! Schema::hasTable('payment_transactions')) {
            return $this->respond(['transactions' => [], 'next_page' => null, 'total' => 0]);
        }
        $page = DB::table('payment_transactions')->where('user_id', $request->user()->id)
            ->latest('created_at')->latest('id')->paginate($data['per_page'] ?? 20);

        return $this->respond([
            'transactions' => collect($page->items())->map(fn ($row): array => [
                'id' => (int) $row->id,
                'provider' => (string) $row->provider,
                'plan' => MediaStorage::membershipLabel($row->membership),
                'amount_cents' => (int) $row->amount_cents,
                'amount' => IntegrationSettings::formatMoney((int) $row->amount_cents, $row->currency),
                'currency' => (string) $row->currency,
                'status' => (string) $row->status,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'paid_at' => $row->paid_at ? Carbon::parse($row->paid_at)->toIso8601String() : null,
            ])->all(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'total' => $page->total(),
        ]);
    }

    private function transaction(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'type' => (string) $row->type,
            'status' => (string) $row->status,
            'method' => $row->method,
            'provider' => $row->provider === 'native_withdrawal' ? null : $row->provider,
            'account_masked' => $row->account ? '••••'.(mb_strlen((string) $row->account) > 4 ? mb_substr((string) $row->account, -4) : '') : null,
            'amount_cents' => (int) $row->amount_cents,
            'amount' => IntegrationSettings::formatMoney((int) $row->amount_cents, $row->currency),
            'currency' => (string) $row->currency,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'processed_at' => $row->processed_at ? Carbon::parse($row->processed_at)->toIso8601String() : null,
        ];
    }

    private function providers(): array
    {
        return collect((array) data_get(IntegrationSettings::publicSettings(), 'payments.providers', []))
            ->map(fn ($provider): bool => (bool) ($provider['enabled'] ?? false))->all();
    }

    private function respond(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }
}
