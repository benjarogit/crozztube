<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\AuthSettings;
use App\Support\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Transfers a web CAPTCHA response, never an authentication credential, using S256 PKCE. */
class RegistrationCaptchaController extends Controller
{
    public function show(Request $request): Response
    {
        $settings = $this->settings($request);
        $data = $request->validate([
            'return_to' => ['required', 'string', 'max:200'],
            'code_challenge' => ['required', 'regex:/^[A-Za-z0-9_-]{43}$/'],
        ]);
        abort_unless(in_array($data['return_to'], (array) config('native_auth.social_return_uris', []), true), 422);
        $state = Str::random(48);
        Cache::put('native-registration-captcha:'.$state, [
            ...$data, 'settings_hash' => $this->settingsHash($settings),
        ], now()->addMinutes(5));
        $nonce = base64_encode(random_bytes(24));

        return response()->view('native-registration-captcha', [
            'state' => $state,
            'nonce' => $nonce,
            'siteKey' => data_get($settings, 'registration.googleCaptchaSiteKey'),
            'appName' => data_get(GeneralSettings::publicSettings(), 'platformName', config('app.name')),
            'submitUrl' => route('api.v1.auth.registration-google-captcha-complete'),
        ])->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/; connect-src https://www.google.com/recaptcha/; style-src 'nonce-{$nonce}'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }

    public function complete(Request $request): RedirectResponse
    {
        $settings = $this->settings($request);
        $data = $request->validate([
            'state' => ['required', 'regex:/^[A-Za-z0-9]{48}$/'],
            'google_captcha_token' => ['required', 'string', 'max:4000'],
        ]);

        return Cache::lock('native-registration-captcha-complete:'.$data['state'], 10)->block(3, function () use ($data, $settings): RedirectResponse {
            $state = Cache::pull('native-registration-captcha:'.$data['state']);
            abort_unless(is_array($state) && hash_equals($this->settingsHash($settings), $state['settings_hash']), 422);
            $code = Str::random(48);
            // The normal registration endpoint still performs Google's mandatory server verification.
            Cache::put($this->handoffKey($code, $state['code_challenge']), [
                'token' => $data['google_captcha_token'], 'settings_hash' => $state['settings_hash'],
                'expires_at' => now()->addSeconds(90)->getTimestamp(),
            ], now()->addSeconds(90));

            return redirect()->away($state['return_to'].'?captcha_auth='.rawurlencode($code))
                ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        });
    }

    public function exchange(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Za-z0-9]{48}$/'],
            'code_verifier' => ['required', 'regex:/^[A-Za-z0-9._~-]{43,128}$/'],
        ]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $data['code_verifier'], true)), '+/', '-_'), '=');
        $key = $this->handoffKey($data['code'], $challenge);

        return Cache::lock($key.':exchange', 10)->block(3, function () use ($key, $settings): JsonResponse {
            $handoff = Cache::pull($key);
            abort_unless(is_array($handoff) && hash_equals($this->settingsHash($settings), $handoff['settings_hash']), 422);
            $remaining = $handoff['expires_at'] - now()->getTimestamp();
            abort_unless($remaining > 0, 422);

            return response()->json(['token' => $handoff['token'], 'expires_in' => $remaining])
                ->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        });
    }

    private function settings(Request $request): array
    {
        $settings = AuthSettings::settings();
        abort_unless(AuthSettings::registrationDeniedMessage($request->ip()) === null
            && (bool) data_get($settings, 'registration.captchaEnabled', false)
            && data_get($settings, 'registration.captchaProvider') === 'google'
            && trim((string) data_get($settings, 'registration.googleCaptchaSiteKey')) !== ''
            && trim((string) data_get($settings, 'registration.googleCaptchaSecretKey')) !== '', 422);

        return $settings;
    }

    private function settingsHash(array $settings): string
    {
        return hash('sha256', json_encode(data_get($settings, 'registration')));
    }

    private function handoffKey(string $code, string $challenge): string
    {
        return 'native-registration-captcha-handoff:'.hash('sha256', $code.'|'.$challenge);
    }
}
