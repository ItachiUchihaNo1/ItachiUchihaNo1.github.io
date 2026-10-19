<?php
require __DIR__.'/common.php';
use Morshed\Core\{Security,Problem};
use Morshed\Identity\Profile;
use Morshed\Product\{Content,ExperienceCases};
$passed=0;$failed=0;
function a7eq(mixed $a,mixed $b):void{if($a!==$b){throw new RuntimeException('Unexpected values');}}
function a7check(string $name,callable $fn):void{global $passed,$failed;try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}
function a7reject(string $code,callable $fn):void{try{$fn();}catch(Problem $e){a7eq($e->errorCode,$code);return;}throw new RuntimeException('Expected '.$code);}
$aa=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='test_admin'")+['realm'=>'admin'];
$ca=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='content_test'")+['realm'=>'admin'];
$db->run('DELETE FROM m_rate_limits');$pw='Correct-Test-Admin-471!';
$profile=['first_name'=>'صاحب','last_name'=>'تجربه جدید','province'=>'تهران','city'=>'تهران','gender'=>'male'];
$uid=$admin->createUser($aa,$pw,$profile+['username'=>'admin07_owner','new_password'=>'Eight123','invite_mentor'=>'1','headline'=>'هنرمند رویداد','bio'=>'معرفی هنرمند']);
$c=new Content($db,$audit);$cases=new ExperienceCases($db,$audit);$actor=['realm'=>'site','user_id'=>$uid];
a7check('create and invite records a real notification pointing to the mentor panel',function()use($db,$uid){$n=$db->one('SELECT * FROM m_notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 1',[$uid]);a7eq((bool)$n,true);a7eq($db->one('SELECT status FROM m_mentors WHERE user_id=?',[$uid])['status'],'invited');});
$legalId=$legal->current()['id'];
a7check('published legal terms must still be accepted before invitation acceptance',fn()=>a7reject('TERMS_REQUIRED',fn()=>$c->acceptInvite($actor)));
a7check('no published legal version does not deadlock invitation and never auto activates mentor',function()use($db,$c,$actor,$uid){$old=$db->one("SELECT * FROM m_legal_publications WHERE document_key='base'");try{$db->run("UPDATE m_legal_publications SET version_id=NULL WHERE document_key='base'");$c->acceptInvite($actor);a7eq($db->one('SELECT status FROM m_mentors WHERE user_id=?',[$uid])['status'],'review');a7eq((bool)$db->one('SELECT user_id FROM m_user_acceptances WHERE user_id=?',[$uid]),false);}finally{$db->run("UPDATE m_legal_publications SET version_id=? WHERE document_key='base'",[$old['version_id']]);}});
$legal->accept($uid,$legalId);
a7check('repeating an accepted invitation cannot create another transition',fn()=>a7reject('CONFLICT',fn()=>$c->acceptInvite($actor)));
// Accept the restored published terms so unrelated checks remain consistent.
$legal->accept($uid,$legalId);
a7check('content manager cannot edit account details',fn()=>a7reject('ACCESS_DENIED',fn()=>$admin->editUser($ca,'Rotated-Admin-Password-492!',$uid,$profile+['username'=>'admin07_owner'])));
a7check('wrong administrator password prevents profile mutation',fn()=>a7reject('REAUTH_REQUIRED',fn()=>$admin->editUser($aa,'wrong',$uid,$profile+['username'=>'admin07_owner'])));
a7check('admin profile update retains fixed user ID and cannot forge verified phone or role',function()use($admin,$aa,$pw,$uid,$profile,$db,$accounts){$admin->editUser($aa,$pw,$uid,array_replace($profile,['city'=>'ری','username'=>'admin07_updated','phone'=>'09120001234','role'=>'system_admin']));a7eq(Profile::get($db,$uid)['city'],'ری');a7eq($accounts->passwordLogin('admin07_updated','Eight123','admin07'),$uid);a7eq($db->one('SELECT phone FROM m_users WHERE id=?',[$uid])['phone'],null);a7eq($db->one('SELECT id FROM m_admin_accounts WHERE user_id=?',[$uid]),null);});
a7check('duplicate username rejects the whole edit including profile changes',function()use($admin,$aa,$pw,$uid,$profile,$db){a7reject('USERNAME_TAKEN',fn()=>$admin->editUser($aa,$pw,$uid,array_replace($profile,['city'=>'نباید ثبت شود','username'=>'manual_release03'])));a7eq(Profile::get($db,$uid)['city'],'ری');});
$title='طراحی و اجرای رویداد هنری سفینةالنجاة با تکیه بر شبکه‌سازی هنرمندان، تولید اثر و تأمین مرحله‌ای منابع';
$summary=str_repeat("در سه دوره رویداد هنری سفینةالنجاة، با طراحی یک الگوی چندروزه و تولیدمحور، بستری برای گردهمایی، شبکه‌سازی، توانمندسازی و تولید اثر هنرمندان فراهم کردم.\n",18);
$input=['mentor_id'=>$uid,'title'=>$title,'summary'=>$summary,'role'=>'طراح اصلی، دبیر، مدیر و مدیر اجرایی رویدادهای سفینةالنجاة','activity_area'=>'مدیریت و برگزاری رویدادهای فرهنگی و هنری','period'=>'پیش از ماه محرم','setting'=>'مشهد مقدس؛ سالن نمایشگاه آستان قدس رضوی','problem'=>'','visibility'=>'private'];
$draft=$cases->save($aa,$input);
a7check('long Persian title and first-tab draft persist without truncation or requiring other tabs',function()use($db,$cases,$draft,$title,$summary){a7eq($db->one('SELECT title FROM m_experience_versions WHERE id=?',[$draft['id']])['title'],$title);a7eq($cases->details($draft['id'])['summary'],trim($summary));});
a7check('over limit first-tab text rejects instead of partially saving',function()use($db,$cases,$aa,$input){$before=$db->run('SELECT COUNT(*) FROM m_experiences')->fetchColumn();a7reject('INVALID_INPUT',fn()=>$cases->save($aa,array_replace($input,['summary'=>str_repeat('آ',10001)])));a7eq($db->run('SELECT COUNT(*) FROM m_experiences')->fetchColumn(),$before);});
a7check('full Persian narrative exceeding old byte cap persists without truncation',function()use($cases,$aa,$input){$long=$input;foreach(array_keys(ExperienceCases::NARRATIVE) as $key){$long[$key]=str_repeat('تجربه عملی مستند ',500);}$v=$cases->save($aa,$long);a7eq($cases->details($v['id'])['actions'],trim($long['actions']));});
a7check('long draft retains all publication and owner approval safeguards',function()use($cases,$c,$aa,$draft){$cases->submit($aa,$draft['id']);a7reject('EXPERIENCE_INCOMPLETE',fn()=>$c->review($aa,$draft['id'],'approved',''));});
$db->run('DELETE FROM m_rate_limits');
echo "ADMIN07 RESULT: $passed passed, $failed failed\n";exit($failed?1:0);

