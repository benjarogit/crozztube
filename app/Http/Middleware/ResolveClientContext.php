<?php

namespace App\Http\Middleware;

use App\Support\Client\ClientContext;
use App\Support\Client\ClientContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveClientContext
{
    public function __construct(private readonly ClientContextResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Client metadata describes presentation capabilities only. Authorization
        // must continue to use the authenticated user, policies, and token abilities.
        $request->attributes->set(ClientContext::REQUEST_ATTRIBUTE, $this->resolver->resolve($request));

        return $next($request);
    }
}
