<?php
declare(strict_types=1);
namespace Morshed\Http;
use Morshed\Core\{Database,Problem};
use Morshed\Product\{AtlasData,Policy,Taxonomy};
final class AtlasController
{
    private AtlasData $atlas;
    public function __construct(private Database $db,private View $view,private ?array $actor,private string $realm,private bool $api){$this->atlas=new AtlasData($db);}
    private function render(string $title,string $template,array $data):void
    {if($this->api){header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}else{$this->view->page($title,$template,$data);}}
    public function get(string $path):bool
    {
        if($this->realm==='site'){
            if($path==='/places'){if(!$this->api){return false;}$province=$_GET['province']??'';if(!is_string($province)){throw new Problem('INVALID_PLACE','استان معتبر نیست.');}$this->atlas->location($province,'');$this->render('شهرستان‌ها','atlas',['places'=>$province===''?$this->atlas->places():$this->atlas->places($province)]);return true;}
            if(in_array($path,['/atlas','/organizations'],true)){
                $filters=$_GET;if($path==='/organizations'){$filters['layer']='organizations';}
                $data=$this->atlas->discover($filters,$path==='/atlas');$data+=['isAtlas'=>$path==='/atlas','provinces'=>$this->atlas->places(),'counties'=>!empty($data['province'])?$this->atlas->places($data['province']):[],'terms'=>(new Taxonomy($this->db))->topics(),'issues'=>$this->db->all("SELECT id,title FROM m_terms WHERE kind='issue' AND active=1 ORDER BY title")];
                $this->render($path==='/atlas'?'اطلس تجربه‌های مرشد':'مجموعه‌های تجربه','atlas',$data);return true;
            }
            if($path==='/organization'){$id=$_GET['id']??'';if(!is_string($id)){throw new Problem('NOT_FOUND','مجموعه پیدا نشد.',404);}$data=$this->atlas->organization($id,['page'=>$_GET['page']??1,'mentor_page'=>$_GET['mentor_page']??1]);$this->render($data['organization']['name'],'organization',$data);return true;}
        }elseif($path==='/atlas/manage'){
            if(!$this->actor){throw new Problem('UNAUTHENTICATED','ابتدا وارد حساب مدیریت شوید.',401);}(new Policy($this->db))->admin($this->actor,true);$this->atlas->storage();
            $this->render('مجموعه‌ها و جغرافیای تجربه','atlas-admin',['aliases'=>$this->db->all('SELECT org_id,alias FROM m_organization_aliases ORDER BY alias'),'rows'=>$this->atlas->organizations(true),'provinces'=>$this->atlas->places(),'places'=>$this->db->all('SELECT id,parent_id,name FROM m_places ORDER BY kind DESC,name'),'versions'=>$this->db->all('SELECT v.id,v.title,v.version_no,v.status FROM m_experience_versions v WHERE v.version_no=(SELECT MAX(vv.version_no) FROM m_experience_versions vv WHERE vv.experience_id=v.experience_id) ORDER BY v.created_at DESC LIMIT 100'),'queue'=>$this->db->all("SELECT x.version_id,v.title,o.name,x.status FROM m_experience_organizations x JOIN m_experience_versions v ON v.id=x.version_id JOIN m_organizations o ON o.id=x.org_id WHERE x.status='proposed' ORDER BY v.created_at LIMIT 100"),'missing'=>$this->db->all("SELECT e.id,v.id AS version_id,v.title FROM m_experiences e JOIN m_experience_versions v ON v.id=e.public_version_id WHERE NOT EXISTS(SELECT 1 FROM m_experience_places xp WHERE xp.version_id=v.id AND xp.status='approved') LIMIT 100")]);return true;
        }
        return false;
    }
    public function post(string $path,array $d):bool
    {
        if(!in_array($path,['/atlas/organization/save','/atlas/organization/merge','/atlas/attribution'],true)){return false;}
        if($this->realm!=='admin'||!$this->actor){throw new Problem('ACCESS_DENIED','مجوز مدیریت لازم است.',403);}
        $id='';if($path==='/atlas/organization/save'){$id=$this->atlas->saveOrganization($this->actor,$d);}
        elseif($path==='/atlas/organization/merge'){$this->atlas->merge($this->actor,Policy::text($d['from']??'',32),Policy::text($d['into']??'',32));}
        else{$this->atlas->attribution($this->actor,$d);}
        if($this->api){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'id'=>$id],JSON_THROW_ON_ERROR);}else{$_SESSION['flash']='اطلاعات مجموعه و انتساب ثبت شد.';header('Location: '.$this->view->url('/atlas/manage'),true,303);}return true;
    }
}
