<?php
// Creates only disposable local test identities and a server-side SMS proof; never loaded by production HTTP.
require __DIR__.'/common.php';
$sid=$argv[2]??'';if(!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$sid)){throw new RuntimeException('Invalid test browser');}
session_name('morshed_dev_csrf_site');session_save_path(__DIR__.'/../morshed-core/storage/sessions/site');session_id($sid);session_start();if(empty($_SESSION['csrf'])){throw new RuntimeException('Existing test browser required');}
$f=json_decode(file_get_contents($cfg['test_fixture_path']),true,512,JSON_THROW_ON_ERROR);$source=Morshed\Core\Security::id();$subject=(string)random_int(700000000,799999999);
$db->run('INSERT INTO m_users(id,display_name,created_at,updated_at) VALUES(?,?,?,?)',[$source,'Disposable Eitaa source',time(),time()]);
$db->run('INSERT INTO m_auth_identities(id,user_id,provider,subject,created_at) VALUES(?,?,?,?,?)',[Morshed\Core\Security::id(),$source,'eitaa',$subject,time()]);
$session=$sessions->issue($source,'site','eitaa');$db->run('DELETE FROM m_rate_limits');$id=$accounts->requestCode($f['phone'],'login','eitaa-http-proof');$_SESSION['otp_flow']=['id'=>$id,'purpose'=>'login','eitaa_source'=>$source];session_write_close();
echo json_encode(['source_id'=>$source,'token'=>$session['token'],'challenge_id'=>$id,'code'=>$sms->codes[$f['phone']]],JSON_THROW_ON_ERROR);
