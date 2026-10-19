<?php
require __DIR__.'/common.php';
use Morshed\Core\{Security,Problem};
use Morshed\Identity\{ExternalAccounts,ProviderHttp,GoogleToken};
$passed=0;$failed=0;
function eq(mixed $a,mixed $b):void{if($a!==$b){throw new RuntimeException('Values differ');}}
function check(string $name,callable $fn):void{global $passed,$failed;try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}
function rejects(string $code,callable $fn):void{try{$fn();}catch(Problem $e){eq($e->errorCode,$code);return;}throw new RuntimeException('Expected '.$code);}
class TestProviderHttp extends ProviderHttp
{public string $jwt='';public array $certs=[];public bool $eitaa=true;public array $last=[];
 public function request(string $url,?array $form=null):array{$this->last=['url'=>$url,'form'=>$form];return match($url){'https://oauth2.googleapis.com/token'=>['id_token'=>$this->jwt],'https://www.googleapis.com/oauth2/v1/certs'=>$this->certs,'https://eitaayar.ir/api/app/verify'=>['ok'=>$this->eitaa],default=>throw new RuntimeException('Unexpected URL')};}}
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);if(!$key){throw new RuntimeException('OpenSSL key generation failed.');}$public=openssl_pkey_get_details($key)['key'];
function b64(string $s):string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function token(array $claims,array $header=['alg'=>'RS256','kid'=>'test-key']):string{global $key;$body=b64(json_encode($header)).'.'.b64(json_encode($claims));openssl_sign($body,$sig,$key,OPENSSL_ALGO_SHA256);return $body.'.'.b64($sig);}
$claims=['iss'=>'https://accounts.google.com','aud'=>'123456789-testclient.apps.googleusercontent.com','sub'=>'google-subject-1','nonce'=>'test-nonce','iat'=>time(),'exp'=>time()+300,'email'=>'untrusted@example.test','email_verified'=>false];$validator=new GoogleToken();$certs=['test-key'=>$public];
check('Google RS256 signature and issuer audience nonce time are validated',fn()=>eq($validator->verify(token($claims),$certs,'123456789-testclient.apps.googleusercontent.com','test-nonce',time())['subject'],'google-subject-1'));
foreach(['iss'=>'https://attacker.example','aud'=>'other-client','nonce'=>'other-nonce','exp'=>time()-1,'iat'=>time()+600] as $field=>$value){check('Google rejects invalid '.$field,fn()=>rejects('INVALID_ASSERTION',fn()=>$validator->verify(token(array_replace($claims,[$field=>$value])),$certs,'123456789-testclient.apps.googleusercontent.com','test-nonce',time())));}
check('Google refuses algorithm substitution',fn()=>rejects('INVALID_ASSERTION',fn()=>$validator->verify(token($claims,['alg'=>'HS256','kid'=>'test-key']),$certs,'123456789-testclient.apps.googleusercontent.com','test-nonce',time())));
check('Google refuses unsigned JWT',fn()=>rejects('INVALID_ASSERTION',fn()=>$validator->verify(b64('{}').'.'.b64(json_encode($claims)).'.',$certs,'123456789-testclient.apps.googleusercontent.com','test-nonce',time())));
$http=new TestProviderHttp();$http->certs=$certs;$settings=$cfg+['google'=>['enabled'=>true,'live_verified'=>true,'client_id'=>'123456789-testclient.apps.googleusercontent.com','client_secret'=>'TEST ONLY','redirect_uri'=>'https://example.test/callback'],'eitaa'=>['enabled'=>true,'live_verified'=>true,'app_token'=>'test-eitaa-app-token-12345']];$ext=new ExternalAccounts($db,$audit,$rate,$http,$settings);$browser=bin2hex(random_bytes(32));
function googleLogin(string $subject,?array $actor=null,string $purpose='login',?string $email=null):array
{global $ext,$db,$http,$browser;$f=$ext->begin('google',$purpose,$actor,$browser,'external-test');$row=$db->one('SELECT * FROM m_external_flows WHERE state_hash=?',[hash('sha256',$f['state'])]);parse_str(parse_url($f['url'],PHP_URL_QUERY),$query);eq($query['code_challenge'],b64(hash('sha256',$row['verifier'],true)));$http->jwt=token(['iss'=>'https://accounts.google.com','aud'=>'123456789-testclient.apps.googleusercontent.com','sub'=>$subject,'nonce'=>$row['nonce'],'iat'=>time(),'exp'=>time()+300,'email'=>$email??'same@example.test','email_verified'=>false]);return $ext->google($f['state'],'test-code',$browser,$actor)+['state'=>$f['state']];}
$first=googleLogin('google-subject-1');$uid=$first['user_id'];$session=$sessions->issue($uid,'site','google');$a=$sessions->resolve($session['token'],'site');
check('Google creates stable internal ID without verifying a phone',function()use($db,$uid){$u=$db->one('SELECT phone,phone_verified_at FROM m_users WHERE id=?',[$uid]);eq($u['phone'],null);eq($u['phone_verified_at'],null);});
check('Google repeated login reuses subject-bound account',fn()=>eq(googleLogin('google-subject-1')['user_id'],$uid));
$second=googleLogin('google-subject-2');check('same unverified email never merges accounts',fn()=>eq($second['user_id']!==$uid,true));
check('external state is one-use',fn()=>rejects('INVALID_STATE',fn()=>$ext->google($first['state'],'test-code',$browser,null)));
$f=$ext->begin('google','login',null,$browser,'external-test');check('external state is bound to initiating browser',fn()=>rejects('INVALID_STATE',fn()=>$ext->google($f['state'],'test-code','wrong-browser',null)));
check('linking an identity owned by another account is refused',fn()=>rejects('IDENTITY_CONFLICT',fn()=>googleLogin('google-subject-2',$a,'link')));
check('last usable login method cannot be disconnected',fn()=>rejects('LAST_LOGIN_METHOD',fn()=>$ext->unlink($a,'google')));
$db->run('UPDATE m_auth_sessions SET auth_at=? WHERE id=?',[time()-1000,$a['session_id']]);$stale=$sessions->resolve($session['token'],'site');
check('link requires recent proof of current account',fn()=>rejects('REAUTH_REQUIRED',fn()=>$ext->begin('eitaa','link',$stale,$browser,'external-test')));
googleLogin('google-subject-1',$stale,'reauth');$a=$sessions->resolve($session['token'],'site');
check('provider reauthentication refreshes existing session after subject proof',fn()=>eq((int)$a['auth_at']>=time()-5,true));
function initData(string $subject,int $when):string{global $settings;$f=['auth_date'=>(string)$when,'user'=>json_encode(['id'=>$subject])];ksort($f);$check=[];foreach($f as $k=>$v){$check[]=$k.'='.$v;}$secret=hash_hmac('sha256',$settings['eitaa']['app_token'],'WebAppData',true);$f['hash']=hash_hmac('sha256',implode("\n",$check),$secret);return http_build_query($f,'','&',PHP_QUERY_RFC3986);}
$assertion=initData('123456789',time());$f=$ext->begin('eitaa','link',$a,$browser,'external-test');$linked=$ext->eitaa($f['state'],$assertion,$browser,$a,'127.0.0.1');
check('Eitaa signed proof links to authenticated internal account',fn()=>eq($linked['user_id'],$uid));
$f=$ext->begin('eitaa','login',null,$browser,'external-test');check('Eitaa assertion cannot be replayed through a new browser flow',fn()=>rejects('ASSERTION_REPLAY',fn()=>$ext->eitaa($f['state'],$assertion,$browser,null,'127.0.0.1')));
$f=$ext->begin('eitaa','login',null,$browser,'external-test');check('Eitaa rejects modified identity without matching signature',fn()=>rejects('INVALID_ASSERTION',fn()=>$ext->eitaa($f['state'],str_replace('123456789','987654321',$assertion),$browser,null,'127.0.0.1')));
$http->eitaa=false;$f=$ext->begin('eitaa','login',null,$browser,'external-test');check('Eitaa official verify refusal blocks login even with valid local HMAC',fn()=>rejects('INVALID_ASSERTION',fn()=>$ext->eitaa($f['state'],initData('111222333',time()),$browser,null,'127.0.0.1')));$http->eitaa=true;
$f=$ext->begin('eitaa','login',null,$browser,'external-test');check('Eitaa stale assertion is refused',fn()=>rejects('INVALID_ASSERTION',fn()=>$ext->eitaa($f['state'],initData('111222333',time()-120),$browser,null,'127.0.0.1')));
$ext->unlink($a,'google');check('disconnecting provider preserves other method and revokes removed-provider sessions',function()use($ext,$uid,$sessions,$session){eq(array_column($ext->methods($uid),'provider'),['eitaa']);eq($sessions->resolve($session['token'],'site'),null);});
$disabled=new ExternalAccounts($db,$audit,$rate,$http,$cfg);check('missing real provider configuration fails closed',fn()=>rejects('PROVIDER_DISABLED',fn()=>$disabled->begin('google','login',null,$browser,'external-test')));
$localSession=$sessions->issue($second['user_id'],'site','google');$localActor=$sessions->resolve($localSession['token'],'site');
$accounts->complete($localActor,'Google_Person','Independent-Device-Password-984!');
check('Google account can set local credentials without claiming phone ownership',function()use($accounts,$db,$second){eq($accounts->passwordLogin('GOOGLE_PERSON','Independent-Device-Password-984!','local-username-test'),$second['user_id']);eq($db->run('SELECT phone_verified_at FROM m_users WHERE id=?',[$second['user_id']])->fetchColumn(),null);});
check('local username is excluded from external provider methods',fn()=>eq(array_column($ext->methods($second['user_id']),'provider'),['google']));
check('credential changes require the current password once configured',fn()=>rejects('REAUTH_REQUIRED',fn()=>$accounts->complete($localActor,'google_person','Changed-Device-Password-582!','wrong-password')));
$freshSession=$sessions->issue($uid,'site','eitaa');$freshActor=$sessions->resolve($freshSession['token'],'site');
check('username ownership collision cannot merge separate accounts',fn()=>rejects('USERNAME_TAKEN',fn()=>$accounts->complete($freshActor,'google_person','Another-Device-Password-481!')));
$db->run('UPDATE m_auth_sessions SET auth_at=? WHERE id=?',[time()-1000,$freshActor['session_id']]);
check('initial local credentials require a recent authenticated session',fn()=>rejects('REAUTH_REQUIRED',fn()=>$accounts->complete($freshActor,'different_person','Another-Device-Password-481!')));
$db->run('UPDATE m_auth_sessions SET auth_at=? WHERE id=?',[time(),$freshActor['session_id']]);$freshActor=$sessions->resolve($freshSession['token'],'site');
function contactData(string $subject,string $phone):string{global $settings;$fields=['auth_date'=>(string)time(),'contact'=>json_encode(['user_id'=>$subject,'phone_number'=>$phone])];ksort($fields);$pairs=[];foreach($fields as $key=>$value){$pairs[]=$key.'='.$value;}$secret=hash_hmac('sha256',$settings['eitaa']['app_token'],'WebAppData',true);$fields['hash']=hash_hmac('sha256',implode("\n",$pairs),$secret);return http_build_query($fields,'','&',PHP_QUERY_RFC3986);}
$contact=contactData('123456789','09126667788');
check('modified Eitaa contact cannot verify a phone',fn()=>rejects('INVALID_ASSERTION',fn()=>$ext->contact($freshActor,str_replace('09126667788','09126667789',$contact),'contact-test')));
check('signed contact must match the Eitaa identity linked to this account',fn()=>rejects('IDENTITY_MISMATCH',fn()=>$ext->contact($freshActor,contactData('999888777','09126667788'),'contact-test')));
$ext->contact($freshActor,$contact,'contact-test');
check('verified signed contact binds the phone to the same internal account',fn()=>eq($db->run('SELECT phone FROM m_users WHERE id=?',[$uid])->fetchColumn(),'09126667788'));
check('signed contact cannot be replayed',fn()=>rejects('ASSERTION_REPLAY',fn()=>$ext->contact($freshActor,$contact,'contact-test')));
$beforeCapture=(int)$db->run('SELECT COUNT(*) FROM m_users')->fetchColumn();$captured=$ext->captureEitaa(initData('777888999',time()),'capture-test');
check('verified launch context alone never creates an account',fn()=>eq((int)$db->run('SELECT COUNT(*) FROM m_users')->fetchColumn(),$beforeCapture));
$captureFlow=$ext->begin('eitaa','login',null,$browser,'capture-test');$capturedLogin=$ext->eitaaCaptured($captureFlow['state'],$captured,$browser,null);
check('new Eitaa login waits for verified phone without creating a second user',function()use($capturedLogin,$beforeCapture,$db){eq($capturedLogin['pending_eitaa']['subject'],'777888999');eq((int)$db->run('SELECT COUNT(*) FROM m_users')->fetchColumn(),$beforeCapture);});
$replayFlow=$ext->begin('eitaa','login',null,$browser,'capture-test');
check('captured launch proof cannot be reused for another login',fn()=>rejects('ASSERTION_REPLAY',fn()=>$ext->eitaaCaptured($replayFlow['state'],$captured,$browser,null)));

check('URL pasted as Google Client ID is rejected before redirecting to Google',function()use($db,$audit,$rate,$http,$settings,$browser){$bad=$settings;$bad['google']['client_id']='https://123456789-testclient.apps.googleusercontent.com';$service=new ExternalAccounts($db,$audit,$rate,$http,$bad);rejects('GOOGLE_CLIENT_CONFIGURATION',fn()=>$service->begin('google','login',null,$browser,'bad-config'));});
$db->run('DELETE FROM m_rate_limits');
function eitaaSource(string $subject):array{global $db,$sessions;$uid=Security::id();$db->run('INSERT INTO m_users(id,created_at,updated_at) VALUES(?,?,?)',[$uid,time(),time()]);$db->run('INSERT INTO m_auth_identities(id,user_id,provider,subject,created_at) VALUES(?,?,?,?,?)',[Security::id(),$uid,'eitaa',$subject,time()]);$s=$sessions->issue($uid,'site','eitaa');return [$sessions->resolve($s['token'],'site'),$s];}
function phoneOwner(string $phone):string{global $accounts,$sms;$id=$accounts->requestCode($phone,'login','phone-owner-test');return $accounts->verifyLogin($id,$sms->codes[$phone],'phone-owner-test');}
[$source,$sourceSession]=eitaaSource('812345671');$owner=phoneOwner('09122223331');$db->run('UPDATE m_users SET display_name=? WHERE id=?',['Existing profile unchanged',$owner]);
$assertion=contactData('812345671','09122223331');
check('verified Eitaa phone recovers the existing SMS account without replacing its profile',function()use($ext,$source,$assertion,$owner,$db){eq($ext->contact($source,$assertion,'phone-link-test'),$owner);eq($db->run('SELECT display_name FROM m_users WHERE id=?',[$owner])->fetchColumn(),'Existing profile unchanged');eq($db->run("SELECT user_id FROM m_auth_identities WHERE provider='eitaa' AND subject='812345671'")->fetchColumn(),$owner);});
check('recovering an existing account revokes old source sessions without deleting its records',function()use($sessions,$sourceSession,$db,$source){eq($sessions->resolve($sourceSession['token'],'site'),null);eq((bool)$db->one('SELECT id FROM m_users WHERE id=?',[$source['user_id']]),true);});
check('revoked source cannot replay contact to take back the linked identity',fn()=>rejects('REAUTH_REQUIRED',fn()=>$ext->contact($source,$assertion,'phone-link-test')));
[$smsSource,$smsSourceSession]=eitaaSource('812345672');$smsOwner=phoneOwner('09122223332');
check('verified SMS proof links Eitaa to the proved phone owner',function()use($ext,$smsSource,$smsOwner,$db){eq($ext->connectVerifiedPhone($smsSource,$smsOwner,'verified_sms'),$smsOwner);eq($db->run("SELECT user_id FROM m_auth_identities WHERE provider='eitaa' AND subject='812345672'")->fetchColumn(),$smsOwner);});
[$blockedSource]=eitaaSource('812345673');$blockedOwner=phoneOwner('09122223333');$db->run("UPDATE m_users SET status='blocked' WHERE id=?",[$blockedOwner]);
check('phone reconciliation never bypasses a restricted destination account',fn()=>rejects('ACCESS_DENIED',fn()=>$ext->contact($blockedSource,contactData('812345673','09122223333'),'phone-link-test')));
check('failed reconciliation preserves the original Eitaa owner',fn()=>eq($db->run("SELECT user_id FROM m_auth_identities WHERE provider='eitaa' AND subject='812345673'")->fetchColumn(),$blockedSource['user_id']));
[$conflictSource]=eitaaSource('812345674');
check('existing destination Eitaa identity is never overwritten',fn()=>rejects('IDENTITY_CONFLICT',fn()=>$ext->contact($conflictSource,contactData('812345674','09122223331'),'phone-link-test')));
[$unverifiedSource]=eitaaSource('812345675');
check('a destination without a proved phone cannot be linked',fn()=>rejects('INVALID_ASSERTION',fn()=>$ext->connectVerifiedPhone($unverifiedSource,$second['user_id'],'verified_sms')));
check('source with an already verified different phone cannot switch ownership',fn()=>rejects('PHONE_READ_ONLY',fn()=>$ext->contact($freshActor,contactData('123456789','09122223332'),'phone-link-test')));

$db->run('DELETE FROM m_rate_limits');
$phoneExisting=phoneOwner('09123334441');$db->run('UPDATE m_users SET display_name=? WHERE id=?',['Keep my existing account',$phoneExisting]);
$pending=$capturedLogin['pending_eitaa'];$count=(int)$db->run('SELECT COUNT(*) FROM m_users')->fetchColumn();
check('pending contact must prove the same Eitaa subject',fn()=>rejects('INVALID_ASSERTION',fn()=>$ext->pendingContact($pending,contactData('777888998','09123334441'),'pending-test')));
check('signed Eitaa contact reuses existing SMS user without another user row',function()use($ext,$pending,$phoneExisting,$count,$db){eq($ext->pendingContact($pending,contactData('777888999','989123334441'),'pending-test'),$phoneExisting);eq((int)$db->run('SELECT COUNT(*) FROM m_users')->fetchColumn(),$count);eq($db->run('SELECT display_name FROM m_users WHERE id=?',[$phoneExisting])->fetchColumn(),'Keep my existing account');});
check('future Eitaa login resolves to the exact existing SMS account',function()use($ext,$browser,$phoneExisting){$f=$ext->begin('eitaa','login',null,$browser,'repeat-test');$result=$ext->eitaa($f['state'],initData('777888999',time()+1),$browser,null,'repeat-test');eq($result['user_id'],$phoneExisting);});
$f=$ext->begin('eitaa','login',null,$browser,'pending-test');$freshPending=$ext->eitaa($f['state'],initData('777888991',time()),$browser,null,'pending-test')['pending_eitaa'];
check('unverified pending login has no identity or user record',function()use($db){eq($db->one("SELECT id FROM m_auth_identities WHERE provider='eitaa' AND subject='777888991'"),null);});
$created=$ext->pendingContact($freshPending,contactData('777888991','+989123334442'),'pending-test');
check('Eitaa-first phone account is reused by later OTP login',function()use($created){eq(phoneOwner('09123334442'),$created);});
$f=$ext->begin('eitaa','login',null,$browser,'pending-test');$smsPending=$ext->eitaa($f['state'],initData('777888992',time()),$browser,null,'pending-test')['pending_eitaa'];$smsTarget=phoneOwner('09123334443');
check('pending Eitaa identity attaches after verified SMS to the same user',fn()=>eq($ext->pendingSms($smsPending,$smsTarget),$smsTarget));
check('expired pending proof cannot attach to a phone owner',fn()=>rejects('INVALID_STATE',fn()=>$ext->pendingSms(array_replace($smsPending,['expires'=>time()-1]),$smsTarget)));
check('pending SMS cannot attach to an unverified user',fn()=>rejects('INVALID_ASSERTION',fn()=>$ext->pendingSms($smsPending,$second['user_id'])));
$f=$ext->begin('eitaa','login',null,$browser,'pending-test');$conflicting=$ext->eitaa($f['state'],initData('777888993',time()),$browser,null,'pending-test')['pending_eitaa'];
check('different Eitaa identity never overwrites an occupied phone account',fn()=>rejects('IDENTITY_CONFLICT',fn()=>$ext->pendingSms($conflicting,$phoneExisting)));
check('pending retry after successful phone binding stays on the same user',fn()=>eq($ext->pendingSms($smsPending,$smsTarget),$smsTarget));

echo "EXTERNAL RESULT: $passed passed, $failed failed\n";exit($failed?1:0);
