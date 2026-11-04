<?php

namespace App\Providers;

use App\Services\Auth\NativeAccessTokenAuthenticator;
use App\Services\Auth\NativeAccountAccess;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class NativeAuthenticationServiceProvider extends ServiceProvider
{
    public function boot(NativeAccountAccess $accountAccess): void
    {
        $previousCallback = Sanctum::$accessTokenAuthenticationCallback;

        while ($previousCallback instanceof NativeAccessTokenAuthenticator) {
            $previousCallback = $previousCallback->previousCallback();
        }

        // PHPUnit rebuilds the application between tests while Sanctum keeps
        // this callback in a static property. Installing an invokable object
        // lets us unwrap our prior instance instead of accumulating closures
        // that retain services from already-destroyed containers.
        Sanctum::authenticateAccessTokensUsing(
            new NativeAccessTokenAuthenticator($accountAccess, $previousCallback),
        );
    }
}
