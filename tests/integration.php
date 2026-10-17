<?php
require __DIR__.'/common.php';
use Morshed\Core\Problem;
$passed=0;$failed=0;
function check(string $name,callable $f):void{global $passed,$failed;try{$f();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo 'FAIL '.$name.' ['.get_class($e).'] '.$e->getMessage()."\n";}}
function eq(mixed $a,mixed $b):void{if($a!==$b){throw new RuntimeException('Assertion failed');}}
function rejects(string $code,callable $f):void{try{$f();}catch(Problem $e){eq($e->errorCode,$code);return;}throw new RuntimeException('Unexpected success');}
function clearRates():void{global $db;$db->run('DELETE FROM m_rate_limits');}

$first=$db->one('SELECT id,user_id FROM m_admin_accounts WHERE username=\'test_admin\'');
if(!$first){throw new RuntimeException('Run the setup race before integration tests.');}
check('bootstrap is permanently locked after concurrent initialization',function()use($admin,$cfg){rejects('SETUP_LOCKED',fn()=>$admin->setup($cfg['test_setup_token'],'other_admin','دیگر','Other-Test-Password-333!','127.0.0.2'));});
$adminToken=$sessions->issue($first['user_id'],'admin','admin_password',$first['id']);$actor=$sessions->resolve($adminToken['token'],'admin');
check('admin session cannot authenticate on site',fn()=>eq($sessions->resolve($adminToken['token'],'site'),null));
check('admin username alone cannot grant entry',function()use($admin){rejects('INVALID_CREDENTIALS',fn()=>$admin->login('test_admin','wrong-password','test-ip'));});
check('site password is not an admin credential',function()use($admin){rejects('INVALID_CREDENTIALS',fn()=>$admin->login('09121111111','Correct-Test-User-572!','test-ip'));});

$phone='09121111111';$challenge=$accounts->requestCode($phone,'login','test-ip');$code=$sms->codes[$phone];
$uid=$accounts->verifyLogin($challenge,$code,'test-ip');$siteToken=$sessions->issue($uid,'site','otp');$user=$sessions->resolve($siteToken['token'],'site');
check('OTP creates exactly one account with verified phone',function()use($db,$uid,$phone){$u=$db->one('SELECT phone,phone_verified_at FROM m_users WHERE id=?',[$uid]);eq($u['phone'],$phone);eq((int)$u['phone_verified_at']>0,true);eq((int)$db->run('SELECT COUNT(*) FROM m_users WHERE phone=?',[$phone])->fetchColumn(),1);});
check('site token cannot authenticate on admin',fn()=>eq($sessions->resolve($siteToken['token'],'admin'),null));
check('consumed OTP cannot be reused',function()use($accounts,$challenge,$code){rejects('INVALID_CODE',fn()=>$accounts->verifyLogin($challenge,$code,'test-ip'));});
check('first password requires a freshly verified OTP session',function()use($accounts,$user){$a=$user;$a['auth_at']=time()-301;rejects('REAUTH_REQUIRED',fn()=>$accounts->setPassword($a,'','Correct-Test-User-572!'));});
$accounts->setPassword($user,'','Correct-Test-User-572!');
check('password is securely hashed and authenticates',function()use($db,$uid,$accounts,$phone){$hash=$db->run('SELECT password_hash FROM m_users WHERE id=?',[$uid])->fetchColumn();eq($hash==='Correct-Test-User-572!',false);eq(password_verify('Correct-Test-User-572!',$hash),true);eq($accounts->passwordLogin($phone,'Correct-Test-User-572!','test-ip'),$uid);});
check('profile fields do not update roles',function()use($accounts,$uid,$db){$accounts->profile($uid,'نام فارسی 🌱');eq($db->run('SELECT display_name FROM m_users WHERE id=?',[$uid])->fetchColumn(),'نام فارسی 🌱');eq($db->one('SELECT id FROM m_admin_accounts WHERE user_id=?',[$uid]),null);});
check('site actor cannot call core legal publishing',function()use($legal,$user){rejects('ACCESS_DENIED',fn()=>$legal->createBase($user,'عنوان','متن'));});

clearRates();$ch=$accounts->requestCode($phone,'login','test-ip');$right=$sms->codes[$phone];$wrong=$right==='111111'?'222222':'111111';
check('failed OTP attempts are durably committed',function()use($accounts,$db,$ch,$wrong){rejects('INVALID_CODE',fn()=>$accounts->verifyLogin($ch,$wrong,'test-ip'));eq((int)$db->run('SELECT attempts FROM m_otp_challenges WHERE id=?',[$ch])->fetchColumn(),1);});
check('five wrong codes lock even the correct code',function()use($accounts,$ch,$wrong,$right){for($i=0;$i<4;$i++){rejects('INVALID_CODE',fn()=>$accounts->verifyLogin($ch,$wrong,'test-ip'));}rejects('INVALID_CODE',fn()=>$accounts->verifyLogin($ch,$right,'test-ip'));});
clearRates();$ch=$accounts->requestCode($phone,'login','test-ip');$right=$sms->codes[$phone];$db->run('UPDATE m_otp_challenges SET expires_at=? WHERE id=?',[time()-1,$ch]);
check('expired OTP is refused',function()use($accounts,$ch,$right){rejects('INVALID_CODE',fn()=>$accounts->verifyLogin($ch,$right,'test-ip'));});
clearRates();$ch=$accounts->requestCode($phone,'reset','test-ip');$right=$sms->codes[$phone];$secondSite=$sessions->issue($uid,'site','password');
$accounts->resetPassword($ch,$right,'New-Test-Password-761!','test-ip');
check('reset revokes all existing site sessions',function()use($sessions,$siteToken,$secondSite){eq($sessions->resolve($siteToken['token'],'site'),null);eq($sessions->resolve($secondSite['token'],'site'),null);});
check('user reset cannot invalidate unrelated admin realm',fn()=>eq($sessions->resolve($adminToken['token'],'admin')['admin_id'],$first['id']));
$newSite=$sessions->issue($uid,'site','password');$user=$sessions->resolve($newSite['token'],'site');

clearRates();$phone2='09122222222';$ch=$accounts->requestCode($phone2,'login','test-ip');$uid2=$accounts->verifyLogin($ch,$sms->codes[$phone2],'test-ip');$token2=$sessions->issue($uid2,'site','otp');
check('cannot revoke another user session by id',function()use($sessions,$uid,$uid2,$token2){$sessions->revoke($uid,'site',$token2['id']);eq($sessions->resolve($token2['token'],'site')['user_id'],$uid2);});
check('password change requires the existing password',function()use($accounts,$user){rejects('REAUTH_REQUIRED',fn()=>$accounts->setPassword($user,'wrong-password','Changed-Test-Password-762!'));});
clearRates();$ch=$accounts->requestCode($phone2,'change_phone','test-ip',$user,'New-Test-Password-761!');$right=$sms->codes[$phone2];
check('verified number already owned by another account is not merged',function()use($accounts,$user,$ch,$right,$db,$uid,$phone){rejects('IDENTITY_CONFLICT',fn()=>$accounts->changePhone($user,$ch,$right,'test-ip'));eq($db->run('SELECT phone FROM m_users WHERE id=?',[$uid])->fetchColumn(),$phone);});

clearRates();$admin->addAdmin($actor,'Correct-Test-Admin-471!',$uid2,'content_test','Separate-Admin-482!','content_admin');$content=$admin->login('content_test','Separate-Admin-482!','test-ip');$ct=$sessions->issue($content['user_id'],'admin','admin_password',$content['admin_id']);$ca=$sessions->resolve($ct['token'],'admin');
check('content admin denied user and role administration',function()use($admin,$ca){rejects('ACCESS_DENIED',fn()=>$admin->system($ca));});
check('content admin denied publishing legal text in core',function()use($legal,$ca){rejects('ACCESS_DENIED',fn()=>$legal->createBase($ca,'عنوان','متن'));});
check('admin operations require current admin password',function()use($admin,$actor,$uid){rejects('REAUTH_REQUIRED',fn()=>$admin->userStatus($actor,'wrong-password',$uid,'blocked'));});
check('last active system admin cannot block itself',function()use($admin,$actor){rejects('SELF_CHANGE_DENIED',fn()=>$admin->userStatus($actor,'Correct-Test-Admin-471!',$actor['user_id'],'blocked'));});
check('role changes invalidate target admin sessions',function()use($admin,$actor,$content,$sessions,$ct){$admin->role($actor,'Correct-Test-Admin-471!',$content['admin_id'],'content_admin');eq($sessions->resolve($ct['token'],'admin'),null);});
clearRates();$admin->userStatus($actor,'Correct-Test-Admin-471!',$uid2,'blocked');
check('blocking an account invalidates its site session',fn()=>eq($sessions->resolve($token2['token'],'site'),null));
$admin->userStatus($actor,'Correct-Test-Admin-471!',$uid2,'active');

$v1=$legal->createBase($actor,'قوانین پایه نسخه یک','این متن فقط داده آزمون است.');
check('legal draft does not become public automatically',fn()=>eq($legal->current(),null));
$legal->publishBase($actor,$v1);$legal->accept($uid,$v1);$v2=$legal->createBase($actor,'قوانین پایه نسخه دو','متن دوم آزمون.');
check('legal draft leaves published version unchanged',fn()=>eq($legal->current()['id'],$v1));
$legal->publishBase($actor,$v2);
check('acceptance cannot silently accept a newer legal version',function()use($legal,$uid,$v1){rejects('LEGAL_VERSION_CHANGED',fn()=>$legal->accept($uid,$v1));});
check('old accepted legal version remains traceable',function()use($db,$uid,$v1){eq($db->run('SELECT content_sha256 FROM m_legal_versions WHERE id=?',[$v1])->fetchColumn(),hash('sha256','این متن فقط داده آزمون است.'));eq((int)$db->run('SELECT COUNT(*) FROM m_user_acceptances WHERE user_id=? AND version_id=?',[$uid,$v1])->fetchColumn(),1);});
check('recording legal publication remains absent',fn()=>eq($db->run('SELECT version_id FROM m_legal_publications WHERE document_key=\'recording\'')->fetchColumn(),null));
check('rate limit remains enforced after rejected operation',function()use($rate){$rate->hit('test-limiter',1,3600);rejects('RATE_LIMITED',fn()=>$rate->hit('test-limiter',1,3600));});
check('audit stores no submitted password or OTP field',function()use($db){$a=json_encode($db->all('SELECT * FROM m_audit_logs'));eq(str_contains($a,'New-Test-Password-761!'),false);eq(str_contains($a,'Correct-Test-Admin-471!'),false);eq(str_contains($a,'secret_hmac'),false);});

clearRates();$oldPhone='09125555555';$newPhone='09126666666';$ch=$accounts->requestCode($oldPhone,'login','test-ip');$swapUser=$accounts->verifyLogin($ch,$sms->codes[$oldPhone],'test-ip');$swapToken=$sessions->issue($swapUser,'site','otp');$swapActor=$sessions->resolve($swapToken['token'],'site');$accounts->setPassword($swapActor,'','Swap-Test-Password-782!');
$oldReset=$accounts->requestCode($oldPhone,'reset','test-ip');$oldResetCode=$sms->codes[$oldPhone];
$newChallenge=$accounts->requestCode($newPhone,'change_phone','test-ip',$swapActor,'Swap-Test-Password-782!');$accounts->changePhone($swapActor,$newChallenge,$sms->codes[$newPhone],'test-ip');
check('successful phone change keeps stable user ID and revokes old site session',function()use($db,$sessions,$swapUser,$newPhone,$swapToken){eq($db->run('SELECT phone FROM m_users WHERE id=?',[$swapUser])->fetchColumn(),$newPhone);eq($sessions->resolve($swapToken['token'],'site'),null);});
$ch=$accounts->requestCode($oldPhone,'login','test-ip');$reassignedUser=$accounts->verifyLogin($ch,$sms->codes[$oldPhone],'test-ip');
check('old reset proof cannot reset a different account after phone reassignment',function()use($accounts,$oldReset,$oldResetCode,$db,$reassignedUser){rejects('RESET_UNAVAILABLE',fn()=>$accounts->resetPassword($oldReset,$oldResetCode,'Never-Accept-Password-562!','test-ip'));eq($db->run('SELECT password_hash FROM m_users WHERE id=?',[$reassignedUser])->fetchColumn(),null);});
clearRates();$ch=$accounts->requestCode('۰۹۱۲۷۷۷۷۷۷۷','login','test-ip');$persianCode=strtr($sms->codes['09127777777'],['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
check('Persian phone and OTP digits are accepted without changing identity',function()use($accounts,$db,$ch,$persianCode){$id=$accounts->verifyLogin($ch,$persianCode,'test-ip');eq($db->run('SELECT phone FROM m_users WHERE id=?',[$id])->fetchColumn(),'09127777777');});
check('bcrypt cannot accept a longer password with the same 72-byte prefix',function(){ $p=str_repeat('A',72);$h=Morshed\Core\Security::hashPassword($p);eq(Morshed\Core\Security::verifyPassword($p.'B',$h),false);});
$expiry=$sessions->issue($uid,'site','password');$db->run('UPDATE m_auth_sessions SET expires_at=? WHERE id=?',[time()-1,$expiry['id']]);
check('expired session cannot authenticate',fn()=>eq($sessions->resolve($expiry['token'],'site'),null));
clearRates();$contentLogin=$admin->login('content_test','Separate-Admin-482!','test-ip');$rotateToken=$sessions->issue($uid2,'admin','admin_password',$contentLogin['admin_id']);$rotateActor=$sessions->resolve($rotateToken['token'],'admin');$independentSite=$sessions->issue($uid2,'site','otp');
$admin->changePassword($rotateActor,'Separate-Admin-482!','Rotated-Admin-Password-492!');
check('admin password rotation revokes its admin sessions only',function()use($sessions,$rotateToken,$independentSite,$uid2){eq($sessions->resolve($rotateToken['token'],'admin'),null);eq($sessions->resolve($independentSite['token'],'site')['user_id'],$uid2);});
clearRates();$adminPhone='09128888888';$ch=$accounts->requestCode($adminPhone,'change_phone','test-ip',$actor,'Correct-Test-Admin-471!');
check('phone-change proof is bound to its issuing realm',function()use($accounts,$actor,$ch,$sms,$adminPhone){$other=$actor;$other['realm']='site';$other['admin_id']=null;rejects('INVALID_CODE',fn()=>$accounts->changePhone($other,$ch,$sms->codes[$adminPhone],'test-ip'));});
$accounts->changePhone($actor,$ch,$sms->codes[$adminPhone],'test-ip');$ch=$accounts->requestCode($adminPhone,'login','test-ip');
check('proved admin phone lets site login reuse the same internal account',function()use($accounts,$ch,$sms,$adminPhone,$actor){eq($accounts->verifyLogin($ch,$sms->codes[$adminPhone],'test-ip'),$actor['user_id']);});

clearRates();$racePhone='09123333333';$raceId=$accounts->requestCode($racePhone,'login','test-ip');
$fixture=['user_id'=>$uid,'phone'=>$phone,'password'=>'New-Test-Password-761!','admin_username'=>'test_admin','admin_password'=>'Correct-Test-Admin-471!',
    'content_username'=>'content_test','content_password'=>'Rotated-Admin-Password-492!',
    'race_job'=>['operation'=>'otp','id'=>$raceId,'code'=>$sms->codes[$racePhone]]];
file_put_contents($cfg['test_fixture_path'],json_encode($fixture,JSON_THROW_ON_ERROR));
echo "RESULT: $passed passed, $failed failed\n";exit($failed?1:0);
