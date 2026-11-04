<?php

namespace App\Http\Middleware\Api\V1;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $settings = is_array($user->settings) ? $user->settings : [];
            $status = strtolower(str_replace(['_', '-'], ' ', trim((string) ($settings['status'] ?? 'active'))));

            abort_if(in_array($status, ['suspended', 'deleted'], true), 403, 'This account is suspended.');
            abort_if($user->isBannedFrom('account'), 403, $user->banMessage('account'));
        }

        return $next($request);
    }
}
