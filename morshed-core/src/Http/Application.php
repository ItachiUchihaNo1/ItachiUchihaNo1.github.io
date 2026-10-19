<?php
declare(strict_types=1);
namespace Morshed\Http;
use Morshed\Core\{Database,RateLimiter,Audit,Problem};
use Morshed\Identity\{Accounts,Sessions,ExternalAccounts,ProviderHttp,Profile};
use Morshed\Access\Administration;
use Morshed\Legal\Documents;
use Morshed\Notifications\{TsmsSendOnlyGateway,CurlFormTransport};
final class Application
{
    private Database $db;private Accounts $accounts;private Sessions $sessions;private Administration $admin;private Documents $legal;private View $view;
    private ExternalAccounts $external;private ?array $actor=null;private array $input=[];private string $path;private bool $api=false;private string $cookie;private string $method;
    public function __construct(private array $config,private string $realm)
    {
        if(!in_array($realm,['site','admin'],true)){throw new \LogicException('Unknown realm.');}
        $this->transport();
        $csrfName=$this->secure()?'__Host-morshed-csrf-'.$realm:'morshed_dev_csrf_'.$realm;
        $this->cookie=$this->secure()?'__Host-morshed-'.$realm:'morshed_dev_'.$realm;
        ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');
        session_name($csrfName);
        $save=__DIR__.'/../../storage/sessions/'.$realm;if(!is_dir($save)&&!mkdir($save,0700,true)&&!is_dir($save)){throw new \RuntimeException('Session directory unavailable.');}
        session_save_path($save);session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$this->secure(),'httponly'=>true,'samesite'=>'Lax']);
        if(!session_start()){throw new \RuntimeException('CSRF session unavailable.');}
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
        $this->db=new Database($config['database']);$audit=new Audit($this->db);$rate=new RateLimiter($this->db,$config['app_key']);
        if(!in_array((int)$this->db->run('SELECT MAX(version) FROM m_schema_migrations')->fetchColumn(),[6,7],true)){throw new \RuntimeException('Database migration version mismatch.');}
        $this->sessions=new Sessions($this->db,$audit);$this->admin=new Administration($this->db,$rate,$audit,$config['setup']??[]);
        $this->accounts=new Accounts($this->db,$rate,$audit,new TsmsSendOnlyGateway($config['sms']??[],new CurlFormTransport()),$config['app_key']);
        $this->legal=new Documents($this->db,$audit);$this->external=new ExternalAccounts($this->db,$audit,$rate,new ProviderHttp(),$config);
        $token=$_COOKIE[$this->cookie]??null;$this->actor=$this->sessions->resolve(is_string($token)?$token:null,$realm);
        $config['_schema_version']=(int)$this->db->run('SELECT MAX(version) FROM m_schema_migrations')->fetchColumn();$this->view=new View($config,$realm,$_SESSION['csrf'],$this->actor);
        $raw=$_GET['r']??parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        if(!is_string($raw)||strlen($raw)>200||!str_starts_with($raw,'/')||str_contains($raw,'..')){throw new Problem('NOT_FOUND','صفحه پیدا نشد.',404);}
        $this->path=$raw;$this->api=str_starts_with($raw,'/api/v1/');if($this->api){$this->path=substr($raw,7);}
        $this->method=$_SERVER['REQUEST_METHOD']??'GET';
        if($this->method==='POST'){
            if(str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')){
                $limit=in_array($this->path,['/experience/save','/mentor/experience/save'],true)?524288:20000;$body=file_get_contents('php://input',false,null,0,$limit+1);if(strlen($body)>$limit){throw new Problem('TOO_LARGE','درخواست بیش از حد بزرگ است.',413);}
                try{$decoded=json_decode($body,false,16,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new Problem('INVALID_JSON','ساختار درخواست معتبر نیست.');}
                if(!is_object($decoded)){throw new Problem('INVALID_JSON','ساختار درخواست معتبر نیست.');}$this->input=(array)$decoded;
            }else{$this->input=$_POST;}
            foreach(['login','phone','display_name','username','user_id','id','purpose','title','body','challenge_id','first_name','last_name','province','city','gender'] as $key){if(is_string($this->input[$key]??null)){$this->view->old[$key]=$this->input[$key];}}
        }
    }
    private function secure():bool{return !empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off';}
    private function transport():void
    {
        $origin=$this->config['origins'][$this->realm];$parts=parse_url($origin);
        $expected=strtolower($parts['host'].(isset($parts['port'])?':'.$parts['port']:''));
        if(strtolower($_SERVER['HTTP_HOST']??'')!==$expected){throw new Problem('INVALID_HOST','دامنه درخواست معتبر نیست.',400);}
        $dev=($this->config['environment']??'')==='testing'&&($this->config['allow_http_loopback']??false)===true&&in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)&&in_array($parts['host'],['127.0.0.1','localhost','[::1]'],true);
        if(!$this->secure()&&!$dev){throw new Problem('HTTPS_REQUIRED','برای استفاده از مرشد، HTTPS معتبر دامنه باید فعال باشد.',503);}
    }
    private function text(string $key,int $max=60000):string
    { $v=$this->input[$key]??'';if(!is_string($v)||strlen($v)>$max){throw new Problem('INVALID_INPUT','مقدار ورودی معتبر نیست.');}return $v; }
    private function queryString(string $key,int $max):string{ $v=$_GET[$key]??'';if(!is_string($v)||strlen($v)>$max){throw new Problem('INVALID_INPUT','پارامتر ورود معتبر نیست.');}return $v;}
    private function requireActor():array
    {
        if(!$this->actor){if(!$this->api&&$this->method==='GET'){if(in_array($this->path,['/account','/account/security','/account/profile','/account/rules','/account/phone','/account/complete','/account/sessions','/me/sessions','/notifications','/security'],true)){$_SESSION['return_to']=$this->path;}$this->redirect('/login');}throw new Problem('UNAUTHENTICATED','ابتدا وارد حساب شوید.',401);}return $this->actor;
    }
    private function csrf():void
    {
        $got=$_SERVER['HTTP_X_CSRF_TOKEN']??$this->input['csrf']??'';
        if(!is_string($got)||!hash_equals($_SESSION['csrf'],$got)){throw new Problem('CSRF_FAILED','اعتبار فرم پایان یافته است؛ داده‌های فرم حفظ شده‌اند. دوباره تلاش کنید.',419);}
        $origin=$_SERVER['HTTP_ORIGIN']??null;
        if($origin!==null&&$origin!==$this->config['origins'][$this->realm]){throw new Problem('CSRF_FAILED','مبدأ درخواست معتبر نیست.',419);}
    }
    private function redirect(string $route):never{header('Location: '.$this->view->url($route),true,303);exit;}
    private function json(mixed $data,int $status=200):void{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function success(string $route,array $data=['ok'=>true]):void
    {if($this->api){$this->json($data+['redirect'=>$this->view->url($route)]);}else{$_SESSION['flash']='عملیات با موفقیت انجام شد.';$this->redirect($route);}}
    private function afterLogin():string{$uid=$_SESSION['issued_user']??($this->actor['user_id']??null);unset($_SESSION['issued_user']);if($this->realm==='site'&&$uid){$u=$this->db->one('SELECT password_hash FROM m_users WHERE id=?',[$uid]);if(!$u['password_hash']||!$this->accounts->username($uid)||!Profile::complete($this->db,$uid)){if(empty($this->db->one('SELECT phone FROM m_users WHERE id=?',[$uid])['phone'])&&$this->db->one("SELECT id FROM m_auth_identities WHERE user_id=? AND provider='eitaa'",[$uid])){return '/account/identify';}return '/account/complete';}}$route=$_SESSION['return_to']??'/account';unset($_SESSION['return_to']);return is_string($route)&&str_starts_with($route,'/')&&!str_starts_with($route,'//')&&!preg_match('/[\r\n]/',$route)?$route:'/account';}
    private function issue(string $user,string $method,?string $admin=null):void
    {
        if($this->actor){$this->sessions->revoke($this->actor['user_id'],$this->realm,$this->actor['session_id']);}
        $_SESSION['issued_user']=$user;$s=$this->sessions->issue($user,$this->realm,$method,$admin,$_SERVER['HTTP_USER_AGENT']??'');
        setcookie($this->cookie,$s['token'],['expires'=>$s['expires_at'],'path'=>'/','secure'=>$this->secure(),'httponly'=>true,'samesite'=>'Lax']);
        session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    private function completedAccount():void
    {
        if($this->realm!=='site'||!$this->actor){return;}
        $protected=in_array($this->path,['/account','/account/security','/account/profile','/account/rules','/account/phone','/mentor','/sessions','/support','/support/create','/ticket','/ticket/reply','/booking','/bookings/create','/session','/feedback'],true)||str_starts_with($this->path,'/booking/')||str_starts_with($this->path,'/session/')||(str_starts_with($this->path,'/mentor/')&&$this->path!=='/mentor/profile');
        if(!$protected){return;}$user=$this->db->one('SELECT password_hash FROM m_users WHERE id=?',[$this->actor['user_id']]);
        if(!$user['password_hash']||!$this->accounts->username($this->actor['user_id'])||!Profile::complete($this->db,$this->actor['user_id'])){if(!$this->api&&$this->method==='GET'){$_SESSION['return_to']=$this->path;$this->redirect($this->afterLogin());}throw new Problem('ACCOUNT_INCOMPLETE','ابتدا اطلاعات حساب، نام کاربری و رمز عبور را تکمیل کنید.',409);}
    }
    public function run():void
    {
        try{
            $product=new ProductController($this->db,$this->view,$this->actor,$this->realm,$this->api);
            if($this->method==='POST'){$this->csrf();$this->completedAccount();if(!$product->post($this->path,$this->input)){$this->post();}}
            elseif($this->method==='GET'){$this->completedAccount();if(!$product->get($this->path)){$this->get();}}
            else{throw new Problem('METHOD_NOT_ALLOWED','روش درخواست مجاز نیست.',405);}
        }catch(Problem $e){$this->error($e);}
    }
    private function post():void
    {
        $ip=$_SERVER['REMOTE_ADDR']??'';
        if($this->path==='/logout'){
            $a=$this->requireActor();$this->sessions->revoke($a['user_id'],$this->realm,$a['session_id']);
            setcookie($this->cookie,'',['expires'=>1,'path'=>'/','secure'=>$this->secure(),'httponly'=>true,'samesite'=>'Lax']);session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));$this->success('/login');return;
        }
        if($this->path==='/auth/password'){
            if($this->realm==='site'){$user=$this->accounts->passwordLogin(($this->text('login',64)?:$this->text('phone',32)),$this->text('password',256),$ip);$this->issue($user,'password');$this->success($this->afterLogin());}
            else{$a=$this->admin->login($this->text('username',64),$this->text('password',256),$ip);$this->issue($a['user_id'],'admin_password',$a['admin_id']);$this->success('/');}return;
        }
        if($this->realm==='admin'){$this->postAdmin($ip);return;}
        if($this->path==='/auth/eitaa/context'){
            if(!($this->config['eitaa']['enabled']??false)||!($this->config['eitaa']['live_verified']??false)){throw new Problem('PROVIDER_DISABLED','ورود با ایتا هنوز راه‌اندازی نشده است.',503);}
            $data=$this->text('init_data',16384);if($data===''){throw new Problem('INVALID_INPUT','داده ایتا دریافت نشد.');}
            if(!isset($_SESSION['eitaa_context'])||($_SESSION['eitaa_context']['expires']??0)<time()||!hash_equals($_SESSION['eitaa_context']['fingerprint']??'',hash('sha256',$data))){$proof=$this->external->captureEitaa($data,$ip);$_SESSION['eitaa_context']=['proof'=>$proof,'fingerprint'=>hash('sha256',$data),'expires'=>time()+900];}$this->json(['ok'=>true]);return;
        }
        if(in_array($this->path,['/auth/google/start','/auth/eitaa/start'],true)){
            if($this->path==='/auth/eitaa/start'&&$this->text('init_data',16384)===''&&(!isset($_SESSION['eitaa_context']['expires'])||$_SESSION['eitaa_context']['expires']<=time())){if($this->api){$this->json(['launch_url'=>'https://eitaa.com/morshed_app']);}else{header('Location: https://eitaa.com/morshed_app',true,303);}return;}
            $provider=$this->path==='/auth/google/start'?'google':'eitaa';$_SESSION['external_browser']??=bin2hex(random_bytes(32));
            $f=$this->external->begin($provider,$this->text('purpose',12)?:'login',$this->actor,$_SESSION['external_browser'],$ip);
            if($provider==='google'){if($this->api){$this->json($f);}else{header("Content-Security-Policy: default-src 'self'; form-action 'self' https://accounts.google.com; frame-ancestors 'none'; base-uri 'none'");header('Location: '.$f['url'],true,303);}}
            else{
                $data=$this->text('init_data',16384);$cached=$_SESSION['eitaa_context']??null;unset($_SESSION['eitaa_context']);
                if(is_array($cached)&&($cached['expires']??0)>time()&&isset($cached['proof'])&&($data===''||hash_equals($cached['fingerprint'],hash('sha256',$data)))){$r=$this->external->eitaaCaptured($f['state'],$cached['proof'],$_SESSION['external_browser'],$this->actor);if($r['purpose']==='login'){$this->issue($r['user_id'],'eitaa');}$this->success($this->afterLogin());return;}
                if($data!==''){$r=$this->external->eitaa($f['state'],$data,$_SESSION['external_browser'],$this->actor,$ip);if($r['purpose']==='login'){$this->issue($r['user_id'],'eitaa');}$this->success($this->afterLogin());return;}
                if($this->api){$this->json(['launch_url'=>'https://eitaa.com/morshed_app']);}else{header('Location: https://eitaa.com/morshed_app',true,303);}}return;
        }
        if($this->path==='/auth/eitaa/complete'){$r=$this->external->eitaa($this->text('state',64),$this->text('init_data',16384),$_SESSION['external_browser']??'',$this->actor,$ip);if($r['purpose']==='login'){$this->issue($r['user_id'],'eitaa');}$this->success($this->afterLogin());return;}
        if($this->path==='/auth/otp/request'){
            $purpose=$this->text('purpose',20);if(!in_array($purpose,['login','reset'],true)){throw new Problem('INVALID_PURPOSE','درخواست معتبر نیست.');}
            $id=$this->accounts->requestCode($this->text('phone',32),$purpose,$ip);$this->rememberCode($id,$purpose);if(!$this->api){$this->redirect('/auth/otp/code');}$this->codeForm($id,$purpose);return;
        }
        if($this->path==='/auth/otp/resend'){
            $flow=$_SESSION['otp_flow']??[];$id=$this->text('challenge_id',32);
            if(!isset($flow['id'])||!hash_equals($flow['id'],$id)){throw new Problem('INVALID_CODE','درخواست ارسال متعلق به این مرورگر نیست؛ شماره را دوباره وارد کنید.',403);}
            $next=$this->accounts->resendCode($id,$flow['purpose'],$ip);$this->rememberCode($next,$flow['purpose']);if(isset($flow['eitaa_source'])){$_SESSION['otp_flow']['eitaa_source']=$flow['eitaa_source'];}if(!$this->api){$this->redirect('/auth/otp/code');}$this->codeForm($next,$flow['purpose']);return;
        }
        if($this->path==='/auth/otp/verify'){
            $flow=$_SESSION['otp_flow']??[];if(isset($flow['eitaa_source'])&&(!$this->actor||$flow['eitaa_source']!==$this->actor['user_id']||!hash_equals($flow['id'],$this->text('challenge_id',32)))){throw new Problem('INVALID_STATE','نشست اتصال حساب تغییر کرده است.',401);}$id=$this->accounts->verifyLogin($this->text('challenge_id',32),$this->text('code',12),$ip);if(isset($flow['eitaa_source'])){$this->external->connectVerifiedPhone($this->actor,$id,'verified_sms');}unset($_SESSION['otp_flow']);$this->issue($id,'otp');$this->success($this->afterLogin());return;
        }
        if($this->path==='/auth/password/reset'){
            $this->accounts->resetPassword($this->text('challenge_id',32),$this->text('code',12),$this->text('new_password',256),$ip);unset($_SESSION['otp_flow']);$this->success('/login');return;
        }
        $a=$this->requireActor();
        switch($this->path){
            case '/me/eitaa/contact':$uid=$this->external->contact($a,$this->text('contact_data',16384),$ip);$this->issue($uid,'eitaa');$this->success($this->afterLogin());return;
            case '/me/eitaa/phone/request':$this->external->requirePhoneLink($a);$id=$this->accounts->requestCode($this->text('phone',32),'login',$ip);$this->rememberCode($id,'login');$_SESSION['otp_flow']['eitaa_source']=$a['user_id'];if(!$this->api){$this->redirect('/auth/otp/code');}$this->codeForm($id,'login');return;
            case '/me/identities/unlink':$this->external->unlink($a,$this->text('provider',16));$this->success('/account/security');return;
            case '/account/complete':$this->accounts->complete($a,$this->text('username',64),$this->text('new_password',256),$this->text('current_password',256),$this->input);$this->success($this->afterLogin());return;
            case '/me/profile':$this->accounts->profile($a['user_id'],$this->input);$this->success('/account/profile');return;
            case '/me/password':$this->accounts->setPassword($a,$this->text('current_password',256),$this->text('new_password',256));$this->success('/account/security');return;
            case '/me/phone/request':if($this->db->one('SELECT phone FROM m_users WHERE id=?',[$a['user_id']])['phone']){throw new Problem('PHONE_READ_ONLY','شماره تأییدشده قابل ویرایش نیست.',403);}$id=$this->accounts->requestCode($this->text('phone',32),'change_phone',$ip,$a,$this->text('current_password',256));$this->codeForm($id,'change_phone');return;
            case '/me/phone/verify':if($this->db->one('SELECT phone FROM m_users WHERE id=?',[$a['user_id']])['phone']){throw new Problem('PHONE_READ_ONLY','شماره تأییدشده قابل ویرایش نیست.',403);}$this->accounts->changePhone($a,$this->text('challenge_id',32),$this->text('code',12),$ip);$this->issue($a['user_id'],'otp');$this->success('/account');return;
            case '/me/sessions/revoke':$this->sessions->revoke($a['user_id'],'site',$this->text('id',32));$this->success('/account/sessions');return;
            case '/me/sessions/revoke-others':$this->sessions->revokeOthers($a['user_id'],'site',$a['session_id']);$this->success('/account/sessions');return;
            case '/legal/accept':$this->legal->accept($a['user_id'],$this->text('id',32));$this->success('/account/rules');return;
            case '/notifications/read':$this->db->run('UPDATE m_notifications SET read_at=COALESCE(read_at,?) WHERE id=? AND user_id=?',[time(),$this->text('id',32),$a['user_id']]);$this->success('/notifications');return;
            default:throw new Problem('FEATURE_UNAVAILABLE','این قابلیت در نسخه فعلی فعال نیست.',503);
        }
    }
    private function postAdmin(string $ip):void
    {
        if($this->path==='/setup'){
            $this->admin->setup($this->text('token',128),$this->text('username',64),$this->text('display_name',500),$this->text('new_password',256),$ip);$this->success('/login');return;
        }
        $a=$this->requireActor();
        switch($this->path){
            case '/me/password':$this->admin->changePassword($a,$this->text('current_password',256),$this->text('new_password',256));setcookie($this->cookie,'',['expires'=>1,'path'=>'/','secure'=>$this->secure(),'httponly'=>true,'samesite'=>'Lax']);$this->success('/login');return;
            case '/me/phone/request':$id=$this->accounts->requestCode($this->text('phone',32),'change_phone',$ip,$a,$this->text('current_password',256));$this->codeForm($id,'change_phone');return;
            case '/me/phone/verify':$this->accounts->changePhone($a,$this->text('challenge_id',32),$this->text('code',12),$ip);$this->success('/security');return;
            case '/me/sessions/revoke':$this->sessions->revoke($a['user_id'],'admin',$this->text('id',32));$this->success('/security');return;
            case '/me/sessions/revoke-others':$this->sessions->revokeOthers($a['user_id'],'admin',$a['session_id']);$this->success('/security');return;
            case '/users/update':$this->admin->editUser($a,$this->text('current_password',256),$this->text('id',32),$this->input);$this->success('/users/edit?id='.rawurlencode($this->text('id',32)));return;
            case '/users/create':$this->admin->createUser($a,$this->text('current_password',256),$this->input);$this->success('/users');return;
            case '/users/delete':case '/users/restore':$this->admin->deleteUser($a,$this->text('current_password',256),$this->text('id',32),$this->path==='/users/restore');$this->success('/users');return;
            case '/upgrade':$this->admin->system($a,$this->text('current_password',256));\Morshed\Core\Upgrade::apply($this->db);(new Audit($this->db))->add($a['user_id'],'admin','installation.schema_upgraded');$this->success('/');return;
            case '/users/status':$this->admin->userStatus($a,$this->text('current_password',256),$this->text('id',32),$this->text('status',16));$this->success('/users');return;
            case '/admins/create':$this->admin->addAdmin($a,$this->text('current_password',256),$this->text('user_id',32),$this->text('username',64),$this->text('new_password',256),$this->text('role',24));$this->success('/admins');return;
            case '/admins/role':$this->admin->role($a,$this->text('current_password',256),$this->text('id',32),$this->text('role',24));$this->success('/admins');return;
            case '/legal/create':$this->admin->system($a);$this->legal->createBase($a,$this->text('title',700),$this->text('body'));$this->success('/legal');return;
            case '/legal/publish':$this->admin->system($a);$this->legal->publishBase($a,$this->text('id',32));$this->success('/legal');return;
            default:throw new Problem('FEATURE_UNAVAILABLE','این قابلیت در نسخه فعلی فعال نیست.',503);
        }
    }
    private function rememberCode(string $id,string $purpose):void
    {$_SESSION['otp_flow']=['id'=>$id,'purpose'=>$purpose];}
    private function codeInfo(string $id,string $purpose):array
    {
        $flow=$_SESSION['otp_flow']??[];$c=$this->db->one('SELECT phone,created_at,expires_at,purpose FROM m_otp_challenges WHERE id=?',[$id]);
        $bound=isset($flow['id'])&&hash_equals($flow['id'],$id)&&($flow['purpose']??'')===$purpose;
        return ['challenge_id'=>$id,'purpose'=>$purpose,'expires_in'=>$c?max(0,(int)$c['expires_at']-time()):0,'resend_in'=>$c?max(0,(int)$c['created_at']+60-time()):60,'masked_phone'=>$bound&&$c?substr($c['phone'],0,4).' ••• '.substr($c['phone'],-2):'','can_resend'=>$bound&&in_array($purpose,['login','reset'],true),'message'=>'کد فقط پس از تأیید سرور پذیرفته می‌شود.'];
    }
    private function codeForm(string $id,string $purpose,?string $error=null):void
    {$data=$this->codeInfo($id,$purpose);if($this->api){$this->json($data);}else{$this->view->page('تأیید شماره','code',['challenge'=>$id,'purpose'=>$purpose,'otp'=>$data],$error);}}
    private function get(?string $error=null):void
    {
        if($this->api&&$this->path==='/csrf'){$this->json(['csrf'=>$_SESSION['csrf']]);return;}
        if($this->path==='/login'){$this->view->page($this->realm==='admin'?'ورود مدیریت':'ورود به مرشد',$this->realm==='admin'?'admin-login':'login',['setupAvailable'=>$this->realm==='admin'&&($this->config['setup']['enabled']??false)&&!$this->db->one('SELECT bootstrapped_at FROM m_installation WHERE id=1')['bootstrapped_at']],$error);return;}
        if($this->realm==='admin'){$this->getAdmin($error);return;}
        if($this->path==='/'){$this->view->page('مرشد؛ گفت‌وگو با صاحب تجربه','home',[],$error);return;}
        if($this->path==='/auth/google/callback'){$r=$this->external->google($this->queryString('state',64),$this->queryString('code',4096),$_SESSION['external_browser']??'',$this->actor);if($r['purpose']==='login'){$this->issue($r['user_id'],'google');}$this->success($this->afterLogin());return;}
        if($this->path==='/auth/otp/code'){$f=$_SESSION['otp_flow']??[];if(!isset($f['id'],$f['purpose'])){throw new Problem('INVALID_CODE','ابتدا درخواست کد کنید.',400);}$this->codeForm($f['id'],$f['purpose'],$error);return;}
        if($this->path==='/auth/eitaa/start'){header('Location: https://eitaa.com/morshed_app',true,303);return;}
        if($this->path==='/password/recover'){$this->view->page('بازیابی رمز','recover',[],$error);return;}
        if($this->path==='/legal'){$this->view->page('قوانین پایه','public-legal',['legal'=>$this->legal->current()],$error);return;}
        $a=$this->requireActor();
        switch($this->path){
            case '/me':$u=$this->db->one('SELECT id,display_name,phone,phone_verified_at FROM m_users WHERE id=?',[$a['user_id']]);$this->json(['user'=>$u,'realm'=>'site']);return;
            case '/account/identify':$this->external->requirePhoneLink($a);$this->view->page('پیداکردن حساب قبلی','identify-account',[],$error);return;
            case '/account/complete':$u=$this->db->one('SELECT phone,display_name,password_hash IS NOT NULL AS has_password FROM m_users WHERE id=?',[$a['user_id']]);$this->view->page('تکمیل حساب','complete-account',['user'=>$u,'profile'=>Profile::get($this->db,$a['user_id']),'username'=>$this->accounts->username($a['user_id'])],$error);return;
            case '/account':case '/account/profile':case '/account/security':case '/account/rules':case '/account/phone':
                $u=$this->db->one('SELECT display_name,phone,password_hash IS NOT NULL AS has_password FROM m_users WHERE id=?',[$a['user_id']]);$legal=$this->legal->current();
                $accepted=$legal&&$this->db->one('SELECT user_id FROM m_user_acceptances WHERE user_id=? AND version_id=?',[$a['user_id'],$legal['id']]);
                $counts=['pending'=>(int)$this->db->run("SELECT COUNT(*) FROM m_bookings WHERE user_id=? AND state='pending' AND expires_at>?",[$a['user_id'],time()])->fetchColumn(),'unread'=>(int)$this->db->run('SELECT COUNT(*) FROM m_notifications WHERE user_id=? AND read_at IS NULL',[$a['user_id']])->fetchColumn()];
                $section=substr($this->path,strlen('/account/'));$this->view->page($this->path==='/account'?'حساب من':'تنظیمات حساب',$this->path==='/account'?'account-home':'account',['section'=>$section,'counts'=>$counts,'username'=>$this->accounts->username($a['user_id']),'profile'=>Profile::get($this->db,$a['user_id']),'user'=>$u,'legal'=>$legal,'accepted'=>(bool)$accepted,'identities'=>$this->external->methods($a['user_id']),'isMentor'=>(bool)$this->db->one('SELECT user_id FROM m_mentors WHERE user_id=?',[$a['user_id']])],$error);return;
            case '/account/sessions':case '/me/sessions':$rows=$this->sessions->list($a['user_id'],'site');if($this->api){$this->json(['sessions'=>$rows]);}else{$this->view->page('نشست‌های فعال','sessions',['rows'=>$rows],$error);}return;
            case '/notifications':$rows=$this->db->all('SELECT id,title,body,created_at,read_at,category,destination FROM m_notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50',[$a['user_id']]);if($this->api){$this->json(['notifications'=>$rows]);}else{$this->view->page('اعلان‌ها','notifications',['rows'=>$rows],$error);}return;
            default:throw new Problem('FEATURE_UNAVAILABLE','این بخش هنوز در دسترس نیست.',503);
        }
    }
    private function getAdmin(?string $error):void
    {
        if($this->path==='/setup'){
            if(($this->config['setup']['enabled']??false)!==true||$this->db->one('SELECT bootstrapped_at FROM m_installation WHERE id=1')['bootstrapped_at']){throw new Problem('NOT_FOUND','راه‌اندازی اولیه بسته است.',404);}
            $this->view->page('ساخت اولین مدیر','setup',[],$error);return;
        }
        $a=$this->requireActor();
        if($this->path==='/me'){$this->json(['user'=>['id'=>$a['user_id'],'display_name'=>$a['display_name']],'admin_role'=>$a['admin_role'],'realm'=>'admin']);return;}
        if($this->path==='/security'||$this->path==='/me/sessions'){$rows=$this->sessions->list($a['user_id'],'admin');if($this->api){$this->json(['sessions'=>$rows]);}else{$this->view->page('امنیت حساب مدیریت','admin-security',['rows'=>$rows],$error);}return;}
        if($this->path==='/'){
            $stats=[];foreach(['users'=>'m_users','admins'=>'m_admin_accounts','sessions'=>'m_auth_sessions','audit'=>'m_audit_logs'] as $key=>$table){$where=$key==='sessions'?' WHERE revoked_at IS NULL AND expires_at>'.time():'';$stats[$key]=(int)$this->db->run('SELECT COUNT(*) FROM '.$table.$where)->fetchColumn();}
            foreach(['mentors'=>"SELECT COUNT(*) FROM m_mentors WHERE status='active'",'experiences'=>'SELECT COUNT(*) FROM m_experiences WHERE public_version_id IS NOT NULL','bookings'=>'SELECT COUNT(*) FROM m_bookings'] as $key=>$sql){$stats[$key]=(int)$this->db->run($sql)->fetchColumn();}
            $canOperate=($a['admin_role']??'')==='system_admin';$daily=$canOperate?$this->db->all('SELECT FLOOR(created_at/86400) AS day,COUNT(*) AS total FROM m_bookings WHERE created_at>=? GROUP BY FLOOR(created_at/86400)',[strtotime('today UTC')-6*86400]):[];$counts=array_column($daily,'total','day');$trend=[];for($i=6;$i>=0;$i--){$day=(int)floor(time()/86400)-$i;$trend[]=['label'=>gmdate('m/d',$day*86400),'count'=>(int)($counts[$day]??0)];}
            $states=$canOperate?$this->db->all('SELECT state,COUNT(*) AS total FROM m_bookings GROUP BY state'):[];$recent=$canOperate?$this->db->all('SELECT b.id,b.state,b.created_at,u.display_name AS user_name,m.display_name AS mentor_name FROM m_bookings b JOIN m_users u ON u.id=b.user_id JOIN m_users m ON m.id=b.mentor_id ORDER BY b.created_at DESC LIMIT 5'):[];
            $this->view->page('داشبورد مدیریت','admin-dashboard',['canOperate'=>$canOperate,'stats'=>$stats,'trend'=>$trend,'bookingStates'=>$states,'recentBookings'=>$recent],$error);return;
        }
        $this->admin->system($a);
        switch($this->path){
            case '/users/new':$this->view->page('افزودن کاربر','admin-user-create',[],$error);return;
            case '/users/edit':
                $id=$this->queryString('id',32);$u=$this->db->one('SELECT id,display_name,phone,phone_verified_at,status,created_at FROM m_users WHERE id=?',[$id]);
                if(!$u){throw new Problem('NOT_FOUND','کاربر پیدا نشد.',404);}
                $this->view->page('ویرایش اطلاعات کاربر','admin-user-edit',['user'=>$u,'profile'=>Profile::get($this->db,$id),'username'=>$this->accounts->username($id)??'','mentor'=>$this->db->one('SELECT status,accepted_at FROM m_mentors WHERE user_id=?',[$id])],$error);return;
            case '/users':
                $q=is_string($_GET['q']??null)?trim(substr($_GET['q'],0,160)):'';$page=max(1,min(10000,(int)($_GET['page']??1)));$offset=($page-1)*20;
                $like='%'.strtr($q,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%';
                $rows=$this->db->all((\Morshed\Core\Upgrade::ready($this->db)?'SELECT u.id,u.display_name,u.phone,u.status,u.created_at,p.deleted_at FROM m_users u LEFT JOIN m_user_profiles p ON p.user_id=u.id WHERE u.display_name LIKE ? OR u.phone LIKE ? OR u.id=? ORDER BY u.created_at DESC,u.id DESC LIMIT 21 OFFSET ':'SELECT id,display_name,phone,status,created_at FROM m_users WHERE display_name LIKE ? OR phone LIKE ? OR id=? ORDER BY created_at DESC,id DESC LIMIT 21 OFFSET ').$offset,[$like,$like,$q]);
                if($this->api){$this->json(['users'=>array_slice($rows,0,20),'has_more'=>count($rows)>20]);}else{$this->view->page('کاربران','admin-users',['rows'=>array_slice($rows,0,20),'more'=>count($rows)>20,'page'=>$page,'query'=>$q],$error);}return;
            case '/admins':$rows=$this->db->all('SELECT a.id,a.user_id,a.username,a.role,u.display_name,u.status FROM m_admin_accounts a JOIN m_users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 100');$this->view->page('دسترسی مدیران','admin-admins',['rows'=>$rows],$error);return;
            case '/legal':$rows=$this->db->all('SELECT id,version_no,title,body,content_sha256 FROM m_legal_versions WHERE document_key=\'base\' ORDER BY version_no DESC LIMIT 30');$this->view->page('نسخه‌های قوانین پایه','admin-legal',['rows'=>$rows,'current'=>$this->legal->current()],$error);return;
            case '/audit':$rows=$this->db->all('SELECT event,actor_user_id,subject_id,realm,created_at FROM m_audit_logs ORDER BY id DESC LIMIT 100');$this->view->page('حسابرسی','admin-audit',['rows'=>$rows],$error);return;
            case '/settings':$this->view->page('وضعیت اتصال‌ها','admin-settings',[],$error);return;
            default:throw new Problem('NOT_FOUND','صفحه پیدا نشد.',404);
        }
    }
    private function error(Problem $e):void
    {
        http_response_code($e->status);
        if($this->api){$this->json(['error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);return;}
        $path=$this->path;if($path==='/users/update'){$_GET['id']=$this->text('id',32);}
        if($path==='/auth/otp/request'&&$this->text('purpose',20)==='login'){$_GET['method']='otp';}
        if($this->method==='POST'){
            if(in_array($path,['/auth/otp/verify','/auth/password/reset','/me/phone/verify'],true)){$purpose=$path==='/auth/password/reset'?'reset':($path==='/me/phone/verify'?'change_phone':'login');$this->codeForm($this->text('challenge_id',32),$purpose,$e->getMessage());return;}
            $this->path=match($path){'/auth/password','/auth/eitaa/start','/auth/eitaa/complete','/auth/google/start'=>'/login','/auth/otp/resend'=>'/auth/otp/code','/auth/otp/request'=>$this->text('purpose',20)==='reset'?'/password/recover':'/login','/account/complete'=>'/account/complete','/me/profile'=>'/account/profile','/me/password','/me/identities/unlink'=>'/account/security','/legal/accept'=>'/account/rules','/me/eitaa/contact','/me/eitaa/phone/request'=>'/account/identify','/users/status','/users/delete','/users/restore'=>'/users','/users/update'=>'/users/edit','/users/create'=>'/users/new','/upgrade'=>'/','/admins/create','/admins/role'=>'/admins','/legal/create','/legal/publish'=>'/legal','/setup'=>'/setup',default=>$this->realm==='admin'?'/security':'/account'};
            try{$this->get($e->getMessage());return;}catch(Problem $ignored){}
        }
        $this->view->page('درخواست انجام نشد','error',['message'=>$e->getMessage(),'errorCode'=>$e->errorCode],$e->getMessage());
    }
}
