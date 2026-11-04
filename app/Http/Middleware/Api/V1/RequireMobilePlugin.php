<?php

namespace App\Http\Middleware\Api\V1;

use App\Plugins\PluginManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Plugin providers can use this on their authenticated V1 routes, including cached routes. */
class RequireMobilePlugin
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function handle(Request $request, Closure $next, string $pluginId): Response
    {
        $capability = $this->plugins->mobileCapabilities($request->user())[$pluginId] ?? null;
        abort_unless($capability && $capability['installed'] && $capability['enabled'] && $capability['mobile_supported'], 404);
        abort_unless($request->user(), 401);
        abort_unless($capability['permitted'], 403, 'This account cannot use this feature.');

        return $next($request);
    }
}
