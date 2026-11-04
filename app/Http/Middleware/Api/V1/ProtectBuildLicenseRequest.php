<?php

namespace App\Http\Middleware\Api\V1;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The purchase key belongs in a build POST body, never in a URL or a session. */
final class ProtectBuildLicenseRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isJson()) {
            return $this->protect(response()->json(['message' => 'A JSON request body is required.'], 415));
        }
        if ($request->query->count() !== 0) {
            return $this->protect(response()->json(['message' => 'Query parameters are not accepted for build verification.'], 422));
        }

        return $this->protect($next($request));
    }

    private function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
