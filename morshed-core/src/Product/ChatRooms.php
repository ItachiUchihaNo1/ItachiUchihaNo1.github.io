<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Audit,Problem};
/** Text transport for existing bookings/messages; never a second reservation system. */
final class ChatRooms {
 public function __construct(private Database $db){}
 private function booking(array $a,string $id):array {
  $b=(new Consultations($this->db,new Audit($this->db)))->booking($a,$id);
  if(($b['requested_method']??'')!=='chat')throw new Problem('CHAT_NOT_SELECTED','روش این جلسه چت متنی نیست.',409);
  return $b;
 }
 public function consentInfo(array $a,string $id):?array {
  $this->booking($a,$id);$v=$this->db->one("SELECT v.id,v.title,v.body FROM m_legal_publications p JOIN m_legal_versions v ON v.id=p.version_id AND v.document_key='recording' WHERE p.document_key='recording'");
  if($v)$v['accepted']=(bool)$this->db->one('SELECT user_id FROM m_user_acceptances WHERE user_id=? AND version_id=?',[$a['user_id'],$v['id']]);return $v;
 }
 public function consent(array $a,string $id,string $version):void {
  $this->db->tx(function()use($a,$id,$version){$p=$this->db->one("SELECT version_id FROM m_legal_publications WHERE document_key='recording' FOR UPDATE");$this->booking($a,$id);if(!$p||!$p['version_id']||!hash_equals($p['version_id'],$version))throw new Problem('LEGAL_VERSION_CHANGED','شرایط تغییر کرده است؛ متن تازه را بخوانید.',409);$this->db->run('INSERT IGNORE INTO m_user_acceptances VALUES(?,?,?)',[$a['user_id'],$version,time()]);(new Audit($this->db))->add($a['user_id'],'site','chat.archive_consent',$id);});
 }
 public function state(array $a,string $id):array {
  $b=$this->booking($a,$id);$now=time();$allowed=false;$reason='';$code='';
  if($b['state']!=='confirmed'){$reason=in_array($b['state'],['completed','no_show','cancelled','rejected','expired'],true)?'این جلسه بسته است؛ تاریخچهٔ پیام‌ها در دسترس شماست.':'ورود پس از تأیید مرشد فعال می‌شود.';}
  elseif($now<(int)$b['starts_at'])$reason='گفت‌وگو در ساعت رزروشده آغاز می‌شود.';
  elseif($now>=(int)$b['ends_at'])$reason='زمان جلسه پایان یافته است؛ تاریخچهٔ پیام‌ها در دسترس شماست.';
  else{try{(new Consultations($this->db,new Audit($this->db)))->writable($a,$id);$allowed=true;$reason='گفت‌وگو فعال است.';}catch(Problem $e){$reason=$e->getMessage();$code=$e->errorCode;}}
  return ['booking'=>$b,'can_send'=>$allowed,'reason'=>$reason,'block_code'=>$code,'server_time'=>$now,'starts_at'=>(int)$b['starts_at'],'ends_at'=>(int)$b['ends_at']];
 }
 private function dto(array $a,array $rows):array {return array_map(fn($r)=>['id'=>(int)$r['id'],'body'=>$r['body'],'created_at'=>(int)$r['created_at'],'own'=>$r['sender_id']===$a['user_id']],$rows);}
 public function read(array $a,string $id,int $after=0,int $before=0,bool $initial=false):array {
  $s=$this->state($a,$id);$after=Policy::integer($after,0,PHP_INT_MAX);$before=Policy::integer($before,0,PHP_INT_MAX);
  if($before||$initial){$rows=$this->db->all('SELECT id,sender_id,body,created_at FROM m_messages WHERE booking_id=?'.($before?' AND id<?':'').' ORDER BY id DESC LIMIT 51',$before?[$id,$before]:[$id]);$more=count($rows)>50;$rows=array_reverse(array_slice($rows,0,50));}
  else{$rows=$this->db->all('SELECT id,sender_id,body,created_at FROM m_messages WHERE booking_id=? AND id>? ORDER BY id LIMIT 101',[$id,$after]);$more=count($rows)>100;$rows=array_slice($rows,0,100);}
  return array_diff_key($s,['booking'=>1])+['messages'=>$this->dto($a,$rows),'more'=>$more];
 }
 public function send(array $a,string $id,string $key,string $body):array {
  if(!preg_match('/^[a-zA-Z0-9_-]{12,64}$/D',$key))throw new Problem('INVALID_INPUT','شناسهٔ پیام معتبر نیست.');$s=$this->state($a,$id);if(!$s['can_send'])throw new Problem('CHAT_CLOSED',$s['reason'],409);$m=(new Consultations($this->db,new Audit($this->db)))->message($a,$id,$key,$body,true);return ['message'=>['id'=>$m['id'],'body'=>$m['body'],'created_at'=>$m['created_at'],'own'=>true],'server_time'=>time(),'ends_at'=>$s['ends_at']];
 }
}
