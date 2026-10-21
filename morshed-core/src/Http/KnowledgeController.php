<?php
declare(strict_types=1);
namespace Morshed\Http;
use Morshed\Core\{Database,Problem};
use Morshed\Product\{Knowledge,InteractionEvents,Policy};
final class KnowledgeController
{
    public function __construct(private Database $db,private View $view,private ?array $actor,private string $realm,private bool $api){}
    private function access():Knowledge
    {if($this->realm!=='admin'||!$this->actor){throw new Problem('ACCESS_DENIED','مجوز مدیریت لازم است.',403);}(new Policy($this->db))->admin($this->actor);$k=new Knowledge($this->db);$k->storage();return $k;}
    public function get(string $path):bool
    {
        if($path!=='/knowledge'){return false;}$k=$this->access();$data=['relations'=>$this->db->all('SELECT * FROM m_knowledge_relations ORDER BY created_at DESC LIMIT 100'),'terms'=>$this->db->all('SELECT id,title,kind FROM m_terms WHERE active=1 ORDER BY kind,title'),'experiences'=>$this->db->all('SELECT e.id,v.title FROM m_experiences e JOIN m_experience_versions v ON v.id=e.public_version_id ORDER BY v.title LIMIT 200'),'eventPolicy'=>$this->db->one('SELECT enabled,retention_days,approved_at FROM m_interaction_policy WHERE id=1'),'backfill'=>$k->backfill(true)];
        if($this->api){header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}else{$this->view->page('روابط و فراداده','knowledge-admin',$data);}return true;
    }
    public function post(string $path,array $d):bool
    {
        if(!in_array($path,['/knowledge/propose','/knowledge/review','/knowledge/aliases','/knowledge/merge','/knowledge/backfill','/knowledge/policy'],true)){return false;}$k=$this->access();$id='';
        switch($path){
            case '/knowledge/propose':$id=$k->propose($this->actor,$d);break;
            case '/knowledge/review':$k->review($this->actor,Policy::text($d['id']??'',32),Policy::text($d['status']??'',16),Policy::integer($d['revision']??0,1,100000));break;
            case '/knowledge/aliases':$k->aliases($this->actor,Policy::text($d['term_id']??'',32),Policy::text($d['aliases']??'',2400,0));break;
            case '/knowledge/merge':$k->mergeTerms($this->actor,Policy::text($d['from']??'',32),Policy::text($d['into']??'',32));break;
            case '/knowledge/backfill':$k->backfill(false);break;
            case '/knowledge/policy':(new InteractionEvents($this->db))->configure($this->actor,($d['enabled']??'')==='1',($d['retention_days']??'')===''?null:Policy::integer($d['retention_days'],1,3650),($d['approved']??'')==='1');break;
        }
        if($this->api){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'id'=>$id],JSON_THROW_ON_ERROR);}else{$_SESSION['flash']='تغییرات شبکهٔ دانش ثبت شد.';header('Location: '.$this->view->url('/knowledge'),true,303);}return true;
    }
}
