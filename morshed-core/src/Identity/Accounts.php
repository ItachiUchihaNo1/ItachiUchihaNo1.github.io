<?php
declare(strict_types=1);
namespace Morshed\Identity;
use Morshed\Core\{Database,Security,RateLimiter,Audit,Problem};
use Morshed\Notifications\SmsGateway;
final class Accounts
{
    private const DUMMY_HASH='$2y$12$eImiTXuWVxfM37uY4JANjQeF7VEffz5ynmEKD/Yh2tA8uXKvH9Z9K';
    public function __construct(private Database $db,private RateLimiter $rate,private Audit $audit,private SmsGateway $sms,private string $key) {}
    public function passwordLogin(string $phone,string $password,string $ip):string
    {
        $login=strtolower(trim(Security::digits($phone)));$isPhone=preg_match('/^(?:09|\+98|0098)/',$login)===1;if($isPhone){$login=Security::phone($login);}elseif(!preg_match('/^[a-z][a-z0-9_]{3,31}$/D',$login)){throw new Problem('INVALID_CREDENTIALS','نام کاربری، شماره یا رمز صحیح نیست.',401);}
        $this->rate->hit('site-login-ip:'.$ip,20,900);$this->rate->hit('site-login-phone:'.$login,8,900);
        $u=$isPhone?$this->db->one('SELECT id,password_hash,status,phone_verified_at FROM m_users WHERE phone=?',[$login]):$this->db->one("SELECT u.id,u.password_hash,u.status,u.phone_verified_at FROM m_users u JOIN m_auth_identities i ON i.user_id=u.id WHERE i.provider='local_username' AND i.subject=?",[$login]);
        $ok=Security::verifyPassword($password,$u['password_hash']??self::DUMMY_HASH);
        if(!$u || !$ok || !$u['password_hash'] || ($isPhone&&!$u['phone_verified_at']) || $u['status']!=='active') {
            $this->audit->add(null,'site','auth.password_failed');
            throw new Problem('INVALID_CREDENTIALS','نام کاربری، شماره یا رمز صحیح نیست یا حساب قابل استفاده نیست.',401);
        }
        return $u['id'];
    }
    public function username(string $uid):?string
    {$r=$this->db->one("SELECT subject FROM m_auth_identities WHERE user_id=? AND provider='local_username'",[$uid]);return $r['subject']??null;}
    public function complete(array $actor,string $username,string $password,string $current='',?array $profile=null):void
    {
        $username=strtolower(trim($username));if(!preg_match('/^[a-z][a-z0-9_]{3,31}$/D',$username)){throw new Problem('INVALID_INPUT','نام کاربری باید ۴ تا ۳۲ حرف انگلیسی، عدد یا زیرخط باشد و با حرف شروع شود.');}
        $this->rate->hit('complete-account:'.$actor['user_id'],8,900);$hash=Security::hashPassword($password);
        $this->db->tx(function()use($actor,$username,$hash,$current,$profile){
            if(($actor['realm']??'')!=='site'){throw new Problem('ACCESS_DENIED','این عملیات مخصوص حساب سایت است.',403);}
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$u=$this->db->one("SELECT password_hash FROM m_users WHERE id=? AND status='active' FOR UPDATE",[$actor['user_id']]);
            $session=$this->db->one("SELECT auth_at FROM m_auth_sessions WHERE id=? AND user_id=? AND realm='site' AND revoked_at IS NULL AND expires_at>?",[$actor['session_id']??'',$actor['user_id'],time()]);
            if(!$u||!$session||($u['password_hash']?!Security::verifyPassword($current,$u['password_hash']):((int)$session['auth_at']<time()-300))){throw new Problem('REAUTH_REQUIRED','رمز فعلی یا ورود تازه برای تکمیل حساب لازم است.',403);}
            $owner=$this->db->one("SELECT user_id FROM m_auth_identities WHERE provider='local_username' AND subject=?",[$username]);if($owner&&$owner['user_id']!==$actor['user_id']){throw new Problem('USERNAME_TAKEN','این نام کاربری قبلاً انتخاب شده است.',409);}
            if($profile!==null){Profile::save($this->db,$actor['user_id'],$profile);}$old=$this->username($actor['user_id']);if($old){$this->db->run("UPDATE m_auth_identities SET subject=? WHERE user_id=? AND provider='local_username'",[$username,$actor['user_id']]);}else{$this->db->run("INSERT INTO m_auth_identities(id,user_id,provider,subject,created_at) VALUES(?,?,'local_username',?,?)",[Security::id(),$actor['user_id'],$username,time()]);}
            $this->db->run('UPDATE m_users SET password_hash=?,updated_at=? WHERE id=?',[$hash,time(),$actor['user_id']]);$this->db->run("UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND realm='site' AND id<>? AND revoked_at IS NULL",[time(),$actor['user_id'],$actor['session_id']]);$this->audit->add($actor['user_id'],'site','account.credentials_completed');
        });
    }
    public function requestCode(string $phone,string $purpose,string $ip,?array $actor=null,string $currentPassword=''):string
    {
        $phone=Security::phone($phone);
        if(!in_array($purpose,['login','reset','change_phone'],true)) { throw new Problem('INVALID_PURPOSE','درخواست معتبر نیست.'); }
        if($purpose==='change_phone') {
            $this->rate->hit('phone-change-proof:'.$ip,8,900);
            if(!$actor) { throw new Problem('UNAUTHENTICATED','ابتدا وارد شوید.',401); }
            if(($actor['realm']??'')==='admin'){
                $u=$this->db->one('SELECT a.password_hash FROM m_admin_accounts a JOIN m_users u ON u.id=a.user_id WHERE a.id=? AND a.user_id=? AND u.status=\'active\'',[$actor['admin_id']??'',$actor['user_id']]);
                $valid=$u && Security::verifyPassword($currentPassword,$u['password_hash']);
            }else{
                $u=$this->db->one('SELECT password_hash FROM m_users WHERE id=? AND status=\'active\'',[$actor['user_id']]);
                $valid=$u && ($u['password_hash'] ? Security::verifyPassword($currentPassword,$u['password_hash']) : (in_array($actor['auth_method'],['otp','google','eitaa'],true) && (int)$actor['auth_at']>=time()-300));
            }
            if(!$valid) { throw new Problem('REAUTH_REQUIRED','برای تغییر شماره، رمز فعلی یا احراز هویت تازه لازم است.',403); }
        }
        $this->rate->hit('otp-ip:'.$ip,12,900);$this->rate->hit('otp-phone:'.$phone,3,900);$this->rate->hit('otp-global',100,3600);
        $id=Security::id();$code=(string)random_int(1000,9999);$now=time();
        $target=$this->db->one('SELECT id FROM m_users WHERE phone=?',[$phone]);
        $this->db->run('INSERT INTO m_otp_challenges(id,phone,purpose,actor_user_id,actor_realm,target_user_id,secret_hmac,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)',[$id,$phone,$purpose,$purpose==='change_phone'?$actor['user_id']:null,$purpose==='change_phone'?($actor['realm']??'site'):null,$target['id']??null,$this->codeHash($id,$code),$now,$now+120]);
        try { $receipt=$this->sms->sendLoginCode($phone,$code);$state=$receipt->status; }
        catch(\Throwable $e) { $state='disabled'; }
        unset($code);
        $this->db->run('UPDATE m_otp_challenges SET submission_status=? WHERE id=?',[$state,$id]);
        $this->audit->add($actor['user_id']??null,$actor['realm']??'site','auth.otp_requested',$id);
        if(in_array($state,['disabled','provider_rejected'],true)) { throw new Problem('SMS_UNAVAILABLE','ارسال کد ورود در حال حاضر فعال یا در دسترس نیست.',503); }
        return $id;
    }
    /** Called only after the controller proves this challenge belongs to its browser flow. */
    public function resendCode(string $id,string $purpose,string $ip):string
    {
        $c=$this->db->one('SELECT * FROM m_otp_challenges WHERE id=?',[$id]);
        if(!$c||!in_array($purpose,['login','reset'],true)||$c['purpose']!==$purpose||$c['actor_user_id']!==null||$c['consumed_at']!==null||(int)$c['created_at']<time()-900){throw new Problem('INVALID_CODE','این درخواست پایان یافته است؛ شماره را دوباره وارد کنید.',409);}
        if((int)$c['created_at']>time()-60){throw new Problem('RESEND_COOLDOWN','برای ارسال دوباره باید یک دقیقه از درخواست قبلی گذشته باشد.',429);}
        // Retire the previous code once, before a new external send. An uncertain send is never retried automatically.
        if($this->db->run('UPDATE m_otp_challenges SET consumed_at=? WHERE id=? AND consumed_at IS NULL',[time(),$id])->rowCount()!==1){throw new Problem('INVALID_CODE','درخواست قبلاً مصرف شده است.',409);}
        return $this->requestCode($c['phone'],$purpose,$ip);
    }
    private function codeHash(string $id,string $code):string {return hash_hmac('sha256',$id.':'.$code,$this->key);}
    private function consume(string $id,string $code,string $purpose,?string $actor,?string $realm=null):array|Problem
    {
        $code=Security::digits($code);
        if(!Security::validId($id) || !preg_match('/^[0-9]{4}$/D',$code)) {return new Problem('INVALID_CODE','کد معتبر نیست.',400);}
        $c=$this->db->one('SELECT * FROM m_otp_challenges WHERE id=? FOR UPDATE',[$id]);
        if(!$c || $c['purpose']!==$purpose || $c['actor_user_id']!==$actor || $c['actor_realm']!==$realm || $c['consumed_at']!==null || (int)$c['expires_at']<=time() || (int)$c['attempts']>=3 || in_array($c['submission_status'],['preparing','disabled','provider_rejected'],true)) {return new Problem('INVALID_CODE','کد نامعتبر، مصرف‌شده یا منقضی است.');}
        $this->db->run('UPDATE m_otp_challenges SET attempts=attempts+1 WHERE id=?',[$id]);
        if(!hash_equals($c['secret_hmac'],$this->codeHash($id,$code))) {return new Problem('INVALID_CODE','کد صحیح نیست.');}
        $this->db->run('UPDATE m_otp_challenges SET consumed_at=? WHERE id=?',[time(),$id]);
        return $c;
    }
    public function verifyLogin(string $id,string $code,string $ip):string
    {
        $this->rate->hit('otp-verify-ip:'.$ip,30,900);
        $result=$this->db->tx(function()use($id,$code){
            $c=$this->consume($id,$code,'login',null);if($c instanceof Problem){return $c;}
            // A singleton lock serializes first-account creation for the same verified number.
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');
            $u=$this->db->one('SELECT id,status FROM m_users WHERE phone=? FOR UPDATE',[$c['phone']]);
            if($c['target_user_id']!==null && (!$u || $u['id']!==$c['target_user_id'])){return new Problem('IDENTITY_CHANGED','حساب مرتبط با شماره تغییر کرده است؛ کد تازه درخواست کنید.',409);}
            if($u && $u['status']!=='active') {return new Problem('ACCESS_DENIED','حساب قابل استفاده نیست.',403);}
            if(!$u){$uid=Security::id();$now=time();$this->db->run('INSERT INTO m_users(id,phone,phone_verified_at,created_at,updated_at) VALUES (?,?,?,?,?)',[$uid,$c['phone'],$now,$now,$now]);}
            else{$uid=$u['id'];}
            $this->audit->add($uid,'site','auth.otp_verified',$id);return $uid;
        });
        if($result instanceof Problem){throw $result;}return $result;
    }
    public function resetPassword(string $id,string $code,string $newPassword,string $ip):void
    {
        $this->rate->hit('password-reset-ip:'.$ip,10,900);$hash=Security::hashPassword($newPassword);
        $result=$this->db->tx(function()use($id,$code,$hash){
            $c=$this->consume($id,$code,'reset',null);if($c instanceof Problem){return $c;}
            $u=$this->db->one('SELECT id,status FROM m_users WHERE phone=? FOR UPDATE',[$c['phone']]);
            if(!$u || $c['target_user_id']!==$u['id'] || $u['status']!=='active'){return new Problem('RESET_UNAVAILABLE','بازیابی این حساب ممکن نیست.');}
            $this->db->run('UPDATE m_users SET password_hash=?,updated_at=? WHERE id=?',[$hash,time(),$u['id']]);
            $this->db->run('UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND realm=\'site\' AND revoked_at IS NULL',[time(),$u['id']]);
            $this->audit->add($u['id'],'site','auth.password_reset');$this->audit->notify($u['id'],'رمز حساب تغییر کرد','رمز حساب شما با تأیید شماره همراه بازیابی شد.');return null;
        });if($result instanceof Problem){throw $result;}
    }
    public function setPassword(array $actor,string $current,string $new):void
    {
        $this->rate->hit('password-change:'.$actor['user_id'],8,900);
        $hash=Security::hashPassword($new);
        $this->db->tx(function()use($actor,$current,$hash){
            $u=$this->db->one('SELECT password_hash,phone_verified_at FROM m_users WHERE id=? FOR UPDATE',[$actor['user_id']]);
            if(!$u){throw new Problem('ACCESS_DENIED','حساب در دسترس نیست.',403);}
            if($u['password_hash'] ? !Security::verifyPassword($current,$u['password_hash']) : (!in_array($actor['auth_method'],['otp','google','eitaa'],true) || (int)$actor['auth_at']<time()-300)) {throw new Problem('REAUTH_REQUIRED','رمز فعلی یا ورود پیامکی تازه لازم است.',403);}
            $this->db->run('UPDATE m_users SET password_hash=?,updated_at=? WHERE id=?',[$hash,time(),$actor['user_id']]);
            $this->db->run('UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND realm=\'site\' AND id<>? AND revoked_at IS NULL',[time(),$actor['user_id'],$actor['session_id']]);
            $this->audit->add($actor['user_id'],'site','auth.password_changed');$this->audit->notify($actor['user_id'],'رمز حساب تغییر کرد','رمز حساب تغییر کرد و نشست‌های دیگر سایت بسته شدند.');
        });
    }
    public function changePhone(array $actor,string $id,string $code,string $ip):void
    {
        $this->rate->hit('phone-change-verify:'.$ip,10,900);
        $result=$this->db->tx(function()use($actor,$id,$code){
            $c=$this->consume($id,$code,'change_phone',$actor['user_id'],$actor['realm']??'site');if($c instanceof Problem){return $c;}
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');
            $owner=$this->db->one('SELECT id FROM m_users WHERE phone=? FOR UPDATE',[$c['phone']]);
            if($owner && $owner['id']!==$actor['user_id']){return new Problem('IDENTITY_CONFLICT','این شماره به حساب دیگری متصل است؛ ادغام خودکار انجام نمی‌شود.',409);}
            $this->db->run('UPDATE m_users SET phone=?,phone_verified_at=?,updated_at=? WHERE id=?',[$c['phone'],time(),time(),$actor['user_id']]);
            $this->db->run('UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND realm=\'site\' AND revoked_at IS NULL',[time(),$actor['user_id']]);
            $this->audit->add($actor['user_id'],$actor['realm']??'site','auth.phone_changed');$this->audit->notify($actor['user_id'],'شماره همراه تغییر کرد','شماره همراه حساب با تأیید مالکیت شماره جدید تغییر کرد.');return null;
        });if($result instanceof Problem){throw $result;}
    }
    public function profile(string $user,string|array $data):void
    {
        $this->db->tx(function()use($user,$data){
            if(is_array($data)){Profile::save($this->db,$user,$data);}else{$this->db->run('UPDATE m_users SET display_name=?,updated_at=? WHERE id=?',[Security::name($data),time(),$user]);}
            $this->audit->add($user,'site','account.profile_changed');
        });
    }
}
