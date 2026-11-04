<?php

namespace App\Http\Middleware\Api\V1;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttachRequestId
{
    public const ATTRIBUTE = 'playhub.request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $provided = trim((string) $request->header('X-Request-ID'));
        $requestId = Str::isUuid($provided) ? strtolower($provided) : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-PlayHub-API-Version', '1');

        return $response;
    }

    public static function for(Request $request): string
    {
        $requestId = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        $requestId = (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $requestId);

        return $requestId;
    }
}
