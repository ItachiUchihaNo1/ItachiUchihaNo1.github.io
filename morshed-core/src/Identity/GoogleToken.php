<?php
declare(strict_types=1);
namespace Morshed\Identity;
use Morshed\Core\Problem;
final class GoogleToken
{
    private static function decode(string $part):string
    {if(!preg_match('/^[A-Za-z0-9_-]+$/D',$part)){throw new Problem('INVALID_ASSERTION','توکن ورود معتبر نیست.',401);}$s=base64_decode(strtr($part,'-_','+/').str_repeat('=',(4-strlen($part)%4)%4),true);if($s===false){throw new Problem('INVALID_ASSERTION','توکن ورود معتبر نیست.',401);}return $s;}
    public function verify(string $jwt,array $certificates,string $clientId,string $nonce,int $now):array
    {
        try{if(strlen($jwt)>20000){throw new \RuntimeException();}$p=explode('.',$jwt);if(count($p)!==3){throw new \RuntimeException();}$h=json_decode(self::decode($p[0]),true,8,JSON_THROW_ON_ERROR);$c=json_decode(self::decode($p[1]),true,16,JSON_THROW_ON_ERROR);$signature=self::decode($p[2]);
            if(!is_array($h)||!is_array($c)||($h['alg']??'')!=='RS256'||!is_string($h['kid']??null)||isset($h['crit'])||!is_string($certificates[$h['kid']]??null)){throw new \RuntimeException();}
            if(openssl_verify($p[0].'.'.$p[1],$signature,$certificates[$h['kid']],OPENSSL_ALGO_SHA256)!==1){throw new \RuntimeException();}
            if(!in_array($c['iss']??'', ['https://accounts.google.com','accounts.google.com'],true)||!is_int($c['exp']??null)||$c['exp']<=$now||!is_int($c['iat']??null)||$c['iat']>$now+30||$c['iat']<$now-600||!is_string($c['nonce']??null)||!hash_equals($nonce,$c['nonce'])){throw new \RuntimeException();}
            $aud=$c['aud']??null;if(is_array($aud)){if(!in_array($clientId,$aud,true)||($c['azp']??'')!==$clientId){throw new \RuntimeException();}}elseif($aud!==$clientId){throw new \RuntimeException();}
            if(isset($c['azp'])&&$c['azp']!==$clientId){throw new \RuntimeException();}if(!is_string($c['sub']??null)||!preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$c['sub'])){throw new \RuntimeException();}
            return ['subject'=>$c['sub'],'auth_date'=>$c['iat'],'replay_key'=>hash('sha256',$jwt),'name'=>is_string($c['name']??null)?$c['name']:'کاربر مرشد'];
        }catch(\Throwable $e){throw new Problem('INVALID_ASSERTION','اعتبار امضا، مخاطب یا زمان توکن ورود تأیید نشد.',401);}
    }
}
