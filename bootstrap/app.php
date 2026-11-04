<?php

use App\Console\Commands\BuildPlayHubUpdate;
use App\Console\Commands\NativePushStatus;
use App\Console\Commands\RecoverNativePush;
use App\Console\Commands\RecoverPlayHubUpdates;
use App\Http\Middleware\Api\V1\ApiErrorRenderer;
use App\Http\Middleware\RejectNativeTokensOutsideV1;
use App\Http\Middleware\ResolveClientContext;
use App\Providers\FirstPartyApiServiceProvider;
use App\Providers\CloudStorageServiceProvider;
use App\Providers\NativeAuthenticationServiceProvider;
use App\Providers\PluginSystemServiceProvider;
use App\Support\Diagnostics\LiveProbeTiming;
use App\Support\GeneralSettings;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        BuildPlayHubUpdate::class,
        RecoverPlayHubUpdates::class,
        NativePushStatus::class,
        RecoverNativePush::class,
    ])
    ->withProviders([
        CloudStorageServiceProvider::class,
        PluginSystemServiceProvider::class,
        FirstPartyApiServiceProvider::class,
        NativeAuthenticationServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            // The service provider replaces this after Laravel has loaded .env
            // and the cached configuration. Keep bootstrap safe in the meantime.
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
        $middleware->api(prepend: [RejectNativeTokensOutsideV1::class]);
        // Resolve identity, account/session and token abilities before consent;
        // an invalid or under-scoped credential must not become a Terms prompt.
        $middleware->appendToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, \App\Http\Middleware\Api\V1\ResolveOptionalSanctum::class);
        $middleware->appendToPriorityList(\App\Http\Middleware\Api\V1\ResolveOptionalSanctum::class, \App\Http\Middleware\Api\V1\EnsureAccountActive::class);
        $middleware->appendToPriorityList(\App\Http\Middleware\Api\V1\EnsureAccountActive::class, \App\Http\Middleware\TrackNativeDeviceSession::class);
        $middleware->appendToPriorityList(\App\Http\Middleware\TrackNativeDeviceSession::class, \App\Http\Middleware\Api\V1\RequireTokenAbility::class);
        $middleware->appendToPriorityList(\App\Http\Middleware\Api\V1\RequireTokenAbility::class, \App\Http\Middleware\EnsureUgcSafety::class);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
        $middleware->preventRequestsDuringMaintenance(except: ['api/admin/system-updates*']);
        $middleware->alias([
            'client.context' => ResolveClientContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['license_key']);
        $exceptions->render(function (Throwable $exception, Request $request) {
            return ApiErrorRenderer::render($exception, $request);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });
    })
    ->booted(function (): void {
        GeneralSettings::apply();
    })
    ->create();

LiveProbeTiming::install($app);

return $app;
