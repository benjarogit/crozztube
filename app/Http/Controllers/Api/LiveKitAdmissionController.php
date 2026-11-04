<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LiveKitAdmissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LiveKitAdmissionController extends Controller
{
    public function __invoke(Request $request, LiveKitAdmissionService $admission): Response
    {
        // Nginx overwrites this header from the original signaling query. This
        // endpoint grants nothing: the signed token and current state are required.
        $token = (string) $request->header('X-PlayHub-LiveKit-Token', '');
        $authorization = (string) $request->header('Authorization', '');
        if ($authorization !== '') {
            // Match the pinned LiveKit middleware's exact Bearer syntax. Reject
            // ambiguity rather than admitting one credential and using another.
            if (! str_starts_with($authorization, 'Bearer ')) {
                $token = '';
            } else {
                $bearer = substr($authorization, strlen('Bearer '));
                $token = $token !== '' && ! hash_equals($token, $bearer) ? '' : $bearer;
            }
        }

        return response('', $admission->allows($token) ? 204 : 403)
            ->header('Cache-Control', 'private, no-store')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
