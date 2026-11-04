<?php

namespace App\Support;

use App\Models\User;
use App\Plugins\PluginHooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class IntegrationSettings
{
    public static function settings(): array
    {
        $path = self::settingsPath();
        $stored = is_file($path) && is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];

        return array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []);
    }

    public static function adminSettings(): array
    {
        $settings = self::settings();
        foreach ([
            'stripe.secretKey' => 'stripe.secretKeyConfigured',
            'paypal.clientSecret' => 'paypal.clientSecretConfigured',
            'sendgrid.apiKey' => 'sendgrid.apiKeyConfigured',
            'smtp.password' => 'smtp.passwordConfigured',
            'smsOtp.authToken' => 'smsOtp.authTokenConfigured',
            'smsOtp.vonageApiSecret' => 'smsOtp.vonageApiSecretConfigured',
            'smsOtp.messagebirdAccessKey' => 'smsOtp.messagebirdAccessKeyConfigured',
        ] as $key => $flag) {
            if (data_get($settings, $key)) {
                data_set($settings, $key, '');
                data_set($settings, $flag, true);
            }
        }
        data_set($settings, 'payments.currency', self::currency($settings));

        return $settings;
    }

    public static function publicSettings(): array
    {
        $settings = self::settings();
        $stripeReady = self::stripeEnabled($settings);
        $paypalReady = self::paypalEnabled($settings);

        return [
            'analytics' => [
                'googleAnalytics' => [
                    'enabled' => self::googleAnalyticsEnabled($settings),
                    'measurementId' => trim((string) data_get($settings, 'googleAnalytics.measurementId', '')),
                ],
            ],
            'payments' => [
                'currency' => self::currency($settings),
                'providers' => [
                    'stripe' => [
                        'enabled' => $stripeReady,
                        'publishableKey' => $stripeReady ? (string) data_get($settings, 'stripe.publishableKey', '') : '',
                    ],
                    'paypal' => [
                        'enabled' => $paypalReady,
                        'clientId' => $paypalReady ? (string) data_get($settings, 'paypal.clientId', '') : '',
                        'environment' => (string) data_get($settings, 'paypal.environment', 'sandbox'),
                    ],
                ],
            ],
        ];
    }

    public static function integrationRows(): array
    {
        $settings = self::settings();

        return [
            ['icon' => 'analytics', 'name' => 'Google Analytics', 'status' => self::googleAnalyticsEnabled($settings) ? 'Enabled' : 'Not configured'],
            ['icon' => 'stripe', 'name' => 'Stripe', 'status' => self::stripeEnabled($settings) ? 'Enabled' : 'Not configured'],
            ['icon' => 'paypal', 'name' => 'PayPal', 'status' => self::paypalEnabled($settings) ? 'Enabled' : 'Not configured'],
            ['icon' => 'sendgrid', 'name' => 'SendGrid', 'status' => self::sendGridEnabled($settings) ? 'Enabled' : 'Not configured'],
        ];
    }

    public static function save(array $settings): void
    {
        $settings = array_replace_recursive(self::defaults(), $settings);
        $path = self::settingsPath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    public static function currency(?array $settings = null): string
    {
        $currency = strtolower((string) data_get($settings ?? self::settings(), 'payments.currency', 'usd'));

        if (app()->bound(PluginHooks::class)) {
            $currency = strtolower((string) app(PluginHooks::class)->filter('playhub.currency.default', $currency));
        }

        return preg_match('/^[a-z]{3}$/', $currency) ? $currency : 'usd';
    }

    public static function formatMoney(int $amountCents, ?string $currency = null): string
    {
        $currency = strtolower((string) ($currency ?: self::currency()));
        if (! preg_match('/^[a-z]{3}$/', $currency)) {
            $currency = self::currency();
        }
        $formatted = strtoupper($currency).' '.number_format($amountCents / 100, 2);

        if (app()->bound(PluginHooks::class)) {
            $filtered = app(PluginHooks::class)->filter('playhub.currency.format', $formatted, $amountCents, $currency);
            if (is_string($filtered) && trim($filtered) !== '') {
                return $filtered;
            }
        }

        return $formatted;
    }

    public static function createStripeCheckout(User $user, string $membership, string $successUrl, string $cancelUrl): array
    {
        $settings = self::settings();
        abort_unless(self::stripeEnabled($settings), 422, 'Stripe is not configured.');

        $membership = MediaStorage::normalizeMembership($membership);
        $price = MediaStorage::membershipPriceUsd($membership);
        abort_if($price <= 0, 422, 'Payment is not required for this plan.');

        $response = Http::asForm()
            ->withToken((string) data_get($settings, 'stripe.secretKey'))
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'subscription',
                'submit_type' => 'subscribe',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $user->id,
                'customer_email' => $user->email,
                'metadata[user_id]' => (string) $user->id,
                'metadata[membership]' => $membership,
                'subscription_data[metadata][user_id]' => (string) $user->id,
                'subscription_data[metadata][membership]' => $membership,
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => self::currency($settings),
                'line_items[0][price_data][unit_amount]' => (int) round($price * 100),
                'line_items[0][price_data][recurring][interval]' => 'month',
                'line_items[0][price_data][recurring][interval_count]' => 1,
                'line_items[0][price_data][product_data][name]' => MediaStorage::membershipLabel($membership).' plan',
            ]);

        abort_if($response->failed(), 422, $response->json('error.message') ?: 'Stripe checkout could not be created.');

        return [
            'provider' => 'stripe',
            'checkout_url' => $response->json('url'),
            'session_id' => $response->json('id'),
        ];
    }

    public static function confirmStripeCheckout(User $user, string $sessionId): array
    {
        $settings = self::settings();
        abort_unless(self::stripeEnabled($settings), 422, 'Stripe is not configured.');

        $response = Http::withToken((string) data_get($settings, 'stripe.secretKey'))
            ->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($sessionId));

        abort_if($response->failed(), 422, $response->json('error.message') ?: 'Stripe payment could not be verified.');

        $session = $response->json();
        $membership = MediaStorage::normalizeMembership(data_get($session, 'metadata.membership'));
        abort_unless((int) data_get($session, 'metadata.user_id') === (int) $user->id, 403, 'This payment belongs to another account.');
        abort_unless(data_get($session, 'mode') === 'subscription', 422, 'Stripe checkout is not a subscription.');
        abort_unless((string) data_get($session, 'subscription') !== '', 422, 'Stripe subscription was not returned.');
        abort_unless(data_get($session, 'payment_status') === 'paid', 422, 'Stripe payment is not paid yet.');

        return self::activateMembership($user, 'stripe', (string) data_get($session, 'subscription'), $membership, (int) data_get($session, 'amount_total', 0), (string) data_get($session, 'currency', self::currency($settings)), $session);
    }

    public static function createPaypalOrder(User $user, string $membership, string $returnUrl, string $cancelUrl): array
    {
        $settings = self::settings();
        abort_unless(self::paypalEnabled($settings), 422, 'PayPal is not configured.');

        $membership = MediaStorage::normalizeMembership($membership);
        $price = MediaStorage::membershipPriceUsd($membership);
        abort_if($price <= 0, 422, 'Payment is not required for this plan.');

        $planId = self::paypalPlanForMembership($settings, $membership, $price);
        $payload = [
            'plan_id' => $planId,
            'custom_id' => 'user:'.$user->id.';membership:'.$membership,
            'application_context' => [
                'brand_name' => config('app.name', 'PlayHub'),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ];

        $response = Http::withToken(self::paypalAccessToken($settings))
            ->post(self::paypalBaseUrl($settings).'/v1/billing/subscriptions', $payload);

        abort_if($response->failed(), 422, $response->json('message') ?: 'PayPal subscription could not be created.');

        $subscription = $response->json();
        $approvalUrl = collect($subscription['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
        abort_unless($approvalUrl, 422, 'PayPal approval URL was not returned.');

        if (Schema::hasTable('payment_transactions') && ! empty($subscription['id'])) {
            DB::table('payment_transactions')->updateOrInsert(
                ['provider' => 'paypal', 'provider_reference' => $subscription['id']],
                [
                    'user_id' => $user->id,
                    'membership' => $membership,
                    'amount_cents' => (int) round($price * 100),
                    'currency' => self::currency($settings),
                    'status' => 'pending',
                    'payload' => json_encode($subscription, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return [
            'provider' => 'paypal',
            'order_id' => $subscription['id'] ?? null,
            'subscription_id' => $subscription['id'] ?? null,
            'checkout_url' => $approvalUrl,
        ];
    }

    public static function capturePaypalOrder(User $user, string $orderId): array
    {
        $settings = self::settings();
        abort_unless(self::paypalEnabled($settings), 422, 'PayPal is not configured.');

        $response = Http::withToken(self::paypalAccessToken($settings))
            ->get(self::paypalBaseUrl($settings).'/v1/billing/subscriptions/'.rawurlencode($orderId));

        abort_if($response->failed(), 422, $response->json('message') ?: 'PayPal subscription could not be verified.');

        $subscription = $response->json();
        abort_unless(($subscription['status'] ?? '') === 'ACTIVE', 422, 'PayPal subscription is not active yet.');

        preg_match('/user:(\d+);membership:([a-z]+)/', (string) ($subscription['custom_id'] ?? ''), $matches);
        $pending = Schema::hasTable('payment_transactions')
            ? DB::table('payment_transactions')->where('provider', 'paypal')->where('provider_reference', $subscription['id'] ?? $orderId)->first()
            : null;
        $ownerId = (int) ($matches[1] ?? ($pending->user_id ?? 0));
        abort_unless($ownerId === (int) $user->id, 403, 'This payment belongs to another account.');

        $membership = MediaStorage::normalizeMembership($matches[2] ?? ($pending->membership ?? 'starter'));
        $amount = (float) data_get($subscription, 'billing_info.last_payment.amount.value', MediaStorage::membershipPriceUsd($membership));
        $currency = (string) data_get($subscription, 'billing_info.last_payment.amount.currency_code', $pending->currency ?? self::currency($settings));

        return self::activateMembership($user, 'paypal', (string) ($subscription['id'] ?? $orderId), $membership, (int) round($amount * 100), strtolower($currency), $subscription);
    }

    private static function paypalPlanForMembership(array $settings, string $membership, float $price): string
    {
        $currency = strtoupper(self::currency($settings));
        $key = strtolower(data_get($settings, 'paypal.environment', 'sandbox').'_'.$currency.'_'.$membership.'_'.((int) round($price * 100)));
        $plans = data_get($settings, 'paypal.subscriptionPlans', []);
        if (is_array($plans) && ! empty($plans[$key])) {
            return (string) $plans[$key];
        }

        $token = self::paypalAccessToken($settings);
        $baseUrl = self::paypalBaseUrl($settings);
        $label = MediaStorage::membershipLabel($membership);

        $productResponse = Http::withToken($token)
            ->withHeaders(['Prefer' => 'return=representation'])
            ->post($baseUrl.'/v1/catalogs/products', [
                'name' => config('app.name', 'PlayHub').' '.$label,
                'description' => $label.' monthly membership plan',
                'type' => 'DIGITAL',
            ]);

        abort_if($productResponse->failed(), 422, $productResponse->json('message') ?: 'PayPal product could not be created.');

        $planResponse = Http::withToken($token)
            ->withHeaders(['Prefer' => 'return=representation'])
            ->post($baseUrl.'/v1/billing/plans', [
                'product_id' => $productResponse->json('id'),
                'name' => $label.' monthly plan',
                'description' => $label.' monthly membership plan',
                'status' => 'ACTIVE',
                'billing_cycles' => [[
                    'frequency' => [
                        'interval_unit' => 'MONTH',
                        'interval_count' => 1,
                    ],
                    'tenure_type' => 'REGULAR',
                    'sequence' => 1,
                    'total_cycles' => 0,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => number_format($price, 2, '.', ''),
                            'currency_code' => $currency,
                        ],
                    ],
                ]],
                'payment_preferences' => [
                    'auto_bill_outstanding' => true,
                    'setup_fee_failure_action' => 'CANCEL',
                    'payment_failure_threshold' => 3,
                ],
            ]);

        abort_if($planResponse->failed(), 422, $planResponse->json('message') ?: 'PayPal plan could not be created.');

        $planId = (string) $planResponse->json('id');
        abort_unless($planId !== '', 422, 'PayPal plan ID was not returned.');

        $plans = is_array($plans) ? $plans : [];
        $plans[$key] = $planId;
        data_set($settings, 'paypal.subscriptionPlans', $plans);
        self::save($settings);

        return $planId;
    }

    public static function createStripeWalletTopup(User $user, int $amountCents, string $successUrl, string $cancelUrl): array
    {
        $settings = self::settings();
        abort_unless(self::stripeEnabled($settings), 422, 'Stripe is not configured.');
        abort_if($amountCents < 100, 422, 'Top-up amount must be at least 1.00.');

        $response = Http::asForm()
            ->withToken((string) data_get($settings, 'stripe.secretKey'))
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $user->id,
                'customer_email' => $user->email,
                'metadata[type]' => 'wallet_topup',
                'metadata[user_id]' => (string) $user->id,
                'metadata[amount_cents]' => (string) $amountCents,
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => self::currency($settings),
                'line_items[0][price_data][unit_amount]' => $amountCents,
                'line_items[0][price_data][product_data][name]' => config('app.name', 'PlayHub').' wallet top-up',
            ]);

        abort_if($response->failed(), 422, $response->json('error.message') ?: 'Stripe checkout could not be created.');

        self::recordPendingWalletTopup($user, 'stripe', (string) $response->json('id'), $amountCents, self::currency($settings), $response->json() ?: []);

        return [
            'provider' => 'stripe',
            'checkout_url' => $response->json('url'),
            'session_id' => $response->json('id'),
        ];
    }

    public static function confirmStripeWalletTopup(User $user, string $sessionId): array
    {
        $settings = self::settings();
        abort_unless(self::stripeEnabled($settings), 422, 'Stripe is not configured.');

        $response = Http::withToken((string) data_get($settings, 'stripe.secretKey'))
            ->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($sessionId));

        abort_if($response->failed(), 422, $response->json('error.message') ?: 'Stripe payment could not be verified.');

        $session = $response->json();
        abort_unless(data_get($session, 'metadata.type') === 'wallet_topup', 422, 'This payment is not a wallet top-up.');
        abort_unless((int) data_get($session, 'metadata.user_id') === (int) $user->id, 403, 'This payment belongs to another account.');
        abort_unless(data_get($session, 'payment_status') === 'paid', 422, 'Stripe payment is not paid yet.');

        $paidAmountCents = (int) data_get($session, 'amount_total', 0);
        $expectedAmountCents = (int) data_get($session, 'metadata.amount_cents', 0);
        abort_if($paidAmountCents < max(100, $expectedAmountCents), 422, 'Stripe payment amount is invalid.');

        return self::recordWalletTopup($user, 'stripe', (string) data_get($session, 'id'), $paidAmountCents, (string) data_get($session, 'currency', self::currency($settings)), $session);
    }

    public static function createPaypalWalletTopup(User $user, int $amountCents, string $returnUrl, string $cancelUrl): array
    {
        $settings = self::settings();
        abort_unless(self::paypalEnabled($settings), 422, 'PayPal is not configured.');
        abort_if($amountCents < 100, 422, 'Top-up amount must be at least 1.00.');

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'wallet-topup',
                'custom_id' => 'user:'.$user->id.';wallet:'.$amountCents,
                'description' => config('app.name', 'PlayHub').' wallet top-up',
                'amount' => [
                    'currency_code' => strtoupper(self::currency($settings)),
                    'value' => number_format($amountCents / 100, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'brand_name' => config('app.name', 'PlayHub'),
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ];

        $response = Http::withToken(self::paypalAccessToken($settings))
            ->post(self::paypalBaseUrl($settings).'/v2/checkout/orders', $payload);

        abort_if($response->failed(), 422, $response->json('message') ?: 'PayPal order could not be created.');

        $order = $response->json();
        $approvalUrl = collect($order['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
        abort_unless($approvalUrl, 422, 'PayPal approval URL was not returned.');

        self::recordPendingWalletTopup($user, 'paypal', (string) ($order['id'] ?? ''), $amountCents, self::currency($settings), $order);

        return [
            'provider' => 'paypal',
            'order_id' => $order['id'] ?? null,
            'checkout_url' => $approvalUrl,
        ];
    }

    public static function capturePaypalWalletTopup(User $user, string $orderId): array
    {
        $settings = self::settings();
        abort_unless(self::paypalEnabled($settings), 422, 'PayPal is not configured.');

        $response = Http::withToken(self::paypalAccessToken($settings))
            ->post(self::paypalBaseUrl($settings).'/v2/checkout/orders/'.rawurlencode($orderId).'/capture');

        abort_if($response->failed(), 422, $response->json('message') ?: 'PayPal payment could not be captured.');

        $order = $response->json();
        abort_unless(($order['status'] ?? '') === 'COMPLETED', 422, 'PayPal payment is not completed yet.');

        $purchaseUnit = $order['purchase_units'][0] ?? [];
        preg_match('/user:(\d+);wallet:(\d+)/', (string) ($purchaseUnit['custom_id'] ?? ''), $matches);
        $pending = Schema::hasTable('wallet_transactions')
            ? DB::table('wallet_transactions')->where('provider', 'paypal')->where('provider_reference', $order['id'] ?? $orderId)->first()
            : null;
        $ownerId = (int) ($matches[1] ?? ($pending->user_id ?? 0));
        abort_unless($ownerId === (int) $user->id, 403, 'This payment belongs to another account.');

        $capture = $purchaseUnit['payments']['captures'][0] ?? [];
        $amount = (float) data_get($capture, 'amount.value', data_get($purchaseUnit, 'amount.value', 0));
        $paidAmountCents = (int) round($amount * 100);
        $expectedAmountCents = (int) ($matches[2] ?? ($pending->amount_cents ?? 0));
        abort_if($paidAmountCents < max(100, $expectedAmountCents), 422, 'PayPal payment amount is invalid.');

        return self::recordWalletTopup($user, 'paypal', (string) ($order['id'] ?? $orderId), $paidAmountCents, strtolower((string) data_get($capture, 'amount.currency_code', data_get($purchaseUnit, 'amount.currency_code', self::currency($settings)))), $order);
    }

    public static function sendSupportNotification(array $message, string $ticketId): void
    {
        $settings = self::settings();
        if (! self::sendGridEnabled($settings)) {
            return;
        }

        $toEmail = trim((string) data_get($settings, 'sendgrid.notificationsEmail'));
        if ($toEmail === '') {
            return;
        }

        self::sendGridMail($settings, $toEmail, 'New '.config('app.name', 'PlayHub').' support message '.$ticketId, implode("\n\n", [
            'Ticket: '.$ticketId,
            'Topic: '.($message['topic'] ?? 'other'),
            'From: '.($message['name'] ?? '').' <'.($message['email'] ?? '').'>',
            'Subject: '.($message['subject'] ?? ''),
            (string) ($message['message'] ?? ''),
        ]));
    }

    public static function sendTestEmail(User $admin): void
    {
        $settings = self::settings();
        abort_unless(self::sendGridEnabled($settings), 422, 'SendGrid is not configured.');

        $appName = (string) config('app.name', 'PlayHub');
        self::sendGridMail($settings, $admin->email, $appName.' SendGrid test', 'SendGrid is connected to '.$appName.'.');
    }

    public static function configureSmtpMailer(?array $settings = null): bool
    {
        $settings = $settings ?? self::settings();
        if (! self::smtpEnabled($settings)) {
            return false;
        }

        $encryption = strtolower((string) data_get($settings, 'smtp.encryption', 'tls'));
        $fromName = trim((string) data_get($settings, 'smtp.fromName'));
        if ($fromName === '' || $fromName === 'PlayHub') $fromName = (string) config('app.name', 'PlayHub');
        Config::set('mail.default', 'playhub_smtp');
        Config::set('mail.mailers.playhub_smtp', [
            'transport' => 'smtp',
            'host' => trim((string) data_get($settings, 'smtp.host')),
            'port' => (int) data_get($settings, 'smtp.port', 587),
            'encryption' => $encryption === 'none' ? null : $encryption,
            'username' => trim((string) data_get($settings, 'smtp.username')),
            'password' => (string) data_get($settings, 'smtp.password'),
            'timeout' => null,
            'local_domain' => null,
        ]);
        Config::set('mail.from.address', trim((string) data_get($settings, 'smtp.fromEmail')));
        Config::set('mail.from.name', $fromName);
        if (method_exists(Mail::getFacadeRoot(), 'forgetMailers')) {
            Mail::forgetMailers();
        }

        return true;
    }

    public static function sendSmtpTestEmail(User $admin): void
    {
        abort_unless(self::configureSmtpMailer(), 422, 'SMTP email is not configured.');

        $appName = (string) config('app.name', 'PlayHub');
        $subject = $appName.' SMTP test';
        $text = 'SMTP email is connected to '.$appName.'.';
        Mail::mailer('playhub_smtp')->raw($text, function ($message) use ($admin, $subject, $text): void {
            BrandedEmail::apply($message, $subject, $text);
            $message->to($admin->email, $admin->name)->subject($subject);
        });
    }

    public static function sendSmsOtpTest(string $phone): void
    {
        $phone = trim($phone);
        abort_unless($phone !== '', 422, 'Test phone number is required.');
        abort_unless(self::sendSmsOtpCode($phone, str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)), 422, 'SMS OTP could not be sent.');
    }

    public static function sendSmsOtpCode(string $phone, string $code): bool
    {
        $settings = self::settings();
        if (! self::smsOtpEnabled($settings)) {
            return false;
        }

        try {
            $message = 'Your '.config('app.name', 'PlayHub')." verification code is: {$code}";
            $provider = data_get($settings, 'smsOtp.provider', 'twilio');

            if ($provider === 'vonage') {
                $response = Http::asForm()->post('https://rest.nexmo.com/sms/json', [
                    'api_key' => trim((string) data_get($settings, 'smsOtp.vonageApiKey')),
                    'api_secret' => (string) data_get($settings, 'smsOtp.vonageApiSecret'),
                    'from' => trim((string) data_get($settings, 'smsOtp.vonageFrom')),
                    'to' => $phone,
                    'text' => $message,
                ]);

                return $response->successful() && (string) $response->json('messages.0.status') === '0';
            }

            if ($provider === 'messagebird') {
                return Http::withToken((string) data_get($settings, 'smsOtp.messagebirdAccessKey'), 'AccessKey')
                    ->asForm()
                    ->post('https://rest.messagebird.com/messages', [
                        'originator' => trim((string) data_get($settings, 'smsOtp.messagebirdOriginator')),
                        'recipients' => $phone,
                        'body' => $message,
                    ])
                    ->successful();
            }

            $accountSid = trim((string) data_get($settings, 'smsOtp.accountSid'));

            return Http::withBasicAuth($accountSid, (string) data_get($settings, 'smsOtp.authToken'))
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                    'From' => trim((string) data_get($settings, 'smsOtp.fromNumber')),
                    'To' => $phone,
                    'Body' => $message,
                ])
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function activateMembership(User $user, string $provider, string $reference, string $membership, int $amountCents, string $currency, array $payload): array
    {
        $membership = MediaStorage::normalizeMembership($membership);
        $settings = is_array($user->settings) ? $user->settings : [];
        $settings['membership'] = $membership;
        $settings['billing'] = [
            'provider' => $provider,
            'reference' => $reference,
            'membership' => $membership,
            'type' => 'subscription',
            'interval' => 'month',
            'paid_at' => now()->toIso8601String(),
        ];
        $user->update(['settings' => $settings]);

        if (Schema::hasTable('payment_transactions')) {
            DB::table('payment_transactions')->updateOrInsert(
                ['provider' => $provider, 'provider_reference' => $reference],
                [
                    'user_id' => $user->id,
                    'membership' => $membership,
                    'amount_cents' => $amountCents,
                    'currency' => strtolower($currency ?: self::currency()),
                    'status' => 'paid',
                    'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    'paid_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        return [
            'message' => 'Payment completed. Your plan has been updated.',
            'membership' => $membership,
            'user' => $user->fresh()->load('channel'),
        ];
    }

    private static function recordPendingWalletTopup(User $user, string $provider, string $reference, int $amountCents, string $currency, array $payload): void
    {
        if (! Schema::hasTable('wallet_transactions') || $reference === '') {
            return;
        }

        DB::table('wallet_transactions')->updateOrInsert(
            ['provider' => $provider, 'provider_reference' => $reference],
            [
                'user_id' => $user->id,
                'type' => 'topup',
                'amount_cents' => $amountCents,
                'currency' => strtolower($currency ?: self::currency()),
                'status' => 'pending',
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'processed_at' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private static function recordWalletTopup(User $user, string $provider, string $reference, int $amountCents, string $currency, array $payload): array
    {
        abort_unless(Schema::hasTable('wallet_transactions'), 422, 'Wallet storage is not ready.');

        DB::table('wallet_transactions')->updateOrInsert(
            ['provider' => $provider, 'provider_reference' => $reference],
            [
                'user_id' => $user->id,
                'type' => 'topup',
                'amount_cents' => $amountCents,
                'currency' => strtolower($currency ?: self::currency()),
                'status' => 'completed',
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'processed_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return [
            'message' => 'Wallet top-up completed.',
            'transaction' => DB::table('wallet_transactions')->where('provider', $provider)->where('provider_reference', $reference)->first(),
        ];
    }

    private static function sendGridMail(array $settings, string $toEmail, string $subject, string $text): void
    {
        $fromName = trim((string) data_get($settings, 'sendgrid.fromName'));
        if ($fromName === '' || $fromName === 'PlayHub') $fromName = (string) config('app.name', 'PlayHub');
        $response = Http::withToken((string) data_get($settings, 'sendgrid.apiKey'))
            ->post('https://api.sendgrid.com/v3/mail/send', [
                'personalizations' => [[
                    'to' => [['email' => $toEmail]],
                    'subject' => $subject,
                ]],
                'from' => [
                    'email' => data_get($settings, 'sendgrid.fromEmail'),
                    'name' => $fromName,
                ],
                'content' => [[
                    'type' => 'text/plain',
                    'value' => $text,
                ], [
                    'type' => 'text/html',
                    'value' => BrandedEmail::render($subject, $text),
                ]],
            ]);

        abort_if($response->failed(), 422, $response->json('errors.0.message') ?: 'SendGrid mail could not be sent.');
    }

    private static function paypalAccessToken(array $settings): string
    {
        $response = Http::asForm()
            ->withBasicAuth((string) data_get($settings, 'paypal.clientId'), (string) data_get($settings, 'paypal.clientSecret'))
            ->post(self::paypalBaseUrl($settings).'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        abort_if($response->failed(), 422, $response->json('error_description') ?: 'PayPal access token could not be created.');

        return (string) $response->json('access_token');
    }

    private static function paypalBaseUrl(array $settings): string
    {
        return data_get($settings, 'paypal.environment') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private static function googleAnalyticsEnabled(array $settings): bool
    {
        return (bool) data_get($settings, 'googleAnalytics.enabled') && trim((string) data_get($settings, 'googleAnalytics.measurementId')) !== '';
    }

    private static function stripeEnabled(array $settings): bool
    {
        return (bool) data_get($settings, 'stripe.enabled') && trim((string) data_get($settings, 'stripe.secretKey')) !== '';
    }

    private static function paypalEnabled(array $settings): bool
    {
        return (bool) data_get($settings, 'paypal.enabled') && trim((string) data_get($settings, 'paypal.clientId')) !== '' && trim((string) data_get($settings, 'paypal.clientSecret')) !== '';
    }

    private static function sendGridEnabled(array $settings): bool
    {
        return (bool) data_get($settings, 'sendgrid.enabled') && trim((string) data_get($settings, 'sendgrid.apiKey')) !== '' && trim((string) data_get($settings, 'sendgrid.fromEmail')) !== '';
    }

    public static function smtpEnabled(?array $settings = null): bool
    {
        $settings = $settings ?? self::settings();

        return (bool) data_get($settings, 'smtp.enabled')
            && trim((string) data_get($settings, 'smtp.host')) !== ''
            && (int) data_get($settings, 'smtp.port', 0) > 0
            && trim((string) data_get($settings, 'smtp.fromEmail')) !== '';
    }

    public static function smsOtpEnabled(?array $settings = null): bool
    {
        $settings = $settings ?? self::settings();
        if (! (bool) data_get($settings, 'smsOtp.enabled')) {
            return false;
        }
        $provider = data_get($settings, 'smsOtp.provider', 'twilio');

        if ($provider === 'vonage') {
            return trim((string) data_get($settings, 'smsOtp.vonageApiKey')) !== ''
                && trim((string) data_get($settings, 'smsOtp.vonageApiSecret')) !== ''
                && trim((string) data_get($settings, 'smsOtp.vonageFrom')) !== '';
        }

        if ($provider === 'messagebird') {
            return trim((string) data_get($settings, 'smsOtp.messagebirdAccessKey')) !== ''
                && trim((string) data_get($settings, 'smsOtp.messagebirdOriginator')) !== '';
        }

        return $provider === 'twilio'
            && trim((string) data_get($settings, 'smsOtp.accountSid')) !== ''
            && trim((string) data_get($settings, 'smsOtp.authToken')) !== ''
            && trim((string) data_get($settings, 'smsOtp.fromNumber')) !== '';
    }

    private static function settingsPath(): string
    {
        return storage_path('app/playhub-integrations.json');
    }

    private static function defaults(): array
    {
        return [
            'payments' => ['currency' => 'usd'],
            'googleAnalytics' => ['enabled' => false, 'measurementId' => ''],
            'stripe' => ['enabled' => false, 'publishableKey' => '', 'secretKey' => ''],
            'paypal' => ['enabled' => false, 'environment' => 'sandbox', 'clientId' => '', 'clientSecret' => ''],
            'smtp' => [
                'enabled' => false,
                'host' => config('mail.mailers.smtp.host', ''),
                'port' => (int) config('mail.mailers.smtp.port', 587),
                'encryption' => config('mail.mailers.smtp.encryption', 'tls') ?: 'none',
                'username' => config('mail.mailers.smtp.username', ''),
                'password' => config('mail.mailers.smtp.password', ''),
                'fromEmail' => config('mail.from.address', 'noreply@playhub.app'),
                'fromName' => config('mail.from.name', config('app.name', 'PlayHub')),
            ],
            'smsOtp' => [
                'enabled' => false,
                'provider' => 'twilio',
                'accountSid' => env('TWILIO_ACCOUNT_SID', ''),
                'authToken' => env('TWILIO_AUTH_TOKEN', ''),
                'fromNumber' => env('TWILIO_FROM_NUMBER', ''),
                'vonageApiKey' => env('VONAGE_API_KEY', ''),
                'vonageApiSecret' => env('VONAGE_API_SECRET', ''),
                'vonageFrom' => env('VONAGE_FROM_NUMBER', ''),
                'messagebirdAccessKey' => env('MESSAGEBIRD_ACCESS_KEY', ''),
                'messagebirdOriginator' => env('MESSAGEBIRD_ORIGINATOR', ''),
                'testPhone' => '',
            ],
            'sendgrid' => [
                'enabled' => false,
                'apiKey' => '',
                'fromEmail' => config('mail.from.address', 'noreply@playhub.app'),
                'fromName' => config('app.name', 'PlayHub'),
                'notificationsEmail' => config('mail.from.address', 'noreply@playhub.app'),
            ],
        ];
    }
}
