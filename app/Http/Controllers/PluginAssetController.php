<?php

namespace App\Http\Controllers;

use App\Plugins\PluginManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PluginAssetController extends Controller
{
    public function __invoke(Request $request, PluginManager $plugins, string $plugin, ?string $path = null): BinaryFileResponse
    {
        $path ??= $request->query('asset');
        abort_unless(is_string($path) && $path !== '', 404);
        $file = $plugins->assetPath($plugin, $path);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'css' => 'text/css; charset=UTF-8',
            'js', 'mjs' => 'text/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'wasm' => 'application/wasm',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            default => 'application/octet-stream',
        };

        return response()->file($file, [
            'Content-Type' => $contentType,
            'Cache-Control' => $request->filled('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
