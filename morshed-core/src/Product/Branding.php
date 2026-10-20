<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Audit,Problem};
final class Branding
{
    public const DEFAULTS=['footer_email'=>'mozafarijavad287@gmail.com','support_phone'=>'09120691542','support_email'=>'mozafarijavad287@gmail.com','support_address'=>'','footer_text'=>'مرشد، جایی برای پیدا کردن مرشد و گفت‌وگوی مستقیم دربارهٔ مسیر پیش روی توست.','footer_logo_link'=>'/','enamad_link'=>''];
    public static function settings(Database $db):array{return array_replace(self::DEFAULTS,array_column($db->all('SELECT setting_key,setting_value FROM m_public_settings'),'setting_value','setting_key'));}
    public static function link(string $value):string
    {
        $value=trim($value);if($value===''){return '';}
        if(preg_match('/[\x00-\x20\x7f\\\\]/',$value)||strlen($value)>1000){throw new Problem('INVALID_INPUT','لینک معتبر نیست.');}
        if(str_starts_with($value,'/')&&!str_starts_with($value,'//')){return $value;}
        $parts=parse_url($value);if(!filter_var($value,FILTER_VALIDATE_URL)||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])){throw new Problem('INVALID_INPUT','لینک باید مسیر داخلی یا نشانی HTTPS معتبر باشد.');}return $value;
    }
    public static function asset(Database $db,Audit $audit,array $actor,string $kind,string $id):void
    {
        Policy::choice($kind,['footer_logo','enamad']);$db->tx(function()use($db,$audit,$actor,$kind,$id){$db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');(new Policy($db))->admin($actor,true);if($id!==''&&!$db->one("SELECT id FROM m_content_assets WHERE id=? AND owner_id=? AND media_type IN ('image/png','image/jpeg')",[$id,$actor['user_id']])){throw new Problem('INVALID_FILE','تصویر بارگذاری‌شده معتبر نیست.');}
            $key=$kind.'_asset';$now=time();$db->run('INSERT INTO m_public_settings VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=?,updated_by=?,updated_at=?',[$key,$id,$actor['user_id'],$now,$id,$actor['user_id'],$now]);$audit->add($actor['user_id'],'admin','branding.image_changed',$id?:null);
        });
    }
}
