<?php
require __DIR__.'/common.php';
use Morshed\Core\{Security,Problem};
use Morshed\Product\{Consultations,ConsultationData};
$pass=0;$fail=0;
function t14($name,$fn){global $pass,$fail;try{$fn();$pass++;echo "PASS $name\n";}catch(Throwable $e){$fail++;echo "FAIL $name: ".$e->getMessage()."\n";}}
function eq14($a,$b){if($a!==$b){throw new RuntimeException(json_encode([$a,$b]));}}
function no14($code,$fn){try{$fn();}catch(Problem $e){eq14($e->errorCode,$code);return;}throw new RuntimeException('Expected '.$code);}
function person14($name){global $db;$id=Security::id();$db->run('INSERT INTO m_users(id,phone,phone_verified_at,display_name,created_at,updated_at) VALUES(?,?,?,?,?,?)',[$id,'098'.random_int(10000000,99999999),time(),$name,time(),time()]);return ['user_id'=>$id,'realm'=>'site'];}
$a=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='test_admin'")+['realm'=>'admin'];
$m=person14('مرشد زمان‌بندی');$n=person14('مرشد دوم زمان‌بندی');$u=person14('متقاضی زمان‌بندی');$v=person14('متقاضی دیگر زمان‌بندی');
foreach([$m,$n] as $host){$db->run("INSERT INTO m_mentors(user_id,headline,bio,status,invited_at,accepted_at,updated_at) VALUES(?,?,?,'active',?,?,?)",[$host['user_id'],'معرفی','زندگی حرفه‌ای',time(),time(),time()]);$db->run('INSERT INTO m_schedule_settings(mentor_id) VALUES(?)',[$host['user_id']]);}
$b=new Consultations($db,$audit);$date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->modify('+4 days')->setTime(0,0);$ymd=$date->format('Y-m-d');$wd=(int)$date->format('w');
t14('additive consultation storage is idempotent',function()use($db){(new ConsultationData($db))->storage();(new ConsultationData($db))->storage();eq14((int)$db->run('SELECT MAX(version) FROM m_schema_migrations')->fetchColumn(),7);});
foreach([$m,$n] as $host){$b->settings($host,['duration_minutes'=>60,'buffer_minutes'=>15,'lead_hours'=>1,'daily_capacity'=>10,'auto_accept'=>1]);$b->rule($host,['kind'=>'date','date'=>$ymd,'weekday'=>$wd,'start_time'=>'10:00','end_time'=>'18:00']);}
t14('one-off availability generates duration and break slots',function()use($b,$m,$ymd){$s=$b->slots($m['user_id'],$ymd);eq14(count($s),6);eq14($s[1]['starts_at']-$s[0]['starts_at'],75*60);eq14($s[0]['ends_at']-$s[0]['starts_at'],60*60);});
t14('settings never enable automatic acceptance',fn()=>eq14((int)$b->calendar($m)['settings']['auto_accept'],0));
t14('ordinary users cannot edit availability',fn()=>no14('MENTOR_REQUIRED',fn()=>$b->settings($u,[])));
$s=$b->slots($m['user_id'],$ymd);$request=['user_id'=>$u['user_id'],'mentor_id'=>$m['user_id'],'starts_at'=>$s[0]['starts_at'],'subject'=>'موضوع مستقل','problem'=>'شرح نیاز آزمون','requested_method'=>'video','idempotency_key'=>Security::id()];$id=$b->reserve($a,$request,true);
t14('internal creation still awaits mentor confirmation',fn()=>eq14($b->booking($u,$id)['state'],'pending'));
t14('subject method and future payment state are persisted',function()use($b,$u,$id,$db){eq14($b->booking($u,$id)['subject'],'موضوع مستقل');eq14($b->booking($u,$id)['requested_method'],'video');eq14($db->one('SELECT payment_state FROM m_booking_details WHERE booking_id=?',[$id])['payment_state'],'free');});
t14('public request intake is enabled independently of communication',fn()=>eq14(Consultations::publicBookingOpen(),true));
t14('site users cannot use internal administrative path',fn()=>no14('ACCESS_DENIED',fn()=>$b->reserve($u,$request,true)));
t14('whole overlapping interval remains occupied across rules',function()use($b,$m,$ymd,$a,$request,$v,$wd){$b->rule($m,['weekday'=>$wd,'start_time'=>'10:30','end_time'=>'11:30']);no14('SLOT_UNAVAILABLE',fn()=>$b->reserve($a,array_replace($request,['user_id'=>$v['user_id'],'starts_at'=>$request['starts_at']+1800,'idempotency_key'=>Security::id()]),true));});
t14('only participant mentor can accept incoming request',function()use($b,$u,$n,$a,$id){no14('INVALID_STATE',fn()=>$b->transition($a,$id,'accept','',true));no14('INVALID_STATE',fn()=>$b->transition($u,$id,'accept'));no14('NOT_FOUND',fn()=>$b->transition($n,$id,'accept'));});
$b->transition($m,$id,'accept');
t14('confirmation sends explicit in-app notification to requester',fn()=>eq14((int)$db->run("SELECT COUNT(*) FROM m_notifications WHERE user_id=? AND title='جلسه شما قطعی شد' AND destination=?",[$u['user_id'],'/booking?id='.$id])->fetchColumn(),1));
t14('mentor acting as requester cannot overlap own hosted session',fn()=>no14('OVERLAP',fn()=>$b->reserve($a,array_replace($request,['user_id'=>$m['user_id'],'mentor_id'=>$n['user_id'],'idempotency_key'=>Security::id()]),true)));
t14('changes to availability preserve existing confirmed session',function()use($b,$m,$id,$u){$rule=$b->calendar($m)['dates'][0];$b->rule($m,['kind'=>'date','id'=>$rule['id'],'date'=>$rule['local_date'],'weekday'=>0,'start_time'=>'13:00','end_time'=>'18:00']);$b->removeRule($m,$rule['id'],'date');eq14($b->booking($u,$id)['state'],'confirmed');});
t14('editing someone else one-off range is rejected',function()use($b,$m,$n,$ymd){$r=$b->calendar($n)['dates'][0];no14('NOT_FOUND',fn()=>$b->rule($m,['kind'=>'date','id'=>$r['id'],'date'=>$ymd,'weekday'=>0,'start_time'=>'12:00','end_time'=>'14:00']));});
t14('incoming and outgoing views are isolated by participant',function()use($b,$m,$u,$v,$id){eq14(array_column($b->listing($m,'incoming'),'id'),[$id]);eq14(array_column($b->listing($u,'outgoing'),'id'),[$id]);eq14($b->listing($v,'outgoing'),[]);no14('MENTOR_REQUIRED',fn()=>$b->listing($u,'incoming'));});
$b->transition($u,$id,'cancel','برنامه تغییر کرد');
t14('either participant can cancel with reason and notify other',function()use($b,$m,$id,$db){eq14($b->booking($m,$id)['state'],'cancelled');eq14((int)$db->run("SELECT COUNT(*) FROM m_notifications WHERE user_id=? AND title='جلسه لغو شد' AND body LIKE ?",[$m['user_id'],'%برنامه تغییر کرد%'])->fetchColumn(),1);});
$req=array_replace($request,['mentor_id'=>$n['user_id'],'idempotency_key'=>Security::id()]);$hold=$b->reserve($a,$req,true);$db->run('UPDATE m_bookings SET expires_at=? WHERE id=?',[time()-1,$hold]);$b->expire();
t14('expiry releases interval and emits exactly one notification',function()use($b,$a,$req,$hold,$db,$u){$b->expire();eq14($b->booking($u,$hold)['state'],'expired');eq14((int)$db->run("SELECT COUNT(*) FROM m_notifications WHERE user_id=? AND title='درخواست مشاوره منقضی شد' AND destination=?",[$u['user_id'],'/booking?id='.$hold])->fetchColumn(),1);});
$new=$b->reserve($a,array_replace($req,['idempotency_key'=>Security::id()]),true);$b->transition($n,$new,'accept');$db->run('UPDATE m_bookings SET starts_at=?,ends_at=? WHERE id=?',[time()+1800,time()+3600,$new]);
t14('reminder is delivered to both parties only once per start',function()use($b,$new,$db){$b->remind();$b->remind();eq14((int)$db->run('SELECT COUNT(*) FROM m_booking_reminders WHERE booking_id=?',[$new])->fetchColumn(),2);});
$db->run('UPDATE m_bookings SET starts_at=?,ends_at=? WHERE id=?',[time()-3600,time()-60,$new]);
t14('only mentor records completion after end',function()use($b,$u,$n,$new){no14('INVALID_STATE',fn()=>$b->transition($u,$new,'complete'));$b->transition($n,$new,'complete');eq14($b->booking($u,$new)['state'],'completed');});
t14('exception closes all generated slots for date',function()use($b,$n,$ymd){$b->exception($n,$ymd);eq14($b->slots($n['user_id'],$ymd),[]);$b->exception($n,$ymd,true);});
echo "CONSULTATIONS14 RESULT: $pass passed, $fail failed\n";exit($fail?1:0);
