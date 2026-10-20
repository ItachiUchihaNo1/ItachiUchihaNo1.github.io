<?php
require __DIR__.'/common.php';
use Morshed\Core\{Security,Problem};
use Morshed\Product\{Catalog,Content};
$passed=0;$failed=0;
function test(string $name,callable $fn):void{global $passed,$failed;try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}
function same($a,$b):void{if($a!==$b){throw new RuntimeException('Unexpected result');}}
function denied(string $code,callable $fn):void{try{$fn();}catch(Problem $e){same($e->errorCode,$code);return;}throw new RuntimeException('Expected rejection');}
$ids=[];foreach(['reader','other','mentor'] as $label){$id=Security::id();$ids[$label]=$id;$db->run('INSERT INTO m_users(id,display_name,created_at,updated_at) VALUES(?,?,?,?)',[$id,'آزمون کارت '.$label,time(),time()]);}
$mentor=$ids['mentor'];$a=['user_id'=>$ids['reader'],'realm'=>'site'];$other=['user_id'=>$ids['other'],'realm'=>'site'];
$db->run("INSERT INTO m_mentors(user_id,headline,bio,status,invited_at,accepted_at,updated_at) VALUES(?,?,?,'active',?,?,?)",[$mentor,'معرفی کوتاه','متن معرفی',time(),time(),time()]);
$db->run('INSERT INTO m_schedule_settings(mentor_id) VALUES(?)',[$mentor]);
$db->run('INSERT INTO m_user_profiles(user_id,city) VALUES(?,?)',[$mentor,'شیراز']);
$topic=Security::id();$issue=Security::id();$db->run("INSERT INTO m_terms(id,kind,title) VALUES(?,'topic',?)",[$topic,'موضوع کارت']);$db->run("INSERT INTO m_terms(id,kind,title,parent_id) VALUES(?,'issue',?,?)",[$issue,'مسئله کارت',$topic]);
$eid=Security::id();$version=Security::id();$draft=Security::id();$db->run('INSERT INTO m_experiences(id,mentor_id,created_at) VALUES(?,?,?)',[$eid,$mentor,time()]);
$db->run("INSERT INTO m_experience_versions(id,experience_id,version_no,title,body,term_id,status,review_note,created_at) VALUES(?,?,1,?,?,?,'approved','',?)",[$version,$eid,'تجربه کارت','روایت عمومی',$issue,time()]);
$db->run("INSERT INTO m_experience_versions(id,experience_id,version_no,title,body,status,review_note,created_at) VALUES(?,?,2,?,?,'draft','',?)",[$draft,$eid,'پیش‌نویس خصوصی','داده خصوصی',time()]);
$db->run('UPDATE m_experiences SET public_version_id=? WHERE id=?',[$version,$eid]);
foreach(['pending','completed'] as $state){$db->run('INSERT INTO m_bookings(id,mentor_id,user_id,starts_at,ends_at,buffer_minutes,local_date,state,problem,idempotency_key,expires_at,created_at) VALUES(?,?,?,?,?,0,?,?,?,?,?,?)',[Security::id(),$mentor,$ids['reader'],time()+3600,time()+5400,'2026-10-20',$state,'آزمون',Security::id(),time()+60,time()]);}
$c=new Catalog($db);$content=new Content($db,$audit);
test('additive feature storage initializes twice without changing schema version',function()use($db,$c,$a){same($c->savedIds($a['user_id']),[]);same((new Catalog($db))->savedIds($a['user_id']),[]);same((int)$db->run('SELECT MAX(version) FROM m_schema_migrations')->fetchColumn(),7);});
test('saving is idempotent and persists across independent connections',function()use($c,$a,$mentor,$cfg){$c->save($a,$mentor,true);$c->save($a,$mentor,true);same((new Catalog(new Morshed\Core\Database($cfg['database'])))->savedIds($a['user_id']),[$mentor]);});
test('saved mentor lists are isolated by account',fn()=>same($c->savedIds($other['user_id']),[]));
test('removal affects only the current account',function()use($c,$a,$other,$mentor){$c->save($other,$mentor,true);$c->save($a,$mentor,false);same($c->savedIds($a['user_id']),[]);same($c->savedIds($other['user_id']),[$mentor]);});
test('admin realm cannot save into a site account',fn()=>denied('ACCESS_DENIED',fn()=>$c->save(['user_id'=>$a['user_id'],'realm'=>'admin'],$mentor,true)));
test('invalid and unavailable mentor identifiers are rejected',function()use($c,$a,$ids){denied('INVALID_INPUT',fn()=>$c->save($a,'invalid',true));denied('NOT_FOUND',fn()=>$c->save($a,$ids['other'],true));});
test('mentor card counts publications and completed conversations only',function()use($content,$mentor,$issue){$m=$content->mentor($mentor);same($m['experience_count'],1);same($m['conversation_count'],1);same($m['city'],'شیراز');same(array_column($m['issue_tags'],'id'),[$issue]);});
test('experience card derives topic and tags from its published version',function()use($content,$eid,$issue){$e=$content->experience($eid);same($e['topic_title'],'موضوع کارت');same(array_column($e['issue_tags'],'id'),[$issue]);same($e['title'],'تجربه کارت');same($e['views'],0);});
test('view counter deduplicates refreshes in a thirty minute session window',function()use($c,$content,$eid){$h=[];$c->view($eid,$h,10000);$c->view($eid,$h,10001);same($content->experience($eid)['views'],1);$c->view($eid,$h,11801);same($content->experience($eid)['views'],2);});
test('unpublished experience cannot receive counted views',function()use($db,$c,$eid){$db->run('UPDATE m_experiences SET public_version_id=NULL WHERE id=?',[$eid]);$h=[];$c->view($eid,$h,20000);same($h,[]);same((int)$db->run('SELECT views FROM m_experience_metrics WHERE experience_id=?',[$eid])->fetchColumn(),2);});
$db->run('UPDATE m_experiences SET public_version_id=? WHERE id=?',[$version,$eid]);
test('display helpers escape profile text and shorten Persian without invalid UTF8',function(){ $v=new Morshed\Http\View([],'site','test');same($v->number(1204),'۱٬۲۰۴');same($v->excerpt('سلام دنیا',4),'سلام…');same($v->h('<script>'),'&lt;script&gt;');});
echo "CATALOG12 RESULT: $passed passed, $failed failed\n";exit($failed?1:0);
