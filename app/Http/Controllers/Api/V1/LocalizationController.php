<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LocalizationService;
use App\Support\Client\ClientContext;
use App\Support\ContentPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalizationController extends Controller
{
    public function index(LocalizationService $localization): JsonResponse
    {
        return $this->respond($localization->catalog());
    }

    public function show(string $locale, LocalizationService $localization): JsonResponse
    {
        $pack = $localization->pack($locale);
        abort_unless($pack !== null, 404);

        return $this->respond($pack);
    }

    public function pages(Request $request, LocalizationService $localization): JsonResponse
    {
        $request->validate(['locale' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/i']]);
        $locale = $localization->resolve($request->query('locale') ?? ClientContext::fromRequest($request)->locale);
        $catalog = ContentPages::publicCatalog($locale, true);
        $catalog['pages'] = array_map(fn (array $page): array => [...$page, 'url' => url($page['route'])], $catalog['pages']);

        return $this->respond($catalog);
    }

    private function respond(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-cache, must-revalidate');
    }
}
