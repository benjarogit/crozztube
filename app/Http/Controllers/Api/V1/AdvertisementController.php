<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;
use App\Support\PlayerAdSettings;
use App\Support\VideoSales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvertisementController extends Controller
{
    public function configuration(): JsonResponse
    {
        return response()->json(PlayerAdSettings::publicSettings())
            ->header('Cache-Control', 'private, max-age=60, must-revalidate');
    }

    public function resolve(Request $request, VideoAccessService $access): JsonResponse
    {
        $data = $request->validate([
            'video_id' => ['required', 'integer', 'min:1'],
            'ad_ids' => ['sometimes', 'array', 'max:8'],
            'ad_ids.*' => ['string', 'max:80'],
        ]);
        $video = Video::query()->findOrFail((int) $data['video_id']);
        $access->assertViewable($video, $request->user());
        abort_unless(VideoSales::hasAccess($video, $request->user()), 403, 'Purchase this video to watch it.');

        if (! $video->monetization_enabled || VideoSales::isPaid($video)) {
            return response()->json(['enabled' => false, 'ads' => []])
                ->header('Cache-Control', 'private, no-store');
        }

        $ads = PlayerAdSettings::resolve($data['ad_ids'] ?? [], $video, $request, $request->user()?->id);

        return response()->json(['enabled' => $ads !== [], 'ads' => $ads])
            ->header('Cache-Control', 'private, no-store');
    }

    public function complete(Request $request, string $impression): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(PlayerAdSettings::completeVideoImpression($impression, $data['token'], $request->user()?->id))
            ->header('Cache-Control', 'private, no-store');
    }
}
