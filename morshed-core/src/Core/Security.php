<?php
declare(strict_types=1);
namespace Morshed\Core;
final class Security
{
    public static function id(): string { return bin2hex(random_bytes(16)); }
    public static function validId(string $id): bool { return preg_match('/^[a-f0-9]{32}$/D', $id) === 1; }
    public static function phone(string $phone): string
    {
        try { return \Morshed\Notifications\TsmsSendOnlyGateway::normalizePhone($phone); }
        catch (\InvalidArgumentException $e) { throw new Problem('INVALID_PHONE','شماره همراه معتبر وارد کنید.'); }
    }
    public static function password(string $value): void
    {
        if (!preg_match('//u', $value) || preg_match_all('/./us', $value) < 8 || strlen($value) > 72) {
            throw new Problem('INVALID_PASSWORD','رمز باید حداقل ۸ نویسه و حداکثر ۷۲ بایت باشد.');
        }
    }
    public static function hashPassword(string $value): string
    { self::password($value); return password_hash($value, PASSWORD_BCRYPT, ['cost'=>12]); }
    public static function verifyPassword(string $value,string $hash):bool
    {return strlen($value)<=72 && password_verify($value,$hash);}
    public static function digits(string $value):string
    {return strtr($value,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);}
    public static function name(string $value): string
    {
        $value = trim($value);
        if (!preg_match('//u', $value) || preg_match_all('/./us', $value) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new Problem('INVALID_NAME','نام نمایش معتبر و حداکثر ۱۲۰ نویسه وارد کنید.');
        }
        return $value;
    }
}
