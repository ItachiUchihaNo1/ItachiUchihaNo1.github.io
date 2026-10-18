<?php
declare(strict_types=1);
require __DIR__.'/../morshed-core/autoload.php';
use Morshed\Notifications\{FormTransport,TsmsSendOnlyGateway};
final class FakeTsms implements FormTransport {
    public int $calls=0;public array $last=[];public string $body='unknown';public bool $fail=false;
    public function postForm(string $url,array $fields):array{$this->last=['POST',$url,$fields];return $this->reply();}
    public function getForm(string $url,array $fields):array{$this->last=['GET',$url,$fields];return $this->reply();}
    private function reply():array{$this->calls++;if($this->fail){throw new RuntimeException('private transport error');}return ['http_status'=>200,'body'=>$this->body];}
}
$transport=new FakeTsms();$cfg=['enabled'=>true,'https_transport_confirmed'=>true,'method'=>'GET','environment'=>'staging','allowed_recipients'=>['09120000000'],'username'=>'test-only-user','password'=>'test-only-password','sender'=>'30001234'];$gateway=new TsmsSendOnlyGateway($cfg,$transport);
if($gateway->sendLoginCode('09120000000','1234')->status!=='unverified_response'){throw new RuntimeException('Unconfirmed responses must not claim delivery');}
if(!str_contains($transport->last[2]['message'],'1234')){throw new RuntimeException('Four-digit SMS content missing');}
if($transport->last[0]!=='GET'||$transport->last[1]!==TsmsSendOnlyGateway::ENDPOINT||array_keys($transport->last[2])!==['from','to','username','password','message']){throw new RuntimeException('Send-only GET contract failed');}
$transport->body='2';if($gateway->sendLoginCode('09120000000','1234')->status!=='provider_rejected'){throw new RuntimeException('Provider errors must be refused');}
$transport->fail=true;$before=$transport->calls;if($gateway->sendLoginCode('09120000000','1234')->status!=='submission_uncertain'||$transport->calls!==$before+1){throw new RuntimeException('Uncertain sends must not retry');}
$before=$transport->calls;if($gateway->sendLoginCode('09121111111','1234')->status!=='disabled'||$transport->calls!==$before){throw new RuntimeException('Staging recipient gate failed');}
$cfg['enabled']=false;if((new TsmsSendOnlyGateway($cfg,$transport))->sendLoginCode('09120000000','1234')->status!=='disabled'){throw new RuntimeException('Disabled SMS gate failed');}
if(TsmsSendOnlyGateway::normalizePhone('+989120000000')!=='09120000000'){throw new RuntimeException('Phone normalization failed');}
echo "TSMS RESULT: 6 send-only transport, failure and recipient-gate checks passed; no real SMS sent\n";
