<?php
declare(strict_types=1);
namespace Morshed\Legal;
use Morshed\Core\{Database,Security,Audit,Problem};
final class Documents
{
    public function __construct(private Database $db,private Audit $audit) {}
    public function current(string $document='base'):?array
    {if(!in_array($document,['base','recording'],true))throw new Problem('INVALID_DOCUMENT','نوع سند معتبر نیست.');return $this->db->one('SELECT v.* FROM m_legal_publications p JOIN m_legal_versions v ON v.id=p.version_id AND v.document_key=p.document_key WHERE p.document_key=?',[$document]);}
    private function publisher(array $actor):void
    {
        if(($actor['realm']??'')!=='admin'||!$this->db->one('SELECT a.id FROM m_admin_accounts a JOIN m_users u ON u.id=a.user_id WHERE a.id=? AND a.user_id=? AND a.role=\'system_admin\' AND u.status=\'active\'',[$actor['admin_id']??'',$actor['user_id']??''])){throw new Problem('ACCESS_DENIED','مجوز انتشار قوانین لازم است.',403);}
    }
    public function createBase(array $actor,string $title,string $body):string {return $this->create($actor,'base',$title,$body);}
    public function createRecording(array $actor,string $title,string $body):string {return $this->create($actor,'recording',$title,$body);}
    private function create(array $actor,string $document,string $title,string $body):string
    {
        $title=trim($title);$body=trim($body);
        if($title===''||!preg_match('//u',$title.$body)||preg_match_all('/./us',$title)>180||$body===''||strlen($body)>60000){throw new Problem('INVALID_DOCUMENT','عنوان و متن معتبر سند را وارد کنید.');}
        return $this->db->tx(function()use($actor,$document,$title,$body){
            $this->db->one('SELECT document_key FROM m_legal_publications WHERE document_key=? FOR UPDATE',[$document]);
            $this->publisher($actor);
            $n=(int)$this->db->run('SELECT COALESCE(MAX(version_no),0)+1 FROM m_legal_versions WHERE document_key=?',[$document])->fetchColumn();
            $id=Security::id();$this->db->run('INSERT INTO m_legal_versions(id,document_key,version_no,title,body,content_sha256,created_by,created_at) VALUES (?,?,?,?,?,?,?,?)',[$id,$document,$n,$title,$body,hash('sha256',$body),$actor['user_id'],time()]);$this->audit->add($actor['user_id'],'admin','legal.'.$document.'_version_created',$id);return $id;
        });
    }
    public function publishBase(array $actor,string $id):void { $this->publish($actor,'base',$id); }
    public function publishRecording(array $actor,string $id):void { $this->publish($actor,'recording',$id); }
    private function publish(array $actor,string $document,string $id):void
    {
        $this->db->tx(function()use($actor,$document,$id){
            $this->db->one('SELECT document_key FROM m_legal_publications WHERE document_key=? FOR UPDATE',[$document]);
            $this->publisher($actor);
            if(!$this->db->one('SELECT id FROM m_legal_versions WHERE id=? AND document_key=?',[$id,$document])){throw new Problem('NOT_FOUND','نسخهٔ این سند پیدا نشد.',404);}
            $this->db->run('UPDATE m_legal_publications SET version_id=?,published_at=? WHERE document_key=?',[$id,time(),$document]);$this->audit->add($actor['user_id'],'admin','legal.'.$document.'_published',$id);
        });
    }
    public function accept(string $user,string $version):void
    {
        $this->db->tx(function()use($user,$version){
            $p=$this->db->one('SELECT version_id FROM m_legal_publications WHERE document_key=\'base\' FOR UPDATE');
            if(!$p || $p['version_id']===null || $p['version_id']!==$version){throw new Problem('LEGAL_VERSION_CHANGED','نسخه قوانین تغییر کرده است؛ متن تازه را بخوانید.',409);}
            $n=$this->db->run('INSERT IGNORE INTO m_user_acceptances(user_id,version_id,accepted_at) VALUES (?,?,?)',[$user,$version,time()])->rowCount();
            if($n){$this->audit->add($user,'site','legal.base_accepted',$version);}
        });
    }
}
