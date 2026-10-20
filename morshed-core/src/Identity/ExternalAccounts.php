<?php
declare(strict_types=1);
namespace Morshed\Identity;
use Morshed\Core\{Database,Security,Audit,RateLimiter,Problem};
use Morshed\Product\Policy;
final class ExternalAccounts
{
    public function __construct(private Database $db,private Audit $audit,private RateLimiter $rate,private ProviderHttp $http,private array $config){}
    private function enabled(string $provider):array
    {$c=$this->config[$provider]??[];if(!in_array($provider,['google','eitaa'],true)||($c['enabled']??false)!==true||($c['live_verified']??false)!==true){throw new Problem('PROVIDER_DISABLED','این روش ورود هنوز با سرویس واقعی تأیید و فعال نشده است.',503);}if(($provider==='google'&&(empty($c['client_id'])||empty($c['client_secret'])||!str_starts_with($c['redirect_uri']??'','https://')))||($provider==='eitaa'&&strlen($c['app_token']??'')<16)){throw new Problem('PROVIDER_DISABLED','تنظیمات خصوصی روش ورود کامل نیست.',503);}if($provider==='google'&&!preg_match('/^[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/D',$c['client_id'])){throw new Problem('GOOGLE_CLIENT_CONFIGURATION','شناسهٔ گوگل در تنظیمات معتبر نیست؛ فقط Client ID را بدون https:// یا فاصله وارد کنید.',503);}return $c;}
    private function recent(array $a):void
    {(new Policy($this->db))->user($a);$s=$this->db->one("SELECT auth_at FROM m_auth_sessions WHERE id=? AND user_id=? AND realm='site' AND revoked_at IS NULL AND expires_at>?",[$a['session_id']??'',$a['user_id'],time()]);if(!$s||(int)$s['auth_at']<time()-300){throw new Problem('REAUTH_REQUIRED','ابتدا دوباره با یکی از روش‌های فعلی حساب وارد شوید؛ تأیید تازه لازم است.',403);}}
    public function methods(string $uid):array
    {return $this->db->all('SELECT provider,created_at FROM m_auth_identities WHERE user_id=? AND provider IN (\'google\',\'eitaa\') ORDER BY provider',[$uid]);}
    public function begin(string $provider,string $purpose,?array $a,string $browser,string $ip):array
    {
        $c=$this->enabled($provider);Policy::choice($purpose,['login','link','reauth']);$this->rate->hit('external-begin:'.$ip,15,900);
        if($purpose==='link'){$this->recent($a??[]);}elseif($purpose==='reauth'){(new Policy($this->db))->user($a??[]);}elseif($a){throw new Problem('EXPLICIT_LINK_REQUIRED','برای افزودن روش ورود از اتصال در تنظیمات حساب استفاده کنید.',409);}
        $state=bin2hex(random_bytes(32));$nonce=bin2hex(random_bytes(32));$verifier=bin2hex(random_bytes(32));
        $this->db->run('INSERT INTO m_external_flows(state_hash,provider,purpose,user_id,session_id,browser_hash,nonce,verifier,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[hash('sha256',$state),$provider,$purpose,$a['user_id']??null,$a['session_id']??null,hash('sha256',$browser),$nonce,$verifier,time(),time()+300]);
        $out=['state'=>$state];if($provider==='google'){$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');$out['url']='https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['response_type'=>'code','client_id'=>$c['client_id'],'redirect_uri'=>$c['redirect_uri'],'scope'=>'openid profile email','state'=>$state,'nonce'=>$nonce,'code_challenge'=>$challenge,'code_challenge_method'=>'S256','prompt'=>'select_account'],'','&',PHP_QUERY_RFC3986);}return $out;
    }
    private function consume(string $provider,string $state,string $browser,?array $a):array
    {return $this->db->tx(function()use($provider,$state,$browser,$a){if(!preg_match('/^[a-f0-9]{64}$/D',$state)){throw new Problem('INVALID_STATE','اعتبار درخواست ورود پایان یافته است.',401);}$f=$this->db->one('SELECT * FROM m_external_flows WHERE state_hash=? FOR UPDATE',[hash('sha256',$state)]);if(!$f||$f['provider']!==$provider||$f['consumed_at']!==null||(int)$f['expires_at']<=time()||!hash_equals($f['browser_hash'],hash('sha256',$browser))){throw new Problem('INVALID_STATE','درخواست ورود معتبر نیست یا قبلاً مصرف شده است.',401);}
        if($f['purpose']!=='login'){if(!$a||$f['user_id']!==$a['user_id']||$f['session_id']!==($a['session_id']??'')){throw new Problem('INVALID_STATE','حساب یا نشست اتصال تغییر کرده است.',401);}if($f['purpose']==='link'){$this->recent($a);}}elseif($a){throw new Problem('INVALID_STATE','نشست مرورگر در میانه ورود تغییر کرده است.',401);}
        $this->db->run('UPDATE m_external_flows SET consumed_at=? WHERE state_hash=?',[time(),$f['state_hash']]);return $f;});}
    public function google(string $state,string $code,string $browser,?array $a):array
    {$c=$this->enabled('google');$f=$this->consume('google',$state,$browser,$a);$tokens=$this->http->request('https://oauth2.googleapis.com/token',['code'=>Policy::text($code,4096),'client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],'redirect_uri'=>$c['redirect_uri'],'grant_type'=>'authorization_code','code_verifier'=>$f['verifier']]);$jwt=$tokens['id_token']??null;if(!is_string($jwt)){throw new Problem('INVALID_ASSERTION','سرویس گوگل توکن هویت معتبر نداد.',401);}
        $certs=$this->http->request('https://www.googleapis.com/oauth2/v1/certs');$proof=(new GoogleToken())->verify($jwt,$certs,$c['client_id'],$f['nonce'],time());return $this->finish('google',$proof,$f,$a);}
    public function eitaa(string $state,string $initData,string $browser,?array $a,string $ip):array
    {$c=$this->enabled('eitaa');$f=$this->consume('eitaa',$state,$browser,$a);$this->rate->hit('eitaa-verify-global',10,1);$this->rate->hit('eitaa-verify-ip:'.$ip,10,900);try{$proof=(new EitaaInitDataVerifier($c['app_token'],60))->verify($initData,time());}catch(\DomainException $e){throw new Problem('INVALID_ASSERTION','امضا یا زمان داده ایتا معتبر نیست.',401);}
        $r=$this->http->request('https://eitaayar.ir/api/app/verify',['token'=>$c['app_token'],'hash'=>$proof['replay_key'],'ip'=>$ip]);if(($r['ok']??false)!==true){throw new Problem('INVALID_ASSERTION','اصالت یک‌بارمصرف ایتا تأیید نشد.',401);}return $this->finish('eitaa',$proof,$f,$a);}
    public function captureEitaa(string $data,string $ip):array
    {
        $c=$this->enabled('eitaa');$this->rate->hit('eitaa-capture:'.$ip,10,900);try{$proof=(new EitaaInitDataVerifier($c['app_token'],60))->verify($data,time());}catch(\DomainException $e){throw new Problem('INVALID_ASSERTION','دادهٔ آغاز برنامک معتبر نیست.',401);}
        $reply=$this->http->request('https://eitaayar.ir/api/app/verify',['token'=>$c['app_token'],'hash'=>$proof['replay_key'],'ip'=>$ip]);if(($reply['ok']??false)!==true){throw new Problem('INVALID_ASSERTION','اعتبار رسمی آغاز برنامک تأیید نشد.',401);}return $proof;
    }
    public function eitaaCaptured(string $state,array $proof,string $browser,?array $actor):array
    {
        $this->enabled('eitaa');$flow=$this->consume('eitaa',$state,$browser,$actor);return $this->finish('eitaa',$proof,$flow,$actor);
    }
    public function contact(array $actor,string $data,string $ip):string
    {
        $c=$this->enabled('eitaa');$this->recent($actor);$this->rate->hit('eitaa-contact:'.$actor['user_id'],6,900);
        $proof=$this->contactProof($data,$c['app_token']);$subject=$proof['subject'];$phone=$proof['phone'];
        $identity=$this->db->one("SELECT user_id FROM m_auth_identities WHERE provider='eitaa' AND subject=?",[$subject]);if(!$identity||$identity['user_id']!==$actor['user_id']){throw new Problem('IDENTITY_MISMATCH','شمارهٔ ایتا باید متعلق به حساب متصل همین کاربر باشد.',403);}
        $reply=$this->http->request('https://eitaayar.ir/api/app/verify',['token'=>$c['app_token'],'hash'=>$proof['replay_key'],'ip'=>$ip]);if(($reply['ok']??false)!==true){throw new Problem('INVALID_ASSERTION','اعتبار رسمی شمارهٔ ایتا تأیید نشد.',401);}
        return $this->db->tx(function()use($actor,$phone,$proof,$subject){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->recent($actor);
            $identity=$this->db->one("SELECT user_id FROM m_auth_identities WHERE provider='eitaa' AND subject=? FOR UPDATE",[$subject]);
            if(!$identity||$identity['user_id']!==$actor['user_id']){throw new Problem('IDENTITY_MISMATCH','اتصال حساب تغییر کرده است.',403);}
            $key=hash('sha256','eitaa-contact:'.$proof['replay_key']);if($this->db->one('SELECT assertion_hash FROM m_identity_assertions WHERE assertion_hash=?',[$key])){throw new Problem('ASSERTION_REPLAY','این تأیید قبلاً استفاده شده است.',401);}
            $existing=$this->db->one('SELECT phone FROM m_users WHERE id=? FOR UPDATE',[$actor['user_id']]);if(!empty($existing['phone'])&&$existing['phone']!==$phone){throw new Problem('PHONE_READ_ONLY','شماره تأییدشده قابل ویرایش نیست.',403);}
            $owner=$this->db->one('SELECT id,phone_verified_at,status FROM m_users WHERE phone=? FOR UPDATE',[$phone]);
            if($owner&&$owner['id']!==$actor['user_id']){
                if($owner['phone_verified_at']===null){throw new Problem('IDENTITY_CONFLICT','شمارهٔ حساب قبلی نیاز به بررسی پشتیبانی دارد.',409);}
                $uid=$this->connectVerifiedPhoneLocked($actor,$owner['id'],'signed_eitaa_contact');
            }else{
                $uid=$actor['user_id'];$this->db->run('UPDATE m_users SET phone=?,phone_verified_at=?,updated_at=? WHERE id=?',[$phone,time(),time(),$uid]);
                $this->db->run("UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND realm='site' AND id<>? AND revoked_at IS NULL",[time(),$uid,$actor['session_id']]);
                $this->audit->add($uid,'site','identity.eitaa.phone_verified');
            }
            $this->db->run('INSERT INTO m_identity_assertions VALUES(?,?,?,?)',[$key,'eitaa',time()+86400,time()]);return $uid;
        });
    }
    public function requirePhoneLink(array $actor):void
    {
        $this->recent($actor);$u=$this->db->one('SELECT phone FROM m_users WHERE id=?',[$actor['user_id']]);
        if(!empty($u['phone'])||!$this->db->one("SELECT id FROM m_auth_identities WHERE user_id=? AND provider='eitaa'",[$actor['user_id']])){throw new Problem('IDENTITY_MISMATCH','این مسیر فقط برای حساب ایتای بدون شماره است.',403);}
    }
    /** Trusted caller must have just verified the destination phone by signed Eitaa contact or OTP.
     * Only the proved Eitaa identity moves. Profiles, content, roles and passwords never move or overwrite.
     */
    public function connectVerifiedPhone(array $actor,string $target,string $proofMethod):string
    {
        if(!in_array($proofMethod,['signed_eitaa_contact','verified_sms'],true)){throw new \LogicException('Unverified link method');}
        return $this->db->tx(fn()=>$this->connectVerifiedPhoneLocked($actor,$target,$proofMethod));
    }
    private function connectVerifiedPhoneLocked(array $actor,string $target,string $proofMethod):string
    {
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->recent($actor);
            $source=$this->db->one('SELECT phone FROM m_users WHERE id=? FOR UPDATE',[$actor['user_id']]);
            $destination=$this->db->one('SELECT id,phone,phone_verified_at,status FROM m_users WHERE id=? FOR UPDATE',[$target]);
            if(!$destination||empty($destination['phone'])||$destination['phone_verified_at']===null){throw new Problem('INVALID_ASSERTION','شمارهٔ مقصد تأیید نشده است.',403);}
            if($destination['status']!=='active'){throw new Problem('ACCESS_DENIED','حساب قبلی محدود شده است.',403);}
            if($target===$actor['user_id']){return $target;}
            if(!empty($source['phone'])){throw new Problem('PHONE_READ_ONLY','شمارهٔ حساب فعلی قابل جایگزینی نیست.',403);}
            $identity=$this->db->one("SELECT id,subject FROM m_auth_identities WHERE user_id=? AND provider='eitaa' FOR UPDATE",[$actor['user_id']]);
            if(!$identity){throw new Problem('IDENTITY_MISMATCH','مالکیت حساب ایتا تأیید نشده است.',403);}
            if($this->db->one("SELECT id FROM m_auth_identities WHERE user_id=? AND provider='eitaa'",[$target])){throw new Problem('IDENTITY_CONFLICT','حساب قبلی به ایتای دیگری متصل است؛ از پشتیبانی پیگیری کنید.',409);}
            $this->db->run('UPDATE m_auth_identities SET user_id=? WHERE id=? AND user_id=?',[$target,$identity['id'],$actor['user_id']]);
            $this->db->run("UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND realm='site' AND revoked_at IS NULL",[time(),$actor['user_id']]);
            $this->audit->add($actor['user_id'],'site','identity.eitaa.moved_after_'.$proofMethod,$target);
            $this->audit->add($target,'site','identity.eitaa.linked_after_'.$proofMethod,$actor['user_id']);
            $this->audit->notify($target,'ورود ایتا به حساب شما متصل شد','پس از تأیید مالکیت شماره، ورود با ایتا به همین حساب متصل شد.');return $target;
    }

    private function contactProof(string $data,string $token):array
    {
        try{$proof=(new EitaaInitDataVerifier($token,60))->verifyFields($data,time());}
        catch(\DomainException $e){throw new Problem('INVALID_ASSERTION','اعتبار امضای شمارهٔ ایتا تأیید نشد؛ دوباره شماره را ارسال کنید یا از کد پیامکی استفاده کنید. (C1)',401);}
        try{
            $fields=$proof['fields'];$contact=$fields;
            if(isset($fields['contact'])){$contact=json_decode($fields['contact'],true,8,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
            if(!is_array($contact)){throw new \DomainException('Contact format');}
            $subject=$contact['user_id']??null;$phone=$contact['phone_number']??null;
            if((!is_int($subject)&&!is_string($subject))||!preg_match('/^[1-9][0-9]{0,19}$/D',(string)$subject)||(!is_string($phone)&&!is_int($phone))){throw new \DomainException('Contact fields');}
            $raw=trim((string)$phone);if(preg_match('/^98[0-9]{10}$/D',$raw)){$raw='+'.$raw;}
            return $proof+['subject'=>(string)$subject,'phone'=>Security::phone($raw)];
        }catch(\Throwable $e){throw new Problem('INVALID_ASSERTION','قالب شمارهٔ امضاشدهٔ ایتا قابل خواندن نیست؛ می‌توانید از کد پیامکی استفاده کنید. (C2)',401);}
    }
    /** Pending data is kept only in the server's CSRF session after verified Eitaa login. */
    public function pendingContact(array $pending,string $data,string $ip):string
    {
        $this->pendingSubject($pending);$c=$this->enabled('eitaa');$this->rate->hit('eitaa-pending-contact:'.$ip,6,900);
        $proof=$this->contactProof($data,$c['app_token']);if($proof['subject']!==$pending['subject']){throw new Problem('INVALID_ASSERTION','شمارهٔ ارسالی متعلق به حساب ایتای در حال ورود نیست. (C3)',401);}$phone=$proof['phone'];
        $reply=$this->http->request('https://eitaayar.ir/api/app/verify',['token'=>$c['app_token'],'hash'=>$proof['replay_key'],'ip'=>$ip]);if(($reply['ok']??false)!==true){throw new Problem('INVALID_ASSERTION','اعتبار رسمی شمارهٔ ایتا تأیید نشد.',401);}
        return $this->db->tx(function()use($pending,$phone,$proof){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$key=hash('sha256','eitaa-contact:'.$proof['replay_key']);
            if($this->db->one('SELECT assertion_hash FROM m_identity_assertions WHERE assertion_hash=?',[$key])){throw new Problem('ASSERTION_REPLAY','این تأیید قبلاً استفاده شده است.',401);}
            $uid=$this->finishPendingPhone($pending,$phone);
            $this->db->run('INSERT INTO m_identity_assertions VALUES(?,?,?,?)',[$key,'eitaa',time()+86400,time()]);return $uid;
        });
    }
    public function pendingSubject(array $pending):string
    {
        if(!is_string($pending['subject']??null)||!preg_match('/^[1-9][0-9]{0,19}$/D',$pending['subject'])||($pending['expires']??0)<=time()){throw new Problem('INVALID_STATE','مهلت تأیید شماره پایان یافته است؛ ورود با ایتا را دوباره آغاز کنید.',401);}return $pending['subject'];
    }
    /** Called only after this browser's OTP challenge has proved the destination user. */
    public function pendingSms(array $pending,string $target):string
    {
        $this->enabled('eitaa');$this->pendingSubject($pending);
        return $this->db->tx(function()use($pending,$target){$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$u=$this->db->one('SELECT phone,phone_verified_at FROM m_users WHERE id=? FOR UPDATE',[$target]);if(!$u||!$u['phone_verified_at']||empty($u['phone'])){throw new Problem('INVALID_ASSERTION','شمارهٔ مقصد تأیید نشده است.',403);}return $this->finishPendingPhone($pending,$u['phone']);});
    }
    private function finishPendingPhone(array $pending,string $phone):string
    {
        $subject=$this->pendingSubject($pending);
        $identity=$this->db->one("SELECT i.user_id,u.phone,u.status FROM m_auth_identities i JOIN m_users u ON u.id=i.user_id WHERE i.provider='eitaa' AND i.subject=? FOR UPDATE",[$subject]);
        $owner=$this->db->one('SELECT id,phone_verified_at,status FROM m_users WHERE phone=? FOR UPDATE',[$phone]);
        if($owner&&($owner['status']!=='active'||!$owner['phone_verified_at'])){throw new Problem('ACCESS_DENIED','حساب مرتبط با این شماره قابل ورود نیست.',403);}
        if($identity){if($identity['status']!=='active'||$identity['phone']!==$phone||!$owner||$identity['user_id']!==$owner['id']){throw new Problem('IDENTITY_CONFLICT','اتصال حساب در زمان تأیید تغییر کرده است؛ دوباره وارد شوید.',409);}return $owner['id'];}
        if($owner){$uid=$owner['id'];if($this->db->one("SELECT id FROM m_auth_identities WHERE user_id=? AND provider='eitaa'",[$uid])){throw new Problem('IDENTITY_CONFLICT','این شماره به حساب ایتای دیگری متصل است؛ از پشتیبانی پیگیری کنید.',409);}}
        else{$uid=Security::id();$now=time();$this->db->run('INSERT INTO m_users(id,phone,phone_verified_at,created_at,updated_at) VALUES(?,?,?,?,?)',[$uid,$phone,$now,$now,$now]);}
        $this->insert($uid,'eitaa',$subject);$this->audit->add($uid,'site','identity.eitaa.linked_after_verified_phone');return $uid;
    }

    private function finish(string $provider,array $proof,array $flow,?array $a):array
    {return $this->db->tx(function()use($provider,$proof,$flow,$a){$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$hash=hash('sha256',$provider.':'.$proof['replay_key']);if($this->db->one('SELECT assertion_hash FROM m_identity_assertions WHERE assertion_hash=?',[$hash])){throw new Problem('ASSERTION_REPLAY','این تأیید هویت قبلاً مصرف شده است.',401);}
        $identity=$this->db->one('SELECT i.user_id,u.status FROM m_auth_identities i JOIN m_users u ON u.id=i.user_id WHERE i.provider=? AND i.subject=?',[$provider,$proof['subject']]);
        if($flow['purpose']==='reauth'){if(!$identity||$identity['user_id']!==$a['user_id']){throw new Problem('IDENTITY_MISMATCH','این حساب خارجی متعلق به حساب فعلی نیست.',403);}$uid=(new Policy($this->db))->user($a);$this->db->run('UPDATE m_auth_sessions SET auth_at=? WHERE id=?',[time(),$a['session_id']]);}
        elseif($flow['purpose']==='link'){$this->recent($a??[]);$uid=$a['user_id'];if($identity&&$identity['user_id']!==$uid){throw new Problem('IDENTITY_CONFLICT','این روش به حساب دیگری متصل است؛ ادغام خودکار انجام نمی‌شود. از پشتیبانی پیگیری کنید.',409);}if(!$identity){if($this->db->one('SELECT id FROM m_auth_identities WHERE user_id=? AND provider=?',[$uid,$provider])){throw new Problem('IDENTITY_CONFLICT','این حساب قبلاً اتصال دیگری برای این سرویس دارد.',409);}$this->insert($uid,$provider,$proof['subject']);}}
        else{if($identity){$uid=$identity['user_id'];if($identity['status']!=='active'){throw new Problem('ACCESS_DENIED','حساب محدود شده است.',403);}}
            else{if($provider==='eitaa'){$this->db->run('INSERT INTO m_identity_assertions VALUES(?,?,?,?)',[$hash,$provider,time()+86400,time()]);return ['purpose'=>'login','pending_eitaa'=>['subject'=>$proof['subject'],'expires'=>time()+600]];}$uid=Security::id();$name=substr(is_string($proof['name']??null)?$proof['name']:'کاربر مرشد',0,100);if(!preg_match('//u',$name)){$name='کاربر مرشد';}$this->db->run('INSERT INTO m_users(id,display_name,created_at,updated_at) VALUES(?,?,?,?)',[$uid,$name,time(),time()]);$this->insert($uid,$provider,$proof['subject']);}}
        $this->db->run('INSERT INTO m_identity_assertions VALUES(?,?,?,?)',[$hash,$provider,time()+86400,time()]);$this->audit->add($uid,'site','identity.'.$provider.'.'.$flow['purpose']);$this->audit->notify($uid,'ورود یا اتصال حساب','یک عملیات احراز هویت خارجی در حساب شما ثبت شد.');return ['user_id'=>$uid,'purpose'=>$flow['purpose']];});}
    private function insert(string $uid,string $provider,string $subject):void
    {$this->db->run('INSERT INTO m_auth_identities(id,user_id,provider,subject,created_at) VALUES(?,?,?,?,?)',[Security::id(),$uid,$provider,$subject,time()]);}
    public function unlink(array $a,string $provider):void
    {$this->db->tx(function()use($a,$provider){$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->recent($a);Policy::choice($provider,['google','eitaa']);$u=$this->db->one('SELECT * FROM m_users WHERE id=? FOR UPDATE',[$a['user_id']]);$usable=$u['password_hash']!==null&&($u['phone_verified_at']!==null||$this->db->one("SELECT id FROM m_auth_identities WHERE user_id=? AND provider='local_username'",[$a['user_id']]));
        $sms=$this->config['sms']??[];$smsReady=($sms['enabled']??false)===true&&($sms['https_transport_confirmed']??$sms['https_post_confirmed']??false)===true&&!empty($sms['username'])&&!empty($sms['password'])&&!empty($sms['sender'])&&(($sms['environment']??'staging')==='production'||in_array($u['phone'],$sms['allowed_recipients']??[],true));$usable=$usable||($u['phone_verified_at']!==null&&$smsReady);foreach($this->methods($a['user_id']) as $m){if($m['provider']!==$provider&&($this->config[$m['provider']]['enabled']??false)===true&&($this->config[$m['provider']]['live_verified']??false)===true&&($m['provider']==='google'?(!empty($this->config['google']['client_id'])&&!empty($this->config['google']['client_secret'])):strlen($this->config['eitaa']['app_token']??'')>=16)){$usable=true;}}
        if(!$usable){throw new Problem('LAST_LOGIN_METHOD','قطع آخرین روش قابل استفاده ورود مجاز نیست.',409);}$this->db->run('DELETE FROM m_auth_identities WHERE user_id=? AND provider=?',[$a['user_id'],$provider]);$this->db->run("UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND realm='site' AND auth_method=? AND revoked_at IS NULL",[time(),$a['user_id'],$provider]);$this->audit->add($a['user_id'],'site','identity.'.$provider.'.unlinked');$this->audit->notify($a['user_id'],'قطع اتصال حساب','یکی از روش‌های ورود خارجی از حساب شما حذف شد.');});}
}
