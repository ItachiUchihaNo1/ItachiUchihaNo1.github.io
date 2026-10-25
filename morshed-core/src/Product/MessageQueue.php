<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Audit,Problem,Security};
final class MessageQueue {
 public function __construct(private Database $db,private array $config){}
 public static function dueSql():string {return "(q.notification_id IS NULL OR (q.mode<>'manual' AND (q.send_after IS NULL OR q.send_after<=".time().")))";}
 public static function due(array $r):bool{return ($r['queue_mode']??null)!=='manual'&&(!isset($r['send_after'])||(int)$r['send_after']<=time());}
 public static function expiryBase(array $r):int{return max((int)$r['notification_created_at'],(int)($r['send_after']??0));}
 private function table(array $a,string $channel):string {(new Policy($this->db))->admin($a,true);(new Messaging($this->db,$this->config))->storage();return Policy::choice($channel,['eitaa','sms'])==='eitaa'?'m_eitaa_deliveries':'m_sms_deliveries';}
 public function control(array $a,string $channel,string $id,string $action):void {
  $table=$this->table($a,$channel);if(!Security::validId($id))throw new Problem('INVALID_INPUT','پیام معتبر نیست.');Policy::choice($action,['cancel','release','restore']);
  $this->db->tx(function()use($a,$table,$id,$action){$row=$this->db->one("SELECT state FROM $table WHERE notification_id=? FOR UPDATE",[$id]);if(!$row)throw new Problem('NOT_FOUND','پیام پیدا نشد.',404);$allowed=$action==='restore'?['cancelled']:($action==='cancel'?['pending','permission_required']:['pending']);if(!in_array($row['state'],$allowed,true))throw new Problem('QUEUE_CHANGED','وضعیت پیام تغییر کرده؛ پیامِ در حال ارسال یا ارسال‌شده قابل لغو یا ارسال دوباره نیست.',409);
   if($action==='cancel')$this->db->run("UPDATE $table SET state='cancelled',updated_at=? WHERE notification_id=?",[time(),$id]);
   else{$manual=$action==='restore';$this->db->run("INSERT INTO m_message_schedule VALUES(?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode),send_after=VALUES(send_after)",[$id,$manual?'manual':'immediate',$manual?null:time()]);$this->db->run("UPDATE $table SET state='pending',updated_at=? WHERE notification_id=?",[time(),$id]);}
   (new Audit($this->db))->add($a['user_id'],'admin','message_queue.'.$action,$id);
  });
 }
 public function releaseAll(array $a,string $channel):int {
  $table=$this->table($a,$channel);return $this->db->tx(function()use($a,$table,$channel){$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$count=(int)$this->db->run("SELECT COUNT(*) FROM $table d LEFT JOIN m_message_schedule q ON q.notification_id=d.notification_id WHERE d.state='pending' AND (q.mode='manual' OR ".self::dueSql().")")->fetchColumn();$this->db->run("UPDATE m_message_schedule q JOIN $table d ON d.notification_id=q.notification_id SET q.mode='immediate',q.send_after=? WHERE d.state='pending' AND (q.mode='manual' OR q.send_after IS NULL OR q.send_after<=?)",[time(),time()]);(new Audit($this->db))->add($a['user_id'],'admin','message_queue.release_all',$channel);return $count;});
 }
 public function dueCount(array $a,string $channel):int {$table=$this->table($a,$channel);return (int)$this->db->run("SELECT COUNT(*) FROM $table d LEFT JOIN m_message_schedule q ON q.notification_id=d.notification_id WHERE d.state='pending' AND ".self::dueSql())->fetchColumn();}
 public function workerActive():bool {(new Messaging($this->db,$this->config))->storage();return (int)$this->db->run('SELECT COALESCE(MAX(last_run),0) FROM m_message_worker')->fetchColumn()>time()-180;}
}
