<?php
require __DIR__.'/common.php';
$sid=$argv[2]??'';if(!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$sid)){throw new RuntimeException('Invalid test browser');}
session_name('morshed_dev_csrf_site');session_save_path(__DIR__.'/../morshed-core/storage/sessions/site');session_id($sid);session_start();if(empty($_SESSION['csrf'])){throw new RuntimeException('Existing test browser required');}
$f=json_decode(file_get_contents($cfg['test_fixture_path']),true,512,JSON_THROW_ON_ERROR);$subject=(string)random_int(800000000,899999999);
$_SESSION['pending_eitaa']=['subject'=>$subject,'expires'=>time()+600];$db->run('DELETE FROM m_rate_limits');$phone='09129998887';$first=$accounts->requestCode($phone,'login','pending-owner-fixture');$owner=$accounts->verifyLogin($first,$sms->codes[$phone],'pending-owner-fixture');$db->run('DELETE FROM m_otp_challenges WHERE phone=?',[$phone]);$id=$accounts->requestCode($phone,'login','eitaa-pending-http');$_SESSION['otp_flow']=['id'=>$id,'purpose'=>'login','pending_eitaa'=>$_SESSION['pending_eitaa']];session_write_close();
echo json_encode(['subject'=>$subject,'owner'=>$owner,'challenge_id'=>$id,'code'=>$sms->codes[$phone]],JSON_THROW_ON_ERROR);
