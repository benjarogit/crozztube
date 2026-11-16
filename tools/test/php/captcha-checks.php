<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\AuthSettings;
use App\Support\CaptchaSettings;
use App\Support\CaptchaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

$pass = $fail = 0;
function check(bool $condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
function throwsValidation(callable $callback): bool {
    try {
        $callback();
    } catch (ValidationException) {
        return true;
    }

    return false;
}

$ipCounter = 0;
$request = function (array $payload = [], array $headers = []) use (&$ipCounter): Request {
    $ipCounter++;
    $request = Request::create('/auth/register', 'POST', $payload);
    $request->server->set('REMOTE_ADDR', '198.51.100.'.(($ipCounter % 200) + 1));
    $request->headers->set('User-Agent', 'crozz-captcha-check');
    foreach ($headers as $key => $value) {
        $request->headers->set($key, $value);
    }

    return $request;
};

$service = new CaptchaService();

// Provider registry
check(count(CaptchaSettings::PROVIDERS) === 6, 'six providers are registered');
check(CaptchaSettings::PROVIDERS['recaptcha_v3']['weight'] === 2, 'external providers start with weight 2');
check(CaptchaSettings::PROVIDERS['simple']['weight'] === 1 && CaptchaSettings::PROVIDERS['question']['weight'] === 1, 'local providers start with weight 1');
check(CaptchaSettings::PROVIDERS['question']['native'] === false, 'question captcha is web-only');
check(CaptchaSettings::SCRIPTS['hcaptcha'] === 'https://js.hcaptcha.com/1/api.js?render=explicit', 'hCaptcha script is known');

// Legacy migration keeps behaviour
$google = CaptchaSettings::migrate(['registration' => ['captchaEnabled' => true, 'captchaProvider' => 'google', 'googleCaptchaSiteKey' => 'site', 'googleCaptchaSecretKey' => 'secret']]);
check($google['captcha']['registrationScope'] === 'always', 'legacy enabled captcha becomes always-on');
check($google['captcha']['providers']['recaptcha_v2']['enabled'] === true, 'legacy Google becomes reCAPTCHA v2');
check($google['captcha']['providers']['recaptcha_v2']['secretKey'] === 'secret', 'legacy secret is carried over');
check($google['captcha']['providers']['simple']['enabled'] === false, 'legacy Google disables the local image captcha');
$simple = CaptchaSettings::migrate(['registration' => ['captchaEnabled' => true, 'captchaProvider' => 'simple']]);
check($simple['captcha']['providers']['simple']['enabled'] === true, 'legacy simple captcha stays the local provider');
check($simple['captcha']['providers']['recaptcha_v2']['enabled'] === false, 'legacy simple captcha leaves Google off');
$fresh = CaptchaSettings::migrate([]);
check($fresh['captcha']['registrationScope'] === 'off', 'fresh installs start with captchas off');
check($fresh['captcha']['providers']['question']['enabled'] === true, 'fresh installs enable both local providers');

// Normalisation
$normalized = CaptchaSettings::normalize(['captcha' => [
    'registrationScope' => 'bogus',
    'loginScope' => 'always',
    'riskThreshold' => 999,
    'challengeTtlSeconds' => 1,
    'providers' => ['simple' => ['enabled' => true, 'weight' => 99]],
    'questions' => [['question' => 'Q', 'answer' => 'A'], ['question' => '', 'answer' => 'X']],
]]);
check($normalized['captcha']['registrationScope'] === 'off', 'unknown scopes fall back to off');
check($normalized['captcha']['loginScope'] === 'always', 'valid scopes survive');
check($normalized['captcha']['riskThreshold'] === 50, 'risk threshold is capped');
check($normalized['captcha']['challengeTtlSeconds'] === 60, 'challenge lifetime is floored');
check($normalized['captcha']['providers']['simple']['weight'] === 10, 'weights are capped at ten');
check(count($normalized['captcha']['questions']) === 1, 'empty questions are dropped');

// Question textarea
$parsed = CaptchaSettings::parseQuestions("What is 2 + 2? | 4\n# a comment\nBroken line\nCapital of France = Paris");
check(count($parsed) === 2, 'two valid question lines are parsed');
check($parsed[1]['answer'] === 'Paris', 'answers keep their text');
check(CaptchaSettings::parseQuestions("\n\n") === [], 'blank question text yields no questions');
check(CaptchaSettings::formatQuestions($parsed) === "What is 2 + 2? | 4\nCapital of France | Paris", 'questions round-trip through the editor text');

// Active providers and configuration
$config = static fn (array $providers, array $extra = []): array => CaptchaSettings::normalize(['captcha' => [
    'registrationScope' => 'always',
    'providers' => $providers,
    'questions' => [['question' => 'Two plus two?', 'answer' => 'four']],
    ...$extra,
]]);
$questionOnly = $config(['question' => ['enabled' => true], 'simple' => ['enabled' => false]]);
check(array_keys(CaptchaSettings::activeProviders($questionOnly)) === ['question'], 'only the enabled and configured provider is active');
check(CaptchaSettings::activeProviders($questionOnly, CaptchaSettings::SURFACE_NATIVE) === [], 'native surfaces skip the question captcha');
$externalWithoutKeys = $config(['question' => ['enabled' => false], 'simple' => ['enabled' => false], 'hcaptcha' => ['enabled' => true]]);
check(CaptchaSettings::activeProviders($externalWithoutKeys) === [], 'an external provider without keys is not active');
$externalWithKeys = $config(['question' => ['enabled' => false], 'simple' => ['enabled' => false], 'hcaptcha' => ['enabled' => true, 'siteKey' => 'site', 'secretKey' => 'secret']]);
check(isset(CaptchaSettings::activeProviders($externalWithKeys)['hcaptcha']), 'a configured external provider becomes active');
$public = CaptchaSettings::publicProviders($externalWithKeys);
check(! array_key_exists('secretKey', $public['hcaptcha'] ?? []), 'public provider data never contains a secret');
check(($public['hcaptcha']['siteKey'] ?? '') === 'site', 'public provider data keeps the site key');

// Secret masking for the admin projection
$masked = CaptchaSettings::masked(['captcha' => ['providers' => ['hcaptcha' => ['secretKey' => 'top-secret']]]]);
check(($masked['providers']['hcaptcha']['secretKey'] ?? 'x') === '', 'admin projection blanks the secret');
check(($masked['providers']['hcaptcha']['secretKeyConfigured'] ?? false) === true, 'admin projection flags a stored secret');

// Weighted rotation
$providers = ['simple' => ['weight' => 1], 'hcaptcha' => ['weight' => 2]];
check(CaptchaService::pickProvider($providers, static fn (int $total): int => 1) === 'simple', 'rotation honours the local weight');
check(CaptchaService::pickProvider($providers, static fn (int $total): int => 2) === 'hcaptcha', 'rotation reaches the weighted provider');
check(CaptchaService::pickProvider($providers, static fn (int $total): int => 3) === 'hcaptcha', 'rotation covers the full weighted range');
check(CaptchaService::pickProvider([], static fn (int $total): int => 1) === null, 'an empty provider set picks nothing');

// Risk and scopes
$off = $config([], ['registrationScope' => 'off']);
check(! $service->required($request(), CaptchaSettings::CONTEXT_REGISTRATION, $off), 'scope off never requires a challenge');
$always = $config([], ['registrationScope' => 'always']);
check($service->required($request(), CaptchaSettings::CONTEXT_REGISTRATION, $always), 'scope always requires a challenge');
$risk = $config([], ['loginScope' => 'risk', 'riskThreshold' => 6]);
check(! $service->required($request(), CaptchaSettings::CONTEXT_LOGIN, $risk, 0), 'low-risk logins skip the challenge');
check($service->required($request(), CaptchaSettings::CONTEXT_LOGIN, $risk, 3), 'three failed logins reach the risk threshold');
check($service->required($request(['captcha_honeypot' => 'http://spam']), CaptchaSettings::CONTEXT_LOGIN, $risk, 0), 'a filled honeypot forces the challenge');
check($service->required($request(['captcha_elapsed_ms' => 200]), CaptchaSettings::CONTEXT_LOGIN, $risk, 2), 'fast submissions raise the risk score');
$noAgent = $request();
$noAgent->headers->remove('User-Agent');
check($service->required($noAgent, CaptchaSettings::CONTEXT_LOGIN, $risk, 2), 'a missing user agent adds risk');

// Local question challenge and one-time verification
$localSettings = $config([
    'question' => ['enabled' => true],
    'simple' => ['enabled' => false],
]);
$challengeRequest = $request();
$challenge = $service->challenge($challengeRequest, CaptchaSettings::CONTEXT_REGISTRATION, $localSettings, static fn (int $total): int => 1);
check(($challenge['required'] ?? false) === true, 'a required challenge is issued');
check(($challenge['provider'] ?? '') === 'question', 'the server selected the question provider');
check(($challenge['kind'] ?? '') === 'question', 'the payload describes the question kind');
check(($challenge['question'] ?? '') === 'Two plus two?', 'the question text is returned');
check(strlen((string) ($challenge['token'] ?? '')) === 64, 'the challenge token is opaque');
$verifyRequest = $request(['captcha_token' => $challenge['token'], 'captcha_answer' => 'FOUR']);
$service->verify($verifyRequest, CaptchaSettings::CONTEXT_REGISTRATION, $localSettings);
check(true, 'the correct answer verifies');
check(throwsValidation(fn () => $service->verify($verifyRequest, CaptchaSettings::CONTEXT_REGISTRATION, $localSettings)), 'a challenge token is one-time only');

$wrongChallenge = $service->challenge($request(), CaptchaSettings::CONTEXT_REGISTRATION, $localSettings, static fn (int $total): int => 1);
check(throwsValidation(fn () => $service->verify(
    $request(['captcha_token' => $wrongChallenge['token'], 'captcha_answer' => 'five']),
    CaptchaSettings::CONTEXT_REGISTRATION,
    $localSettings,
)), 'a wrong answer is rejected');
check(throwsValidation(fn () => $service->verify($request(['captcha_token' => 'missing']), CaptchaSettings::CONTEXT_REGISTRATION, $localSettings)), 'an unknown token is rejected');
$contextChallenge = $service->challenge($request(), CaptchaSettings::CONTEXT_REGISTRATION, $localSettings, static fn (int $total): int => 1);
check(throwsValidation(fn () => $service->verify(
    $request(['captcha_token' => $contextChallenge['token'], 'captcha_answer' => 'four']),
    CaptchaSettings::CONTEXT_LOGIN,
    $localSettings,
)), 'a challenge is bound to its context');
check(($service->challenge($request(), CaptchaSettings::CONTEXT_REGISTRATION, $off)['required'] ?? true) === false, 'no challenge is issued when the scope is off');

// Remote providers answer through their documented endpoints. A single fake
// routing table is used because Laravel appends URL stubs instead of replacing
// them, so later stubs for the same URL would never run.
$fakeResponses = [];
Http::fake([
    'https://www.google.com/recaptcha/api/siteverify' => function () use (&$fakeResponses) { return Http::response($fakeResponses['google'] ?? ['success' => false]); },
    'https://api.hcaptcha.com/siteverify' => function () use (&$fakeResponses) { return Http::response($fakeResponses['hcaptcha'] ?? ['success' => false]); },
    'https://challenges.cloudflare.com/turnstile/v0/siteverify' => function () use (&$fakeResponses) { return Http::response($fakeResponses['turnstile'] ?? ['success' => false]); },
]);
$remoteSettings = $config([
    'question' => ['enabled' => false],
    'simple' => ['enabled' => false],
    'recaptcha_v2' => ['enabled' => true, 'siteKey' => 'site', 'secretKey' => 'secret'],
]);
$remoteChallenge = $service->challenge($request(), CaptchaSettings::CONTEXT_REGISTRATION, $remoteSettings, static fn (int $total): int => 1);
check(($remoteChallenge['kind'] ?? '') === 'recaptcha', 'reCAPTCHA v2 is rendered through the recaptcha widget');
check(($remoteChallenge['siteKey'] ?? '') === 'site', 'the public site key reaches the browser');
check(($remoteChallenge['script'] ?? '') === 'https://www.google.com/recaptcha/api.js?render=explicit', 'the recaptcha script is announced');
$fakeResponses['google'] = ['success' => true];
$service->verify($request(['captcha_token' => $remoteChallenge['token'], 'captcha_response' => 'provider-token']), CaptchaSettings::CONTEXT_REGISTRATION, $remoteSettings);
check(true, 'reCAPTCHA v2 accepts a successful siteverify');
$fakeResponses['google'] = ['success' => false];
$failed = $service->challenge($request(), CaptchaSettings::CONTEXT_REGISTRATION, $remoteSettings, static fn (int $total): int => 1);
check(throwsValidation(fn () => $service->verify(
    $request(['captcha_token' => $failed['token'], 'captcha_response' => 'bad-token']),
    CaptchaSettings::CONTEXT_REGISTRATION,
    $remoteSettings,
)), 'reCAPTCHA v2 rejects a failed siteverify');

$hcaptchaSettings = $config(['question' => ['enabled' => false], 'simple' => ['enabled' => false], 'hcaptcha' => ['enabled' => true, 'siteKey' => 'h-site', 'secretKey' => 'h-secret']]);
$hcaptchaChallenge = $service->challenge($request(), CaptchaSettings::CONTEXT_REGISTRATION, $hcaptchaSettings, static fn (int $total): int => 1);
$fakeResponses['hcaptcha'] = ['success' => true];
$service->verify($request(['captcha_token' => $hcaptchaChallenge['token'], 'captcha_response' => 'h-token']), CaptchaSettings::CONTEXT_REGISTRATION, $hcaptchaSettings);
check(true, 'hCaptcha accepts a successful siteverify');

$turnstileSettings = $config(['question' => ['enabled' => false], 'simple' => ['enabled' => false], 'turnstile' => ['enabled' => true, 'siteKey' => 't-site', 'secretKey' => 't-secret']]);
$turnstileChallenge = $service->challenge($request(), CaptchaSettings::CONTEXT_REGISTRATION, $turnstileSettings, static fn (int $total): int => 1);
$fakeResponses['turnstile'] = ['success' => true];
$service->verify($request(['captcha_token' => $turnstileChallenge['token'], 'captcha_response' => 't-token']), CaptchaSettings::CONTEXT_REGISTRATION, $turnstileSettings);
check(true, 'Turnstile accepts a successful siteverify');

// reCAPTCHA v3 scores and escalates to a visible provider
$v3Settings = $config([
    'question' => ['enabled' => true],
    'simple' => ['enabled' => false],
    'recaptcha_v3' => ['enabled' => true, 'siteKey' => 'v3-site', 'secretKey' => 'v3-secret', 'threshold' => 0.5],
]);
$v3Challenge = $service->challenge($request(), CaptchaSettings::CONTEXT_REGISTRATION, $v3Settings, static fn (int $total): int => $total);
check(($v3Challenge['kind'] ?? '') === 'recaptcha_v3', 'v3 is chosen when it wins the rotation');
check(($v3Challenge['invisible'] ?? false) === true, 'v3 is announced as invisible');
$fakeResponses['google'] = ['success' => true, 'score' => 0.9, 'action' => 'submit'];
$service->verify($request(['captcha_token' => $v3Challenge['token'], 'captcha_response' => 'v3-token']), CaptchaSettings::CONTEXT_REGISTRATION, $v3Settings);
check(true, 'v3 accepts a score above the threshold');
$lowScore = $service->challenge($request(), CaptchaSettings::CONTEXT_REGISTRATION, $v3Settings, static fn (int $total): int => $total);
$fakeResponses['google'] = ['success' => true, 'score' => 0.1, 'action' => 'submit'];
$lowRequest = $request(['captcha_token' => $lowScore['token'], 'captcha_response' => 'low-token']);
check(throwsValidation(fn () => $service->verify($lowRequest, CaptchaSettings::CONTEXT_REGISTRATION, $v3Settings)), 'v3 rejects a low score');
$escalated = $service->challenge($lowRequest, CaptchaSettings::CONTEXT_REGISTRATION, $v3Settings, static fn (int $total): int => $total);
check(($escalated['provider'] ?? '') !== 'recaptcha_v3', 'a failed v3 challenge escalates to a visible provider');
check(($escalated['kind'] ?? '') === 'question', 'the escalated challenge is solvable by a person');

// Public settings never leak secrets
$publicJson = json_encode(AuthSettings::publicSettings());
check(! str_contains((string) $publicJson, 'secretKey'), 'public settings never expose a secret field');
$publicAuth = AuthSettings::publicSettings();
check(in_array($publicAuth['captcha']['registration']['scope'], CaptchaSettings::scopes(), true), 'public registration scope is a known value');
check(is_int($publicAuth['captcha']['login']['threshold']), 'public login threshold is exposed');
$adminAuth = AuthSettings::adminSettings();
check(array_key_exists('questionsText', $adminAuth['captcha']), 'admin settings expose the question textarea');
check(($adminAuth['captcha']['providers']['hcaptcha']['secretKey'] ?? 'x') === '', 'admin settings blank every provider secret');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
