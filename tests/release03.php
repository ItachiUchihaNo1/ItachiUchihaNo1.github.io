<?php
require __DIR__.'/common.php';
use Morshed\Core\{Security,Problem};
use Morshed\Identity\Profile;
use Morshed\Product\{Content,ExperienceCases};
$passed=0;$failed=0;
function clearRates():void{global $db;$db->run('DELETE FROM m_rate_limits');}
function eq(mixed $a,mixed $b):void{if($a!==$b){throw new RuntimeException('Unexpected values');}}
function check(string $name,callable $fn):void{global $passed,$failed;try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}
function rejects(string $code,callable $fn):void{try{$fn();}catch(Problem $e){eq($e->errorCode,$code);return;}throw new RuntimeException('Expected '.$code);}
$aa=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='test_admin'")+['realm'=>'admin'];
$ca=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='content_test'")+['realm'=>'admin'];
check('profile gender accepts only the two supported values',function(){foreach(['female','male'] as $gender){eq(\Morshed\Identity\Profile::validate(['first_name'=>'کاربر','last_name'=>'آزمون','province'=>'تهران','city'=>'تهران','gender'=>$gender])['gender'],$gender);}rejects('INVALID_PROFILE',fn()=>\Morshed\Identity\Profile::validate(['first_name'=>'کاربر','last_name'=>'آزمون','province'=>'تهران','city'=>'تهران','gender'=>'unspecified']));});
$profile=['first_name'=>'آزمایش','last_name'=>'حساب','province'=>'تهران','city'=>'تهران','gender'=>'female'];
check('passwords of 8 characters are accepted and 7 rejected',function(){eq(Security::verifyPassword('Eight123',Security::hashPassword('Eight123')),true);rejects('INVALID_PASSWORD',fn()=>Security::hashPassword('Seven12'));});
$data=$profile+['username'=>'manual_release03','new_password'=>'Eight123','phone'=>'09129998877'];
clearRates();check('content admin cannot create site users',fn()=>rejects('ACCESS_DENIED',fn()=>$admin->createUser($ca,'Rotated-Admin-Password-492!',$data)));
$uid=$admin->createUser($aa,'Correct-Test-Admin-471!',$data);
check('manual account has profile and username but no invented phone proof',function()use($uid,$accounts,$db){eq($accounts->passwordLogin('manual_release03','Eight123','r03'),$uid);eq($db->run('SELECT phone FROM m_users WHERE id=?',[$uid])->fetchColumn(),null);eq(Profile::complete($db,$uid),true);});
check('profile updates cannot change phone or grant admin role',function()use($uid,$accounts,$db,$profile){$accounts->profile($uid,$profile+['phone'=>'09120001111','role'=>'system_admin']);eq($db->run('SELECT phone FROM m_users WHERE id=?',[$uid])->fetchColumn(),null);eq($db->one('SELECT id FROM m_admin_accounts WHERE user_id=?',[$uid]),null);});
check('profile rejects invalid province and does not partially update',function()use($uid,$accounts,$profile){rejects('INVALID_PROFILE',fn()=>$accounts->profile($uid,array_replace($profile,['province'=>'invalid','first_name'=>'نباید ذخیره شود'])));eq(Profile::get($GLOBALS['db'],$uid)['first_name'],'آزمایش');});
$session=$sessions->issue($uid,'site','password');$admin->deleteUser($aa,'Correct-Test-Admin-471!',$uid);
check('deleted user cannot log in and active sessions stop',function()use($accounts,$sessions,$session){rejects('INVALID_CREDENTIALS',fn()=>$accounts->passwordLogin('manual_release03','Eight123','r03'));eq($sessions->resolve($session['token'],'site'),null);});
check('deleted user requires restore rather than status toggle',fn()=>rejects('USER_DELETED',fn()=>$admin->userStatus($aa,'Correct-Test-Admin-471!',$uid,'active')));
$admin->deleteUser($aa,'Correct-Test-Admin-471!',$uid,true);
check('restored user keeps stable internal identity',fn()=>eq($accounts->passwordLogin('manual_release03','Eight123','r03'),$uid));
check('administrator accounts cannot be deleted from user management',fn()=>rejects('ADMIN_PROTECTED',fn()=>$admin->deleteUser($aa,'Correct-Test-Admin-471!',$aa['user_id'])));
clearRates();$c=new Content($db,$audit);$cases=new ExperienceCases($db,$audit);
$c->invite($aa,['user_id'=>$uid,'headline'=>'کار عملی','bio'=>'روایت عملی آزمون']);$mentor=['realm'=>'site','user_id'=>$uid];$current=$legal->current();$legal->accept($uid,$current['id']);$c->acceptInvite($mentor);$c->mentorState($aa,$uid,'active');
$input=['mentor_id'=>$uid,'title'=>'پرونده ساختاریافته آزمون','problem'=>'مسئله واقعی','context'=>'منابع محدود','actions'=>"گام اول\nگام دوم",'results'=>'نتیجه ناموفق مستند','lessons'=>'درس عملی','summary'=>'خلاصه کوتاه','outcome'=>'failure','visibility'=>'public','source_text'=>'PRIVATE SOURCE SENTINEL','confidential_notes'=>'PRIVATE NOTE SENTINEL','target_audience'=>'مدیران اردو'];
$draft=$cases->save($aa,$input);$id=$draft['id'];$eid=$draft['experience_id'];$input+=['experience_id'=>$eid,'base_version_id'=>$id];$again=$cases->save($aa,$input);
check('experience edits preserve revision history while lists show latest case',fn()=>eq($again['id']===$id,false));$id=$again['id'];$input['base_version_id']=$id;
$cases->submit($aa,$id);
check('complete administrative case does not require owner acceptance',fn()=>eq($cases->missing($cases->details($id)),[]));
check('administrator cannot impersonate owner approval',fn()=>rejects('ACCESS_DENIED',fn()=>$cases->ownerApprove($aa,$id)));
$c->review($aa,$id,'approved','');
check('failed outcome can be published by content administration without owner workflow',fn()=>eq($c->experience($eid)['version_id'],$id));
check('public detail projection excludes all private sources and notes',function()use($cases,$id){$d=$cases->details($id,true);eq(isset($d['source_text']),false);eq(isset($d['confidential_notes']),false);eq($d['outcome'],'failure');});
check('public filters use actual structured target audience and outcome',function()use($c,$eid){eq($c->experiences(['q'=>'ساختاریافته','target_audience'=>'اردو','outcome'=>'failure'])[0]['id'],$eid);eq($c->experiences(['q'=>'ساختاریافته','outcome'=>'success']),[]);});
$next=$cases->save($aa,array_replace($input,['title'=>'ویرایش جدید منتظر تأیید']));
check('editing approved case preserves public revision and resets owner approval',function()use($c,$cases,$id,$eid,$next){eq($c->experience($eid)['version_id'],$id);eq($cases->details($next['id'])['owner_approved_at'],null);eq($next['id']===$id,false);});
check('stale case editor cannot overwrite a newer revision',fn()=>rejects('STALE_VERSION',fn()=>$cases->save($aa,$input)));
$before=(int)$db->run('SELECT COUNT(*) FROM m_store_versions')->fetchColumn();$s1=$c->saveStore($aa,[['type'=>'text','title'=>'پیش‌نویس الف']]);$s2=$c->saveStore($aa,[['type'=>'text','title'=>'پیش‌نویس ب']]);
check('store drafts reuse one row and do not add publication history',function()use($s1,$s2,$before,$db){eq($s1,$s2);eq((int)$db->run('SELECT COUNT(*) FROM m_store_versions')->fetchColumn(),$before);});
check('draft preview reflects latest durable save',fn()=>eq($c->store($s2)[0]['title'],'پیش‌نویس ب'));
$pub=$db->run('SELECT version_id FROM m_store_publication WHERE id=1')->fetchColumn();$c->publishStore($aa,$s2);
check('publication snapshots exactly one working draft',function()use($db,$before,$s2){eq((int)$db->run('SELECT COUNT(*) FROM m_store_versions')->fetchColumn(),$before+1);eq($db->one('SELECT id FROM m_store_drafts WHERE id=?',[$s2]),null);});
if($pub){$c->publishStore($aa,$pub);} // Restore fixtures for the existing HTTP checks.
echo "RELEASE03 RESULT: $passed passed, $failed failed\n";exit($failed?1:0);
