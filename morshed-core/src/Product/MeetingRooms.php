<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Audit,Problem};

/** Booking authorization is independent of media transport. Secrets stay in private config. */
final class MeetingRooms
{
    public function __construct(private Database $db,private array $config){}
    public static function origin(array $config):?string
    {
        $j=$config['jitsi']??[];$url=$j['origin']??'';
        if(($j['enabled']??false)!==true||($j['live_verified']??false)!==true||!is_string($url)){return null;}
        $p=parse_url($url);
        if(!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['path']??'',['','/'],true)||!preg_match('/^(?=.{1,253}$)[a-z0-9]+(?:[.-][a-z0-9]+)*$/Di',$p['host'])||isset($p['port'])&&$p['port']!==443){return null;}
        if(!is_string($j['app_id']??null)||!preg_match('/^[a-z0-9_-]{3,80}$/Di',$j['app_id'])||!is_string($j['app_secret']??null)||strlen($j['app_secret'])<32){return null;}
        return 'https://'.strtolower($p['host']);
    }
    public function status(array $actor,string $id,?int $now=null):array
    {
        (new Policy($this->db))->user($actor);$b=(new Consultations($this->db,new Audit($this->db)))->booking($actor,$id);$now??=time();
        // Consultations::booking also admits authorized administrators; this module never does.
        if(!in_array($actor['user_id'],[$b['user_id'],$b['mentor_id']],true)){throw new Problem('ACCESS_DENIED','این جلسه متعلق به شما نیست.',403);}
        $origin=self::origin($this->config);$window=$b['state']==='confirmed'&&$now>=(int)$b['starts_at']-300&&$now<(int)$b['ends_at'];
        return ['booking'=>$b,'configured'=>$origin!==null,'can_join'=>$origin!==null&&$window,'reason'=>!$origin?'سرور تماس در حال آماده‌سازی است.':($b['state']!=='confirmed'?'ورود فقط برای جلسهٔ تأییدشده فعال است.':($now>=(int)$b['ends_at']?'زمان جلسه پایان یافته است.':(!$window?'ورود از پنج دقیقه پیش از جلسه فعال می‌شود.':'برای شروع، روش ارتباط را انتخاب کن.')))];
    }
    private static function base64(string $s):string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
    public function token(array $actor,string $id,string $method,?int $now=null):array
    {
        $now??=time();$s=$this->status($actor,$id,$now);Policy::choice($method,['audio','video']);
        if(!$s['can_join']){throw new Problem('MEETING_UNAVAILABLE',$s['reason'],409);}
        $b=$s['booking'];$j=$this->config['jitsi'];$origin=self::origin($this->config);
        $room='morshed-'.substr(hash_hmac('sha256',$id,$this->config['app_key']),0,40);
        $expires=min((int)$b['ends_at'],$now+300);$user=['id'=>hash_hmac('sha256',$actor['user_id'],$this->config['app_key']),'name'=>$actor['display_name']??'کاربر مرشد'];
        $claims=['iss'=>$j['app_id'],'aud'=>$j['app_id'],'sub'=>($j['subject']??'')?:parse_url($origin,PHP_URL_HOST),'room'=>$room,'iat'=>$now,'nbf'=>$now-10,'exp'=>$expires,'context'=>['user'=>$user]];
        $head=self::base64(json_encode(['alg'=>'HS256','typ'=>'JWT'],JSON_THROW_ON_ERROR));$body=self::base64(json_encode($claims,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$jwt=$head.'.'.$body.'.'.self::base64(hash_hmac('sha256',$head.'.'.$body,$j['app_secret'],true));
        (new Audit($this->db))->add($actor['user_id'],'site','meeting.access_issued',$id);
        return ['origin'=>$origin,'room'=>$room,'jwt'=>$jwt,'expires_at'=>$expires,'ends_at'=>(int)$b['ends_at'],'method'=>$method,'display_name'=>$user['name']];
    }
}
