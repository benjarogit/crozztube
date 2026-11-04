<?php

namespace App\Http\Middleware\Api\V1;

use App\Support\Client\ClientContext;
use Closure;
use Illuminate\Http\Request;

class RejectTvCreator
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_if(ClientContext::fromRequest($request)->isTv(), 403, 'Creator Studio is available on mobile clients.');

        return $next($request);
    }
}
