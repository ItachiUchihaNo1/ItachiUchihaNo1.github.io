<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Security,Audit,Problem};
final class PrivateFiles
{
    public function __construct(private Database $db,private Audit $audit){}
    private function directory():string
    {$base=__DIR__.'/../../storage/files';if(!is_dir($base)&&!mkdir($base,0700,true)&&!is_dir($base)){throw new Problem('STORAGE_UNAVAILABLE','فضای ذخیره خصوصی در دسترس نیست.',503);}return realpath($base);}
    public static function inspect(string $path,string $name):array
    {
        if(!class_exists(\finfo::class)){throw new Problem('FILEINFO_REQUIRED','افزونه fileinfo برای بارگذاری لازم است.',503);}$size=filesize($path);if(!$size||$size>5242880){throw new Problem('INVALID_FILE','اندازه فایل باید بین یک بایت و پنج مگابایت باشد.');}
        $name=Policy::text($name,180);if(preg_match('/[\x00-\x1F\x7F\\\\\/]/',$name)){throw new Problem('INVALID_FILE','نام فایل معتبر نیست.');}$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$allowed=['image/png'=>['png'],'image/jpeg'=>['jpg','jpeg'],'image/webp'=>['webp'],'application/pdf'=>['pdf']];
        if(!isset($allowed[$mime])||!in_array($ext,$allowed[$mime],true)){throw new Problem('INVALID_FILE','فقط PNG، JPEG و PDF با نوع و پسوند منطبق مجازند.');}
        if(str_starts_with($mime,'image/')){$image=@getimagesize($path);if(!$image||$image[0]*$image[1]>20000000){throw new Problem('INVALID_FILE','ابعاد تصویر معتبر نیست یا بیش از حد بزرگ است.');}}
        elseif(file_get_contents($path,false,null,0,5)!=='%PDF-'){throw new Problem('INVALID_FILE','ساختار فایل PDF معتبر نیست.');}
        return ['name'=>$name,'mime'=>$mime,'size'=>$size,'sha256'=>hash_file('sha256',$path)];
    }
    public function upload(array $a,string $booking,array $file,?string $chatKey=null):string
    {
        (new ConsultationData($this->db))->storage();
        if($chatKey!==null){
            if(!preg_match('/^[a-zA-Z0-9_-]{12,64}$/D',$chatKey))throw new Problem('INVALID_INPUT','شناسهٔ پیام معتبر نیست.');
            $state=(new ChatRooms($this->db))->state($a,$booking);
            if(!$state['can_send'])throw new Problem('CHAT_CLOSED',$state['reason'],409);
        }
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_string($file['name']??null)){
            throw new Problem('INVALID_FILE','بارگذاری انجام نشد؛ حداکثر ۵ مگابایت و محدودیت آپلود هاست را بررسی کنید.');
        }
        $meta=$chatKey!==null?ChatFiles::inspect($file['tmp_name'],$file['name']):self::inspect($file['tmp_name'],$file['name']);$target=null;
        try{return $this->db->tx(function()use($a,$booking,$file,$meta,$chatKey,&$target){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$s=new Consultations($this->db,$this->audit);$s->booking($a,$booking);
            if($chatKey!==null){
                $s->writable($a,$booking);
                $old=$this->db->one('SELECT m.id,f.id AS file_id,f.sha256,f.display_name FROM m_messages m LEFT JOIN m_chat_message_files l ON l.message_id=m.id LEFT JOIN m_private_files f ON f.id=l.file_id WHERE m.booking_id=? AND m.sender_id=? AND m.client_key=?',[$booking,$a['user_id'],$chatKey]);
                if($old){if(!$old['file_id']||!hash_equals($old['sha256'],$meta['sha256'])||$old['display_name']!==$meta['name'])throw new Problem('IDEMPOTENCY_CONFLICT','شناسهٔ پیام قبلاً برای محتوای دیگری استفاده شده است.',409);return $old['file_id'];}
            }
            $existing=$this->db->one('SELECT id FROM m_private_files WHERE booking_id=? AND owner_id=? AND sha256=? AND display_name=?',[$booking,$a['user_id'],$meta['sha256'],$meta['name']]);
            if($existing&&$chatKey===null)return $existing['id'];
            $s->writable($a,$booking);
            if($existing){$id=$existing['id'];}
            else{
                if((int)$this->db->run('SELECT COALESCE(SUM(byte_size),0) FROM m_private_files WHERE booking_id=?',[$booking])->fetchColumn()+$meta['size']>52428800)throw new Problem('STORAGE_LIMIT','سقف پنجاه مگابایت فایل برای هر جلسه تکمیل شده است.');
                $key=bin2hex(random_bytes(32));$target=$this->directory().DIRECTORY_SEPARATOR.$key;
                if(!move_uploaded_file($file['tmp_name'],$target))throw new Problem('STORAGE_UNAVAILABLE','ذخیره فایل انجام نشد؛ فایل را دوباره انتخاب کنید.',503);
                chmod($target,0600);$id=Security::id();
                $this->db->run('INSERT INTO m_private_files VALUES(?,?,?,?,?,?,?,?,?)',[$id,$booking,$a['user_id'],$key,$meta['name'],$meta['mime'],$meta['size'],$meta['sha256'],time()]);
                $this->audit->add($a['user_id'],'site','file.stored',$id);
            }
            if($chatKey!==null){
                $s->writable($a,$booking); // Time/consent may have changed while bytes were being stored.
                $this->db->run('INSERT INTO m_messages(booking_id,sender_id,client_key,body,created_at) VALUES(?,?,?,?,?)',[$booking,$a['user_id'],$chatKey,'پیوست: '.$meta['name'],time()]);
                $message=(int)$this->db->pdo->lastInsertId();
                $this->db->run('INSERT INTO m_chat_message_files(message_id,file_id) VALUES(?,?)',[$message,$id]);
            }
            return $id;
        });}catch(\Throwable $e){if($target!==null&&is_file($target))unlink($target);throw $e;}
    }
    public function listing(array $a,string $booking):array
    {(new Consultations($this->db,$this->audit))->archive($a,$booking);return $this->db->all('SELECT id,display_name,byte_size,created_at FROM m_private_files WHERE booking_id=? ORDER BY created_at,id LIMIT 100',[$booking]);}
    public function download(array $a,string $id):array
    {$f=$this->db->one('SELECT * FROM m_private_files WHERE id=?',[$id]);if(!$f){throw new Problem('NOT_FOUND','فایل پیدا نشد.',404);}(new Consultations($this->db,$this->audit))->archive($a,$f['booking_id']);if(!preg_match('/^[a-f0-9]{64}$/D',$f['storage_key'])){throw new \RuntimeException('Invalid storage key.');}$base=$this->directory();$path=$base.DIRECTORY_SEPARATOR.$f['storage_key'];if(is_link($path)||!is_file($path)||dirname(realpath($path))!==$base||filesize($path)!==(int)$f['byte_size']||!hash_equals($f['sha256'],hash_file('sha256',$path))){throw new Problem('STORAGE_UNAVAILABLE','یکپارچگی فایل تأیید نشد.',503);}$this->audit->add($a['user_id'],$a['realm'],'file.downloaded',$id);return $f+['path'=>$path];}
    public function stream(array $a,string $id,bool $forceDownload=false):void
    {
        $f=$this->download($a,$id);$size=(int)$f['byte_size'];$mime=$f['media_type'];
        $inline=!$forceDownload&&(str_starts_with($mime,'image/')||str_starts_with($mime,'audio/')||str_starts_with($mime,'video/'));
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");header('Accept-Ranges: bytes');
        header('Content-Type: '.($inline?$mime:'application/octet-stream'));
        header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="attachment"; filename*=UTF-8\'\''.rawurlencode($f['display_name']));
        $start=0;$end=$size-1;$range=$_SERVER['HTTP_RANGE']??'';
        if($range!==''){
            if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)||($m[1]===''&&$m[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$size);return;}
            if($m[1]===''){$start=max(0,$size-(int)$m[2]);}else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}
            if($start>$end||$start>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);return;}
            http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
        }
        header('Content-Length: '.($end-$start+1));if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $h=fopen($f['path'],'rb');if(!$h)throw new Problem('STORAGE_UNAVAILABLE','فایل در دسترس نیست.',503);
        try{fseek($h,$start);$remaining=$end-$start+1;while($remaining>0&&!feof($h)&&!connection_aborted()){$chunk=fread($h,min(65536,$remaining));if($chunk===false||$chunk==='')break;echo $chunk;$remaining-=strlen($chunk);}}finally{fclose($h);}
    }
}
