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

$expected = ['radiusSm' => '6px', 'radius' => '8px', 'radiusLg' => '12px', 'radiusMedia' => '12px', 'radiusPill' => '999px'];
$defaults = GeneralSettings::defaultSettings();
check($defaults['design']['radiusScaleVersion'] === 4, 'fresh installs use scale 4');
foreach ($expected as $key => $value) {
    check($defaults['design']['radii'][$key] === $value, "fresh installs use {$value} for {$key}");
}

$versions = [
    ['radii' => ['radius' => '14px', 'radiusLg' => '18px']],
    ['radiusScaleVersion' => 2, 'radii' => ['radius' => '10px', 'radiusLg' => '12px', 'radiusMedia' => '8px']],
    ['radiusScaleVersion' => 3, 'radii' => ['radius' => '4px', 'radiusLg' => '4px', 'radiusMedia' => '4px', 'radiusPill' => '2px']],
];
foreach ($versions as $index => $design) {
    $migrated = GeneralSettings::migrateRadiusScale(['design' => $design]);
    check($migrated['design']['radiusScaleVersion'] === 4, "historical design {$index} migrates to scale 4");
    foreach ($expected as $key => $value) {
        check($migrated['design']['radii'][$key] === $value, "historical design {$index} migrates {$key}");
    }
    check(GeneralSettings::migrateRadiusScale($migrated) === $migrated, 'migration is idempotent');
}

$custom = ['radius' => '9px', 'radiusLg' => '20px', 'radiusMedia' => '7px', 'radiusPill' => '16px', 'radiusSm' => '5px'];
$result = GeneralSettings::migrateRadiusScale(['design' => ['radiusScaleVersion' => 3, 'radii' => $custom]]);
check($result['design']['radii'] === $custom, 'all customized radii are preserved');

foreach ([1, 2] as $version) {
    $customFour = ['radius' => '4px', 'radiusLg' => '4px', 'radiusMedia' => '4px'];
    $original = ['radii' => $customFour];
    if ($version === 2) {
        $original['radiusScaleVersion'] = 2;
    }
    $result = GeneralSettings::migrateRadiusScale(['design' => $original]);
    check(array_intersect_key($result['design']['radii'], $customFour) === $customFour, "custom 4px stops being mistaken for shipped defaults in v{$version}");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
