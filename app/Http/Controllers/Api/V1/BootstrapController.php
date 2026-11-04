<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BootstrapResource;
use App\Services\ApplicationBootstrapService;
use App\Support\Client\ClientContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __invoke(Request $request, ApplicationBootstrapService $bootstrap): JsonResponse
    {
        $payload = (new BootstrapResource(
            $bootstrap->build(ClientContext::fromRequest($request), $request->ip()),
        ))->resolve($request);

        $response = response()->json($payload);
        $response->headers->set('Cache-Control', 'private, max-age=60, must-revalidate');
        $response->headers->set('Vary', implode(', ', [
            'Accept',
            'Accept-Language',
            'X-PlayHub-Client',
            'X-PlayHub-App-Version',
            'X-PlayHub-OS-Version',
            'X-PlayHub-Device-Model',
            'X-PlayHub-Locale',
            'X-PlayHub-Timezone',
            'X-PlayHub-Capabilities',
        ]));

        return $response;
    }
}
