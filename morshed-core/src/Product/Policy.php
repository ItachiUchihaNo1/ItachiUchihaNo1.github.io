<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Problem};
final class Policy
{
    public function __construct(private Database $db){}
    public function user(array $a,string $realm='site'):string
    {
        if(($a['realm']??'')!==$realm||!$this->db->one("SELECT id FROM m_users WHERE id=? AND status='active'",[$a['user_id']??''])){throw new Problem('ACCESS_DENIED','دسترسی مجاز نیست.',403);}
        return $a['user_id'];
    }
    public function admin(array $a,bool $system=false):string
    {
        $uid=$this->user($a,'admin');$r=$this->db->one('SELECT role FROM m_admin_accounts WHERE id=? AND user_id=?',[$a['admin_id']??'',$uid]);
        if(!$r||($system&&$r['role']!=='system_admin')){throw new Problem('ACCESS_DENIED','مجوز مدیریتی لازم است.',403);}return $uid;
    }
    public function mentor(array $a,bool $active=false):array
    {
        $uid=$this->user($a);$m=$this->db->one('SELECT * FROM m_mentors WHERE user_id=?',[$uid]);
        if(!$m||$m['status']==='restricted'||($active&&$m['status']!=='active')){throw new Problem('MENTOR_REQUIRED','دسترسی مرشد فعال لازم است.',403);}return $m;
    }
    public static function text(mixed $s,int $max=10000,int $min=1):string
    {
        if(!is_string($s)||strlen(trim($s))<$min||strlen($s)>$max||!preg_match('//u',$s)){throw new Problem('INVALID_INPUT','متن ورودی یا طول آن معتبر نیست.');}return trim($s);
    }
    public static function integer(mixed $v,int $min,int $max):int
    {if(filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<$min||(int)$v>$max){throw new Problem('INVALID_INPUT','عدد ورودی خارج از محدوده است.');}return (int)$v;}
    public static function choice(mixed $s,array $choices):string
    {if(!is_string($s)||!in_array($s,$choices,true)){throw new Problem('INVALID_INPUT','انتخاب معتبر نیست.');}return $s;}
    public static function query(mixed $s):string
    {return '%'.strtr(str_replace(['ي','ك'],['ی','ک'],is_string($s)?substr($s,0,160):''),['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%';}
}
