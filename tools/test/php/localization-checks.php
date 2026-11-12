<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error::class.': '.$error->getMessage()."\n"); exit(1); });

use App\Support\EmailTemplates;

// All template edits are confined to temporary storage. No emails or DB writes.
$temporary = sys_get_temp_dir().'/crozztube-localization-'.bin2hex(random_bytes(8));
mkdir($temporary.'/app', 0700, true);
$app->useStoragePath($temporary);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) throw new RuntimeException($message);
    $checks++;
};
try {
    $variables = ['USERNAME' => 'Saved', 'VERIFICATION_CODE' => '123456', 'EXPIRY_MINUTES' => 15, 'SITENAME' => 'TestSite'];
    foreach (['de_de', 'de_at', 'de_ch', 'de_ch_informal'] as $locale) {
        $rendered = EmailTemplates::render('verification_code', $variables, $locale);
        $check($rendered['subject'] === 'Dein Bestätigungscode für TestSite', $locale.' subject');
        $check(str_contains($rendered['body'], '123456') && str_contains($rendered['body'], '15 Min.'), $locale.' code and expiry');
        foreach (EmailTemplates::catalog($locale)['templates'] as $template) {
            $check(! $template['customized'], 'A localized default is not an admin override');
            $check($template['body'] === $template['default_body'], 'Reset preview uses the localized default');
            $check(! preg_match('/\b(Sie|Ihnen|Ihre)\b/u', $template['body']), 'Personal address in complete email');
        }
    }
    $rendered = EmailTemplates::render('password_reset_code', ['USERNAME' => 'Saved', 'RESET_CODE' => '789012', 'EXPIRY_MINUTES' => 10], 'de_de');
    $check(str_contains($rendered['body'], 'Hallo Saved,'), 'Recipient names are not translated');
    $check(str_contains($rendered['body'], '789012'), 'Reset token is retained');
    $check(str_starts_with(EmailTemplates::render('verification_code', $variables, 'en')['subject'], 'Your '), 'English defaults remain English');
    EmailTemplates::save('en', 'verification_code', 'Admin {SITENAME}', 'Code {VERIFICATION_CODE}');
    $check(EmailTemplates::render('verification_code', $variables, 'de_de')['subject'] === 'Admin TestSite', 'Existing English admin fallback remains authoritative');
    EmailTemplates::save('de_de', 'verification_code', 'Eigener Betreff', 'Eigener Code {VERIFICATION_CODE}');
    $check(EmailTemplates::render('verification_code', $variables, 'de_de')['body'] === 'Eigener Code 123456', 'Exact locale override wins');
    EmailTemplates::reset('en', 'verification_code');
    EmailTemplates::reset('de_de', 'verification_code');
    $check(EmailTemplates::render('verification_code', $variables, 'de_de')['subject'] === 'Dein Bestätigungscode für TestSite', 'Reset restores localized default');

    // Corrupted translation fallback, using an isolated catalog as well.
    mkdir($temporary.'/languages/de_de', 0700, true);
    file_put_contents($temporary.'/languages/de_de/de_de.php', '<?php return '.var_export([
        'Your {SITENAME} verification code' => 'Fehler ohne Platzhalter',
        "Your {SITENAME} verification code is: {VERIFICATION_CODE}\n\nThis code expires in {EXPIRY_MINUTES} minutes." => 'Fehler ohne Code',
    ], true).';');
    config(['playhub.language_path' => $temporary.'/languages']);
    $rendered = EmailTemplates::render('verification_code', $variables, 'de_de');
    $check($rendered['subject'] === 'Your TestSite verification code', 'Damaged subject falls back to intact source');
    $check(str_contains($rendered['body'], '123456') && str_contains($rendered['body'], '15 minutes'), 'Damaged email cannot lose security tokens');
    echo $checks." localization checks passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($temporary);
}
