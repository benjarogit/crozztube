<?php

namespace App\Http\Middleware;

use App\Support\Diagnostics\LiveProbeTiming;
use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MeasureLiveProbe
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->app->environment(['local', 'testing']) || ! LiveProbeTiming::eligible($request)) {
            $request->attributes->remove(LiveProbeTiming::ATTRIBUTE);

            return $next($request);
        }

        if (! LiveProbeTiming::current($request)) {
            $request->attributes->set(LiveProbeTiming::ATTRIBUTE, new LiveProbeTiming(strtolower($request->header(LiveProbeTiming::HEADER))));
        }
        LiveProbeTiming::observeMiddleware($this->app);
        LiveProbeTiming::current($request)->begin('request_pipeline');

        return $next($request);
    }
}
