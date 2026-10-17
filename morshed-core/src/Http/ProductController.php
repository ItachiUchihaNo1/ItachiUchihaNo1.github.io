<?php
declare(strict_types=1);
namespace Morshed\Http;
use Morshed\Core\{Database,Audit,Problem};
use Morshed\Product\{Content,Consultations,Operations,Policy,Delivery,PrivateFiles,ContentAssets,Branding};
final class ProductController
{
    private Content $content;private Consultations $consult;private Operations $ops;private Policy $policy;private string $currentPath='';
    public function __construct(private Database $db,private View $view,private ?array $actor,private string $realm,private bool $api)
    {$audit=new Audit($db);$this->content=new Content($db,$audit);$this->consult=new Consultations($db,$audit);$this->ops=new Operations($db,$audit);$this->policy=new Policy($db);}
    private function actor():array
    {if(!$this->actor){if(!$this->api&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){$_SESSION['return_to']=(is_string($_GET['r']??null)?$_GET['r']:(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/account')).(preg_match('/^[a-f0-9]{32}$/D',is_string($_GET['id']??null)?$_GET['id']:'')?'?id='.$_GET['id']:'');header('Location: '.$this->view->url('/login'),true,303);exit;}throw new Problem('UNAUTHENTICATED','ابتدا وارد حساب شوید.',401);}return $this->actor;}
    private function value(array $d,string $key,string $default=''):string
    {return Policy::text($d[$key]??$default,60000,0);}
    private function page(string $title,string $template,array $data,?string $error=null):void
    {
        $lists=['/sessions','/support','/mentor/experiences','/experiences','/storefront','/bookings','/archives','/organizations','/notification-templates','/notification-deliveries','/mentors'];
        if(isset($data['rows'])&&in_array($this->currentPath,$lists,true)&&($this->realm==='admin'||in_array($this->currentPath,['/sessions','/support','/mentor/experiences'],true))){$size=$this->currentPath==='/mentors'?20:50;$data['pagination']=['page'=>Policy::integer($_GET['page']??1,1,10000),'more'=>count($data['rows'])>$size,'route'=>$this->currentPath];$data['rows']=array_slice($data['rows'],0,$size);}
        if($this->api){header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}else{$this->view->page($title,$template,$data,$error);}
    }
    private function window():string{return ' LIMIT 51 OFFSET '.((Policy::integer($_GET['page']??1,1,10000)-1)*50);}
    private function done(string $path,array $result=[]):void
    {if($this->api){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}else{$_SESSION['flash']='عملیات با موفقیت ثبت شد.';header('Location: '.$this->view->url($path),true,303);}}
    private function id():string{return is_string($_GET['id']??null)?$_GET['id']:'';}
    public function get(string $path,?string $error=null):bool
    {
        $this->currentPath=$path;foreach(['q','term','topic','issue','page','id','sort','mentor_id','date','kind','draft'] as $key){if(isset($_GET[$key])&&(!is_string($_GET[$key])||strlen($_GET[$key])>240)){throw new Problem('INVALID_INPUT','پارامتر فهرست معتبر نیست.');}}
        if($path==='/content/asset'){$f=(new ContentAssets($this->db,new Audit($this->db)))->download($this->actor,$this->id());$image=in_array($f['media_type'],['image/png','image/jpeg'],true);header('Content-Type: '.($image?$f['media_type']:'application/octet-stream'));header('Content-Disposition: '.($image?'inline':'attachment').'; filename="content-file"; filename*=UTF-8\'\''.rawurlencode($f['display_name']));header('Content-Length: '.$f['byte_size']);header('X-Content-Type-Options: nosniff');readfile($f['path']);return true;}
        if($path==='/files/download'){$f=(new PrivateFiles($this->db,new Audit($this->db)))->download($this->actor(),$this->id());header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="attachment"; filename*=UTF-8\'\''.rawurlencode($f['display_name']));header('Content-Length: '.$f['byte_size']);header('X-Content-Type-Options: nosniff');readfile($f['path']);return true;}
        if($this->realm==='site'){
            switch($path){
                case '/about':$this->page('درباره و تماس','product-about',['settings'=>Branding::settings($this->db)],$error);return true;
                case '/':$this->page('گفت‌وگو با صاحب تجربه','product-home',['blocks'=>$this->content->store(null,true),'footerSettings'=>Branding::settings($this->db)],$error);return true;
                case '/search':case '/mentors':$this->page('مرشدها','product-search',['kind'=>'mentors','rows'=>$this->content->mentors($_GET),'terms'=>$this->content->terms(),'mentorTotal'=>$this->content->mentorTotal($_GET),'experienceTotal'=>$this->content->experienceTotal($_GET)],$error);return true;
                case '/experiences':$this->page('تجربه‌ها','product-search',['kind'=>'experiences','rows'=>$this->content->experiences($_GET),'terms'=>$this->content->terms(),'mentorTotal'=>$this->content->mentorTotal($_GET),'experienceTotal'=>$this->content->experienceTotal($_GET)],$error);return true;
                case '/mentor/profile':$m=$this->content->mentor($this->id());$this->page($m['display_name'],'product-mentor',['mentor'=>$m,'experiences'=>$this->content->experiences(['mentor_id'=>$m['user_id']]),'terms'=>$this->db->all('SELECT t.title FROM m_mentor_terms mt JOIN m_terms t ON t.id=mt.term_id WHERE mt.mentor_id=? AND t.active=1',[$m['user_id']])],$error);return true;
                case '/experience':$v=$this->content->experience($this->id());$this->page($v['title'],'product-experience',['experience'=>$v,'assets'=>$this->db->all('SELECT a.id,a.display_name,a.media_type FROM m_experience_assets ea JOIN m_content_assets a ON a.id=ea.asset_id WHERE ea.version_id=?',[$v['version_id']])],$error);return true;
                case '/slots':$this->page('زمان‌های مرشد','product-slots',['slots'=>$this->consult->slots($this->value($_GET,'mentor_id'),$this->value($_GET,'date'))],$error);return true;
                case '/mentor':case '/mentor/experiences':case '/mentor/experience':case '/mentor/calendar':
                    $a=$this->actor();$m=$this->policy->mentor($a);$data=['mode'=>$path,'mentor'=>$m,'terms'=>$this->content->terms(),'ownedAssets'=>(new ContentAssets($this->db,new Audit($this->db)))->owned($a['user_id']),'selectedTerms'=>array_column($this->db->all('SELECT term_id FROM m_mentor_terms WHERE mentor_id=?',[$a['user_id']]),'term_id')];
                    if($path==='/mentor/experiences'){$data['rows']=$this->db->all('SELECT e.id,v.id AS version_id,v.title,v.status,v.version_no,e.public_version_id FROM m_experiences e JOIN m_experience_versions v ON v.experience_id=e.id WHERE e.mentor_id=? ORDER BY e.created_at DESC,v.version_no DESC'.$this->window(),[$a['user_id']]);}
                    if($path==='/mentor/experience'){$data['experience']=$this->db->one('SELECT e.id,v.id AS version_id,v.title,v.body,v.term_id FROM m_experiences e JOIN m_experience_versions v ON v.experience_id=e.id WHERE e.id=? AND e.mentor_id=? ORDER BY v.version_no DESC LIMIT 1',[$this->id(),$a['user_id']]);$data['selectedAssets']=$data['experience']?array_column($this->db->all('SELECT asset_id FROM m_experience_assets WHERE version_id=?',[$data['experience']['version_id']]),'asset_id'):[];if($this->id()&&!$data['experience']){throw new Problem('NOT_FOUND','تجربه پیدا نشد.',404);}}
                    if($path==='/mentor/calendar'){$data['settings']=$this->db->one('SELECT * FROM m_schedule_settings WHERE mentor_id=?',[$a['user_id']]);$data['rules']=$this->db->all('SELECT * FROM m_schedule_rules WHERE mentor_id=? ORDER BY weekday,start_minute',[$a['user_id']]);$data['exceptions']=$this->db->all('SELECT local_date FROM m_schedule_exceptions WHERE mentor_id=? ORDER BY local_date',[$a['user_id']]);}
                    $this->page('پنل مرشد','product-mentor-panel',$data,$error);return true;
                case '/sessions':$a=$this->actor();$this->consult->expire();$rows=$this->db->all('SELECT b.id,b.state,b.starts_at,b.ends_at,u.display_name AS mentor_name FROM m_bookings b JOIN m_users u ON u.id=b.mentor_id WHERE b.user_id=? OR b.mentor_id=? ORDER BY starts_at DESC'.$this->window(),[$a['user_id'],$a['user_id']]);$this->page('جلسات و درخواست‌ها','product-bookings',['rows'=>$rows,'admin'=>false],$error);return true;
                case '/booking':$a=$this->actor();$this->consult->expire();$b=$this->consult->booking($a,$this->id());$this->bookingPage($b,false,$error);return true;
                case '/session':$a=$this->actor();$b=$this->consult->booking($a,$this->id());$this->page('آرشیو و جلسه متنی','product-session',['booking'=>$b,'rows'=>$this->consult->archive($a,$b['id'],(int)($_GET['after']??0)),'files'=>(new PrivateFiles($this->db,new Audit($this->db)))->listing($a,$b['id'])],$error);return true;
                case '/support':$a=$this->actor();$this->page('پشتیبانی و شکایت','product-support',['admin'=>false,'rows'=>$this->db->all('SELECT * FROM m_tickets WHERE user_id=? ORDER BY updated_at DESC'.$this->window(),[$a['user_id']])],$error);return true;
                case '/ticket':$this->ticketPage($this->actor(),$this->id(),false,$error);return true;
            }return false;
        }
        $known=['/mentors','/taxonomy','/experiences','/experience','/storefront','/storefront/preview','/storefront/items','/bookings','/booking','/support','/ticket','/archives','/archive','/organizations','/reports','/general','/notification-templates','/notification-deliveries'];if(!in_array($path,$known,true)){return false;}$a=$this->actor();$this->policy->admin($a,in_array($path,['/mentors','/bookings','/booking','/support','/ticket','/archives','/organizations','/reports','/general','/notification-templates','/notification-deliveries'],true));
        switch($path){
            case '/mentors':$this->page('مدیریت مرشدها','product-admin-mentors',['rows'=>$this->content->mentors($_GET,true)],$error);break;
            case '/taxonomy':$this->page('موضوعات و مسائل','product-taxonomy',['rows'=>$this->content->terms(true)],$error);break;
            case '/experiences':$this->page('بررسی تجربه‌ها','product-review-list',['rows'=>$this->db->all('SELECT v.id,v.title,v.status,v.version_no,e.id AS experience_id,u.display_name FROM m_experience_versions v JOIN m_experiences e ON e.id=v.experience_id JOIN m_users u ON u.id=e.mentor_id ORDER BY v.created_at DESC,v.version_no DESC'.$this->window())],$error);break;
            case '/experience':$v=$this->db->one('SELECT v.*,e.public_version_id FROM m_experience_versions v JOIN m_experiences e ON e.id=v.experience_id WHERE v.id=?',[$this->id()]);if(!$v){throw new Problem('NOT_FOUND','نسخه پیدا نشد.',404);}$previous=$this->db->one('SELECT title,body,version_no FROM m_experience_versions WHERE experience_id=? AND version_no<? ORDER BY version_no DESC LIMIT 1',[$v['experience_id'],$v['version_no']]);$this->page('مقایسه و تصمیم نسخه','product-review',['version'=>$v,'previous'=>$previous,'assets'=>$this->db->all('SELECT a.id,a.display_name FROM m_experience_assets ea JOIN m_content_assets a ON a.id=ea.asset_id WHERE ea.version_id=?',[$v['id']])],$error);break;
            case '/storefront/items':if(!$this->api){throw new Problem('NOT_FOUND','صفحه پیدا نشد.',404);}$kind=Policy::choice($_GET['kind']??'', ['mentors','experiences','topics']);$items=match($kind){'mentors'=>$this->content->mentors($_GET),'experiences'=>$this->content->experiences($_GET),'topics'=>array_values(array_filter($this->content->terms(),fn($t)=>str_contains($t['title'],$_GET['q']??'')))};$this->page('انتخاب محتوای ویترین','product-home',['rows'=>$items]);break;
            case '/storefront':$this->page('مدیریت ویترین','product-store',['rows'=>$this->db->all('SELECT * FROM m_store_versions ORDER BY version_no DESC'.$this->window()),'current'=>$this->db->one('SELECT version_id FROM m_store_publication WHERE id=1'),'draftJson'=>$this->content->storeDraft($_GET['draft']??null),'storeOptions'=>['topics'=>$this->content->terms(),'mentors'=>$this->content->mentors(),'experiences'=>$this->content->experiences(),'terms'=>$this->content->terms()]],$error);break;
            case '/storefront/preview':$this->page('پیش‌نمایش ویترین','product-home',['blocks'=>$this->content->store($this->id(),true)],$error);break;
            case '/bookings':$this->consult->expire();$this->page('عملیات رزروها','product-bookings',['admin'=>true,'rows'=>$this->db->all('SELECT b.*,u.display_name AS mentor_name FROM m_bookings b JOIN m_users u ON u.id=b.mentor_id ORDER BY b.created_at DESC'.$this->window())],$error);break;
            case '/booking':$this->consult->expire();$this->bookingPage($this->consult->booking($a,$this->id(),true),true,$error);break;
            case '/support':$this->page('پشتیبانی و شکایت','product-support',['admin'=>true,'rows'=>$this->db->all('SELECT * FROM m_tickets ORDER BY updated_at DESC'.$this->window())],$error);break;
            case '/ticket':$this->ticketPage($a,$this->id(),true,$error);break;
            case '/archives':$this->page('مجوزهای نظارت موردی','product-grants',['rows'=>$this->db->all('SELECT * FROM m_archive_grants ORDER BY created_at DESC'.$this->window())],$error);break;
            case '/archive':$id=$this->id();if($id===''){if($this->api){throw new Problem('INVALID_INPUT','شناسه رزرو لازم است.');}$this->page('آرشیو دارای مجوز','product-archive-lookup',[],$error);break;}$this->page('آرشیو تحت مجوز','product-session',['booking'=>['id'=>$id],'rows'=>$this->consult->archive($a,$id,(int)($_GET['after']??0)),'files'=>(new PrivateFiles($this->db,new Audit($this->db)))->listing($a,$id)],$error);break;
            case '/organizations':$this->page('سازمان‌ها و عضویت پایه','product-organizations',['rows'=>$this->db->all('SELECT o.*,(SELECT COUNT(*) FROM m_memberships m WHERE m.org_id=o.id) AS member_count FROM m_organizations o ORDER BY name'.$this->window()),'members'=>$this->db->all('SELECT m.org_id,m.user_id,u.display_name FROM m_memberships m JOIN m_users u ON u.id=m.user_id ORDER BY m.org_id LIMIT 200')],$error);break;
            case '/general':$this->page('تنظیمات عمومی','product-general',['settings'=>Branding::settings($this->db)],$error);break;
            case '/notification-templates':$this->page('قالب‌های اعلان','product-notifications',['mode'=>'templates','rows'=>$this->db->all('SELECT * FROM m_notification_templates ORDER BY created_at DESC'.$this->window())],$error);break;
            case '/notification-deliveries':$this->page('سوابق اعلان','product-notifications',['mode'=>'deliveries','rows'=>$this->db->all('SELECT * FROM m_notification_deliveries ORDER BY created_at DESC'.$this->window())],$error);break;
            case '/reports':$stats=[];foreach(['mentors'=>"SELECT COUNT(*) FROM m_mentors WHERE status='active'",'published_experiences'=>'SELECT COUNT(*) FROM m_experiences WHERE public_version_id IS NOT NULL','open_tickets'=>"SELECT COUNT(*) FROM m_tickets WHERE state='open'",'completed_sessions'=>"SELECT COUNT(*) FROM m_bookings WHERE state='completed'"] as $key=>$sql){$stats[$key]=(int)$this->db->run($sql)->fetchColumn();}$this->page('گزارش عملیاتی','product-reports',['stats'=>$stats,'feedback'=>$this->db->all('SELECT f.*,u.display_name FROM m_feedback f JOIN m_users u ON u.id=f.user_id ORDER BY f.created_at DESC'.$this->window()),'states'=>$this->db->all('SELECT state,COUNT(*) AS total FROM m_bookings GROUP BY state')],$error);break;
        }return true;
    }
    private function bookingPage(array $b,bool $admin,?string $error):void
    {$this->page('جزئیات درخواست مشاوره','product-booking',['booking'=>$b,'admin'=>$admin,'events'=>$this->db->all('SELECT event,note,created_at FROM m_booking_events WHERE booking_id=? ORDER BY id',[$b['id']]),'proposals'=>$this->db->all('SELECT * FROM m_time_proposals WHERE booking_id=? ORDER BY created_at DESC',[$b['id']])],$error);}
    private function ticketPage(array $a,string $id,bool $admin,?string $error):void
    {$t=$this->ops->getTicket($a,$id);$this->page('پرونده پشتیبانی','product-ticket',['ticket'=>$t,'admin'=>$admin,'rows'=>$this->db->all('SELECT m.*,u.display_name FROM m_ticket_messages m JOIN m_users u ON u.id=m.author_id WHERE m.ticket_id=? ORDER BY m.id',[$id])],$error);}
    public function post(string $path,array $d):bool
    {
        $site=['/mentor/accept','/mentor/avatar','/mentor/evidence','/mentor/profile/save','/mentor/experience/save','/mentor/experience/submit','/mentor/calendar/settings','/mentor/calendar/rule','/mentor/calendar/remove','/mentor/calendar/exception','/bookings/create','/booking/action','/booking/propose','/booking/answer','/session/message','/session/files','/support/create','/ticket/reply','/feedback'];
        $admin=['/mentors/invite','/mentors/status','/taxonomy/save','/experience/review','/experience/unpublish','/storefront/save','/storefront/publish','/bookings/internal','/booking/action','/ticket/reply','/archives/grant','/archives/revoke','/organizations/save','/organizations/member','/general/save','/general/asset','/notification-templates/create','/notification-deliveries/send'];
        if(!in_array($path,$this->realm==='site'?$site:$admin,true)){return false;}
        $a=$this->actor();$result=[];$back='/';$id=$this->value($d,'id');$adminRealm=$this->realm==='admin';
        foreach($d as $k=>$v){if(is_string($k)&&is_string($v)&&!str_contains($k,'password')&&!in_array($k,['csrf','token','code'],true)){$this->view->old[$k]=$v;}}
        if(is_array($d['terms']??null)){$this->view->old['terms']=array_slice(array_filter($d['terms'],'is_string'),0,20);}
        if(is_array($d['asset_ids']??null)){$this->view->old['asset_ids']=array_slice(array_filter($d['asset_ids'],'is_string'),0,5);}
        // Pick the return page before mutation, so validation errors preserve entered fields.
        $back=match(true){$path==='/mentor/evidence'=>'/mentor/experience',str_starts_with($path,'/general/')=>'/general',str_starts_with($path,'/notification-templates/')=>'/notification-templates',str_starts_with($path,'/notification-deliveries/')=>'/notification-templates',str_starts_with($path,'/mentor/calendar')=>'/mentor/calendar',str_starts_with($path,'/mentor/experience')=>'/mentor/experience',str_starts_with($path,'/mentor/')=>'/mentor',str_starts_with($path,'/mentors/')=>'/mentors',str_starts_with($path,'/taxonomy/')=>'/taxonomy',str_starts_with($path,'/storefront/')=>'/storefront',str_starts_with($path,'/experience/')=>'/experience',str_starts_with($path,'/archives/')=>'/archives',str_starts_with($path,'/organizations/')=>'/organizations',str_starts_with($path,'/ticket/')=>'/ticket',str_starts_with($path,'/session/')=>'/session',str_starts_with($path,'/booking/')||$path==='/feedback'=>'/booking',str_starts_with($path,'/bookings/')=>$adminRealm?'/bookings':'/sessions',default=>'/support'};
        try{
            if(isset($d['starts_local'])){$d['starts_at']=Consultations::localTimestamp($this->value($d,'starts_local'));}
            switch($path){
                case '/mentor/avatar':$result=['asset_id'=>(new ContentAssets($this->db,new Audit($this->db)))->upload($a,$_FILES['attachment']??[],true)];$back='/mentor';break;
                case '/mentor/evidence':$result=['asset_id'=>(new ContentAssets($this->db,new Audit($this->db)))->upload($a,$_FILES['attachment']??[])];$back='/mentor/experience';break;
                case '/mentor/accept':$this->content->acceptInvite($a);break;
                case '/mentor/profile/save':$this->content->mentorProfile($a,$d);break;
                case '/mentor/experience/save':$result=$this->content->saveExperience($a,$d);$id=$result['experience_id'];$back='/mentor/experience';break;
                case '/mentor/experience/submit':$this->content->submit($a,$id);$back='/mentor/experiences';break;
                case '/mentor/calendar/settings':$this->consult->settings($a,$d);break;
                case '/mentor/calendar/rule':$this->consult->rule($a,$d);break;
                case '/mentor/calendar/remove':$this->consult->removeRule($a,$id);break;
                case '/mentor/calendar/exception':$this->consult->exception($a,$this->value($d,'date'),($d['remove']??'0')==='1');break;
                case '/bookings/create':$this->consult->reserve($a,$d);break;
                case '/bookings/internal':$id=$this->consult->reserve($a,$d,true);$back='/booking';$result=['id'=>$id];break;
                case '/booking/action':$this->consult->transition($a,$id,$this->value($d,'action'),$this->value($d,'note'),$adminRealm);break;
                case '/booking/propose':$result=['proposal_id'=>$this->consult->propose($a,$id,Policy::integer($d['starts_at']??'',1,4102444800))];break;
                case '/booking/answer':$this->consult->answerProposal($a,$this->value($d,'proposal_id'),($d['accept']??'0')==='1');break;
                case '/session/files':$result=['file_id'=>(new PrivateFiles($this->db,new Audit($this->db)))->upload($a,$id,$_FILES['attachment']??[])];break;
                case '/general/asset':$kind=$this->value($d,'kind');Policy::choice($kind,['footer_logo','enamad']);$asset=($d['remove']??'')==='1'?'':(new ContentAssets($this->db,new Audit($this->db)))->upload($a,$_FILES['attachment']??[],false,true);Branding::asset($this->db,new Audit($this->db),$a,$kind,$asset);$result=['asset_id'=>$asset];break;
                case '/general/save':(new Delivery($this->db,new Audit($this->db)))->settings($a,$d);break;
                case '/notification-templates/create':$result=['id'=>(new Delivery($this->db,new Audit($this->db)))->template($a,$d)];break;
                case '/notification-deliveries/send':$result=['notification_id'=>(new Delivery($this->db,new Audit($this->db)))->send($a,$d)];break;
                case '/session/message':$result=$this->consult->message($a,$id,$this->value($d,'client_key'),$this->value($d,'body'));break;
                case '/support/create':$id=$this->ops->ticket($a,$d);$back='/ticket';$result=['id'=>$id];break;
                case '/ticket/reply':$this->ops->reply($a,$id,$this->value($d,'body'),$adminRealm?$this->value($d,'state','open'):null);break;
                case '/feedback':$this->ops->feedback($a,$id,Policy::integer($d['score']??'',1,5),$this->value($d,'body'));break;
                case '/mentors/invite':$id=$this->content->invite($a,$d);break;
                case '/mentors/status':$this->content->mentorState($a,$id,$this->value($d,'status'));break;
                case '/taxonomy/save':$id=$this->content->term($a,$d);break;
                case '/experience/review':$this->content->review($a,$id,$this->value($d,'decision'),$this->value($d,'note'));break;
                case '/experience/unpublish':$this->content->unpublish($a,$id);$back='/experiences';break;
                case '/storefront/save':$this->view->old['blocks_json']=$this->value($d,'blocks_json');try{$blocks=json_decode($this->value($d,'blocks_json'),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new Problem('INVALID_JSON','ساختار بلوک‌های ویترین معتبر نیست.');}if(!is_array($blocks)||!array_is_list($blocks)){throw new Problem('INVALID_INPUT','فهرست بلوک‌ها لازم است.');}$id=$this->content->saveStore($a,$blocks);$result=['id'=>$id];$back='/storefront?draft='.$id;break;
                case '/storefront/publish':$this->content->publishStore($a,$id);break;
                case '/archives/grant':$id=$this->ops->grant($a,$d);$result=['id'=>$id];break;
                case '/archives/revoke':$this->ops->revokeGrant($a,$id);break;
                case '/organizations/save':$id=$this->ops->organization($a,$d);break;
                case '/organizations/member':$this->ops->membership($a,$this->value($d,'org_id'),$this->value($d,'user_id'),($d['remove']??'0')==='1');break;
            }
        }catch(\PDOException $e){$failure=new Problem('STORAGE_UNAVAILABLE','ذخیره انجام نشد. داده‌های فرم حفظ شده‌اند؛ پس از بررسی ارتباط دوباره تلاش کنید.',503);if($this->api){throw $failure;}http_response_code(503);$_GET['id']=$id?:($d['experience_id']??'');$this->get($back,$failure->getMessage());return true;}catch(Problem $e){if($this->api){throw $e;}http_response_code($e->status);$_GET['id']=$id?:($d['experience_id']??'');$this->get($back,$e->getMessage());return true;}
        $this->done($back.($id&&in_array($back,['/mentor/experience','/booking','/ticket','/session','/experience'],true)?'?id='.rawurlencode($id):''),$result);return true;
    }
}
