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
        $a=$this->db->one('SELECT a.role,a.password_hash FROM m_admin_accounts a JOIN m_users u ON u.id=a.user_id WHERE a.id=? AND a.user_id=? AND u.status=\'active\''.($lock?' FOR UPDATE':''),[$actor['admin_id']??'', $actor['user_id']??'']);
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
            $this->db->run('UPDATE m_users SET status=?,updated_at=? WHERE id=?',[$status,time(),$target]);
            if($status==='blocked'){$this->db->run('UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL',[time(),$target]);}
            $this->audit->add($actor['user_id'],'admin','access.user_'.$status,$target);
        });
    }
}
