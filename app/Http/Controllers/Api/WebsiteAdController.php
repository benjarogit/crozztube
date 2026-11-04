<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebsiteAd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteAdController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $authenticated = auth('sanctum')->check();
        $device = $this->requestDevice($request);
        $ads = WebsiteAd::query()
            ->active()
            ->whereIn('audience', ['all', $authenticated ? 'members' : 'guests'])
            ->whereIn('device', ['all', $device])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (WebsiteAd $ad): array => $this->publicPayload($ad))
            ->values();

        return response()->json(['ads' => $ads]);
    }

    public function impression(WebsiteAd $websiteAd): JsonResponse
    {
        $tracked = WebsiteAd::query()->active()->whereKey($websiteAd->getKey())->exists();
        if ($tracked) $websiteAd->increment('impressions');

        return response()->json(['tracked' => $tracked]);
    }

    public function click(WebsiteAd $websiteAd): JsonResponse
    {
        $tracked = WebsiteAd::query()->active()->whereKey($websiteAd->getKey())->exists();
        if ($tracked) $websiteAd->increment('clicks');

        return response()->json(['tracked' => $tracked]);
    }

    private function requestDevice(Request $request): string
    {
        $agent = strtolower((string) $request->userAgent());
        if (preg_match('/ipad|tablet|kindle|silk/', $agent) || (str_contains($agent, 'android') && ! str_contains($agent, 'mobile'))) return 'tablet';
        if (preg_match('/mobile|iphone|ipod|android/', $agent)) return 'mobile';

        return 'desktop';
    }

    private function publicPayload(WebsiteAd $ad): array
    {
        return [
            'id' => (int) $ad->id,
            'placement' => $ad->placement,
            'creative_type' => $ad->creative_type,
            'title' => $ad->title,
            'body' => $ad->body,
            'html_content' => $ad->html_content,
            'image_url' => $ad->image_url,
            'target_url' => $ad->target_url,
            'display_style' => $ad->display_style,
            'label' => $ad->label,
            'background_color' => $ad->background_color,
            'height' => (int) $ad->height,
            'max_width' => $ad->max_width ? (int) $ad->max_width : null,
            'every_n_items' => (int) $ad->every_n_items,
            'dismissible' => (bool) $ad->dismissible,
            'open_new_tab' => (bool) $ad->open_new_tab,
            'show_label' => (bool) $ad->show_label,
        ];
    }
}
