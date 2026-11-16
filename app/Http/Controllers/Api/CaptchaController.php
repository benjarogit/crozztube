<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CaptchaService;
use App\Support\CaptchaSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CaptchaController extends Controller
{
    /**
     * Issue a server-bound challenge for the requested context. The provider is
     * chosen here; the browser only renders it.
     */
    public function challenge(Request $request, CaptchaService $captcha): JsonResponse
    {
        $data = $request->validate([
            'context' => ['required', 'string', Rule::in([CaptchaSettings::CONTEXT_REGISTRATION, CaptchaSettings::CONTEXT_LOGIN])],
            'captcha_honeypot' => ['nullable', 'string', 'max:200'],
            'captcha_elapsed_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
        ]);

        return response()
            ->json($captcha->challenge($request, $data['context']))
            ->header('Cache-Control', 'no-store');
    }
}
