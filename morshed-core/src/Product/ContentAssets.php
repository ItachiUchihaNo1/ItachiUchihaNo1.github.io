<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Security,Audit,Problem};
final class ContentAssets
{
    public function __construct(private Database $db,private Audit $audit){}
    private function directory():string
    {$base=__DIR__.'/../../storage/content-files';if(!is_dir($base)&&!mkdir($base,0700,true)&&!is_dir($base)){throw new Problem('STORAGE_UNAVAILABLE','فضای فایل در دسترس نیست.',503);}return realpath($base);}
    public function upload(array $a,array $file,bool $avatar=false,bool $branding=false):string
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_string($file['name']??null)){throw new Problem('INVALID_FILE','بارگذاری انجام نشد؛ فایل را دوباره انتخاب کنید.');}$meta=PrivateFiles::inspect($file['tmp_name'],$file['name']);if(($avatar||$branding)&&!in_array($meta['mime'],['image/png','image/jpeg'],true)){throw new Problem('INVALID_FILE','عکس پروفایل فقط PNG یا JPEG است.');}$target=null;
        try{return $this->db->tx(function()use($a,$file,$meta,$avatar,$branding,&$target){$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$m=null;if($branding){(new Policy($this->db))->admin($a,true);}else{$m=(new Policy($this->db))->mentor($a);}if(!$branding&&!$m['accepted_at']){throw new Problem('INVITE_NOT_ACCEPTED','ابتدا دعوت را بپذیرید.',409);}$existing=$this->db->one('SELECT id FROM m_content_assets WHERE owner_id=? AND sha256=?',[$a['user_id'],$meta['sha256']]);if($existing){if($avatar&&!$branding){$this->db->run("UPDATE m_mentors SET avatar_asset_id=?,status='review',updated_at=? WHERE user_id=?",[$existing['id'],time(),$a['user_id']]);$this->audit->add($a['user_id'],'site','mentor.portrait_review_requested',$existing['id']);}return $existing['id'];}if((int)$this->db->run('SELECT COALESCE(SUM(byte_size),0) FROM m_content_assets WHERE owner_id=?',[$a['user_id']])->fetchColumn()+$meta['size']>104857600){throw new Problem('STORAGE_LIMIT','سقف صد مگابایت فایل محتوایی تکمیل است.');}
            $key=bin2hex(random_bytes(32));$target=$this->directory().DIRECTORY_SEPARATOR.$key;if(!move_uploaded_file($file['tmp_name'],$target)){throw new Problem('STORAGE_UNAVAILABLE','ذخیره فایل انجام نشد.',503);}chmod($target,0600);$id=Security::id();$this->db->run('INSERT INTO m_content_assets VALUES(?,?,?,?,?,?,?,?)',[$id,$a['user_id'],$key,$meta['name'],$meta['mime'],$meta['size'],$meta['sha256'],time()]);if($avatar&&!$branding){$this->db->run("UPDATE m_mentors SET avatar_asset_id=?,status='review',updated_at=? WHERE user_id=?",[$id,time(),$a['user_id']]);}$this->audit->add($a['user_id'],'site',$branding?'branding.image_uploaded':($avatar?'mentor.portrait_review_requested':'experience.evidence_uploaded'),$id);return $id;});}catch(\Throwable $e){if($target!==null&&is_file($target)){unlink($target);}throw $e;}
    }
    public function owned(string $uid):array{return $this->db->all('SELECT id,display_name,media_type FROM m_content_assets WHERE owner_id=? ORDER BY created_at DESC LIMIT 100',[$uid]);}
    public function download(?array $a,string $id):array
    {
        $f=$this->db->one('SELECT * FROM m_content_assets WHERE id=?',[$id]);if(!$f){throw new Problem('NOT_FOUND','فایل پیدا نشد.',404);}
        $public=$this->db->one("SELECT m.user_id FROM m_mentors m JOIN m_users u ON u.id=m.user_id WHERE m.status='active' AND u.status='active' AND (m.avatar_asset_id=? OR EXISTS(SELECT 1 FROM m_experiences e JOIN m_experience_assets ea ON ea.version_id=e.public_version_id WHERE e.mentor_id=m.user_id AND ea.asset_id=?))",[$id,$id]);
        if(!$public){$public=$this->db->one("SELECT setting_key FROM m_public_settings WHERE setting_key IN ('footer_logo_asset','enamad_asset') AND setting_value=?",[$id]);}if(!$public){if(!$a){throw new Problem('NOT_FOUND','فایل منتشر نشده است.',404);}if(($a['realm']??'')==='admin'){(new Policy($this->db))->admin($a);$this->audit->add($a['user_id'],'admin','content.asset_reviewed',$id);}else{(new Policy($this->db))->user($a);if($a['user_id']!==$f['owner_id']){throw new Problem('NOT_FOUND','فایل در دسترس نیست.',404);}}}
        if(!preg_match('/^[a-f0-9]{64}$/D',$f['storage_key'])){throw new \RuntimeException('Invalid content storage key.');}$base=$this->directory();$path=$base.DIRECTORY_SEPARATOR.$f['storage_key'];if(is_link($path)||!is_file($path)||dirname(realpath($path))!==$base||filesize($path)!==(int)$f['byte_size']||!hash_equals($f['sha256'],hash_file('sha256',$path))){throw new Problem('STORAGE_UNAVAILABLE','یکپارچگی فایل تأیید نشد.',503);}return $f+['path'=>$path];
    }
}
