<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\KlipyStickers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StickerController extends Controller
{
    public function index(Request $request, KlipyStickers $stickers): JsonResponse
    {
        return response()->json($stickers->catalog($request))->header('Cache-Control', 'private, no-store');
    }

    public function selection(Request $request, KlipyStickers $stickers): JsonResponse
    {
        abort_unless($request->user(), 401);
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:240', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/D'],
            'name' => ['required', 'string', 'max:80'],
            'image_url' => ['required', 'string', 'max:2048'],
            'preview_url' => ['nullable', 'string', 'max:2048'],
            'query' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(['sticker' => $stickers->registerSelection($data, $request)])->header('Cache-Control', 'private, no-store');
    }
}
