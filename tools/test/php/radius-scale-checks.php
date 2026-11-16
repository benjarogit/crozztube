<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\GeneralSettings;

set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error."\n"); exit(1); });

$pass = $fail = 0;
function check(bool $condition, string $name): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

// Fresh installs get the flat 4px scale with 2px pills.
$defaults = GeneralSettings::defaultSettings();
check(($defaults['design']['radiusScaleVersion'] ?? null) === 3, 'defaults carry scale version 3');
foreach (['radiusMedia', 'radius', 'radiusLg'] as $key) {
    check(($defaults['design']['radii'][$key] ?? null) === '4px', "default {$key} is 4px");
}
check(($defaults['design']['radii']['radiusPill'] ?? null) === '2px', 'default radiusPill is 2px');

// A v2 record that still holds shipped values moves to 4px.
$v2 = GeneralSettings::migrateRadiusScale([
    'design' => [
        'radiusScaleVersion' => 2,
        'radii' => ['radiusMedia' => '8px', 'radius' => '10px', 'radiusLg' => '12px'],
    ],
]);
check($v2['design']['radiusScaleVersion'] === 3, 'v2 record upgraded to 3');
check($v2['design']['radii']['radius'] === '4px', 'v2 shipped default radius becomes 4px');
check($v2['design']['radii']['radiusMedia'] === '4px', 'v2 shipped media radius becomes 4px');
check($v2['design']['radii']['radiusLg'] === '4px', 'v2 shipped large radius becomes 4px');
check($v2['design']['radii']['radiusPill'] === '2px', 'v2 record receives pill radius');

// Administrator-authored values are preserved.
$custom = GeneralSettings::migrateRadiusScale([
    'design' => [
        'radiusScaleVersion' => 2,
        'radii' => ['radiusMedia' => '6px', 'radius' => '9px', 'radiusLg' => '20px'],
    ],
]);
check($custom['design']['radii']['radius'] === '9px', 'custom radius preserved');
check($custom['design']['radii']['radiusLg'] === '20px', 'custom large radius preserved');
check($custom['design']['radii']['radiusPill'] === '2px', 'custom record receives pill radius');

// Legacy records without a version are migrated through both scales.
$legacy = GeneralSettings::migrateRadiusScale(['design' => ['radii' => ['radius' => '14px', 'radiusLg' => '18px']]]);
check($legacy['design']['radii']['radius'] === '4px', 'legacy 14px radius becomes 4px');
check($legacy['design']['radii']['radiusLg'] === '4px', 'legacy 18px radius becomes 4px');
check($legacy['design']['radii']['radiusMedia'] === '4px', 'legacy record gains media radius');
check($legacy['design']['radii']['radiusPill'] === '2px', 'legacy record gains pill radius');

// Migration is idempotent.
$twice = GeneralSettings::migrateRadiusScale($v2);
check($twice === $v2, 'migration is idempotent');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
