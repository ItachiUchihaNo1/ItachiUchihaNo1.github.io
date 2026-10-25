<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Audit,Security,Problem};
use Morshed\Identity\ProviderHttp;
/** Notifications remain in-app even if the external provider rejects delivery. */
final class EitaaDelivery
{
 public function __construct(private Database $db,private array $config,private ?ProviderHttp $http=null){}
 public function enabled():bool {$c=$this->config['eitaa']??[];return ($c['enabled']??false)===true&&($c['live_verified']??false)===true&&strlen($c['app_token']??'')>=16;}
 public function storage():void {(new Messaging($this->db,$this->config))->storage();}
 public function enqueue(string $id):void {$this->db->run("INSERT IGNORE INTO m_eitaa_deliveries VALUES(?,'pending',?,?)",[$id,time(),time()]);}
 public function dispatch(int $limit=5,?string $only=null):array {
  if(!$this->enabled())return ['disabled'=>true];$this->storage();$rows=$this->db->all("SELECT d.notification_id FROM m_eitaa_deliveries d LEFT JOIN m_message_schedule q ON q.notification_id=d.notification_id WHERE d.state='pending' AND ".MessageQueue::dueSql()."".($only?' AND d.notification_id=?':'')." ORDER BY d.created_at LIMIT ".max(1,min(20,$limit)),$only?[$only]:[]);$out=[];
  foreach($rows as $row){$id=$row['notification_id'];$payload=$this->db->tx(function()use($id){$q=$this->db->one('SELECT d.state,q.mode AS queue_mode,q.send_after,n.user_id,n.title,n.body,n.destination,n.created_at AS notification_created_at,i.subject,u.status,p.eitaa_allowed FROM m_eitaa_deliveries d LEFT JOIN m_message_schedule q ON q.notification_id=d.notification_id JOIN m_notifications n ON n.id=d.notification_id JOIN m_users u ON u.id=n.user_id LEFT JOIN m_auth_identities i ON i.user_id=n.user_id AND i.provider=\'eitaa\' LEFT JOIN m_message_permissions p ON p.user_id=n.user_id WHERE d.notification_id=? FOR UPDATE',[$id]);if(!$q||$q['state']!=='pending'||!MessageQueue::due($q))return null;$state=$q['status']!=='active'||!preg_match('/^[1-9][0-9]{0,19}$/D',$q['subject']??'')?'not_linked':(MessageQueue::expiryBase($q)<time()-86400?'expired':(!empty($q['eitaa_allowed'])?'sending':'permission_required'));$this->db->run('UPDATE m_eitaa_deliveries SET state=?,updated_at=? WHERE notification_id=?',[$state,time(),$id]);return $state==='sending'?$q:null;});if(!$payload)continue;
   // A timeout is ambiguous: never blindly retry and duplicate a private notification.
   $state='unknown';try{$reply=($this->http??new ProviderHttp())->request('https://eitaayar.ir/api/app/sendMessage',['token'=>$this->config['eitaa']['app_token'],'chat_id'=>$payload['subject'],'text'=>$payload['title']."\n\n".$payload['body']."\n".rtrim($this->config['origins']['site'],'/').($payload['destination']??'/notifications')]);$state=($reply['ok']??false)===true&&($reply['result']??null)==='success'?'sent':'rejected';}catch(\Throwable $e){$state='unknown';}
   $this->db->run('UPDATE m_eitaa_deliveries SET state=?,updated_at=? WHERE notification_id=?',[$state,time(),$id]);$out[$id]=$state;
  }return $out;
 }
 public function announce(array $a,array $d):array {
  (new Policy($this->db))->admin($a,true);$this->storage();if(!isset($d['user_ids'])){$names=array_values(array_unique(array_filter(array_map('trim',preg_split('/[\r\n,]+/',Policy::text($d['recipients']??'',1200))))));if(!$names||count($names)>20)throw new Problem('INVALID_RECIPIENT','مخاطب را انتخاب کنید.');$d['user_ids']=[];foreach($names as $name){$u=$this->db->one("SELECT user_id FROM m_auth_identities WHERE provider='local_username' AND subject=?",[$name]);if(!$u)throw new Problem('INVALID_RECIPIENT','مخاطب معتبر نیست.');$d['user_ids'][]=$u['user_id'];}}
  return (new Messaging($this->db,$this->config))->send($a,array_replace($d,['channel'=>'eitaa']));
 }
 public function process(array $a):array {(new Policy($this->db))->admin($a,true);return $this->dispatch(3);}
 public function overview(array $a):array {(new Policy($this->db))->admin($a,true);$this->storage();return $this->db->all('SELECT d.notification_id,d.state,d.updated_at,u.display_name,n.title FROM m_eitaa_deliveries d LEFT JOIN m_message_schedule q ON q.notification_id=d.notification_id JOIN m_notifications n ON n.id=d.notification_id JOIN m_users u ON u.id=n.user_id ORDER BY d.created_at DESC LIMIT 50');}
 public function test(array $a,string $username):array {
  (new Policy($this->db))->admin($a,true);if(!$this->enabled())throw new Problem('PROVIDER_DISABLED','اتصال ایتا در تنظیمات خصوصی کامل نیست.',503);$this->storage();$u=$this->db->one("SELECT u.id FROM m_users u JOIN m_auth_identities i ON i.user_id=u.id AND i.provider='local_username' WHERE i.subject=? AND u.status='active'",[$username]);if(!$u)throw new Problem('NOT_FOUND','حساب آزمایشی پیدا نشد.',404);if(!$this->db->one("SELECT id FROM m_auth_identities WHERE user_id=? AND provider='eitaa'",[$u['id']]))throw new Problem('EITAA_NOT_LINKED','این حساب هنوز به هویت تأییدشدهٔ ایتا متصل نیست.',409);
  return $this->probe($a,$u['id']);
 }
 public function selfTest(array $a):array {(new Policy($this->db))->user($a);if(!$this->enabled())throw new Problem('PROVIDER_DISABLED','اتصال ایتا هنوز فعال نیست.',503);$this->storage();if(!$this->db->one("SELECT id FROM m_auth_identities WHERE user_id=? AND provider='eitaa'",[$a['user_id']]))throw new Problem('EITAA_NOT_LINKED','ابتدا ورود ایتا را به همین حساب متصل کن.',409);return $this->probe($a,$a['user_id']);}
 private function probe(array $a,string $uid):array {
  $id=Security::id();$this->db->run("INSERT INTO m_notifications(id,user_id,title,body,created_at,destination,category) VALUES(?,?,?,?,?,'/sessions','system')",[$id,$uid,'آزمون اعلان جلسه مرشد','این یک پیام آزمایشی برای بررسی دریافت اعلان جلسه است؛ جلسهٔ تازه‌ای ثبت یا قطعی نشده است.',time()]);$this->enqueue($id);(new Audit($this->db))->add($a['user_id'],$a['realm'],'eitaa.test_requested',$id);return $this->dispatch(1,$id);
 }
}
