<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error::class.': '.$error->getMessage()."\n"); exit(1); });

use App\Http\Middleware\LocalizeResponseMessages;
use App\Services\LocalizationService;
use App\Support\BrandedEmail;
use App\Support\ContentPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

$temporary = sys_get_temp_dir().'/crozztube-message-checks-'.bin2hex(random_bytes(8));
mkdir($temporary.'/app', 0700, true);
foreach (['en', 'de_de'] as $locale) mkdir($temporary.'/languages/'.$locale, 0700, true);
$app->useStoragePath($temporary);
config(['playhub.language_path' => $temporary.'/languages', 'playhub.content_pages_path' => $temporary.'/pages.json']);
$english = ['Legacy demo title' => 'Share your videos.', 'Saved' => 'Saved', 'Required' => 'Required', 'Hello {name}' => 'Hello {name}', 'Broken {name}' => 'Broken {name}', 'Help Center' => 'Help Center', 'Notification' => 'Notification', 'This email was sent by {site}.' => 'This email was sent by {site}.'];
$german = ['Legacy demo title' => 'Legacy demo title', 'Saved' => 'Gespeichert', 'Required' => 'Erforderlich', 'Hello {name}' => 'Hallo {name}', 'Broken {name}' => 'Ohne Namen', 'Help Center' => 'Hilfe', 'Notification' => 'Benachrichtigung', 'This email was sent by {site}.' => 'Diese E-Mail wurde von {site} gesendet.'];
foreach (['en' => $english, 'de_de' => $german] as $locale => $pack) file_put_contents($temporary.'/languages/'.$locale.'/pack.php', '<?php return '.var_export($pack, true).';');
$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void { if (! $ok) throw new RuntimeException($message); $count++; };
try {
    $service = app(LocalizationService::class);
    $check($service->translate('Legacy demo title', 'de_de') === 'Share your videos.', 'Copied historical keys use the corrected English reference, not obsolete copy');
    $check($service->translate('Hello {name}', 'de_de', ['name' => 'Saved']) === 'Hallo Saved', 'User names stay literal inside translated messages');
    $check($service->translate('Hello {name}', 'de_de') === 'Hallo {name}', 'Unknown placeholders stay visible');
    $check($service->translate('Broken {name}', 'de_de', ['name' => 'Saved']) === 'Broken Saved', 'Damaged dictionary falls back without token loss');
    $request = Request::create('/api/fixture');
    $request->headers->set('X-PlayHub-Language', 'de_de_formal');
    $response = new JsonResponse(['message' => 'Saved', 'data' => ['message' => 'Saved', 'name' => 'Saved'], 'empty' => (object) [], 'items' => []], 201);
    LocalizeResponseMessages::apply($response, $request);
    $body = $response->getData();
    $check($body->message === 'Gespeichert', 'Owned top-level message localized through legacy header');
    $check($body->data->message === 'Saved' && $body->data->name === 'Saved', 'Nested user/provider content untouched');
    $check(is_object($body->empty) && is_array($body->items), 'JSON object/list distinctions preserved');
    $check($response->getStatusCode() === 201, 'HTTP status preserved');
    $check(in_array('X-PlayHub-Language', $response->getVary(), true), 'Caches vary on presentation language');
    $response = new JsonResponse(['error' => ['message' => 'Required', 'code' => 'Required', 'request_id' => 'Saved', 'validation' => ['title' => ['Required']]]], 422);
    LocalizeResponseMessages::apply($response, $request);
    $body = $response->getData();
    $check($body->error->message === 'Erforderlich', 'Versioned API error text localized');
    $check($body->error->validation->title === ['Erforderlich'], 'Validation messages localized');
    $check($body->error->code === 'Required' && $body->error->request_id === 'Saved', 'Machine codes and request ids unchanged');
    $check(ContentPages::page('support', 'de_de')['title'] === 'Hilfe', 'Project-owned help defaults use the selected language');
    ContentPages::save('de_de', 'support', ['title' => 'Saved', 'navigation_label' => 'Saved', 'subtitle' => 'Saved', 'sections' => [['title' => 'Saved', 'body' => 'Saved']]], true);
    $page = ContentPages::page('support', 'de_de');
    $check($page['title'] === 'Saved' && $page['sections'][0]['body'] === 'Saved', 'Administrator-authored pages remain literal');
    $html = BrandedEmail::render('Saved', 'Saved', 'de_de_formal');
    $check(str_contains($html, 'Benachrichtigung') && str_contains($html, 'Diese E-Mail wurde von '), 'Email wrapper header and footer localized');
    $check(str_contains($html, 'lang="de-de"') && str_contains($html, '>Saved</h1>'), 'Email language canonicalized without rewriting subject content');
    echo $count." localized message and ownership checks passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($temporary);
}
