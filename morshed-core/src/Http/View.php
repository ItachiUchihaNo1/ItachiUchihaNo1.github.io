<?php
declare(strict_types=1);
namespace Morshed\Http;
final class View
{
    public array $old=[];
    private int $fieldSerial=0;
    public function __construct(public array $config,public string $realm,public string $csrf,public ?array $actor=null) {}
    public function h(mixed $v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public function icon(string $name):string
    {
        $paths=['security'=>'M5 11h14v10H5ZM8 11V7a4 4 0 0 1 8 0v4','eye'=>'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0','home'=>'M3 10 12 3l9 7v11h-6v-7H9v7H3Z','users'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z','calendar'=>'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2ZM8 14h2M14 14h2M8 18h2','book'=>'M12 6v15M3 3c4-1 7 0 9 3 2-3 5-4 9-3v15c-4-1-7 0-9 3-2-3-5-4-9-3Z','user'=>'M20 21v-2a7 7 0 0 0-14 0v2M17 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z','chat'=>'M21 11a9 9 0 0 1-9 9 10 10 0 0 1-4-.8L3 21l1.8-5A9 9 0 1 1 21 11ZM8 10h8M8 14h5','arrow'=>'M14 5l-7 7 7 7M7 12h14','topic'=>'M4 4h6v6H4ZM14 4h6v6h-6ZM4 14h6v6H4ZM14 14h6v6h-6Z'];
        return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="'.($paths[$name]??$paths['chat']).'"/></svg>';
    }
    public function status(string $value):string
    {return $this->h(['invited'=>'دعوت‌شده','review'=>'در انتظار بررسی','active'=>'فعال','restricted'=>'محدود','blocked'=>'مسدود','draft'=>'پیش‌نویس','submitted'=>'ارسال‌شده برای بررسی','changes'=>'نیازمند اصلاح','approved'=>'تأییدشده','rejected'=>'ردشده','pending'=>'در انتظار پذیرش','confirmed'=>'قطعی','expired'=>'منقضی','cancelled'=>'لغوشده','completed'=>'پایان‌یافته','accepted'=>'پذیرفته‌شده','open'=>'باز','closed'=>'بسته','support'=>'پشتیبانی','complaint'=>'شکایت','in_app'=>'درون سامانه','stored'=>'ذخیره‌شده','account'=>'حساب','sessions'=>'جلسات','system'=>'سامانه'][$value]??$value);}
    public function url(string $path):string
    {if($this->config['pretty_urls']??false){return $path;}[$route,$query]=array_pad(explode('?',$path,2),2,'');return '/index.php?r='.rawurlencode($route).($query!==''?'&'.$query:'');}
    public function val(string $key,string $default=''):string
    {return $this->h(is_string($this->old[$key]??null)?$this->old[$key]:$default);}
    public function start(string $route):string
    {return '<form method="post" action="'.$this->h($this->url($route)).'"><input type="hidden" name="csrf" value="'.$this->h($this->csrf).'">';}
    public function field(string $key,string $label,string $type='text',string $value='',bool $required=true,int $maxLength=180):string
    {
        $secret=$type==='password'||in_array($key,['code','token','current_password','new_password'],true);
        $id=$key.'-'.(++$this->fieldSerial);$auto=match($key){'token'=>'off','password','current_password'=>'current-password','new_password'=>'new-password','phone'=>'tel','username','login'=>'username',default=>'on'};
        return '<label for="'.$this->h($id).'">'.$this->h($label).'</label><input id="'.$this->h($id).'" name="'.$this->h($key).'" type="'.$this->h($type).'" value="'.($secret?'':$this->val($key,$value)).'" '.($required?'required ':'').' maxlength="'.($type==='password'?'128':(string)max(1,min(2000,$maxLength))).'" autocomplete="'.$auto.'">';
    }
    public function page(string $title,string $template,array $data=[],?string $error=null):void
    {
        if(!preg_match('/^[a-z-]+$/D',$template)){throw new \LogicException('Invalid template.');}
        $focused=in_array($template,['login','admin-login','recover','code','setup','eitaa'],true);
        extract($data,EXTR_SKIP);ob_start();require __DIR__.'/../../templates/'.$template.'.php';$content=ob_get_clean();
        require __DIR__.'/../../templates/layout.php';
    }
}
