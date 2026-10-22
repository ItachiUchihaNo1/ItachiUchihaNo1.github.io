<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Problem,Security,Audit};

/** Four independent, versioned dimensions. Existing terms and reviewed attributions remain canonical. */
final class Classifications
{
    public const FAMILIES=['age'=>'گروه سنی','role'=>'نقش یا موقعیت','community'=>'جامعه یا شرایط مشترک'];
    public function __construct(private Database $db){}
    public function storage():void
    {
        if($this->db->featureReady('classification20')){return;}
        (new Taxonomy($this->db))->storage();(new AtlasData($this->db))->storage();
        try{$this->db->run('SELECT id FROM m_classification_four_state LIMIT 0');}
        catch(\PDOException $e){
            if((int)($e->errorInfo[1]??0)!==1146||$this->db->pdo->inTransaction()){throw $e;}
            foreach(explode(';',file_get_contents(__DIR__.'/../../database/classification20.sql')) as $sql){if(trim($sql)!==''){$this->db->run($sql);}}
            $this->db->tx(function(){
                $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');
                if(!(int)$this->db->run('SELECT COUNT(*) FROM m_org_types')->fetchColumn()){
                    $types=['mosque'=>'مسجد','heyat'=>'هیئت','cultural'=>'کانون یا مرکز فرهنگی','institute'=>'مؤسسه فرهنگی','academy'=>'آموزشگاه یا آموزشکده','school'=>'مدرسه','pupil_group'=>'تشکل دانش‌آموزی','student_group'=>'تشکل دانشجویی','jihadi'=>'گروه جهادی','local_group'=>'گروه یا تشکل محلی','charity'=>'خیریه یا گروه حمایتی','ngo'=>'سازمان مردم‌نهاد','art_group'=>'گروه یا مجموعه هنری','media'=>'گروه یا مجموعه رسانه‌ای','sport'=>'مجموعه ورزشی','business'=>'کسب‌وکار یا تعاونی','network'=>'شبکه یا ائتلاف مجموعه‌ها','informal'=>'گروه مستقل یا غیررسمی','other'=>'سایر'];
                    foreach($types as $id=>$title){$this->db->run('INSERT IGNORE INTO m_org_types(id,title,description) VALUES(?,?,?)',[$id,$title,'']);}
                }
                foreach($this->db->all('SELECT DISTINCT org_type FROM m_organization_profiles') as $row){$id=$row['org_type'];$this->db->run('INSERT IGNORE INTO m_org_types(id,title,description) VALUES(?,?,?)',[$id,'نوع قبلی: '.$id,'مقدار تاریخی؛ نیازمند بررسی مدیر']);}
                $this->db->run('INSERT IGNORE INTO m_organization_type_links SELECT org_id,org_type FROM m_organization_profiles');
                if(!(int)$this->db->run('SELECT COUNT(*) FROM m_audiences')->fetchColumn()){
                    $groups=['age'=>['خردسال','کودک','نوجوان','جوان','بزرگسال','سالمند'],'role'=>['دانش‌آموز','دانشجو','والدین','زوج‌ها','خانواده‌ها','مربیان و معلمان','مدیران و مسئولان مجموعه‌ها','فعالان و داوطلبان'],'community'=>['عموم مردم','ساکنان یک محله','ساکنان روستا','افراد دارای معلولیت','خانواده‌های کم‌برخوردار']];
                    foreach($groups as $family=>$titles){foreach($titles as $order=>$title){$this->db->run('INSERT IGNORE INTO m_audiences VALUES(?,?,?,?,?,?)',[substr(hash('sha256','morshed-audience:'.$family.':'.$title),0,32),$title,$family,'',1,$order]);}}
                }
                if(!(int)$this->db->run("SELECT COUNT(*) FROM m_terms WHERE kind='topic'")->fetchColumn()){
                    foreach(['آموزش و یادگیری','تربیت و رشد فردی','دین و معارف','فرهنگ و هویت','هنر','رسانه و ارتباطات','کتاب و مطالعه','خانواده و فرزندپروری','خدمات اجتماعی و حمایت از نیازمندان','سلامت و بهداشت','ورزش و تفریح','اشتغال و کارآفرینی','علم و فناوری','محیط‌زیست','عمران و آبادانی'] as $title){$this->db->run("INSERT INTO m_terms(id,kind,title) VALUES(?,'topic',?)",[Security::id(),$title]);}(new ClassificationDetails($this->db))->rebuildPaths();
                }
                if(!(int)$this->db->run("SELECT COUNT(*) FROM m_terms WHERE kind='issue'")->fetchColumn()){
                    foreach(['راه‌اندازی','نیازسنجی و طراحی','جذب و نگهداشت مخاطب','جذب و سازمان‌دهی نیرو','مدیریت و راهبری','تأمین منابع و امکانات','طراحی و تولید محتوا','برنامه‌ریزی و اجرا','معرفی و اطلاع‌رسانی','همکاری و شبکه‌سازی','حل تعارض و عبور از بحران','ارزیابی و بهبود','توسعه و تکثیر','احیا و بازفعال‌سازی'] as $title){$this->db->run("INSERT INTO m_terms(id,kind,title) VALUES(?,'issue',?)",[Security::id(),$title]);}
                }
            });
            $this->db->run('CREATE TABLE IF NOT EXISTS m_classification_four_state(id TINYINT PRIMARY KEY) ENGINE=InnoDB');
        }$this->db->markFeatureReady('classification20');
    }
    public function types(bool $all=false):array
    {$this->storage();return $this->db->all('SELECT t.*,(SELECT COUNT(*) FROM m_organization_type_links x WHERE x.type_id=t.id) AS usage_count FROM m_org_types t'.($all?'':' WHERE t.active=1').' ORDER BY t.display_order,t.title');}
    public function audiences(bool $all=false):array
    {$this->storage();return $this->db->all('SELECT a.*,(SELECT COUNT(DISTINCT v.experience_id) FROM m_experience_audiences x JOIN m_experience_versions v ON v.id=x.version_id WHERE x.audience_id=a.id) AS usage_count FROM m_audiences a'.($all?'':' WHERE a.active=1').' ORDER BY a.family,a.display_order,a.title');}
    public function saveCatalog(array $actor,string $kind,array $d):string
    {
        (new Policy($this->db))->admin($actor);$this->storage();$table=match($kind){'organization-types'=>'m_org_types','audiences'=>'m_audiences',default=>throw new Problem('INVALID_INPUT','فهرست معتبر نیست.')};
        return $this->db->tx(function()use($actor,$kind,$table,$d){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$id=($d['id']??'')?:($kind==='audiences'?Security::id():substr(Security::id(),0,24));
            if(!is_string($id)||!preg_match($kind==='audiences'?'/^[a-f0-9]{32}$/D':'/^[a-z0-9_]{1,24}$/D',$id)){throw new Problem('INVALID_INPUT','شناسه معتبر نیست.');}
            $old=$this->db->one("SELECT * FROM $table WHERE id=?",[$id]);$title=Policy::text($d['title']??'',640);if(preg_match_all('/./us',$title)>160){throw new Problem('INVALID_INPUT','عنوان بیش از حد طولانی است.');}
            $description=Policy::text($d['description']??'',6000,0);$active=Policy::integer($d['active']??1,0,1);$order=Policy::integer($d['display_order']??0,0,1000000);
            if($kind==='audiences'){$family=Policy::choice($d['family']??$old['family']??'age',array_keys(self::FAMILIES));$dup=$this->db->one("SELECT id FROM $table WHERE family=? AND title=? AND id<>?",[$family,$title,$id]);}
            else{$dup=$this->db->one("SELECT id FROM $table WHERE title=? AND id<>?",[$title,$id]);}
            if($dup){throw new Problem('DUPLICATE_CLASSIFICATION','این عنوان قبلاً ثبت شده است.',409);}
            if($old){$this->db->run("UPDATE $table SET title=?,description=?,active=?,display_order=?".($kind==='audiences'?',family=?':'').' WHERE id=?',[$title,$description,$active,$order,...($kind==='audiences'?[$family]:[]),$id]);}
            else{$this->db->run("INSERT INTO $table(id,title,description,active,display_order".($kind==='audiences'?',family':'').') VALUES('.implode(',',array_fill(0,$kind==='audiences'?6:5,'?')).')',[$id,$title,$description,$active,$order,...($kind==='audiences'?[$family]:[])]);}
            (new Audit($this->db))->add($actor['user_id'],'admin','classification.'.$kind.'.saved',$id);return $id;
        });
    }
    public function version(string $id):array
    {
        $this->storage();$problems=$this->db->all('SELECT * FROM m_experience_problems WHERE version_id=? ORDER BY display_order,id',[$id]);
        $tags=$this->db->all('SELECT p.problem_id,t.id,t.title,p.is_primary,t.active FROM m_problem_tags p LEFT JOIN m_term_redirects r ON r.source_id=p.term_id JOIN m_terms t ON t.id=COALESCE(r.target_id,p.term_id) WHERE p.version_id=? ORDER BY p.is_primary DESC,t.title',[$id]);
        foreach($problems as &$p){$p['tags']=array_values(array_filter($tags,fn($t)=>$t['problem_id']===$p['id']));}unset($p);
        return ['problems'=>$problems,'audiences'=>$this->db->all('SELECT a.* FROM m_experience_audiences x JOIN m_audiences a ON a.id=x.audience_id WHERE x.version_id=? ORDER BY a.family,a.display_order,a.title',[$id]),'context'=>$this->db->one('SELECT * FROM m_experience_context WHERE version_id=?',[$id])??[],'proposals'=>$this->db->all('SELECT id,type_id,name,status FROM m_org_proposals WHERE version_id=?',[$id])];
    }
    private function allowed(string $table,string $id,?string $oldVersion,string $relation,string $column):bool
    {return (bool)$this->db->one("SELECT id FROM $table WHERE id=? AND (active=1 OR EXISTS(SELECT 1 FROM $relation x WHERE x.version_id=? AND x.$column=$table.id))",[$id,$oldVersion??'']);}
    public function assign(string $version,?string $previous,array $d,array $actor):void
    {
        $old=$previous?$this->version($previous):['problems'=>[],'audiences'=>[],'context'=>[],'proposals'=>[]];
        $rows=$d['problems']??(isset($d['_problems_present'])?[]:$old['problems']);
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>30){throw new Problem('INVALID_PROBLEM','حداکثر سی مسئله مجاز است.');}$seen=[];
        foreach($rows as $order=>$row){
            if($row instanceof \stdClass){$row=(array)$row;}if(!is_array($row)){throw new Problem('INVALID_PROBLEM','مسئله معتبر نیست.');}$title=Policy::text($row['title']??'',1200,0);$description=Policy::text($row['description']??'',12000,0);
            if($title===''&&$description===''&&empty($row['tag_id'])){continue;}if($title===''||preg_match_all('/./us',$title)>300){throw new Problem('INVALID_PROBLEM','عنوان مشخص مسئله لازم و حداکثر ۳۰۰ نویسه است.');}
            $id=($row['id']??'')?:Security::id();if(!is_string($id)||!Security::validId($id)||isset($seen[$id])){throw new Problem('INVALID_PROBLEM','شناسهٔ مسئله معتبر نیست.');}$seen[$id]=true;
            $prior=null;foreach($old['problems'] as $p){if($p['id']===$id){$prior=$p;break;}}
            if(!empty($row['id'])&&!$prior){throw new Problem('INVALID_PROBLEM','مسئله متعلق به این تجربه نیست.');}
            $primary=$row['tag_id']??($prior['tags'][0]['id']??'');$tagIds=array_column($prior['tags']??[],'id');
            if(!is_string($primary)||!Security::validId($primary)){throw new Problem('INVALID_PROBLEM','برای هر مسئله یک برچسب اصلی انتخاب کنید.');}
            // Existing secondary tags survive edits; a changed primary replaces only the previous primary.
            if($prior){$tagIds=array_column(array_filter($prior['tags'],fn($t)=>!$t['is_primary']),'id');}$tagIds[]=$primary;
            foreach(array_unique($tagIds) as $tag){$term=$this->db->one("SELECT id,active FROM m_terms WHERE id=? AND kind='issue'",[$tag]);if(!$term||(!$term['active']&&!in_array($tag,array_column($prior['tags']??[],'id'),true))){throw new Problem('INVALID_PROBLEM','برچسب مسئله معتبر نیست.');}}
            $this->db->run('INSERT INTO m_experience_problems VALUES(?,?,?,?,?)',[$version,$id,$title,$description,$order]);foreach(array_unique($tagIds) as $tag){$this->db->run('INSERT INTO m_problem_tags VALUES(?,?,?,?)',[$version,$id,$tag,$tag===$primary?1:0]);}
        }
        $aud=$d['audiences']??(isset($d['_audiences_present'])?[]:array_column($old['audiences'],'id'));
        if(!is_array($aud)||!array_is_list($aud)||count($aud)>60){throw new Problem('INVALID_AUDIENCE','مخاطبان معتبر نیستند.');}
        foreach(array_unique($aud) as $id){if(!is_string($id)||!Security::validId($id)||!$this->allowed('m_audiences',$id,$previous,'m_experience_audiences','audience_id')){throw new Problem('INVALID_AUDIENCE','گروه مخاطب معتبر نیست.');}$this->db->run('INSERT INTO m_experience_audiences VALUES(?,?)',[$version,$id]);}
        if(!isset($d['_organization_present'])){
            if($old['context']){$this->db->run('INSERT INTO m_experience_context VALUES(?,?,?)',[$version,$old['context']['org_type_id'],$old['context']['independent']]);}
            foreach($old['proposals'] as $p){if($p['status']==='proposed'){$this->propose($version,$p['type_id'],$p['name'],$actor);}}return;
        }
        $mode=Policy::choice($d['organization_mode']??'unspecified',['unspecified','independent','existing','proposed']);$type=$d['org_type']??'';$org=$d['organization_id']??'';
        if(!is_string($type)||strlen($type)>24||!is_string($org)){throw new Problem('INVALID_ORGANIZATION','نوع یا مجموعه معتبر نیست.');}
        if($type!==''&&!$this->db->one('SELECT id FROM m_org_types WHERE id=? AND (active=1 OR id=?)',[$type,$old['context']['org_type_id']??''])){throw new Problem('INVALID_ORGANIZATION','نوع مجموعه معتبر نیست.');}
        if(in_array($mode,['existing','proposed'],true)&&$type===''){throw new Problem('INVALID_ORGANIZATION','ابتدا نوع مجموعه را انتخاب کنید.');}
        if($mode==='independent'&&($type!==''||$org!=='')){throw new Problem('INVALID_ORGANIZATION','تجربهٔ مستقل نباید به مجموعه متصل باشد.');}
        $this->db->run('INSERT INTO m_experience_context VALUES(?,?,?)',[$version,$type?:null,$mode==='independent'?1:0]);
        if(in_array($mode,['independent','existing','proposed'],true)){
            // Only the new draft's selected association is replaced; reviewed historical versions remain immutable.
            $this->db->run('DELETE FROM m_experience_organizations WHERE version_id=?',[$version]);
        }
        if($mode==='existing'){
            $record=$this->db->one('SELECT o.id FROM m_organizations o JOIN m_organization_profiles p ON p.org_id=o.id WHERE o.id=? AND p.org_type=? AND o.active=1 AND p.merged_into IS NULL',[$org,$type]);if(!$record){throw new Problem('INVALID_ORGANIZATION','مجموعه باید متعلق به نوع انتخاب‌شده باشد.');}
            $approved=$previous?$this->db->one("SELECT * FROM m_experience_organizations WHERE version_id=? AND org_id=? AND status='approved'",[$previous,$org]):null;
            $this->db->run('INSERT INTO m_experience_organizations VALUES(?,?,?,?,?,?,?,?,?,?)',[$version,$org,$approved['relation_type']??'formed_at',1,$approved?'approved':'proposed',$approved['raw_name']??'',$approved['internal_note']??'',$approved['reviewed_by']??null,$approved['reviewed_at']??null,$approved['evidence']??'']);
        }elseif($mode==='proposed'){$this->propose($version,$type,Policy::text($d['organization_name']??'',720),$actor);}
    }
    private function propose(string $version,string $type,string $name,array $a):void
    {if(preg_match_all('/./us',$name)>180){throw new Problem('INVALID_ORGANIZATION','نام مجموعه حداکثر ۱۸۰ نویسه است.');}$this->db->run('INSERT INTO m_org_proposals(id,version_id,type_id,name,created_by,created_at) VALUES(?,?,?,?,?,?)',[Security::id(),$version,$type,$name,$a['user_id'],time()]);}
    public function reviewProposal(array $a,array $d):void
    {
        (new Policy($this->db))->admin($a,true);$this->storage();$this->db->tx(function()use($a,$d){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$id=Policy::text($d['id']??'',32);$p=$this->db->one("SELECT * FROM m_org_proposals WHERE id=? AND status='proposed' FOR UPDATE",[$id]);if(!$p){throw new Problem('NOT_FOUND','پیشنهاد رسیدگی‌نشده پیدا نشد.',404);}
            $decision=Policy::choice($d['decision']??'',['approved','rejected']);$org=null;
            if($decision==='approved'){$org=Policy::text($d['org_id']??'',32);if(!$this->db->one('SELECT o.id FROM m_organizations o JOIN m_organization_profiles p ON p.org_id=o.id WHERE o.id=? AND o.active=1 AND p.org_type=? AND p.verified_at IS NOT NULL AND p.merged_into IS NULL',[$org,$p['type_id']])){throw new Problem('INVALID_ORGANIZATION','ابتدا مجموعهٔ هم‌نوع را ایجاد و تأیید یا از موارد موجود انتخاب کنید.');}
                $this->db->run("INSERT INTO m_experience_organizations VALUES(?,?,'formed_at',1,'approved',?,'',?,?,'') ON DUPLICATE KEY UPDATE status='approved',reviewed_by=VALUES(reviewed_by),reviewed_at=VALUES(reviewed_at)",[$p['version_id'],$org,$p['name'],$a['user_id'],time()]);
            }
            $this->db->run('UPDATE m_org_proposals SET status=?,resolved_org_id=?,reviewed_by=?,reviewed_at=? WHERE id=?',[$decision,$org,$a['user_id'],time(),$id]);(new Audit($this->db))->add($a['user_id'],'admin','organization.proposal.'.$decision,$id);
        });
    }
    public function publication(string $version):void
    {
        $labels=(new Taxonomy($this->db))->labels([$version])[$version]??[];
        if(empty($labels['topics'])){throw new Problem('TOPIC_REQUIRED','برای انتشار تجربه حداقل یک موضوع مرتبط لازم است.',409);}
    }
}
