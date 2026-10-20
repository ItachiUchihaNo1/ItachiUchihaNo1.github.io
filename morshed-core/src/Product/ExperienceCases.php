<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Security,Audit,Problem,Upgrade};
final class ExperienceCases
{
    public const NARRATIVE=['problem'=>'مسئله و هدف','context'=>'شرایط و منابع','decisions'=>'تصمیم‌ها و دلایل انتخاب','actions'=>'اقدامات و مراحل اجرایی','challenges'=>'چالش‌ها و اصلاح مسیر','results'=>'نتایج و شواهد','lessons'=>'آموخته‌ها و توصیه‌های عملی'];
    public const PUBLIC_FIELDS=['summary','role','activity_area','period','setting','problem','context','decisions','actions','challenges','results','lessons','problem_statement','synonyms','target_audience','scale','constraints','applicability','consultation_topics'];
    public const PRIVATE_FIELDS=['source_text','claim_sources','unanswered','confidential_notes'];
    public function __construct(private Database $db,private Audit $audit){}
    public function details(string $version,bool $public=false):array
    {
        if(!Upgrade::ready($this->db)){return [];}$d=$this->db->one('SELECT * FROM m_experience_details WHERE version_id=?',[$version])??[];
        return $public?array_intersect_key($d,array_flip(array_keys(self::NARRATIVE))):$d;
    }
    public function missing(array $d):array
    {
        $out=[];foreach(['problem','context','actions','results','lessons'] as $k){if(trim($d[$k]??'')===''){$out[]=self::NARRATIVE[$k];}}
        if(($d['visibility']??'')!=='public'){$out[]='مجوز نمایش عمومی';}return $out;
    }
    private static function field(mixed $value,string $label,int $max,int $min=0):string
    {
        if(!is_string($value)||!preg_match('//u',$value)){throw new Problem('INVALID_INPUT','فیلد «'.$label.'» باید متن معتبر باشد.');}
        $value=trim($value);$length=preg_match_all('/./us',$value);
        if($length<$min||$length>$max||strlen($value)>60000){throw new Problem('INVALID_INPUT','فیلد «'.$label.'» باید '.($min?'بین '.$min.' و ':'حداکثر ').$max.' نویسه و حداکثر ۶۰ کیلوبایت باشد.');}return $value;
    }
    public function save(array $a,array $input):array
    {
        if(!Upgrade::ready($this->db)){throw new Problem('UPGRADE_REQUIRED','ابتدا به‌روزرسانی دیتابیس لازم است.',503);}
        return $this->db->tx(function()use($a,$input){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$policy=new Policy($this->db);$admin=($a['realm']??'')==='admin';
            if($admin){$policy->admin($a);}else{$m=$policy->mentor($a);if(!$m['accepted_at']){throw new Problem('INVITE_NOT_ACCEPTED','دعوت باید پذیرفته شود.',409);}}
            $eid=($input['experience_id']??'')?:Security::id();$e=$this->db->one('SELECT * FROM m_experiences WHERE id=? FOR UPDATE',[$eid]);
            $mentor=$e['mentor_id']??($admin?($input['mentor_id']??''):$a['user_id']);
            if(!$admin&&$mentor!==$a['user_id']){throw new Problem('ACCESS_DENIED','تجربه متعلق به شما نیست.',403);}
            if(!$this->db->one("SELECT m.user_id FROM m_mentors m JOIN m_users u ON u.id=m.user_id WHERE m.user_id=? AND m.status='active' AND u.status='active'",[$mentor])){throw new Problem('INVALID_MENTOR','مرشد را از پرونده‌های مرشد انتخاب کنید.');}
            $latest=$this->db->one('SELECT * FROM m_experience_versions WHERE experience_id=? ORDER BY version_no DESC LIMIT 1 FOR UPDATE',[$eid]);
            if(($input['base_version_id']??'')!==($latest['id']??'')){throw new Problem('STALE_VERSION','نسخه تغییر کرده است؛ صفحه را دوباره باز کنید.',409);}
            $title=self::field($input['title']??'','عنوان تجربه',180,1);$term=($input['term_id']??'')?:null;if($term&&!$this->db->one('SELECT id FROM m_terms WHERE id=? AND active=1',[$term])){throw new Problem('INVALID_TOPIC','موضوع معتبر نیست.');}
            $d=[];foreach([...self::PUBLIC_FIELDS,...self::PRIVATE_FIELDS] as $k){$labels=['summary'=>'خلاصه و شناسنامه','role'=>'نقش مرشد','activity_area'=>'حوزه فعالیت','period'=>'زمان اجرا','setting'=>'بستر اجرا','source_text'=>'متن خام مصاحبه'];$d[$k]=self::field($input[$k]??'',$labels[$k]??(self::NARRATIVE[$k]??$k),$k==='source_text'?30000:10000);}
            $d['outcome']=Policy::choice($input['outcome']??'unknown',['success','failure','mixed','unknown']);$d['visibility']=Policy::choice($input['visibility']??'private',['private','public']);$d['method']='manual';
            $required=array_filter(['problem','context','actions','results','lessons'],fn($k)=>$d[$k]==='');$d['quality_level']=$required?'incomplete':(($d['claim_sources']!==''&&$d['challenges']!==''&&$d['decisions']!=='')?'rich':'ready');
            $d['owner_approved_at']=(!$admin&&($input['owner_approve']??'')==='1')?time():null;$d['updated_by']=$a['user_id'];$d['updated_at']=time();
            $body=$d['summary']."\n".$d['problem_statement']."\n".$d['synonyms']."\n".$d['target_audience']."\n".$d['applicability']."\n".implode("\n\n",array_map(fn($k)=>self::NARRATIVE[$k]."\n".$d[$k],array_keys(self::NARRATIVE)));
            if(strlen($body)>480000){throw new Problem('TOO_LARGE','مجموع متن قابل جست‌وجوی تجربه از ظرفیت ۴۸۰ کیلوبایت بیشتر است؛ متن خام طولانی را در بخش منابع خصوصی قرار دهید. هیچ تغییری ذخیره نشد.');}
            if(!$e){$this->db->run('INSERT INTO m_experiences(id,mentor_id,created_at) VALUES(?,?,?)',[$eid,$mentor,time()]);}
            // Reviewed, submitted and public versions are immutable; edits start a new revision.
            $id=Security::id();
            {$this->db->run('INSERT INTO m_experience_versions(id,experience_id,version_no,title,body,term_id,review_note,created_at) VALUES(?,?,?,?,?,?,?,?)',[$id,$eid,($latest?(int)$latest['version_no']:0)+1,$title,$body,$term,'',time()]);}
            $columns=array_keys($d);$this->db->run('INSERT INTO m_experience_details(version_id,'.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns)+1,'?')).') ON DUPLICATE KEY UPDATE '.implode(',',array_map(fn($k)=>$k.'=VALUES('.$k.')',$columns)),[$id,...array_values($d)]);
            $assets=$input['asset_ids']??($latest?array_column($this->db->all('SELECT asset_id FROM m_experience_assets WHERE version_id=?',[$latest['id']]),'asset_id'):[]);if(!is_array($assets)||count($assets)>5){throw new Problem('INVALID_FILE','حداکثر پنج مستند مجاز است.');}foreach($assets as $asset){if(!is_string($asset)||!$this->db->one('SELECT id FROM m_content_assets WHERE id=? AND owner_id=?',[$asset,$mentor])){throw new Problem('ACCESS_DENIED','مستند متعلق به مرشد نیست.',403);}}$this->db->run('DELETE FROM m_experience_assets WHERE version_id=?',[$id]);foreach(array_unique($assets) as $asset){$this->db->run('INSERT INTO m_experience_assets VALUES(?,?)',[$id,$asset]);}$this->audit->add($a['user_id'],$admin?'admin':'site','experience.structured_saved',$id);return ['id'=>$id,'experience_id'=>$eid];
        });
    }
    public function submit(array $a,string $id):void
    {
        $this->db->tx(function()use($a,$id){$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');(new Policy($this->db))->admin($a);$v=$this->db->one('SELECT status FROM m_experience_versions WHERE id=? FOR UPDATE',[$id]);if(!$v||$v['status']!=='draft'){throw new Problem('INVALID_STATE','فقط پیش‌نویس به بررسی ارسال می‌شود.',409);}$this->db->run("UPDATE m_experience_versions SET status='submitted' WHERE id=?",[$id]);$this->audit->add($a['user_id'],'admin','experience.submitted',$id);});
    }
    public function ownerApprove(array $a,string $id):void
    {
        $this->db->tx(function()use($a,$id){$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');(new Policy($this->db))->mentor($a);$v=$this->db->one('SELECT e.mentor_id,v.status FROM m_experience_versions v JOIN m_experiences e ON e.id=v.experience_id WHERE v.id=? FOR UPDATE',[$id]);
            if(!$v||$v['mentor_id']!==$a['user_id']||!in_array($v['status'],['draft','submitted'],true)){throw new Problem('ACCESS_DENIED','نسخه قابل تأیید نیست.',403);}
            if(!$this->details($id)){throw new Problem('NOT_FOUND','پرونده ساختاریافته وجود ندارد.',404);}$this->db->run('UPDATE m_experience_details SET owner_approved_at=? WHERE version_id=?',[time(),$id]);$this->audit->add($a['user_id'],'site','experience.owner_approved',$id);
        });
    }
    public function listing(array $d):array
    {
        $q=Policy::query($d['q']??'');$where='(v.title LIKE ? OR v.body LIKE ? OR u.display_name LIKE ?)';$args=[$q,$q,$q];
        foreach(['status'=>'v.status','mentor_id'=>'e.mentor_id','term_id'=>'v.term_id'] as $key=>$column){if(!empty($d[$key])){$where.=' AND '.$column.'=?';$args[]=Policy::text($d[$key],32);}}
        foreach(['from'=>'>=','to'=>'<='] as $key=>$op){if(!empty($d[$key])){$date=Policy::text($d[$key],10);$time=strtotime($date.' UTC');if(!$time){throw new Problem('INVALID_INPUT','تاریخ معتبر نیست.');}$where.=' AND v.created_at'.$op.'?';$args[]=$time+($key==='to'?86399:0);}}
        if(Upgrade::ready($this->db)){foreach(['method','outcome','target_audience'] as $key){if(!empty($d[$key])){$where.=' AND x.'.$key.($key==='target_audience'?' LIKE ?':'=?');$args[]=$key==='target_audience'?Policy::query($d[$key]):Policy::text($d[$key],40);}}$join=' LEFT JOIN m_experience_details x ON x.version_id=v.id';$fields=',x.quality_level,x.method,x.updated_at';}else{$join='';$fields='';}
        $page=Policy::integer($d['page']??1,1,10000);return $this->db->all('SELECT v.*,e.mentor_id,e.public_version_id,u.display_name'.$fields.' FROM m_experiences e JOIN m_experience_versions v ON v.experience_id=e.id AND v.version_no=(SELECT MAX(vv.version_no) FROM m_experience_versions vv WHERE vv.experience_id=e.id) JOIN m_users u ON u.id=e.mentor_id'.$join.' WHERE '.$where.' ORDER BY v.created_at DESC LIMIT 51 OFFSET '.(($page-1)*50),$args);
    }
}
