<?php
require __DIR__.'/../../../vendor/autoload.php';
$app=require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error::class.': '.$error->getMessage()."\n");exit(1);});
use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
$method=new ReflectionMethod(AuthController::class,'validateRegistrationPasswordStrength');
$settings=['registration'=>['passwordStrengthEnabled'=>true,'passwordStrengthLevel'=>8]];
$controller=app(AuthController::class);$checks=0;
foreach(['de_de','de_de_formal','de_at','de_ch','en']as$locale){
    $request=Request::create('/api/auth/register','POST');
    $request->headers->set('X-PlayHub-Language',$locale);
    try{$method->invoke($controller,'a',$settings,$request);throw new RuntimeException('Weak password must fail');}
    catch(ValidationException$error){
        $expected=$locale==='en'?'Password strength must be at least 8/10.':'Dein Passwort muss mindestens die Stärke 8/10 erreichen.';
        if(($error->errors()['password'][0]??null)!==$expected)throw new RuntimeException($locale.': complete server password message');
        $checks++;
    }
    $method->invoke($controller,'Aa2!VeryLongSecure',$settings,$request);$checks++;
}
echo "$checks localized password-validation checks passed; no registration or account writes.\n";
