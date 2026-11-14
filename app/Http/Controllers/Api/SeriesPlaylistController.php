<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Support\SeriesPlaylists;
use App\Support\VideoSales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Both API surfaces share exactly the same series authorization and data service. */
class SeriesPlaylistController extends Controller
{
    public function show(Request $request, Playlist $playlist): JsonResponse
    {
        return response()->json(SeriesPlaylists::payload($playlist, VideoSales::viewer($request)))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Playlist $playlist): JsonResponse
    {
        abort_unless($request->user(), 401);

        return response()->json(SeriesPlaylists::update($request->user(), $playlist, $request->all()))->header('Cache-Control', 'private, no-store');
    }

    public function next(Request $request, Playlist $playlist): JsonResponse
    {
        $data = $request->validate([
            'after' => ['required', 'integer', 'min:1'],
            'queue' => ['sometimes', 'array', 'max:50'],
            'queue.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return response()->json(['next_episode' => SeriesPlaylists::next($playlist, VideoSales::viewer($request), (int) $data['after'], $data['queue'] ?? [])])->header('Cache-Control', 'private, no-store');
    }
}
