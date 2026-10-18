<?php
declare(strict_types=1);
namespace Morshed\Identity;
use Morshed\Core\{Database,Security,Problem,Upgrade};
final class Profile
{
    public const PROVINCES=['آذربایجان شرقی','آذربایجان غربی','اردبیل','اصفهان','البرز','ایلام','بوشهر','تهران','چهارمحال و بختیاری','خراسان جنوبی','خراسان رضوی','خراسان شمالی','خوزستان','زنجان','سمنان','سیستان و بلوچستان','فارس','قزوین','قم','کردستان','کرمان','کرمانشاه','کهگیلویه و بویراحمد','گلستان','گیلان','لرستان','مازندران','مرکزی','هرمزگان','همدان','یزد'];
    public static function validate(array $d):array
    {
        $v=[];foreach(['first_name'=>'نام','last_name'=>'نام خانوادگی','province'=>'استان','city'=>'شهر','gender'=>'جنسیت'] as $k=>$label){
            $s=$d[$k]??'';if(!is_string($s)||trim($s)===''||preg_match_all('/./us',$s)>($k==='city'?80:60)){throw new Problem('INVALID_PROFILE','فیلد «'.$label.'» را کامل و صحیح وارد کنید.');}$v[$k]=Security::name($s);
        }
        if(!in_array($v['province'],self::PROVINCES,true)||!in_array($v['gender'],['female','male'],true)){throw new Problem('INVALID_PROFILE','استان یا جنسیت انتخاب‌شده معتبر نیست.');}
        return $v;
    }
    public static function get(Database $db,string $uid):array
    {return Upgrade::ready($db)?($db->one('SELECT * FROM m_user_profiles WHERE user_id=?',[$uid])??[]):[];}
    public static function complete(Database $db,string $uid):bool
    {if(!Upgrade::ready($db)){return true;}$p=self::get($db,$uid);return !empty($p['completed_at'])&&empty($p['deleted_at']);}
    public static function save(Database $db,string $uid,array $data):void
    {
        if(!Upgrade::ready($db)){throw new Problem('UPGRADE_REQUIRED','مدیر باید ابتدا به‌روزرسانی دیتابیس را انجام دهد.',503);}
        $v=self::validate($data);$display=Security::name($v['first_name'].' '.$v['last_name']);$db->run('INSERT INTO m_user_profiles(user_id,first_name,last_name,province,city,gender,completed_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE first_name=VALUES(first_name),last_name=VALUES(last_name),province=VALUES(province),city=VALUES(city),gender=VALUES(gender),completed_at=VALUES(completed_at)',[$uid,...array_values($v),time()]);
        $db->run('UPDATE m_users SET display_name=?,updated_at=? WHERE id=?',[$display,time(),$uid]);
    }
}
