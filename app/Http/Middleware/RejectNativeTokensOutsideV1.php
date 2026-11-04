<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RejectNativeTokensOutsideV1
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1', 'api/v1/*')) {
            return $next($request);
        }

        $plainTextToken = $request->bearerToken();
        if (! $plainTextToken) {
            return $next($request);
        }

        $accessToken = PersonalAccessToken::findToken($plainTextToken);
        if (! $accessToken || $accessToken->name !== 'playhub-native') {
            return $next($request);
        }

        return new JsonResponse([
            'message' => 'Native access tokens are only accepted by PlayHub API v1.',
        ], 401, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
