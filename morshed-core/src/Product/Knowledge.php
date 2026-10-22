<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Problem,Security,Audit};

/** Stores only new semantic assertions. Existing ownership/taxonomy/booking edges remain projections. */
final class Knowledge
{
    public const TYPES=['PROBLEM','AUDIENCE','ORGANIZATION_TYPE','MENTOR','EXPERIENCE','ISSUE','TOPIC','ORGANIZATION','USER','CONSULTATION_REQUEST','SESSION','PLACE'];
    public const RELATIONS=[
        'ISSUE_IN_TOPIC'=>['ISSUE','TOPIC',false,false],
        'TOPIC_PARENT_OF'=>['TOPIC','TOPIC',false,true],
        'ISSUE_SUBISSUE_OF'=>['ISSUE','ISSUE',false,true],
        'ISSUE_RELATED_TO_ISSUE'=>['ISSUE','ISSUE',true,false],
        'EXPERIENCE_RELATED_TO_EXPERIENCE'=>['EXPERIENCE','EXPERIENCE',true,false],
    ];
    public function __construct(private Database $db){}
    public function storage():void
    {
        if($this->db->featureReady('knowledge15')){return;}
        (new Classifications($this->db))->storage();(new AtlasData($this->db))->storage();(new Taxonomy($this->db))->storage();
        try{$this->db->run('SELECT id FROM m_interaction_policy LIMIT 0');}
        catch(\PDOException $e){if((int)($e->errorInfo[1]??0)!==1146){throw $e;}if($this->db->pdo->inTransaction()){throw new \LogicException('Prepare knowledge storage before transaction.');}foreach(explode(';',file_get_contents(__DIR__.'/../../database/knowledge15.sql')) as $sql){if(trim($sql)!==''){$this->db->run($sql);}}}
        $this->db->markFeatureReady('knowledge15');
    }
    public function entity(string $type,string $id,bool $public=false):bool
    {
        if(!in_array($type,self::TYPES,true)||strlen($id)>80){return false;}
        if(in_array($type,['ISSUE','TOPIC'],true)){$id=(new Taxonomy($this->db))->resolve($id);}
        if($public&&in_array($type,['USER','CONSULTATION_REQUEST','SESSION'],true)){return false;}
        return match($type){
            'AUDIENCE'=>(bool)$this->db->one('SELECT id FROM m_audiences WHERE id=?'.($public?' AND active=1':''),[$id]),
            'ORGANIZATION_TYPE'=>(bool)$this->db->one('SELECT id FROM m_org_types WHERE id=?'.($public?' AND active=1':''),[$id]),
            'PROBLEM'=>$this->problemEntity($id,$public),
            'MENTOR'=>(bool)$this->db->one("SELECT m.user_id FROM m_mentors m JOIN m_users u ON u.id=m.user_id WHERE m.user_id=?".($public?" AND m.status='active' AND u.status='active'":''),[$id]),
            'EXPERIENCE'=>(bool)$this->db->one($public?'SELECT e.id'.AtlasData::publicBase().' WHERE e.id=?':'SELECT id FROM m_experiences WHERE id=?',[$id]),
            'ISSUE','TOPIC'=>(bool)$this->db->one('SELECT id FROM m_terms WHERE id=? AND kind=? AND active=1',[$id,strtolower($type)]),
            'ORGANIZATION'=>$public?$this->publicOrganization($id):(bool)$this->db->one('SELECT id FROM m_organizations WHERE id=?',[$id]),
            'PLACE'=>(bool)$this->db->one('SELECT id FROM m_places WHERE id=?',[$id]),
            'USER'=>(bool)$this->db->one('SELECT id FROM m_users WHERE id=?',[$id]),
            'CONSULTATION_REQUEST','SESSION'=>(bool)$this->db->one('SELECT id FROM m_bookings WHERE id=?',[$id]),
            default=>false,
        };
    }
    private function problemEntity(string $id,bool $public):bool
    {
        if(!preg_match('/^([a-f0-9]{32}):([a-f0-9]{32})$/D',$id,$parts)){return false;}
        return (bool)$this->db->one('SELECT p.id FROM m_experience_problems p'.($public?' JOIN m_experiences e ON e.public_version_id=p.version_id JOIN m_mentors m ON m.user_id=e.mentor_id AND m.status=\'active\' JOIN m_users u ON u.id=m.user_id AND u.status=\'active\'':'').' WHERE p.version_id=? AND p.id=?',[$parts[1],$parts[2]]);
    }
    private function publicOrganization(string $id):bool
    {try{return (new AtlasData($this->db))->organization($id)['organization']['id']===$id;}catch(Problem $e){if($e->status!==404){throw $e;}return false;}}
    private function history(array $a,string $id,string $action,array $before,array $after):void
    {$this->db->run('INSERT INTO m_knowledge_history VALUES(?,?,?,?,?,?,?)',[Security::id(),$id,$a['user_id'],$action,json_encode($before,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($after,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),time()]);(new Audit($this->db))->add($a['user_id'],'admin',$action,$id);}
    public function propose(array $a,array $d):string
    {
        (new Policy($this->db))->admin($a);$this->storage();
        return $this->db->tx(function()use($a,$d){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$kind=Policy::choice($d['relation_type']??'',array_keys(self::RELATIONS));[$fromType,$toType,$symmetric]=self::RELATIONS[$kind];
            $from=Policy::text($d['from_id']??'',80);$to=Policy::text($d['to_id']??'',80);
            if(in_array($fromType,['ISSUE','TOPIC'],true)){$from=(new Taxonomy($this->db))->resolve($from);}if(in_array($toType,['ISSUE','TOPIC'],true)){$to=(new Taxonomy($this->db))->resolve($to);}
            if($from===$to||!$this->entity($fromType,$from)||!$this->entity($toType,$to)){throw new Problem('INVALID_RELATION','دو موجودیت معتبر و متفاوت انتخاب کنید.');}
            if($symmetric&&strcmp($from,$to)>0){[$from,$to]=[$to,$from];}
            $origin=Policy::choice($d['origin']??'admin',['admin','system','mentor','ai','import']);$visibility=Policy::choice($d['visibility']??'private',['public','private']);
            if($visibility==='public'&&(!$this->entity($fromType,$from,true)||!$this->entity($toType,$to,true))){throw new Problem('PRIVATE_ENDPOINT','رابطه با موجودیت غیرعمومی نمی‌تواند عمومی باشد.');}
            $reason=Policy::text($d['reason']??'',1000,1);$evidence=Policy::text($d['evidence']??'',500,0);$confidence=$d['confidence']??'';
            if($confidence===''){$confidence=null;}elseif(!is_numeric($confidence)||(float)$confidence<0||(float)$confidence>1){throw new Problem('INVALID_CONFIDENCE','اطمینان باید بین صفر و یک باشد.');}
            if($this->db->one('SELECT id FROM m_knowledge_relations WHERE relation_type=? AND from_id=? AND to_id=?',[$kind,$from,$to])){throw new Problem('DUPLICATE_RELATION','این رابطه قبلاً ثبت شده است.',409);}
            $id=Security::id();$this->db->run('INSERT INTO m_knowledge_relations(id,relation_type,from_type,from_id,to_type,to_id,origin,status,visibility,evidence,reason,confidence,created_by,created_at) VALUES(?,?,?,?,?,?,?,\'proposed\',?,?,?,?,?,?)',[$id,$kind,$fromType,$from,$toType,$to,$origin,$visibility,$evidence,$reason,$confidence,$a['user_id'],time()]);
            foreach([[$fromType,$from],[$toType,$to]] as [$type,$ref]){$this->db->run('INSERT IGNORE INTO m_knowledge_entities VALUES(?,?,?)',[$type,$ref,time()]);}
            $this->history($a,$id,'knowledge.proposed',[],['type'=>$kind,'from'=>$from,'to'=>$to,'origin'=>$origin]);return $id;
        });
    }
    public function review(array $a,string $id,string $status,int $revision):void
    {
        (new Policy($this->db))->admin($a);$this->storage();Policy::choice($status,['approved','rejected','revoked']);
        $this->db->tx(function()use($a,$id,$status,$revision){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$r=$this->db->one('SELECT * FROM m_knowledge_relations WHERE id=? FOR UPDATE',[$id]);if(!$r){throw new Problem('NOT_FOUND','رابطه پیدا نشد.',404);}if((int)$r['revision']!==$revision){throw new Problem('STALE_RELATION','رابطه تغییر کرده؛ صفحه را دوباره باز کنید.',409);}
            if($status==='approved'){
                if(!$this->entity($r['from_type'],$r['from_id'],$r['visibility']==='public')||!$this->entity($r['to_type'],$r['to_id'],$r['visibility']==='public')){throw new Problem('INVALID_RELATION','یکی از موجودیت‌ها دیگر معتبر نیست.',409);}
                if(self::RELATIONS[$r['relation_type']][3]){$this->acyclic($r['relation_type'],$r['from_id'],$r['to_id']);}
            }
            $this->db->run('UPDATE m_knowledge_relations SET status=?,reviewed_by=?,reviewed_at=?,revision=revision+1 WHERE id=?',[$status,$a['user_id'],time(),$id]);$this->history($a,$id,'knowledge.'.$status,$r,['status'=>$status,'revision'=>$revision+1]);
        });
    }
    private function acyclic(string $kind,string $from,string $to):void
    {
        $from=(new Taxonomy($this->db))->resolve($from);$to=(new Taxonomy($this->db))->resolve($to);$edges=$this->db->all("SELECT COALESCE(fr.target_id,r.from_id) AS from_id,COALESCE(tr.target_id,r.to_id) AS to_id FROM m_knowledge_relations r LEFT JOIN m_term_redirects fr ON fr.source_id=r.from_id LEFT JOIN m_term_redirects tr ON tr.source_id=r.to_id WHERE r.relation_type=? AND r.status='approved'",[$kind]);$adj=[];foreach($edges as $edge){$adj[$edge['from_id']][]=$edge['to_id'];}$todo=[$to];$seen=[];
        while($todo){$node=array_pop($todo);if($node===$from){throw new Problem('RELATION_CYCLE','رابطهٔ سلسله‌مراتبی چرخه ایجاد می‌کند.',409);}if(isset($seen[$node])){continue;}$seen[$node]=true;foreach($adj[$node]??[] as $next){$todo[]=$next;}}
    }
    public function publicRelations(string $type,string $id):array
    {
        $this->storage();if(!$this->entity($type,$id,true)){return [];}if(in_array($type,['ISSUE','TOPIC'],true)){$id=(new Taxonomy($this->db))->resolve($id);}
        $rows=$this->db->all("SELECT r.relation_type,r.from_type,COALESCE(fr.target_id,r.from_id) AS from_id,r.to_type,COALESCE(tr.target_id,r.to_id) AS to_id,r.origin,r.reviewed_at FROM m_knowledge_relations r LEFT JOIN m_term_redirects fr ON fr.source_id=r.from_id AND r.from_type IN ('ISSUE','TOPIC') LEFT JOIN m_term_redirects tr ON tr.source_id=r.to_id AND r.to_type IN ('ISSUE','TOPIC') WHERE r.status='approved' AND r.visibility='public' AND ((r.from_type=? AND COALESCE(fr.target_id,r.from_id)=?) OR (r.to_type=? AND COALESCE(tr.target_id,r.to_id)=?)) LIMIT 100",[$type,$id,$type,$id]);
        return array_values(array_filter($rows,fn($r)=>$this->entity($r['from_type'],$r['from_id'],true)&&$this->entity($r['to_type'],$r['to_id'],true)));
    }
    public function backfill(bool $dryRun=true):array
    {
        $this->storage();$sources=['MENTOR'=>['m_mentors','user_id'],'EXPERIENCE'=>['m_experiences','id'],'ISSUE'=>['m_terms','id'], 'TOPIC'=>['m_terms','id'],'ORGANIZATION'=>['m_organizations','id'],'USER'=>['m_users','id'],'CONSULTATION_REQUEST'=>['m_bookings','id'],'SESSION'=>['m_bookings','id'],'PLACE'=>['m_places','id']];$counts=[];
        foreach($sources as $type=>[$table,$column]){$where=in_array($type,['ISSUE','TOPIC'],true)?" WHERE kind='".strtolower($type)."'":'';$counts[$type]=(int)$this->db->run("SELECT COUNT(*) FROM $table$where")->fetchColumn();if(!$dryRun){$last='';do{$batch=$this->db->all("SELECT $column AS id FROM $table".($where?$where.' AND ':' WHERE ')."$column>? ORDER BY $column LIMIT 500",[$last]);if($batch){$values=[];$params=[];foreach($batch as $row){$values[]='(?,?,?)';array_push($params,$type,$row['id'],time());$last=$row['id'];}$this->db->run('INSERT IGNORE INTO m_knowledge_entities(entity_type,source_id,created_at) VALUES'.implode(',',$values),$params);}}while(count($batch)===500);}}
        return ['dry_run'=>$dryRun,'counts'=>$counts,'canonical_edges_copied'=>0];
    }
    public function aliases(array $a,string $term,string $text):void
    {
        (new Policy($this->db))->admin($a);$this->storage();$text=Policy::text($text,2400,0);
        $this->db->tx(function()use($a,$term,$text){$t=$this->db->one('SELECT * FROM m_terms WHERE id=? AND active=1',[$term]);if(!$t){throw new Problem('INVALID_TOPIC','طبقه‌بندی فعال را انتخاب کنید.');}$before=$this->db->all('SELECT alias FROM m_term_aliases WHERE term_id=?',[$term]);$this->db->run('DELETE FROM m_term_aliases WHERE term_id=?',[$term]);foreach(array_unique(array_filter(array_map('trim',explode("\n",$text)))) as $alias){$this->db->run('INSERT INTO m_term_aliases VALUES(?,?)',[$term,Policy::text($alias,160)]);}$this->history($a,$term,'taxonomy.aliases',$before,['aliases'=>$text]);});
    }
    public function mergeTerms(array $a,string $from,string $into):void
    {
        (new Policy($this->db))->admin($a);$this->storage();
        $this->db->tx(function()use($a,$from,$into){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$source=$this->db->one('SELECT * FROM m_terms WHERE id=? AND active=1',[$from]);$target=$this->db->one('SELECT * FROM m_terms WHERE id=? AND active=1',[$into]);
            if(!$source||!$target||$source['kind']!==$target['kind']||$from===$into){throw new Problem('INVALID_MERGE','دو طبقه‌بندی فعال و هم‌نوع انتخاب کنید.');}
            foreach(['TOPIC_PARENT_OF','ISSUE_SUBISSUE_OF'] as $kind){
                $edges=$this->db->all("SELECT COALESCE(fr.target_id,r.from_id) AS source,COALESCE(tr.target_id,r.to_id) AS target FROM m_knowledge_relations r LEFT JOIN m_term_redirects fr ON fr.source_id=r.from_id LEFT JOIN m_term_redirects tr ON tr.source_id=r.to_id WHERE r.relation_type=? AND r.status='approved'",[$kind]);$adj=[];
                foreach($edges as $edge){$f=$edge['source']===$from?$into:$edge['source'];$t=$edge['target']===$from?$into:$edge['target'];$adj[$f][]=$t;}
                $colors=[];$visit=function($node)use(&$visit,&$colors,$adj){if(($colors[$node]??0)===1){throw new Problem('MERGE_CYCLE','این ادغام سلسله‌مراتب را نامعتبر می‌کند؛ ابتدا روابط را بازبینی کنید.',409);}if(($colors[$node]??0)===2){return;}$colors[$node]=1;foreach($adj[$node]??[] as $next){$visit($next);}$colors[$node]=2;};foreach(array_keys($adj) as $node){$visit($node);}
            }
            if($source['kind']==='topic'&&$this->db->one('SELECT ancestor_id FROM m_topic_paths WHERE ancestor_id=? AND descendant_id=?',[$from,$into])){throw new Problem('MERGE_CYCLE','ابتدا رابطهٔ والد و فرزند را اصلاح کنید.',409);}$this->db->run('INSERT INTO m_term_redirects VALUES(?,?,?,?)',[$from,$into,$a['user_id'],time()]);$this->db->run('UPDATE m_term_redirects SET target_id=? WHERE target_id=?',[$into,$from]);
            $this->db->run('INSERT IGNORE INTO m_term_aliases VALUES(?,?)',[$into,$source['title']]);$this->db->run('INSERT IGNORE INTO m_term_aliases SELECT ?,alias FROM m_term_aliases WHERE term_id=?',[$into,$from]);
            // Historical versions retain their IDs. Queries resolve redirects; no content/booking history is rewritten.
            if($source['kind']==='issue'){$this->db->run('INSERT IGNORE INTO m_issue_topics SELECT ?,topic_id FROM m_issue_topics WHERE issue_id=?',[$into,$from]);}else{$this->db->run('INSERT IGNORE INTO m_issue_topics SELECT issue_id,? FROM m_issue_topics WHERE topic_id=?',[$into,$from]);}$this->db->run('UPDATE m_terms SET parent_id=? WHERE parent_id=? AND id<>?',[$into,$from,$into]);$this->db->run('UPDATE m_terms SET active=0 WHERE id=?',[$from]);(new ClassificationDetails($this->db))->rebuildPaths();$this->history($a,$from,'taxonomy.merged',$source,['into'=>$into]);
        });
    }
    public function projectedEdges(string $experience):array
    {
        $this->storage();if(!$this->entity('EXPERIENCE',$experience,true)){return [];}
        $e=$this->db->one('SELECT mentor_id,public_version_id FROM m_experiences WHERE id=?',[$experience]);$out=[['type'=>'MENTOR_OWNS_EXPERIENCE','from'=>$e['mentor_id'],'to'=>$experience,'origin'=>'system','visibility'=>'public']];
        $labels=(new Taxonomy($this->db))->labels([$e['public_version_id']])[$e['public_version_id']]??[];
        foreach(['topics'=>'EXPERIENCE_ABOUT_TOPIC','issues'=>'EXPERIENCE_TAGGED_WITH_ISSUE'] as $key=>$type){foreach($labels[$key]??[] as $term){$out[]=['type'=>$type,'from'=>$experience,'to'=>$term['id'],'origin'=>'system','visibility'=>'public'];}}
        $facts=(new Classifications($this->db))->version($e['public_version_id']);
        foreach($facts['problems'] as $problem){$ref=$e['public_version_id'].':'.$problem['id'];$out[]=['type'=>'EXPERIENCE_HAS_PROBLEM','from'=>$experience,'to'=>$ref,'origin'=>'system','visibility'=>'public'];foreach($problem['tags'] as $tag){if($tag['active']){$out[]=['type'=>'PROBLEM_HAS_ISSUE_TAG','from'=>$ref,'to'=>$tag['id'],'origin'=>'system','visibility'=>'public'];}}}
        foreach($facts['audiences'] as $audience){if($audience['active']){$out[]=['type'=>'EXPERIENCE_FOR_AUDIENCE','from'=>$experience,'to'=>$audience['id'],'origin'=>'system','visibility'=>'public'];}}
        $links=(new AtlasData($this->db))->publicLinks($e['public_version_id']);foreach($links['organizations'] as $org){if(!empty($org['org_type'])){$out[]=['type'=>'ORGANIZATION_OF_TYPE','from'=>$org['id'],'to'=>$org['org_type'],'origin'=>'admin','visibility'=>'public'];}$out[]=['type'=>'EXPERIENCE_OCCURRED_AT_ORGANIZATION','from'=>$experience,'to'=>$org['id'],'attribution'=>$org['relation_type'],'origin'=>'admin','visibility'=>'public'];}
        foreach($links['places'] as $place){$out[]=['type'=>'EXPERIENCE_OCCURRED_IN_PLACE','from'=>$experience,'to'=>$place['county_id']??$place['province_id'],'origin'=>'admin','visibility'=>'public'];}
        return $out;
    }
}
