<?php
// CLI-only fixture for the disposable database and its own local browser session.
require __DIR__.'/common.php';
$sid=$argv[2]??'';if(!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$sid)){throw new RuntimeException('Invalid disposable test session.');}
session_name('morshed_dev_csrf_site');session_save_path(__DIR__.'/../morshed-core/storage/sessions/site');session_id($sid);session_start();
if(empty($_SESSION['csrf'])){throw new RuntimeException('Existing test browser session required.');}
$db->run('DELETE FROM m_rate_limits');$phone='099'.random_int(10000000,99999999);$id=$accounts->requestCode($phone,'login','fixture-test');$_SESSION['otp_flow']=['id'=>$id,'purpose'=>'login'];session_write_close();
echo json_encode(['challenge_id'=>$id,'code'=>$sms->codes[$phone]],JSON_THROW_ON_ERROR);
