<?php

namespace App\Http\Middleware\Api\V1;

use App\Support\Client\ClientContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectTvPublishing
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(ClientContext::fromRequest($request)->isTv(), 403, 'TV clients cannot request live publishing access.');
        abort_if($request->user()?->currentAccessToken()?->cant('live:publish'), 403, 'This device session cannot publish live video.');

        return $next($request);
    }
}
