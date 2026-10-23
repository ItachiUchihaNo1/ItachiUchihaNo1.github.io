<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Security,Audit,Problem};
final class Contributions
{
 public function __construct(private Database $db){}
 public function storage():void {
  if($this->db->featureReady('contributions'))return;
  try{$this->db->run('SELECT org_id FROM m_organization_details LIMIT 0');$this->db->run('SELECT id FROM m_experience_leads LIMIT 0');}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)!==1146)throw $e;if($this->db->pdo->inTransaction())throw new \LogicException('Prepare storage before transaction');foreach(explode(';',file_get_contents(__DIR__.'/../../database/contributions.sql')) as $sql){if(trim($sql)!=='')$this->db->run($sql);}}
  $this->db->markFeatureReady('contributions');
 }
 public function submit(array $a,array $d):string {
  $uid=(new Policy($this->db))->user($a);$title=Policy::text($d['title']??'',960,4);if(preg_match_all('/./us',$title)>240)throw new Problem('INVALID_INPUT','عنوان باید حداکثر ۲۴۰ نویسه باشد.');$summary=Policy::text($d['summary']??'',6000,15);$this->storage();
  return $this->db->tx(function()use($uid,$title,$summary){$this->db->one('SELECT id FROM m_users WHERE id=? FOR UPDATE',[$uid]);
   $old=$this->db->one('SELECT id FROM m_experience_leads WHERE user_id=? AND title=? AND summary=? AND created_at>?',[$uid,$title,$summary,time()-86400]);if($old)return $old['id'];
   if((int)$this->db->run('SELECT COUNT(*) FROM m_experience_leads WHERE user_id=? AND created_at>?',[$uid,time()-86400])->fetchColumn()>=5)throw new Problem('RATE_LIMITED','امروز پنج تجربه فرستاده‌ای؛ فردا دوباره اقدام کن.',429);
   $id=Security::id();$this->db->run('INSERT INTO m_experience_leads(id,user_id,title,summary,created_at,updated_at) VALUES(?,?,?,?,?,?)',[$id,$uid,$title,$summary,time(),time()]);(new Audit($this->db))->add($uid,'site','experience.lead_submitted',$id);return $id;
  });
 }
 public function listing(array $a,bool $admin=false):array {
  $p=new Policy($this->db);$admin?$p->admin($a,true):$p->user($a);$this->storage();return $this->db->all('SELECT l.*,u.display_name'.($admin?',u.phone':'').' FROM m_experience_leads l JOIN m_users u ON u.id=l.user_id'.($admin?'':' WHERE l.user_id=?').' ORDER BY l.created_at DESC LIMIT 100',$admin?[]:[$a['user_id']]);
 }
 public function review(array $a,array $d):void {
  (new Policy($this->db))->admin($a,true);$this->storage();$id=Policy::text($d['id']??'',32);$state=Policy::choice($d['state']??'',['open','contacted','closed']);if(!$this->db->one('SELECT id FROM m_experience_leads WHERE id=?',[$id]))throw new Problem('NOT_FOUND','درخواست پیدا نشد.',404);$this->db->run('UPDATE m_experience_leads SET state=?,updated_at=? WHERE id=?',[$state,time(),$id]);(new Audit($this->db))->add($a['user_id'],'admin','experience.lead_'.$state,$id);
 }
 public function details(string $id):array {$this->storage();return $this->db->one('SELECT d.*,u.display_name AS representative_name,m.user_id AS representative_mentor_id FROM m_organization_details d LEFT JOIN m_users u ON u.id=d.representative_id AND u.status=\'active\' LEFT JOIN m_mentors m ON m.user_id=u.id AND m.status=\'active\' WHERE d.org_id=?',[$id])??[];}
 private function link(mixed $value):string {$v=Policy::text($value,1000,0);if($v!==''&&(!filter_var($v,FILTER_VALIDATE_URL)||!in_array(strtolower(parse_url($v,PHP_URL_SCHEME)??''),['https','http'],true)||parse_url($v,PHP_URL_USER)||parse_url($v,PHP_URL_PASS)))throw new Problem('INVALID_URL','نشانی سایت یا شبکه اجتماعی معتبر نیست.');return $v;}
 public function prepareDetails(array $a,array $d):array {
  (new Policy($this->db))->admin($a,true);$this->storage();$website=$this->link($d['website']??'');$social=[];foreach(array_filter(array_map('trim',explode("\n",Policy::text($d['social_urls']??'',6000,0)))) as $line){$social[]=$this->link($line);}if(count($social)>10)throw new Problem('INVALID_INPUT','حداکثر ده نشانی اجتماعی مجاز است.');
  $logo=Policy::text($d['logo_asset_id']??'',32,0);$existingLogo=is_string($d['id']??null)?($this->db->one('SELECT logo_asset_id FROM m_organization_details WHERE org_id=?',[$d['id']])['logo_asset_id']??null):null;if($logo!==''&&$logo!==$existingLogo&&!$this->db->one("SELECT id FROM m_content_assets WHERE id=? AND owner_id=? AND media_type IN ('image/png','image/jpeg','image/webp')",[$logo,$a['user_id']]))throw new Problem('INVALID_INPUT','لوگو باید تصویر عمومی ثبت‌شده باشد.');
  $rep=Policy::text($d['representative_id']??'',32,0);$role=Policy::text($d['representative_role']??'',180,0);if($rep!==''&&(!$this->db->one("SELECT id FROM m_users WHERE id=? AND status='active'",[$rep])||$role===''))throw new Problem('INVALID_INPUT','حساب فعال مسئول و عنوان مسئولیت را مشخص کنید.');return [$logo?:null,$website,json_encode($social,JSON_THROW_ON_ERROR),$rep?:null,$role];
 }
 public function saveDetails(string $id,array $values):void {$this->db->run('INSERT INTO m_organization_details(org_id,logo_asset_id,website,social_urls,representative_id,representative_role,updated_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE logo_asset_id=VALUES(logo_asset_id),website=VALUES(website),social_urls=VALUES(social_urls),representative_id=VALUES(representative_id),representative_role=VALUES(representative_role),updated_at=VALUES(updated_at)',array_merge([$id],$values,[time()]));}
}
