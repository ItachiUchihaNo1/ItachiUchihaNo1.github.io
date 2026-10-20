<?php
declare(strict_types=1);
namespace Morshed\Access;
use Morshed\Core\{Database,Security,RateLimiter,Audit,Problem};
final class Administration
{
    public function __construct(private Database $db,private RateLimiter $rate,private Audit $audit,private array $setup) {}
    private function username(string $value):string
    { $value=strtolower(trim($value));if(!preg_match('/^[a-z0-9_.-]{3,64}$/D',$value)){throw new Problem('INVALID_USERNAME','شناسه مدیر باید ۳ تا ۶۴ نویسه لاتین، عدد، نقطه، خط تیره یا زیرخط باشد.');}return $value; }
    public function setup(string $token,string $username,string $name,string $password,string $ip):string
    {
        if(($this->setup['enabled']??false)!==true){throw new Problem('NOT_FOUND','راه‌اندازی اولیه بسته است.',404);}
        $this->rate->hit('admin-setup-ip:'.$ip,5,3600);$this->rate->hit('admin-setup-global',10,3600);
        $expected=$this->setup['token_sha256']??'';
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected) || strlen($token)<32 || strlen($token)>128 || !hash_equals($expected,hash('sha256',$token))){throw new Problem('ACCESS_DENIED','کلید راه‌اندازی معتبر نیست.',403);}
        $username=$this->username($username);$name=Security::name($name);$hash=Security::hashPassword($password);
        return $this->db->tx(function()use($username,$name,$hash){
            $i=$this->db->one('SELECT bootstrapped_at FROM m_installation WHERE id=1 FOR UPDATE');
            if(!$i || $i['bootstrapped_at']!==null || $this->db->one('SELECT id FROM m_admin_accounts LIMIT 1')){throw new Problem('SETUP_LOCKED','راه‌اندازی اولیه قبلاً انجام شده است.',409);}
            $uid=Security::id();$aid=Security::id();$now=time();
            $this->db->run('INSERT INTO m_users(id,display_name,created_at,updated_at) VALUES (?,?,?,?)',[$uid,$name,$now,$now]);
            $this->db->run('INSERT INTO m_admin_accounts(id,user_id,username,password_hash,role,created_at) VALUES (?,?,?,?,\'system_admin\',?)',[$aid,$uid,$username,$hash,$now]);
            $this->db->run('UPDATE m_installation SET bootstrapped_at=? WHERE id=1',[$now]);$this->audit->add($uid,'admin','installation.first_admin_created',$aid);return $aid;
        });
    }
    public function login(string $username,string $password,string $ip):array
    {
        $username=strtolower(trim($username));$this->rate->hit('admin-login-ip:'.$ip,10,900);$this->rate->hit('admin-login-name:'.$username,5,900);
        $a=$this->db->one('SELECT a.id,a.user_id,a.password_hash,u.status FROM m_admin_accounts a JOIN m_users u ON u.id=a.user_id WHERE a.username=?',[$username]);
        $ok=Security::verifyPassword($password,$a['password_hash']??'$2y$12$eImiTXuWVxfM37uY4JANjQeF7VEffz5ynmEKD/Yh2tA8uXKvH9Z9K');
        if(!$a || !$ok || $a['status']!=='active'){$this->audit->add(null,'admin','auth.password_failed');throw new Problem('INVALID_CREDENTIALS','شناسه یا رمز مدیر صحیح نیست یا دسترسی فعال نیست.',401);}
        return ['user_id'=>$a['user_id'],'admin_id'=>$a['id']];
    }
    public function system(array $actor,?string $password=null,bool $lock=false):void
    {
        if(($actor['realm']??'')!=='admin'){throw new Problem('ACCESS_DENIED','نشست مدیریت لازم است.',403);}$a=$this->db->one('SELECT a.role,a.password_hash FROM m_admin_accounts a JOIN m_users u ON u.id=a.user_id WHERE a.id=? AND a.user_id=? AND u.status=\'active\''.($lock?' FOR UPDATE':''),[$actor['admin_id']??'', $actor['user_id']??'']);
        if(!$a || $a['role']!=='system_admin'){throw new Problem('ACCESS_DENIED','مجوز مدیر سامانه لازم است.',403);}
        if($password!==null && !Security::verifyPassword($password,$a['password_hash'])){throw new Problem('REAUTH_REQUIRED','رمز فعلی مدیر صحیح نیست.',403);}
    }
    public function changePassword(array $actor,string $current,string $new):void
    {
        $this->rate->hit('admin-password-change:'.$actor['user_id'],8,900);$hash=Security::hashPassword($new);
        $this->db->tx(function()use($actor,$current,$hash){
            $a=$this->db->one('SELECT a.password_hash FROM m_admin_accounts a JOIN m_users u ON u.id=a.user_id WHERE a.id=? AND a.user_id=? AND u.status=\'active\' FOR UPDATE',[$actor['admin_id']??'',$actor['user_id']]);
            if(($actor['realm']??'')!=='admin'||!$a||!Security::verifyPassword($current,$a['password_hash'])){throw new Problem('REAUTH_REQUIRED','رمز فعلی مدیریت صحیح نیست.',403);}
            $this->db->run('UPDATE m_admin_accounts SET password_hash=? WHERE id=?',[$hash,$actor['admin_id']]);
            $this->db->run('UPDATE m_auth_sessions SET revoked_at=? WHERE admin_id=? AND revoked_at IS NULL',[time(),$actor['admin_id']]);
            $this->audit->add($actor['user_id'],'admin','auth.admin_password_changed',$actor['admin_id']);
        });
    }
    public function addAdmin(array $actor,string $current,string $user,string $username,string $password,string $role):void
    {
        $this->rate->hit('admin-proof:'.$actor['user_id'],10,900);
        if(!in_array($role,['system_admin','content_admin'],true)||!Security::validId($user)){throw new Problem('INVALID_INPUT','نقش یا حساب معتبر نیست.');}
        $username=$this->username($username);$hash=Security::hashPassword($password);
        $this->db->tx(function()use($actor,$current,$user,$username,$hash,$role){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->system($actor,$current,true);
            if(!$this->db->one('SELECT id FROM m_users WHERE id=? AND status=\'active\' FOR UPDATE',[$user])){throw new Problem('NOT_FOUND','حساب فعال پیدا نشد.',404);}
            if($this->db->one('SELECT id FROM m_admin_accounts WHERE user_id=? OR username=?',[$user,$username])){throw new Problem('CONFLICT','این حساب یا شناسه قبلاً مدیر شده است.',409);}
            $id=Security::id();$this->db->run('INSERT INTO m_admin_accounts(id,user_id,username,password_hash,role,created_at) VALUES (?,?,?,?,?,?)',[$id,$user,$username,$hash,$role,time()]);$this->audit->add($actor['user_id'],'admin','access.admin_created',$id);
        });
    }
    public function role(array $actor,string $current,string $target,string $role):void
    {
        $this->rate->hit('admin-proof:'.$actor['user_id'],10,900);
        if(!in_array($role,['system_admin','content_admin'],true)){throw new Problem('INVALID_ROLE','نقش معتبر نیست.');}
        $this->db->tx(function()use($actor,$current,$target,$role){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->system($actor,$current,true);
            if($target===$actor['admin_id']){throw new Problem('SELF_CHANGE_DENIED','تغییر نقش خودتان از این مسیر مجاز نیست.',403);}
            $a=$this->db->one('SELECT id FROM m_admin_accounts WHERE id=? FOR UPDATE',[$target]);if(!$a){throw new Problem('NOT_FOUND','مدیر پیدا نشد.',404);}
            $this->db->run('UPDATE m_admin_accounts SET role=? WHERE id=?',[$role,$target]);
            $this->db->run('UPDATE m_auth_sessions SET revoked_at=? WHERE admin_id=? AND revoked_at IS NULL',[time(),$target]);$this->audit->add($actor['user_id'],'admin','access.admin_role_changed',$target);
        });
    }
    public function userStatus(array $actor,string $current,string $target,string $status):void
    {
        $this->rate->hit('admin-proof:'.$actor['user_id'],10,900);
        if(!in_array($status,['active','blocked'],true)){throw new Problem('INVALID_STATUS','وضعیت معتبر نیست.');}
        $this->db->tx(function()use($actor,$current,$target,$status){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->system($actor,$current,true);
            if($target===$actor['user_id']){throw new Problem('SELF_CHANGE_DENIED','محدودکردن حساب خودتان مجاز نیست.',403);}
            $u=$this->db->one('SELECT id FROM m_users WHERE id=? FOR UPDATE',[$target]);if(!$u){throw new Problem('NOT_FOUND','حساب پیدا نشد.',404);}
            if(\Morshed\Core\Upgrade::ready($this->db)&&!empty(\Morshed\Identity\Profile::get($this->db,$target)['deleted_at'])){throw new Problem('USER_DELETED','ابتدا کاربر حذف‌شده را بازیابی کنید.',409);}$this->db->run('UPDATE m_users SET status=?,updated_at=? WHERE id=?',[$status,time(),$target]);
            if($status==='blocked'){$this->db->run('UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL',[time(),$target]);}
            $this->audit->add($actor['user_id'],'admin','access.user_'.$status,$target);
        });
    }
    public function createUser(array $actor,string $current,array $d):string
    {
        $this->rate->hit('admin-proof:'.$actor['user_id'],10,900);
        $username=strtolower(trim($d['username']??''));if(!preg_match('/^[a-z][a-z0-9_]{3,31}$/D',$username)){throw new Problem('INVALID_INPUT','نام کاربری ۴ تا ۳۲ حرف انگلیسی، عدد یا زیرخط و با حرف شروع شود.');}
        $profile=\Morshed\Identity\Profile::validate($d);$hash=Security::hashPassword($d['new_password']??'');
        return $this->db->tx(function()use($actor,$current,$d,$profile,$username,$hash){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->system($actor,$current,true);
            if(!\Morshed\Core\Upgrade::ready($this->db)){throw new Problem('UPGRADE_REQUIRED','ابتدا دیتابیس را به‌روزرسانی کنید.',409);}
            if($this->db->one("SELECT id FROM m_auth_identities WHERE provider='local_username' AND subject=?",[$username])){throw new Problem('USERNAME_TAKEN','نام کاربری تکراری است.',409);}
            $uid=Security::id();$this->db->run('INSERT INTO m_users(id,password_hash,created_at,updated_at) VALUES(?,?,?,?)',[$uid,$hash,time(),time()]);
            \Morshed\Identity\Profile::save($this->db,$uid,$profile);
            $this->db->run("INSERT INTO m_auth_identities VALUES(?,?,'local_username',?,?)",[Security::id(),$uid,$username,time()]);
            $role=$d['role']??'user';$this->assignUserRole($uid,is_string($role)?$role:'',$d);
            $this->audit->add($actor['user_id'],'admin','access.user_created',$uid);return $uid;
        });
    }
    private function assignUserRole(string $target,string $role,array $d=[]):void
    {
        if(!in_array($role,['user','mentor'],true)){throw new Problem('INVALID_ROLE','نقش کاربر یا مرشد را انتخاب کنید.');}
        $u=$this->db->one("SELECT id,display_name FROM m_users WHERE id=? AND status='active' FOR UPDATE",[$target]);
        if(!$u||!empty(\Morshed\Identity\Profile::get($this->db,$target)['deleted_at'])){throw new Problem('NOT_FOUND','حساب فعال پیدا نشد.',404);}
        if($role==='user'){$this->db->run("UPDATE m_mentors SET status='restricted',updated_at=? WHERE user_id=?",[time(),$target]);return;}
        $old=$this->db->one('SELECT headline,bio FROM m_mentors WHERE user_id=?',[$target]);
        $headline=\Morshed\Product\Policy::text(($d['headline']??'')?:($old['headline']??($u['display_name']?:'مرشد')),600,1);
        if(preg_match_all('/./us',$headline)>180){throw new Problem('INVALID_INPUT','عنوان فعالیت حداکثر ۱۸۰ نویسه دارد.');}
        $bio=\Morshed\Product\Policy::text($d['bio']??($old['bio']??''),20000,0);
        // Role assignment is an administrator decision, never a fabricated user acceptance.
        $this->db->run("INSERT INTO m_mentors(user_id,headline,bio,status,invited_at,updated_at) VALUES(?,?,?,'active',?,?) ON DUPLICATE KEY UPDATE headline=VALUES(headline),bio=VALUES(bio),status='active',updated_at=VALUES(updated_at)",[$target,$headline,$bio,time(),time()]);
        $this->db->run('INSERT IGNORE INTO m_schedule_settings(mentor_id) VALUES(?)',[$target]);
    }
    public function userRole(array $actor,string $current,string $target,string $role,array $d=[]):void
    {
        $this->rate->hit('admin-proof:'.$actor['user_id'],10,900);
        $this->db->tx(function()use($actor,$current,$target,$role,$d){$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->system($actor,$current,true);$this->assignUserRole($target,$role,$d);$this->audit->add($actor['user_id'],'admin','access.user_role_'.$role,$target);});
    }
    public function editUser(array $actor,string $current,string $target,array $d):void
    {
        $this->rate->hit('admin-proof:'.$actor['user_id'],10,900);
        $this->db->tx(function()use($actor,$current,$target,$d){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->system($actor,$current,true);
            if(!Security::validId($target)||!$this->db->one('SELECT id FROM m_users WHERE id=? FOR UPDATE',[$target])){throw new Problem('NOT_FOUND','کاربر پیدا نشد.',404);}
            if(!empty(\Morshed\Identity\Profile::get($this->db,$target)['deleted_at'])){throw new Problem('USER_DELETED','ابتدا کاربر حذف‌شده را بازیابی کنید.',409);}
            $profile=\Morshed\Identity\Profile::validate($d);$username=$d['username']??'';
            if(!is_string($username)||!preg_match('/^[a-z][a-z0-9_]{3,31}$/D',$username)){throw new Problem('INVALID_USERNAME','نام کاربری باید ۴ تا ۳۲ نویسه انگلیسی باشد و با حرف شروع شود.');}
            $existing=$this->db->one("SELECT user_id FROM m_auth_identities WHERE provider='local_username' AND subject=?",[$username]);
            if($existing&&$existing['user_id']!==$target){throw new Problem('USERNAME_TAKEN','نام کاربری قبلاً استفاده شده است.',409);}
            $identity=$this->db->one("SELECT id FROM m_auth_identities WHERE provider='local_username' AND user_id=?",[$target]);
            if($identity){$this->db->run('UPDATE m_auth_identities SET subject=? WHERE id=?',[$username,$identity['id']]);}
            else{$this->db->run("INSERT INTO m_auth_identities VALUES(?,?,'local_username',?,?)",[Security::id(),$target,$username,time()]);}
            \Morshed\Identity\Profile::save($this->db,$target,$profile);
            $this->audit->add($actor['user_id'],'admin','access.user_profile_edited',$target);
        });
    }
    public function deleteUser(array $actor,string $current,string $target,bool $restore=false):void
    {
        $this->rate->hit('admin-proof:'.$actor['user_id'],10,900);
        $this->db->tx(function()use($actor,$current,$target,$restore){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->system($actor,$current,true);
            if(!\Morshed\Core\Upgrade::ready($this->db)){throw new Problem('UPGRADE_REQUIRED','ابتدا دیتابیس را به‌روزرسانی کنید.',409);}
            if($target===$actor['user_id']||$this->db->one('SELECT id FROM m_admin_accounts WHERE user_id=?',[$target])){throw new Problem('ADMIN_PROTECTED','حساب مدیر از این بخش حذف نمی‌شود.',403);}
            if(!$this->db->one('SELECT id FROM m_users WHERE id=? FOR UPDATE',[$target])){throw new Problem('NOT_FOUND','کاربر پیدا نشد.',404);}
            $this->db->run('INSERT INTO m_user_profiles(user_id,deleted_at) VALUES(?,?) ON DUPLICATE KEY UPDATE deleted_at=VALUES(deleted_at)',[$target,$restore?null:time()]);
            $this->db->run('UPDATE m_users SET status=?,updated_at=? WHERE id=?',[$restore?'active':'blocked',time(),$target]);
            $this->db->run('UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL',[time(),$target]);
            $this->audit->add($actor['user_id'],'admin',$restore?'access.user_restored':'access.user_deleted',$target);
        });
    }
}
