<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class FirstPartyApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureTrustedProxies();

        RateLimiter::for('api-v1', fn (Request $request) => Limit::perMinute(240)->by($this->key($request)));
        RateLimiter::for('build-license', fn (Request $request) => [
            Limit::perMinute(5)->by('build-license-ip-minute:'.hash('sha256', (string) $request->ip())),
            Limit::perHour(30)->by('build-license-ip-hour:'.hash('sha256', (string) $request->ip())),
            Limit::perMinute(20)->by('build-license-installation-minute'),
            Limit::perHour(120)->by('build-license-installation-hour'),
        ]);
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(8)->by('login:'.$this->loginKey($request)),
            Limit::perHour(40)->by('login-hour:'.$this->loginKey($request)),
        ]);
        RateLimiter::for('auth-captcha', fn (Request $request) => [
            Limit::perMinute(10)->by('login-captcha:'.$request->ip()),
            Limit::perHour(60)->by('login-captcha-hour:'.$request->ip()),
        ]);
        RateLimiter::for('auth-register', fn (Request $request) => [
            Limit::perMinute(4)->by('register:'.$request->ip()),
            Limit::perHour(15)->by('register-hour:'.$request->ip()),
        ]);
        RateLimiter::for('auth-password', fn (Request $request) => Limit::perMinute(5)->by('password:'.$request->ip()));
        RateLimiter::for('auth-refresh', fn (Request $request) => [
            Limit::perMinute(15)->by('refresh:'.$this->deviceKey($request)),
            Limit::perHour(120)->by('refresh-hour:'.$this->deviceKey($request)),
        ]);
        RateLimiter::for('device-pair-create', fn (Request $request) => Limit::perMinute(10)->by('pair-create:'.$request->ip()));
        RateLimiter::for('device-pair-poll', fn (Request $request) => Limit::perMinute(60)->by('pair-poll:'.$this->deviceKey($request)));
        RateLimiter::for('device-pair-approve', fn (Request $request) => Limit::perMinute(10)->by('pair-approve:'.$this->key($request)));
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by('search:'.$this->key($request)));
        RateLimiter::for('api-write', fn (Request $request) => Limit::perMinute(60)->by('write:'.$this->key($request)));
        RateLimiter::for('comments', fn (Request $request) => Limit::perMinute(30)->by('comments:'.$this->key($request)));
        RateLimiter::for('reports', fn (Request $request) => Limit::perHour(10)->by('reports:'.$this->key($request)));
        RateLimiter::for('ads-resolve', fn (Request $request) => [
            Limit::perMinute(10)->by('ads-resolve-minute:'.$this->key($request)),
            Limit::perHour(60)->by('ads-resolve-hour:'.$this->key($request)),
        ]);
        RateLimiter::for('ads-complete', fn (Request $request) => [
            Limit::perMinute(60)->by('ads-complete-minute:'.$this->key($request)),
            Limit::perHour(300)->by('ads-complete-hour:'.$this->key($request)),
        ]);
        RateLimiter::for('progress', fn (Request $request) => Limit::perMinute(30)->by('progress:'.$this->key($request)));
        RateLimiter::for('live-token', fn (Request $request) => Limit::perMinute(20)->by('live-token:'.$this->key($request)));
        RateLimiter::for('live-ad-impressions', function (Request $request): array {
            $operation = $request->route('impression') ? 'complete' : 'serve';
            $identity = $this->key($request);

            return [
                Limit::perMinute($operation === 'serve' ? 10 : 30)->by('live-ad-'.$operation.'-minute:'.$identity),
                Limit::perHour($operation === 'serve' ? 60 : 180)->by('live-ad-'.$operation.'-hour:'.$identity),
            ];
        });
        RateLimiter::for('push-devices', fn (Request $request) => Limit::perMinute(30)->by('push:'.$this->key($request)));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(120)->by('uploads:'.$this->key($request)));
    }

    private function configureTrustedProxies(): void
    {
        $configured = trim((string) config('playhub.trusted_proxies', '127.0.0.1,::1'));
        $proxies = in_array($configured, ['*', '**'], true)
            ? $configured
            : array_values(array_filter(array_map('trim', explode(',', $configured))));

        TrustProxies::at($proxies);
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
    }

    private function key(Request $request): string
    {
        return $request->user()?->getAuthIdentifier()
            ? 'user:'.$request->user()->getAuthIdentifier()
            : 'ip:'.$request->ip();
    }

    private function loginKey(Request $request): string
    {
        $login = mb_strtolower(trim((string) ($request->input('login') ?: $request->input('email'))));

        return hash('sha256', $request->ip().'|'.$login);
    }

    private function deviceKey(Request $request): string
    {
        $deviceId = trim((string) ($request->input('device_code') ?: $request->input('device_id') ?: $request->header('X-PlayHub-Device-Id')));

        return hash('sha256', $request->ip().'|'.$deviceId);
    }
}
