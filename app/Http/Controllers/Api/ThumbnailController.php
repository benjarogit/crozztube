<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\Thumbnails\ThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThumbnailController extends Controller
{
    public function update(Request $request, Video $video, ThumbnailService $service): JsonResponse
    {
        abort_unless($request->user() && (int) $request->user()->id === (int) $video->user_id, 403);
        abort_unless(str_starts_with(strtolower($request->header('Content-Type', '')), 'image/'), 415, 'Send the image directly, without multipart encoding.');
        $limit = (int) config('thumbnails.max_bytes');
        abort_if((int) $request->header('Content-Length', 0) > $limit, 413, 'Thumbnail exceeds five MiB.');
        $stream = $request->getContent(true);
        try { $bytes = stream_get_contents($stream, $limit + 1); }
        finally { if (is_resource($stream)) fclose($stream); }
        abort_if(strlen($bytes) > $limit, 413, 'Thumbnail exceeds five MiB.');
        try { $saved = $service->replace($video, $bytes); }
        catch (\RuntimeException) { abort(422, 'Select a valid JPEG, PNG, WebP, GIF or AVIF image, at most five MiB.'); }
        return response()->json([
            'thumbnail_saved' => $saved,
            'message' => $saved ? 'Thumbnail hosted externally.' : 'Image hosting is unavailable. Your previous thumbnail or a blank preview remains. Select the image again later.',
            'video' => new VideoResource($video->fresh()->load(['channel.user', 'user', 'category', 'language'])),
        ]);
    }
}
