<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\ReportReasons;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

$pass = $fail = 0;
function check(bool $condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

// Reasons stay stable because they are stored in the report tables.
check(ReportReasons::keys(ReportReasons::VIDEO) === ['spam', 'abuse', 'violence', 'copyright', 'other'], 'video reasons keep their stored keys');
check(in_array('harassment', ReportReasons::keys(ReportReasons::COMMENT), true), 'comment reasons cover harassment');
check(in_array('impersonation', ReportReasons::keys(ReportReasons::PROFILE), true), 'profile reasons cover impersonation');

// Labels are resolved at read time so renaming never rewrites a row.
check(ReportReasons::label(ReportReasons::VIDEO, 'copyright') === 'Copyright infringement', 'video label resolves');
check(ReportReasons::adminLabel(ReportReasons::COMMENT, 'hate') === 'Hate speech', 'comment admin label resolves');
check(ReportReasons::adminLabel(ReportReasons::PROFILE, null) === 'No reason given', 'missing legacy reason stays readable');
check(ReportReasons::label(ReportReasons::VIDEO, 'unknown') === null, 'unknown reasons resolve to nothing');

// A reason is required, the description is optional and capped.
$rules = ReportReasons::rules(ReportReasons::VIDEO);
check(in_array('required', $rules['reason'], true), 'reason is required');
check(in_array('max:500', $rules['details'], true), 'details are capped at 500 characters');
$rejectsUnknown = Validator::make(['reason' => 'not-a-reason'], $rules)->fails();
$acceptsKnown = Validator::make(['reason' => 'spam', 'details' => 'Reposted without credit'], $rules)->passes();
check($rejectsUnknown, 'unknown reasons are rejected');
check($acceptsKnown, 'known reasons are accepted');

check(ReportReasons::details('   ') === null, 'blank details become null');
check(ReportReasons::details(str_repeat('a', 600)) === str_repeat('a', 500), 'long details are truncated to 500');
check(mb_strlen(ReportReasons::details(str_repeat('ä', 300)) ?? '') === 300, 'multibyte details survive trimming');

// Every report table carries the new columns.
foreach (['video_reports', 'comment_reports', 'user_reports'] as $table) {
    check(Schema::hasTable($table), "{$table} exists");
    check(Schema::hasColumn($table, 'reason'), "{$table} has reason");
    check(Schema::hasColumn($table, 'details'), "{$table} has details");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
