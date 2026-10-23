<?php
declare(strict_types=1);
namespace Morshed\Identity;
use Morshed\Core\Problem;
class ProviderHttp
{
    public function request(string $url,?array $form=null):array
    {
        if(!in_array($url,['https://oauth2.googleapis.com/token','https://www.googleapis.com/oauth2/v1/certs','https://eitaayar.ir/api/app/verify','https://eitaayar.ir/api/app/sendMessage'],true)){throw new \LogicException('Provider endpoint not allowed.');}
        $ch=curl_init($url);$body='';curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>($url==='https://eitaayar.ir/api/app/sendMessage'?3:5),CURLOPT_TIMEOUT=>($url==='https://eitaayar.ir/api/app/sendMessage'?6:15),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>131072){return 0;}$body.=$chunk;return strlen($chunk);}]);
        if($form!==null){curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form,'','&',PHP_QUERY_RFC3986)]);}
        $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($ok===false||$status!==200){throw new Problem('PROVIDER_UNAVAILABLE','سرویس ورود پاسخ معتبر نداد؛ دوباره ورود را آغاز کنید.',503);}try{$data=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new Problem('PROVIDER_UNAVAILABLE','پاسخ سرویس ورود معتبر نیست.',503);}if(!is_array($data)){throw new Problem('PROVIDER_UNAVAILABLE','پاسخ سرویس ورود معتبر نیست.',503);}return $data;
    }
}
