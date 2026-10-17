<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Security,Audit,Problem};
final class Operations
{
    private Policy $policy;
    public function __construct(private Database $db,private Audit $audit){$this->policy=new Policy($db);}
    private function write(callable $fn):mixed{return $this->db->tx(function()use($fn){$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');return $fn();});}
    public function ticket(array $a,array $d):string
    {return $this->write(function()use($a,$d){$uid=$this->policy->user($a);$kind=Policy::choice($d['kind']??'support',['support','complaint']);$bid=($d['booking_id']??'')?:null;if($bid){(new Consultations($this->db,$this->audit))->booking($a,$bid);}if($kind==='complaint'&&!$bid){throw new Problem('BOOKING_REQUIRED','شکایت باید به رزرو خودتان متصل باشد.');}
        $title=Policy::text($d['title']??'',180);$body=Policy::text($d['body']??'',12000);$id=Security::id();$this->db->run('INSERT INTO m_tickets(id,user_id,booking_id,kind,title,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$id,$uid,$bid,$kind,$title,time(),time()]);$this->db->run('INSERT INTO m_ticket_messages(ticket_id,author_id,body,created_at) VALUES(?,?,?,?)',[$id,$uid,$body,time()]);$this->audit->add($uid,'site','ticket.created',$id);return $id;});}
    public function getTicket(array $a,string $id):array
    {$admin=($a['realm']??'')==='admin';$uid=$admin?$this->policy->admin($a,true):$this->policy->user($a);$t=$this->db->one('SELECT * FROM m_tickets WHERE id=?',[$id]);if(!$t||(!$admin&&$t['user_id']!==$uid)){throw new Problem('NOT_FOUND','پرونده پیدا نشد.',404);}return $t;}
    public function reply(array $a,string $id,string $body,?string $state=null):void
    {$this->write(function()use($a,$id,$body,$state){$t=$this->getTicket($a,$id);$admin=$a['realm']==='admin';if(!$admin&&$t['state']==='closed'){throw new Problem('INVALID_STATE','پرونده بسته است.',409);}$body=Policy::text($body,12000);if($state!==null){if(!$admin){throw new Problem('ACCESS_DENIED','مجوز لازم است.',403);}Policy::choice($state,['open','closed']);}
        $this->db->run('INSERT INTO m_ticket_messages(ticket_id,author_id,body,created_at) VALUES(?,?,?,?)',[$id,$a['user_id'],$body,time()]);$this->db->run('UPDATE m_tickets SET updated_at=?,state=? WHERE id=?',[time(),$state??$t['state'],$id]);if($state==='closed'){$this->db->run('UPDATE m_archive_grants SET revoked_at=COALESCE(revoked_at,?) WHERE ticket_id=?',[time(),$id]);}$this->audit->add($a['user_id'],$a['realm'],'ticket.replied',$id);if($admin){$this->audit->notify($t['user_id'],'پاسخ پشتیبانی','پاسخ پرونده شما ثبت شد.','/ticket?id='.$id);}});}
    public function grant(array $a,array $d):string
    {return $this->write(function()use($a,$d){$uid=$this->policy->admin($a,true);$t=$this->getTicket($a,$d['ticket_id']??'');$reviewer=Policy::text($d['reviewer_id']??'',32);$purpose=Policy::text($d['purpose']??'',500);$hours=Policy::integer($d['hours']??'',1,72);
        if($t['kind']!=='complaint'||!$t['booking_id']||$t['state']!=='open'){throw new Problem('CASE_REQUIRED','پرونده شکایت باز و مرتبط با رزرو لازم است.',409);}
        $b=$this->db->one('SELECT user_id,mentor_id FROM m_bookings WHERE id=?',[$t['booking_id']]);if(in_array($uid,[$reviewer,$b['user_id'],$b['mentor_id']],true)||in_array($reviewer,[$b['user_id'],$b['mentor_id']],true)){throw new Problem('SEPARATION_REQUIRED','تأییدکننده و ناظر باید مستقل از یکدیگر و طرفین جلسه باشند.',403);}
        if(!$this->db->one("SELECT a.id FROM m_admin_accounts a JOIN m_users u ON u.id=a.user_id WHERE a.user_id=? AND u.status='active'",[$reviewer])){throw new Problem('INVALID_REVIEWER','ناظر باید مدیر فعال باشد.');}
        $id=Security::id();$this->db->run('INSERT INTO m_archive_grants(id,ticket_id,booking_id,reviewer_id,approved_by,purpose,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?)',[$id,$t['id'],$t['booking_id'],$reviewer,$uid,$purpose,time()+$hours*3600,time()]);$this->audit->add($uid,'admin','archive.grant_issued',$id);return $id;});}
    public function revokeGrant(array $a,string $id):void
    {$this->write(function()use($a,$id){$this->policy->admin($a,true);$this->db->run('UPDATE m_archive_grants SET revoked_at=COALESCE(revoked_at,?) WHERE id=?',[time(),$id]);$this->audit->add($a['user_id'],'admin','archive.grant_revoked',$id);});}
    public function feedback(array $a,string $bid,int $score,string $body):void
    {$this->write(function()use($a,$bid,$score,$body){$this->policy->user($a);$b=(new Consultations($this->db,$this->audit))->booking($a,$bid);if($b['user_id']!==$a['user_id']||$b['state']!=='completed'){throw new Problem('INVALID_STATE','بازخورد فقط توسط دریافت‌کننده جلسه پایان‌یافته مجاز است.',409);}Policy::integer($score,1,5);$body=Policy::text($body,6000,0);if($this->db->one('SELECT booking_id FROM m_feedback WHERE booking_id=?',[$bid])){throw new Problem('CONFLICT','بازخورد قبلاً ثبت شده است.',409);}$this->db->run('INSERT INTO m_feedback VALUES(?,?,?,?,?)',[$bid,$a['user_id'],$score,$body,time()]);$this->audit->add($a['user_id'],'site','feedback.created',$bid);});}
    public function organization(array $a,array $d):string
    {return $this->write(function()use($a,$d){$this->policy->admin($a,true);$id=($d['id']??'')?:Security::id();$name=Policy::text($d['name']??'',180);$active=Policy::integer($d['active']??1,0,1);$this->db->run('INSERT INTO m_organizations(id,name,active) VALUES(?,?,?) ON DUPLICATE KEY UPDATE name=?,active=?',[$id,$name,$active,$name,$active]);$this->audit->add($a['user_id'],'admin','organization.saved',$id);return $id;});}
    public function membership(array $a,string $org,string $user,bool $remove=false):void
    {$this->write(function()use($a,$org,$user,$remove){$this->policy->admin($a,true);if(!$this->db->one('SELECT id FROM m_organizations WHERE id=? AND active=1',[$org])||!$this->db->one('SELECT id FROM m_users WHERE id=?',[$user])){throw new Problem('NOT_FOUND','سازمان یا کاربر پیدا نشد.',404);}if($remove){$this->db->run('DELETE FROM m_memberships WHERE org_id=? AND user_id=?',[$org,$user]);}else{$this->db->run('INSERT IGNORE INTO m_memberships VALUES(?,?)',[$org,$user]);}$this->audit->add($a['user_id'],'admin','organization.membership_changed',$org);});}
}
