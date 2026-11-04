<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VideoCategory;
use App\Models\VideoLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        $categories = VideoCategory::query()
            ->where('enabled', true)
            ->withCount(['videos' => fn ($query) => $this->visibleVideos($query)])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (VideoCategory $category): array => [
                'id' => (int) $category->id,
                'slug' => (string) $category->slug,
                'name' => (string) $category->name,
                'videos_count' => (int) $category->videos_count,
            ])->all();

        return response()->json(['categories' => $categories])->header('Cache-Control', 'private, max-age=60, must-revalidate');
    }

    public function languages(Request $request): JsonResponse
    {
        $languages = VideoLanguage::query()
            ->withCount(['videos' => fn ($query) => $this->visibleVideos($query)])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (VideoLanguage $language): array => [
                'id' => (int) $language->id,
                'code' => (string) $language->code,
                'name' => (string) $language->name,
                'videos_count' => (int) $language->videos_count,
            ])->all();

        return response()->json(['languages' => $languages])->header('Cache-Control', 'private, max-age=60, must-revalidate');
    }

    private function visibleVideos($query)
    {
        return $query
            ->where('visibility', 'public')
            ->where('status', 'ready')
            ->where(fn ($approval) => $approval->whereNull('approval_status')->orWhere('approval_status', 'approved'))
            ->where(fn ($schedule) => $schedule->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()));
    }
}
