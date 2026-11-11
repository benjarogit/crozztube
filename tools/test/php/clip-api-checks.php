<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Routing\Route;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$routes = collect(app('router')->getRoutes()->getRoutes());
$expected = [
    'api/videos/{video}/clip-transfers' => 'store',
    'api/clip-transfers/{uuid}/upload-grant' => 'uploadGrant',
    'api/clip-transfers/{uuid}/uploaded' => 'uploaded',
    'api/v1/videos/{video}/clip-transfers' => 'store',
    'api/v1/clip-transfers/{uuid}/upload-grant' => 'uploadGrant',
    'api/v1/clip-transfers/{uuid}/uploaded' => 'uploaded',
];

foreach ($expected as $uri => $method) {
    /** @var Route|null $route */
    $route = $routes->first(fn (Route $candidate): bool => $candidate->uri() === $uri
        && in_array('POST', $candidate->methods(), true));
    $check($route !== null, "Missing clip API route: {$uri}");
    $check(str_ends_with($route->getActionName(), "ClipTransferController@{$method}"), "Wrong controller action: {$uri}");
    $middleware = $route->gatherMiddleware();
    $check(collect($middleware)->contains(fn ($item): bool => $item === 'auth:sanctum'
        || str_contains($item, 'Authenticate:sanctum')),
        "Clip API route must require Sanctum authentication: {$uri}");
    $check(collect($middleware)->contains(fn ($item): bool => str_contains($item, 'EnsureUgcSafety')),
        "Clip API route must retain UGC safety middleware: {$uri}");
    if (str_starts_with($uri, 'api/v1/')) {
        $check(collect($middleware)->contains(fn ($item): bool => str_contains($item, 'RequireTokenAbility:account:write')),
            "Versioned clip writes must require account:write: {$uri}");
    }
}

$management = [
    ['PATCH', 'api/clip-transfers/{uuid}', 'ClipTransferController@update'],
    ['DELETE', 'api/clip-transfers/{uuid}', 'ClipTransferController@destroy'],
    ['GET', 'api/clip-collections', 'ClipCollectionController@index'],
    ['POST', 'api/clip-collections', 'ClipCollectionController@store'],
    ['GET', 'api/clip-collections/{collection}', 'ClipCollectionController@show'],
    ['PATCH', 'api/clip-collections/{collection}', 'ClipCollectionController@update'],
    ['DELETE', 'api/clip-collections/{collection}', 'ClipCollectionController@destroy'],
    ['PUT', 'api/clip-collections/{collection}/clips/{uuid}', 'ClipCollectionController@addClip'],
    ['DELETE', 'api/clip-collections/{collection}/clips/{uuid}', 'ClipCollectionController@removeClip'],
];
foreach ($management as [$verb, $legacyUri, $action]) {
    foreach ([$legacyUri, str_replace('api/', 'api/v1/', $legacyUri)] as $uri) {
        /** @var Route|null $route */
        $route = $routes->first(fn (Route $candidate): bool => $candidate->uri() === $uri
            && in_array($verb, $candidate->methods(), true));
        $check($route !== null && str_ends_with($route->getActionName(), $action), "Missing clip management route: {$verb} {$uri}");
        $middleware = $route->gatherMiddleware();
        $check(collect($middleware)->contains(fn ($item): bool => $item === 'auth:sanctum'
            || str_contains($item, 'Authenticate:sanctum')), "Clip management must require authentication: {$verb} {$uri}");
        if (str_starts_with($uri, 'api/v1/')) {
            $ability = $verb === 'GET' ? 'account:read' : 'account:write';
            $check(collect($middleware)->contains(fn ($item): bool => str_contains($item, "RequireTokenAbility:{$ability}")),
                "Versioned clip management requires {$ability}: {$verb} {$uri}");
        }
    }
}

foreach (['api/clips/{uuid}', 'api/clips/{uuid}/playback', 'api/v1/clips/{uuid}', 'api/v1/clips/{uuid}/playback'] as $uri) {
    /** @var Route|null $route */
    $route = $routes->first(fn (Route $candidate): bool => $candidate->uri() === $uri
        && in_array('GET', $candidate->methods(), true));
    $check($route !== null, "Missing public clip route: {$uri}");
    $check(collect($route->gatherMiddleware())->contains(fn ($item): bool => str_contains($item, 'EnsureUgcSafety')),
        "Public clip reads must retain UGC safety: {$uri}");
}

$controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/ClipTransferController.php');
$check(is_string($controller) && ! str_contains($controller, 'UploadedFile')
    && ! str_contains($controller, '->file(') && ! str_contains($controller, 'getContent('),
    'Clip admission must not accept or read video bytes through PHP.');
$check(str_contains($controller, 'OracleStagingStore') && str_contains($controller, 'uploadGrant('),
    'Clip API must issue an Oracle-bound browser upload grant.');
$check(str_contains($controller, 'ClipTransferQueue') && str_contains($controller, 'uploaded('),
    'Clip API must complete uploads through the transfer queue state machine.');
$check(str_contains($controller, "where('metadata->visibility', 'public')")
    && str_contains($controller, 'authorizeSource($request, $row)'),
    'Public clip reads must require public visibility and preserve original-video access.');

echo "Clip API checks passed: {$checks}\n";
