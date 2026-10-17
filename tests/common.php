<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../morshed-core/autoload.php';
$cfg=require ($argv[1]??'');
if(($cfg['environment']??'')!=='testing'||!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=morshed_test_[a-f0-9]+;charset=utf8mb4$/D',$cfg['database']['dsn']??'')){throw new RuntimeException('An isolated local test database is required.');}
$db=new Morshed\Core\Database($cfg['database']);$audit=new Morshed\Core\Audit($db);$rate=new Morshed\Core\RateLimiter($db,$cfg['app_key']);
$admin=new Morshed\Access\Administration($db,$rate,$audit,$cfg['setup']);$sessions=new Morshed\Identity\Sessions($db,$audit);$legal=new Morshed\Legal\Documents($db,$audit);
final class TestSms implements Morshed\Notifications\SmsGateway
{
    public array $codes=[];public string $state='provider_accepted';
    public function sendLoginCode(string $phone,string $code):Morshed\Notifications\SmsSubmission{$this->codes[$phone]=$code;return new Morshed\Notifications\SmsSubmission($this->state);}
}
$sms=new TestSms();$accounts=new Morshed\Identity\Accounts($db,$rate,$audit,$sms,$cfg['app_key']);
