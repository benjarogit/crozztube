<?php
require __DIR__.'/../../../vendor/autoload.php';
$app=require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage()."\n");exit(1);});
use App\Support\EmailTemplates;
use App\Support\BrandedEmail;
use App\Services\LocalizationService;
$temporary=sys_get_temp_dir().'/crozztube-reference-emails-'.bin2hex(random_bytes(8));
mkdir($temporary.'/app',0700,true);$app->useStoragePath($temporary);config(['cache.default'=>'array']);
$definitions=(new ReflectionMethod(EmailTemplates::class,'definitions'))->invoke(null);
$service=app(LocalizationService::class);$reference=(array)$service->pack('en')['translations'];
$locales=['en','en_au','en_ca','en_gb','en_nz','en_za','de_de','de_at','de_ch'];
$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
try{foreach($locales as$locale){
    $observed=[];$dictionary=(array)$service->pack($locale)['translations'];
    foreach($definitions as$key=>$definition)foreach([1,2,15]as$minutes){
        $variables=array_fill_keys($definition['placeholders'],'Saved <Home & Settings>');
        $variables['EXPIRY_MINUTES']=$minutes;
        $rendered=EmailTemplates::render($key,$variables,$locale);
        foreach(['subject','body']as$field){
            $assert(!preg_match('/\{[A-Z_]+\}/',$rendered[$field]),$locale.' all known placeholders resolved');
            $assert(str_contains($rendered[$field],'Saved <Home & Settings>'),$locale.' literal names retained');
            $observed[$definition[$field]]=$dictionary[$definition[$field]];
        }
        if(in_array('EXPIRY_MINUTES',$definition['placeholders'],true)){
            $anchor=str_starts_with($locale,'de_')?"{$minutes} Min. gültig.":"valid for {$minutes} min.";
            $assert(str_contains($rendered['body'],$anchor),$locale.' grammatical invariant expiry at1/2/15');
        }
        $html=BrandedEmail::render($rendered['subject'],$rendered['body'],$locale);
        $assert(str_contains($html,'Saved &lt;Home &amp; Settings&gt;'),$locale.' literal content escaped as HTML');
        $assert(!str_contains($html,'Saved <Home & Settings>'),$locale.' raw HTML does not leak from variables');
    }
    EmailTemplates::save($locale,'verification_code','Home {VERIFICATION_CODE}','Saved {VERIFICATION_CODE}');
    $custom=EmailTemplates::render('verification_code',['VERIFICATION_CODE'=>'literal'],$locale);
    $assert($custom['subject']==='Home literal'&&$custom['body']==='Saved literal',$locale.' fixture-only override retains priority');
    EmailTemplates::reset($locale,'verification_code');
    if(in_array('--record',$argv,true)){
        $file=base_path('tools/i18n/reviews/'.$locale.'.json');$review=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        foreach($observed as$source=>$translation){
            $row=&$review['messages'][hash('sha256',$source)];
            $assert(($row['source_hash']??null)===hash('sha256',$reference[$source]??$source),$locale.' current editorial reference');
            $assert(($row['translation_hash']??null)===hash('sha256',$translation),$locale.' current editorial target');
            $row['status']='verified';$row['verified_at']=gmdate('c');
            $row['contexts']=array_values(array_unique([...$row['contexts'],'Actual six standard EmailTemplates/BrandedEmail renders in temporary storage: expiry1/2/15 with independent min./Min. grammar anchors, literal names and HTML escaping, fixture-only administrator override precedence; no email sending or production override writes.']));unset($row);
        }
        file_put_contents($file,json_encode($review,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    }
    echo $locale.": all six reference emails passed, including1/2/15 expiry grammar and literal overrides.\n";
}}finally{
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($temporary);
}
