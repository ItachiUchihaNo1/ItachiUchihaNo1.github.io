<?php
require __DIR__.'/common.php';
use Morshed\Core\{Security,Problem};
use Morshed\Product\{Consultations,ContentAssets};
$pass=0;
function check16(bool $ok,string $name):void{global $pass;if(!$ok){throw new RuntimeException($name);}++$pass;echo "PASS $name\n";}
function reject16(string $code,callable $fn):bool{try{$fn();}catch(Problem $e){return $e->errorCode===$code;}return false;}
$id=Security::id();$db->run('INSERT INTO m_users(id,phone,phone_verified_at,display_name,created_at,updated_at) VALUES(?,?,?,?,?,?)',[$id,'098'.random_int(10000000,99999999),time(),'مرشد برنامهٔ تکرارشونده',time(),time()]);$a=['realm'=>'site','user_id'=>$id];
$db->run("INSERT INTO m_mentors(user_id,headline,bio,status,invited_at,accepted_at,updated_at) VALUES(?,?,?,'active',?,?,?)",[$id,'معرفی','زندگی حرفه‌ای',time(),time(),time()]);$db->run('INSERT INTO m_schedule_settings(mentor_id) VALUES(?)',[$id]);
$c=new Consultations($db,$audit);$c->settings($a,['duration_minutes'=>30,'buffer_minutes'=>15,'lead_hours'=>1,'daily_capacity'=>20]);
$tomorrow=(new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran')))->modify('+2 days');$date=$tomorrow->format('Y-m-d');
$c->rule($a,['kind'=>'daily','weekday'=>0,'start_time'=>'10:00','end_time'=>'12:00']);
check16(count($c->slots($id,$date,null,15))===4,'daily recurrence generates 15-minute choices with buffers');
check16(count($c->slots($id,$date,null,30))===3,'same free window supports 30-minute choices');
check16(reject16('INVALID_DURATION',fn()=>$c->slots($id,$date,null,20)),'arbitrary requester duration is rejected');
$before=$db->queryCount();$browse=$c->browse($id,'2020-01-01',15);check16($browse['dateCorrected']&&$browse['slots']&&$browse['date']>='2026-01-01','stale calendar links recover to a current available date');
check16($db->queryCount()-$before<=6,'finding availability uses a bounded query snapshot');
$daily=$c->calendar($a)['recurrences'][0];$c->removeRule($a,$daily['id'],'daily');
check16($c->slots($id,$date,null,30)===[],'removing a recurrence immediately removes its availability');
check16(Consultations::persianMonthDay(new DateTimeImmutable('2026-03-21'))===[1,1],'Nowruz 1405 uses the correct Persian month boundary');
check16(Consultations::persianMonthDay(new DateTimeImmutable('2026-10-21'))===[7,29],'current date has the correct Persian month and day');
[$month,$day]=Consultations::persianMonthDay($tomorrow);$ordinal=intdiv($day-1,7)+1;
if($ordinal>4){$tomorrow=$tomorrow->modify('+7 days');[$month,$day]=Consultations::persianMonthDay($tomorrow);$ordinal=intdiv($day-1,7)+1;$date=$tomorrow->format('Y-m-d');}
$c->rule($a,['kind'=>'monthly','weekday'=>(int)$tomorrow->format('w'),'ordinal'=>$ordinal,'start_time'=>'10:00','end_time'=>'12:00']);
check16(count($c->slots($id,$date,null,30))===3,'monthly ordinal weekday generates the selected Persian-month occurrence');
check16($c->slots($id,$tomorrow->modify('+7 days')->format('Y-m-d'),null,30)===[],'monthly recurrence does not become an accidental weekly recurrence');
$c->exception($a,$date);check16($c->slots($id,$date,null,15)===[],'exceptions override monthly rules');$c->exception($a,$date,true);
$r=$c->calendar($a)['recurrences'][0];$c->removeRule($a,$r['id'],'monthly');
$c->rule($a,['kind'=>'monthly','weekday'=>(int)$tomorrow->format('w'),'ordinal'=>5,'start_time'=>'10:00','end_time'=>'12:00']);
$last=$tomorrow;while(Consultations::persianMonthDay($last->modify('+7 days'))[0]===$month){$last=$last->modify('+7 days');}
check16(count($c->slots($id,$last->format('Y-m-d'),null,30))===3,'last weekday is determined by the Persian month end');
$adminActor=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='test_admin'")+['realm'=>'admin'];
$uid=Security::id();$db->run('INSERT INTO m_users(id,phone,phone_verified_at,display_name,created_at,updated_at) VALUES(?,?,?,?,?,?)',[$uid,'098'.random_int(10000000,99999999),time(),'درخواست‌دهندهٔ پانزده دقیقه',time(),time()]);
$request=['mentor_id'=>$id,'user_id'=>$uid,'starts_at'=>$c->slots($id,$last->format('Y-m-d'),null,15)[0]['starts_at'],'duration_minutes'=>15,'subject'=>'گفت‌وگوی کوتاه','problem'=>'نیاز آزمایشی','idempotency_key'=>Security::id()];
$booking=$c->reserve($adminActor,$request,true);$user=['realm'=>'site','user_id'=>$uid];$saved=$c->booking($user,$booking);
check16((int)$saved['ends_at']-(int)$saved['starts_at']===900,'selected 15-minute duration is stored as the full booked interval');
check16($c->reserve($adminActor,$request,true)===$booking,'duration-aware booking replay is idempotent');
check16(reject16('IDEMPOTENCY_CONFLICT',fn()=>$c->reserve($adminActor,array_replace($request,['duration_minutes'=>30]),true)),'same request key cannot silently change its duration');
check16(!in_array($request['starts_at'],array_column($c->slots($id,$last->format('Y-m-d'),null,30),'starts_at'),true),'pending 15-minute hold also closes overlapping 30-minute choices');
$c->transition($user,$booking,'cancel');
check16(in_array($request['starts_at'],array_column($c->slots($id,$last->format('Y-m-d'),null,30),'starts_at'),true),'cancelling a short session releases the overlapping interval');
$spoofed=$c->reserve($user,array_replace($request,['user_id'=>$adminActor['user_id'],'internal'=>true,'idempotency_key'=>Security::id()]));
check16($c->booking($user,$spoofed)['user_id']===$uid,'public requests cannot forge another requester or elevate themselves through an internal flag');$c->transition($user,$spoofed,'cancel');
$assets=new ContentAssets($db,$audit);check16($assets->avatar($id)===null,'personal avatar is absent until explicitly uploaded');
check16(Consultations::publicBookingOpen(),'request intake is enabled while communication remains independently gated');
echo "CONSULTATIONS16 RESULT: $pass passed\n";
