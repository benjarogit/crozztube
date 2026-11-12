<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error::class.': '.$error->getMessage()."\n"); exit(1); });

use App\Services\LocalizationService;
use App\Support\EmailTemplates;
use App\Support\GeneralSettings;
use App\Support\LanguageLocales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

$temporary = sys_get_temp_dir().'/crozztube-alias-checks-'.bin2hex(random_bytes(8));
mkdir($temporary.'/app', 0700, true);
$app->useStoragePath($temporary);
config(['cache.default' => 'array']);
Bus::fake(); Queue::fake();
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (! $ok) throw new RuntimeException($name);
    $checks++;
};
$request = static function (string $path, string $method = 'GET', array $data = []) use ($app, $kernel) {
    $request = Request::create($path, $method, $data);
    $request->headers->set('Accept', 'application/json');
    $app->instance('request', $request);

    return $kernel->handle($request);
};
DB::beginTransaction();
try {
    $service = app(LocalizationService::class);
    $check(count(GeneralSettings::languageIdentifiers()) === 125, '125 canonical regional packs');
    $check(LanguageLocales::languageTag('pt_pt_ao90') === 'pt-PT-ao1990', 'Registered Portuguese orthography tag');
    $check(LanguageLocales::canonical('pt-PT-ao1990') === 'pt_pt_ao90', 'BCP47 orthography input resolves to existing catalog');
    $check($service->resolve('pt-PT-ao1990') === 'pt_pt_ao90', 'Locale header does not choose another Portuguese region');
    $check(($service->pack('pt-PT-ao1990')['language_tag'] ?? null) === 'pt-PT-ao1990', 'Public language metadata exposes its registered tag');
    $check(str_contains(\App\Support\BrandedEmail::render('Home', 'Saved', 'pt_pt_ao90'), 'lang="pt-PT-ao1990"'), 'Email HTML carries the registered locale tag');
    foreach (LanguageLocales::ALIASES as $alias => $target) {
        $check(! in_array($alias, GeneralSettings::languageIdentifiers(), true), 'Retired code not selectable');
        $check($service->pack(strtoupper(str_replace('_', '-', $alias)))['code'] === $target, 'Service resolves legacy casing and separators');
        $check($service->resolve($alias) === $target, 'Exact alias beats another regional variant');
        $pack = json_decode($request('/api/i18n/'.$alias)->getContent(), true);
        $check(($pack['code'] ?? null) === $target, 'Legacy website endpoint serves canonical pack');
        $mobile = $request('/api/v1/localization/'.$alias);
        $check($mobile->getStatusCode() === 200 && (json_decode($mobile->getContent(), true)['data']['code'] ?? null) === $target, 'Versioned API agrees');
    }
    foreach (['/api/i18n', '/api/v1/localization'] as $path) {
        $response = $request($path);
        $catalog = json_decode($response->getContent(), true);
        if (str_starts_with($path, '/api/v1/')) $catalog = $catalog['data'] ?? [];
        $check($response->getStatusCode() === 200 && ($catalog['aliases'] ?? []) === LanguageLocales::ALIASES, 'Catalog publishes the shared alias map');
        $check(count($catalog['languages'] ?? []) === 125, 'Public catalog contains canonical choices only');
    }
    GeneralSettings::save(['defaultLanguage' => 'de_de_formal']);
    $check(GeneralSettings::settings()['defaultLanguage'] === 'de_de', 'Legacy website default saved canonically');
    $check(EmailTemplates::render('verification_code', ['SITENAME' => 'Fixture', 'VERIFICATION_CODE' => '123456', 'EXPIRY_MINUTES' => 15], 'de_de_formal')['locale'] === 'de_de', 'Email locale canonicalized');
    $overrides = json_encode(['locales' => ['de_de_formal' => ['verification_code' => ['subject' => 'Sie', 'body' => 'Sie {VERIFICATION_CODE}']]]]);
    file_put_contents($temporary.'/app/playhub-email-templates.json', $overrides);
    $rendered = EmailTemplates::render('verification_code', ['SITENAME' => 'Fixture', 'VERIFICATION_CODE' => '123456', 'EXPIRY_MINUTES' => 15], 'de_de_formal');
    $check(str_starts_with($rendered['subject'], 'Dein '), 'Retired formal override is not served');
    $check(file_get_contents($temporary.'/app/playhub-email-templates.json') === $overrides, 'Administrator original remains intact');
    GeneralSettings::save(['defaultLanguage' => 'en', 'disabledLanguages' => array_values(LanguageLocales::ALIASES)]);
    foreach (LanguageLocales::ALIASES as $alias => $target) {
        $check($service->pack($alias) === null, 'Disabled target cannot be fetched through alias');
        $check($request('/api/i18n/'.$alias)->getStatusCode() === 404, 'Disabled alias endpoint is unavailable');
    }
    GeneralSettings::save(['defaultLanguage' => 'en']);
    Auth::guard('sanctum')->setUser(App\Models\User::findOrFail(1));
    foreach (array_keys(LanguageLocales::ALIASES) as $alias) {
        $check($request('/api/admin/languages/'.$alias.'/status', 'PUT', ['enabled' => true])->getStatusCode() === 422, 'Retired variant cannot be enabled');
        $check($request('/api/admin/languages/'.$alias, 'PUT', ['changes' => []])->getStatusCode() === 422, 'Retired variant cannot be edited');
        $check($request('/api/admin/languages/default', 'PUT', ['locale' => $alias])->getStatusCode() === 422, 'Retired variant cannot be selected as an administrator default');
        $check($request('/api/admin/email-templates/verification_code', 'PUT', ['locale' => $alias, 'subject' => 'Fixture', 'body' => 'Fixture {VERIFICATION_CODE}'])->getStatusCode() === 422, 'Retired email variant cannot be written');
        $check($request('/api/admin/content-pages/support', 'PUT', ['locale' => $alias, 'enabled' => true, 'title' => 'Fixture', 'navigation_label' => 'Fixture', 'subtitle' => 'Fixture', 'sections' => [['title' => 'Fixture', 'body' => 'Fixture']]])->getStatusCode() === 422, 'Retired help page variant cannot be written');
    }
    $catalog = json_decode($request('/api/admin/languages')->getContent(), true);
    $check(count($catalog['languages'] ?? []) === 125, 'Admin catalog excludes all address-style duplicates');
    echo $checks." locale alias checks passed. Live settings and DB remain unchanged.\n";
} finally {
    DB::rollBack();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($temporary);
}
