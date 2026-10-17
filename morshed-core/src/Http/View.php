<?php
declare(strict_types=1);
namespace Morshed\Http;
final class View
{
    public array $old=[];
    private int $fieldSerial=0;
    public function __construct(public array $config,public string $realm,public string $csrf,public ?array $actor=null) {}
    public function h(mixed $v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public function status(string $value):string
    {return $this->h(['invited'=>'دعوت‌شده','review'=>'در انتظار بررسی','active'=>'فعال','restricted'=>'محدود','blocked'=>'مسدود','draft'=>'پیش‌نویس','submitted'=>'ارسال‌شده برای بررسی','changes'=>'نیازمند اصلاح','approved'=>'تأییدشده','rejected'=>'ردشده','pending'=>'در انتظار پذیرش','confirmed'=>'قطعی','expired'=>'منقضی','cancelled'=>'لغوشده','completed'=>'پایان‌یافته','accepted'=>'پذیرفته‌شده','open'=>'باز','closed'=>'بسته','support'=>'پشتیبانی','complaint'=>'شکایت','in_app'=>'درون سامانه','stored'=>'ذخیره‌شده','account'=>'حساب','sessions'=>'جلسات','system'=>'سامانه'][$value]??$value);}
    public function url(string $path):string
    {if($this->config['pretty_urls']??false){return $path;}[$route,$query]=array_pad(explode('?',$path,2),2,'');return '/index.php?r='.rawurlencode($route).($query!==''?'&'.$query:'');}
    public function val(string $key,string $default=''):string
    {return $this->h(is_string($this->old[$key]??null)?$this->old[$key]:$default);}
    public function start(string $route):string
    {return '<form method="post" action="'.$this->h($this->url($route)).'"><input type="hidden" name="csrf" value="'.$this->h($this->csrf).'">';}
    public function field(string $key,string $label,string $type='text',string $value='',bool $required=true):string
    {
        $secret=$type==='password'||in_array($key,['code','token','current_password','new_password'],true);
        $id=$key.'-'.(++$this->fieldSerial);$auto=match($key){'token'=>'off','password','current_password'=>'current-password','new_password'=>'new-password','phone'=>'tel','username'=>'username',default=>'on'};
        return '<label for="'.$this->h($id).'">'.$this->h($label).'</label><input id="'.$this->h($id).'" name="'.$this->h($key).'" type="'.$this->h($type).'" value="'.($secret?'':$this->val($key,$value)).'" '.($required?'required ':'').' maxlength="'.($type==='password'?'128':'180').'" autocomplete="'.$auto.'">';
    }
    public function page(string $title,string $template,array $data=[],?string $error=null):void
    {
        if(!preg_match('/^[a-z-]+$/D',$template)){throw new \LogicException('Invalid template.');}
        $focused=in_array($template,['login','admin-login','recover','code','setup'],true);
        extract($data,EXTR_SKIP);ob_start();require __DIR__.'/../../templates/'.$template.'.php';$content=ob_get_clean();
        require __DIR__.'/../../templates/layout.php';
    }
}
