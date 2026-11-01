<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Problem};

/** Links private files to the existing durable message timeline. */
final class ChatFiles
{
    public const MAX_BYTES=5242880;
    public function __construct(private Database $db){}
    public function storage():void
    {
        if($this->db->featureReady('chat_files33'))return;
        try{$this->db->run('SELECT 1 FROM m_chat_message_files LIMIT 0');}
        catch(\PDOException $e){
            if((int)($e->errorInfo[1]??0)!==1146||$this->db->pdo->inTransaction())throw $e;
            $this->db->run('CREATE TABLE IF NOT EXISTS m_chat_message_files (message_id BIGINT UNSIGNED PRIMARY KEY,file_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,INDEX(file_id),FOREIGN KEY(message_id) REFERENCES m_messages(id),FOREIGN KEY(file_id) REFERENCES m_private_files(id)) ENGINE=InnoDB');
        }
        $this->db->markFeatureReady('chat_files33');
    }
    public static function inspect(string $path,string $name):array
    {
        $size=filesize($path);
        if(!$size||$size>self::MAX_BYTES)throw new Problem('INVALID_FILE','حداکثر اندازهٔ هر پیوست ۵ مگابایت است.');
        $name=Policy::text($name,180);
        if(preg_match('/[\x00-\x1F\x7F\\\\\/]/',$name))throw new Problem('INVALID_FILE','نام فایل معتبر نیست.');
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed=[
            'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'webp'=>['image/webp'],'gif'=>['image/gif'],
            'pdf'=>['application/pdf'],'mp4'=>['video/mp4'],'webm'=>['video/webm','audio/webm'],'mov'=>['video/quicktime'],
            'mp3'=>['audio/mpeg'],'wav'=>['audio/x-wav','audio/wav'],'ogg'=>['audio/ogg','video/ogg','application/ogg'],
            'oga'=>['audio/ogg','application/ogg'],'m4a'=>['audio/mp4','audio/x-m4a','video/mp4'],
            'txt'=>['text/plain'],'csv'=>['text/plain','text/csv'],'rtf'=>['text/rtf','application/rtf'],
            'doc'=>['application/msword','application/CDFV2','application/x-ole-storage'],
            'xls'=>['application/vnd.ms-excel','application/CDFV2','application/x-ole-storage'],
            'ppt'=>['application/vnd.ms-powerpoint','application/CDFV2','application/x-ole-storage'],
        ];
        $office=[
            'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','word/document.xml'],
            'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','xl/workbook.xml'],
            'pptx'=>['application/vnd.openxmlformats-officedocument.presentationml.presentation','ppt/presentation.xml'],
            'odt'=>['application/vnd.oasis.opendocument.text','content.xml'],
            'ods'=>['application/vnd.oasis.opendocument.spreadsheet','content.xml'],
        ];
        if(isset($office[$ext])){
            if(!in_array($mime,[$office[$ext][0],'application/zip','application/x-zip'],true))throw new Problem('INVALID_FILE','نوع و پسوند سند یکسان نیست.');
            $names=self::zipNames($path,(int)$size);
            if(!in_array($office[$ext][1],$names,true)||(!in_array('[Content_Types].xml',$names,true)&&!in_array('mimetype',$names,true)))throw new Problem('INVALID_FILE','ساختار سند معتبر نیست.');
            $mime=$office[$ext][0];
        }elseif(!isset($allowed[$ext])||!in_array($mime,$allowed[$ext],true)){
            throw new Problem('INVALID_FILE','این نوع فایل پشتیبانی نمی‌شود یا پسوند با محتوای فایل سازگار نیست.');
        }
        if(str_starts_with($mime,'image/')){
            $image=@getimagesize($path);if(!$image||$image[0]*$image[1]>20000000)throw new Problem('INVALID_FILE','تصویر معتبر نیست یا ابعاد آن بیش از حد بزرگ است.');
        }
        if($ext==='pdf'&&file_get_contents($path,false,null,0,5)!=='%PDF-')throw new Problem('INVALID_FILE','ساختار PDF معتبر نیست.');
        return ['name'=>$name,'mime'=>$mime,'size'=>$size,'sha256'=>hash_file('sha256',$path)];
    }
    /** Inspect a bounded ZIP directory without extracting or inflating user data. */
    private static function zipNames(string $path,int $size):array
    {
        $bad=static fn()=>new Problem('INVALID_FILE','ساختار سند فشرده معتبر نیست یا بیش از حد بزرگ است.');
        $h=fopen($path,'rb');if(!$h)throw $bad();
        try{
            $tailSize=min($size,65557);fseek($h,$size-$tailSize);$tail=fread($h,$tailSize);$pos=strrpos($tail,"PK\x05\x06");
            if($pos===false||strlen($tail)-$pos<22)throw $bad();
            $e=unpack('vdisk/vstart/vcountDisk/vcount/Vlength/Voffset/vcomment',substr($tail,$pos+4,18));
            if($e['disk']||$e['start']||$e['countDisk']!==$e['count']||$e['count']<1||$e['count']>2000||$e['length']<46||$e['length']>2097152||$e['offset']+$e['length']>$size-$tailSize+$pos||$pos+22+$e['comment']!==strlen($tail))throw $bad();
            fseek($h,$e['offset']);$data=fread($h,$e['length']);$cursor=0;$names=[];$total=0;
            for($i=0;$i<$e['count'];$i++){
                if(substr($data,$cursor,4)!=="PK\x01\x02"||strlen($data)-$cursor<46)throw $bad();
                $f=unpack('vflags',substr($data,$cursor+8,2));$uncompressed=unpack('Vsize',substr($data,$cursor+24,4))['size'];
                $n=unpack('vname/vextra/vcomment',substr($data,$cursor+28,6));$length=46+$n['name']+$n['extra']+$n['comment'];
                if(($f['flags']&1)||$cursor+$length>strlen($data)||($total+=$uncompressed)>104857600)throw $bad();
                $name=substr($data,$cursor+46,$n['name']);
                if(str_contains($name,"\0")||str_contains($name,'..')||str_starts_with($name,'/')||str_contains($name,'\\')||preg_match('/vbaProject\.bin$/i',$name))throw $bad();
                $names[]=$name;$cursor+=$length;
            }
            if($cursor!==strlen($data))throw $bad();return $names;
        }finally{fclose($h);}
    }
    public function decorate(array $rows):array
    {
        $this->storage();if(!$rows)return [];
        $ids=array_column($rows,'id');$map=[];
        foreach($this->db->all('SELECT l.message_id,f.id,f.display_name,f.media_type,f.byte_size FROM m_chat_message_files l JOIN m_private_files f ON f.id=l.file_id WHERE l.message_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids) as $f){
            $map[(int)$f['message_id']]=['id'=>$f['id'],'name'=>$f['display_name'],'mime'=>$f['media_type'],'size'=>(int)$f['byte_size']];
        }
        foreach($rows as &$r){$r['attachment']=$map[(int)$r['id']]??null;}unset($r);return $rows;
    }
}
