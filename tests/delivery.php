<?php
require __DIR__.'/common.php';
use Morshed\Core\Problem;
use Morshed\Product\{Delivery,PrivateFiles};
$passed=0;$failed=0;
function eq(mixed $a,mixed $b):void{if($a!==$b){throw new RuntimeException('Values differ');}}
function check(string $name,callable $fn):void{global $passed,$failed;try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}
function rejects(string $code,callable $fn):void{try{$fn();}catch(Problem $e){eq($e->errorCode,$code);return;}throw new RuntimeException('Expected '.$code);}
$a=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='test_admin'")+['realm'=>'admin'];$ca=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='content_test'")+['realm'=>'admin'];$fixture=json_decode(file_get_contents($cfg['test_fixture_path']),true);$uid=$fixture['user_id'];$service=new Delivery($db,$audit);
check('notification template rejects executable or unknown placeholders',fn()=>rejects('INVALID_VARIABLE',fn()=>$service->template($a,['name'=>'قالب','title'=>'{password}','body'=>'متن'])));
check('content administrator cannot send account notifications',fn()=>rejects('ACCESS_DENIED',fn()=>$service->template($ca,['name'=>'قالب','title'=>'سلام','body'=>'متن'])));
$tid=$service->template($a,['name'=>'اطلاع‌رسانی','title'=>'پیام برای {display_name}','body'=>'متن: {message}']);$d=['user_id'=>$uid,'template_id'=>$tid,'message'=>'پیام واقعی','request_key'=>'notification-request-key'];$id=$service->send($a,$d);
check('notification delivery commits recipient content and delivery record together',fn()=>eq($db->run('SELECT body FROM m_notifications WHERE id=?',[$id])->fetchColumn(),'متن: پیام واقعی'));
check('notification retry cannot create duplicate delivery',fn()=>eq($service->send($a,$d),$id));
check('notification idempotency key rejects altered content',fn()=>rejects('IDEMPOTENCY_CONFLICT',fn()=>$service->send($a,array_replace($d,['message'=>'متفاوت']))));
$service->settings($a,['about_text'=>'درباره آزمون','support_email'=>'support@example.test','support_phone'=>'']);
check('public settings reject invalid email',fn()=>rejects('INVALID_INPUT',fn()=>$service->settings($a,['support_email'=>'invalid-email'])));
check('public settings atomic failure preserves previously stored values',fn()=>eq($db->run("SELECT setting_value FROM m_public_settings WHERE setting_key='about_text'")->fetchColumn(),'درباره آزمون'));
$logo=__DIR__.'/../morshedapp.ir/assets/morshed-logo.png';$files=new PrivateFiles($db,$audit);
check('real image MIME dimensions and size are inspected',fn()=>eq(PrivateFiles::inspect($logo,'logo.png')['mime'],'image/png'));
check('image bytes with executable extension are refused',fn()=>rejects('INVALID_FILE',fn()=>PrivateFiles::inspect($logo,'evil.php')));
check('file names cannot include traversal',fn()=>rejects('INVALID_FILE',fn()=>PrivateFiles::inspect($logo,'../logo.png')));
check('fabricated non-HTTP upload cannot be moved into private storage',fn()=>rejects('INVALID_FILE',fn()=>$files->upload(['realm'=>'site','user_id'=>$uid],$fixture['product']['booking_id'],['error'=>0,'tmp_name'=>$logo,'name'=>'logo.png'])));
echo "DELIVERY RESULT: $passed passed, $failed failed\n";exit($failed?1:0);
