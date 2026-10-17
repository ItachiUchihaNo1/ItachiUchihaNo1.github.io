<?php
declare(strict_types=1);
namespace Morshed\Identity;
use Morshed\Core\{Database,Security,Audit,Problem};
final class Sessions
{
    public function __construct(private Database $db,private Audit $audit) {}
    public function issue(string $user,string $realm,string $method,?string $admin=null,string $agent=''):array
    {
        if(!in_array($realm,['site','admin'],true) || ($realm==='admin')!==($admin!==null)) { throw new \LogicException('Invalid session realm.'); }
        $owner=$this->db->one('SELECT status FROM m_users WHERE id=?',[$user]);
        if(!$owner || $owner['status']!=='active') { throw new Problem('ACCESS_DENIED','حساب قابل استفاده نیست.',403); }
        if($admin!==null && !$this->db->one('SELECT id FROM m_admin_accounts WHERE id=? AND user_id=?',[$admin,$user])) { throw new Problem('ACCESS_DENIED','مجوز مدیریت معتبر نیست.',403); }
        $token=bin2hex(random_bytes(32));$id=Security::id();$now=time();$expiry=$now+($realm==='admin'?1800:1209600);
        $agent=substr(preg_replace('/[^\x20-\x7E]/','',$agent)??'',0,180);
        $this->db->tx(function()use($id,$user,$admin,$realm,$token,$method,$now,$expiry,$agent){
            $this->db->run('INSERT INTO m_auth_sessions(id,user_id,admin_id,realm,token_hash,auth_method,auth_at,created_at,expires_at,agent_label) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,$user,$admin,$realm,hash('sha256',$token),$method,$now,$now,$expiry,$agent]);
            $this->audit->add($user,$realm,'auth.session_issued',$id);
        });
        return ['token'=>$token,'id'=>$id,'expires_at'=>$expiry];
    }
    public function resolve(?string $token,string $realm):?array
    {
        if(!$token || !preg_match('/^[a-f0-9]{64}$/D',$token)) { return null; }
        $s=$this->db->one('SELECT s.id AS session_id,s.user_id,s.admin_id,s.auth_at,s.auth_method,s.expires_at,u.display_name,u.phone,u.phone_verified_at,a.role AS admin_role FROM m_auth_sessions s JOIN m_users u ON u.id=s.user_id LEFT JOIN m_admin_accounts a ON a.id=s.admin_id AND a.user_id=s.user_id WHERE s.token_hash=? AND s.realm=? AND s.revoked_at IS NULL AND s.expires_at>? AND u.status=\'active\'',[hash('sha256',$token),$realm,time()]);
        if(!$s || ($realm==='admin' && $s['admin_role']===null)) { return null; }
        $s['realm']=$realm;return $s;
    }
    public function list(string $user,string $realm):array
    {return $this->db->all('SELECT id,auth_method,created_at,expires_at,agent_label FROM m_auth_sessions WHERE user_id=? AND realm=? AND revoked_at IS NULL AND expires_at>? ORDER BY created_at DESC',[$user,$realm,time()]);}
    public function revoke(string $user,string $realm,string $id):void
    {
        $this->db->tx(function()use($user,$realm,$id){
            $n=$this->db->run('UPDATE m_auth_sessions SET revoked_at=? WHERE id=? AND user_id=? AND realm=? AND revoked_at IS NULL',[time(),$id,$user,$realm])->rowCount();
            if($n) { $this->audit->add($user,$realm,'auth.session_revoked',$id); }
        });
    }
    public function revokeOthers(string $user,string $realm,string $keep):void
    {
        $this->db->tx(function()use($user,$realm,$keep){
            $this->db->run('UPDATE m_auth_sessions SET revoked_at=? WHERE user_id=? AND realm=? AND id<>? AND revoked_at IS NULL',[time(),$user,$realm,$keep]);
            $this->audit->add($user,$realm,'auth.other_sessions_revoked');
        });
    }
}
