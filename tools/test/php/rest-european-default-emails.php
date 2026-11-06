<?php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e::class.': '.$e->getMessage()."\n"); exit(1); });
use App\Support\EmailTemplates;
use App\Support\BrandedEmail;
use App\Services\LocalizationService;
$temporary = sys_get_temp_dir().'/rest-european-email-'.bin2hex(random_bytes(8));
mkdir($temporary.'/app',0700,true);
$app->useStoragePath($temporary);
config(['cache.default'=>'array']);
Illuminate\Support\Facades\Bus::fake();
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\DB::beginTransaction();
$b=['messages'=>[]];foreach(['north','east','balkan','extra'] as $group){$batch=json_decode(file_get_contents(base_path('tools/i18n/batches/rest-european-default-emails-'.$group.'.json')),true,512,JSON_THROW_ON_ERROR);foreach($batch['messages'] as $source=>$values)$b['messages'][$source]=array_merge($b['messages'][$source]??[],$values);}
$wrapper=['messages'=>[]];foreach(['north','east','balkan','extra'] as $group){$batch=json_decode(file_get_contents(base_path('tools/i18n/batches/rest-european-email-wrapper-'.$group.'.json')),true,512,JSON_THROW_ON_ERROR);foreach($batch['messages'] as $source=>$values)$wrapper['messages'][$source]=array_merge($wrapper['messages'][$source]??[],$values);}
$definitions = (new ReflectionMethod(EmailTemplates::class,'definitions'))->invoke(null);
$english = (array) app(LocalizationService::class)->pack('en')['translations'];
$check = static function(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); };
try {
    foreach(['nl_be','nl_nl','af','da_dk','sv_se','nb_no','nn_no','fi','ru','ru_ru','uk','pl_pl','cs_cz','sk_sk','bg_bg','ro_ro','hu_hu','el','sl_si','hr','bs_ba','sr_rs','mk_mk','sq','et','lv','lt_lt','bel','eu','fy','cy'] as $locale) {
        $reviewFile = base_path('tools/i18n/reviews/'.$locale.'.json');
        $review = json_decode(file_get_contents($reviewFile),true,512,JSON_THROW_ON_ERROR);
        $observed = [];
        foreach($definitions as $key=>$definition) {
            foreach([1,2,15] as $minutes) {
                $variables = array_combine($definition['placeholders'],array_map(fn($token)=>'literal_'.$token.'_Home_Save', $definition['placeholders']));
                $variables['EXPIRY_MINUTES'] = (string)$minutes;
                $replace=[];foreach($variables as $token=>$value)$replace['{'.$token.'}']=$value;
                $rendered=EmailTemplates::render($key,$variables,$locale);
                $check($rendered['locale']===$locale,$locale.' canonical renderer locale');
                foreach(['subject','body'] as $field) {
                    $source=$definition[$field]; $target=$b['messages'][$source][$locale]??$b['messages'][$source][explode('_',$locale)[0]];
                    $check($rendered[$field]===strtr($target,$replace),$locale.' '.$key.' '.$field.' complete template/tokens');
                    $observed[$source]=$target;
                }
                $html=BrandedEmail::render($rendered['subject'],$rendered['body'],$locale);
                $direction=in_array($locale,['ar','he_il','fa_ir','fa_af'],true)?'rtl':'ltr';
                $check(str_contains($html,'dir="'.$direction.'"'),$locale.' email direction');
                $check(str_contains($html,htmlspecialchars($rendered['subject'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),$locale.' escaped literal subject');
                $check(str_contains($html,($wrapper['messages']['Notification'][$locale]??$wrapper['messages']['Notification'][explode('_',$locale)[0]])),$locale.' native wrapper notification');
                $sentBy=$wrapper['messages']['This email was sent by {site}.'][$locale]??$wrapper['messages']['This email was sent by {site}.'][explode('_',$locale)[0]];
                $siteUrl=rtrim((string)config('app.url',''),'/')?:url('/');
                $check(str_contains(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'),str_replace('{site}',$siteUrl,$sentBy)),$locale.' complete localized sender footer');
                foreach($wrapper['messages'] as $source=>$values)$observed[$source]=$values[$locale]??$values[explode('_',$locale)[0]];
                foreach($variables as $token=>$value) if(str_contains($definition['body'],'{'.$token.'}')) $check(str_contains($html,htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),$locale.' literal body token '.$token);
            }
        }
        // Overrides are fixture-only under the temporary storage root; real admin content is never opened or changed.
        EmailTemplates::save($locale,'verification_code','Saved {VERIFICATION_CODE}','Home {VERIFICATION_CODE}');
        $custom=EmailTemplates::render('verification_code',['VERIFICATION_CODE'=>'literal_saved'],$locale);
        $check($custom['subject']==='Saved literal_saved'&&$custom['body']==='Home literal_saved',$locale.' administrator override remains literal');
        EmailTemplates::reset($locale,'verification_code');
        if(in_array('--record',$argv,true)) {
            foreach($observed as $source=>$target) {
                $row=&$review['messages'][hash('sha256',$source)];
                $check(($row['source_hash']??null)===hash('sha256',$english[$source]??$source),$locale.' current source hash');
                $check(($row['translation_hash']??null)===hash('sha256',$target),$locale.' current target hash');
                $row['status']='verified';$row['verified_at']=gmdate('c');
                $row['contexts']=array_values(array_unique([...$row['contexts'],'Actual EmailTemplates and BrandedEmail renderers (European northern/eastern groups): all 6 subjects/bodies; 1/2/15-minute expiry; literal usernames/roles/title/credentials/URLs retained; HTML escaping and LTR attributes; fixture-only override precedence; no sending or production writes. Rendering check, not complete visual email-client acceptance.']));
                unset($row);
            }
            file_put_contents($reviewFile,json_encode($review,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
        }
        echo $locale.': 6 complete email templates rendered with expiry/tokens/LTR/wrapper and literal override checks.'."\n";
    }
} finally {
    Illuminate\Support\Facades\DB::rollBack();
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($temporary);
}
