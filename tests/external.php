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
$claims=['iss'=>'https://accounts.google.com','aud'=>'test-client','sub'=>'google-subject-1','nonce'=>'test-nonce','iat'=>time(),'exp'=>time()+300,'email'=>'untrusted@example.test','email_verified'=>false];$validator=new GoogleToken();$certs=['test-key'=>$public];
check('Google RS256 signature and issuer audience nonce time are validated',fn()=>eq($validator->verify(token($claims),$certs,'test-client','test-nonce',time())['subject'],'google-subject-1'));
foreach(['iss'=>'https://attacker.example','aud'=>'other-client','nonce'=>'other-nonce','exp'=>time()-1,'iat'=>time()+600] as $field=>$value){check('Google rejects invalid '.$field,fn()=>rejects('INVALID_ASSERTION',fn()=>$validator->verify(token(array_replace($claims,[$field=>$value])),$certs,'test-client','test-nonce',time())));}
check('Google refuses algorithm substitution',fn()=>rejects('INVALID_ASSERTION',fn()=>$validator->verify(token($claims,['alg'=>'HS256','kid'=>'test-key']),$certs,'test-client','test-nonce',time())));
check('Google refuses unsigned JWT',fn()=>rejects('INVALID_ASSERTION',fn()=>$validator->verify(b64('{}').'.'.b64(json_encode($claims)).'.',$certs,'test-client','test-nonce',time())));
$http=new TestProviderHttp();$http->certs=$certs;$settings=$cfg+['google'=>['enabled'=>true,'live_verified'=>true,'client_id'=>'test-client','client_secret'=>'TEST ONLY','redirect_uri'=>'https://example.test/callback'],'eitaa'=>['enabled'=>true,'live_verified'=>true,'app_token'=>'test-eitaa-app-token-12345']];$ext=new ExternalAccounts($db,$audit,$rate,$http,$settings);$browser=bin2hex(random_bytes(32));
function googleLogin(string $subject,?array $actor=null,string $purpose='login',?string $email=null):array
{global $ext,$db,$http,$browser;$f=$ext->begin('google',$purpose,$actor,$browser,'external-test');$row=$db->one('SELECT * FROM m_external_flows WHERE state_hash=?',[hash('sha256',$f['state'])]);parse_str(parse_url($f['url'],PHP_URL_QUERY),$query);eq($query['code_challenge'],b64(hash('sha256',$row['verifier'],true)));$http->jwt=token(['iss'=>'https://accounts.google.com','aud'=>'test-client','sub'=>$subject,'nonce'=>$row['nonce'],'iat'=>time(),'exp'=>time()+300,'email'=>$email??'same@example.test','email_verified'=>false]);return $ext->google($f['state'],'test-code',$browser,$actor)+['state'=>$f['state']];}
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
echo "EXTERNAL RESULT: $passed passed, $failed failed\n";exit($failed?1:0);
