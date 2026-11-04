<?php

namespace App\Http\Middleware\Api\V1;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveOptionalSanctum
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();

        if ($request->bearerToken() !== null && $user === null) {
            abort(401, 'The access token is invalid or expired.');
        }

        if ($user !== null) {
            $request->setUserResolver(static fn () => $user);
        }

        return $next($request);
    }
}
