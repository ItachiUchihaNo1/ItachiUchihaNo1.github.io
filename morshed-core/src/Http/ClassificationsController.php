<?php
declare(strict_types=1);
namespace Morshed\Http;
use Morshed\Core\{Database,Problem};
use Morshed\Product\{Classifications,Content,Taxonomy,Knowledge,Policy};

final class ClassificationsController
{
    public function __construct(private Database $db,private View $view,private ?array $actor,private string $realm,private bool $api){}
    private function access():Classifications
    {if($this->realm!=='admin'||!$this->actor){throw new Problem('ACCESS_DENIED','مجوز مدیریت لازم است.',403);}(new Policy($this->db))->admin($this->actor);$f=new Classifications($this->db);$f->storage();return $f;}
    public function get(string $path):bool
    {
        if($this->realm==='site'&&!$this->api&&in_array($path,['/topic','/topics','/issue','/issues'],true)){
            $filter='';if(in_array($path,['/topic','/issue'],true)){$id=$_GET['id']??'';if(!is_string($id)||!\Morshed\Core\Security::validId($id)){throw new Problem('INVALID_FILTER','شناسهٔ طبقه‌بندی معتبر نیست.');}$filter='?'.($path==='/topic'?'topic':'issue').'='.(new Taxonomy($this->db))->resolve($id);}
            header('Location: '.$this->view->url('/experiences'.$filter),true,301);return true;
        }
        if($path==='/classification/organization-options'){
            $this->access();if(!$this->api){throw new Problem('NOT_FOUND','مسیر داده است.',404);}$q=Policy::query(Policy::text($_GET['q']??'',160,0));$type=Policy::text($_GET['org_type']??'',24,0);$rows=$this->db->all("SELECT o.id,o.name,p.org_type FROM m_organizations o JOIN m_organization_profiles p ON p.org_id=o.id WHERE o.active=1 AND p.merged_into IS NULL AND o.name LIKE ?".($type!==''?' AND p.org_type=?':'').' ORDER BY o.name LIMIT 51',[$q,...($type!==''?[$type]:[])]);header('Content-Type: application/json; charset=utf-8');echo json_encode(['rows'=>array_slice($rows,0,50),'more'=>count($rows)>50],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);return true;
        }
        if($this->realm!=='admin'||!in_array($path,['/organization-types','/audiences'],true)){return false;}$f=$this->access();$kind=substr($path,1);$rows=$kind==='audiences'?$f->audiences(true):$f->types(true);$q=Policy::text($_GET['q']??'',160,0);if($q!==''){$rows=array_values(array_filter($rows,fn($r)=>str_contains(str_replace(['ي','ك'],['ی','ک'],$r['title']),str_replace(['ي','ك'],['ی','ک'],$q))));}
        $data=['kind'=>$kind,'rows'=>$rows];$title=$kind==='audiences'?'مدیریت گروه‌های مخاطب':'مدیریت انواع مجموعه';
        if($this->api){header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}else{$this->view->page($title,'classification-catalog',$data);}return true;
    }
    public function post(string $path,array $d):bool
    {
        if(!in_array($path,['/classification/catalog/save','/classification/proposal/review','/taxonomy/merge','/taxonomy/delete'],true)){return false;}$f=$this->access();$id='';
        try{
            if($path==='/classification/catalog/save'){$kind=Policy::choice($d['kind']??'',['audiences','organization-types']);$id=$f->saveCatalog($this->actor,$kind,$d);$back='/'.$kind;}
            elseif($path==='/taxonomy/delete'){if(($d['confirm_delete']??'')!=='1'){throw new Problem('INVALID_INPUT','تأیید حذف لازم است.');}$kind=(new Content($this->db,new \Morshed\Core\Audit($this->db)))->deleteTerm($this->actor,Policy::text($d['id']??'',32));$back='/taxonomy?kind='.$kind;}
            elseif($path==='/taxonomy/merge'){(new Knowledge($this->db))->mergeTerms($this->actor,Policy::text($d['from']??'',32),Policy::text($d['into']??'',32));$back='/taxonomy';}
            else{$f->reviewProposal($this->actor,$d);$back='/atlas/manage';}
        }catch(Problem $e){if($this->api){throw $e;}http_response_code($e->status);$this->view->old=$d;$this->view->page('اصلاح اطلاعات','classification-error',['message'=>$e->getMessage(),'input'=>$d,'retryPath'=>$path]);return true;}
        if($this->api){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'id'=>$id],JSON_THROW_ON_ERROR);}else{$_SESSION['flash']='اطلاعات ثبت شد.';header('Location: '.$this->view->url($back),true,303);}return true;
    }
}
