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

$controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/ClipTransferController.php');
$check(is_string($controller) && ! str_contains($controller, 'UploadedFile')
    && ! str_contains($controller, '->file(') && ! str_contains($controller, 'getContent('),
    'Clip admission must not accept or read video bytes through PHP.');
$check(str_contains($controller, 'OracleStagingStore') && str_contains($controller, 'uploadGrant('),
    'Clip API must issue an Oracle-bound browser upload grant.');
$check(str_contains($controller, 'ClipTransferQueue') && str_contains($controller, 'uploaded('),
    'Clip API must complete uploads through the transfer queue state machine.');

echo "Clip API checks passed: {$checks}\n";
