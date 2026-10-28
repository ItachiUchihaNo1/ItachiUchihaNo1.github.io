<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Problem,Security,Audit};

/** Versioned, reviewed geography and attribution. Operational memberships are never public evidence. */
final class AtlasData
{
    public function __construct(private Database $db){}
    public function storage():void
    {
        if($this->db->featureReady('atlas15')){return;}
        try{$this->db->run('SELECT id FROM m_atlas_changes LIMIT 0');}
        catch(\PDOException $e){
            if((int)($e->errorInfo[1]??0)!==1146){throw $e;}
            if($this->db->pdo->inTransaction()){throw new \LogicException('Atlas storage must be prepared before a transaction.');}
            $sql=file_get_contents(__DIR__.'/../../database/atlas15.sql');
            foreach(explode(';',$sql) as $statement){if(trim($statement)!==''){$this->db->run($statement);}}
            $data=json_decode(file_get_contents(__DIR__.'/../../data/iran-places.json'),true,512,JSON_THROW_ON_ERROR);
            foreach($data['places'] as $p){$this->db->run('INSERT IGNORE INTO m_places(id,parent_id,kind,name,source_ref) VALUES(?,?,?,?,?)',[$p['id'],$p['parent_id'],$p['kind'],$p['name'],$p['source_ref']]);}
            // Marker is last: interrupted installations are safely retried.
            $this->db->run('CREATE TABLE IF NOT EXISTS m_atlas_changes (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,actor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,subject_id VARCHAR(80) NOT NULL,action VARCHAR(40) NOT NULL,before_json MEDIUMTEXT NOT NULL,after_json MEDIUMTEXT NOT NULL,created_at BIGINT NOT NULL,INDEX(subject_id,created_at)) ENGINE=InnoDB');
        }
        $this->db->markFeatureReady('atlas15');
    }
    public function places(?string $province=null):array
    {$this->storage();return $province===null?$this->db->all("SELECT id,name FROM m_places WHERE kind='province' ORDER BY name"):$this->db->all("SELECT id,name FROM m_places WHERE kind='county' AND parent_id=? ORDER BY name",[$province]);}
    public function location(mixed $province,mixed $county):array
    {
        if(!is_string($province)||!is_string($county)){throw new Problem('INVALID_PLACE','مکان معتبر نیست.');}
        if($province===''){if($county!==''){throw new Problem('INVALID_PLACE','ابتدا استان را انتخاب کنید.');}return [null,null];}
        if(!$this->db->one("SELECT id FROM m_places WHERE id=? AND kind='province'",[$province])){throw new Problem('INVALID_PLACE','استان معتبر نیست.');}
        if($county!==''&&!$this->db->one("SELECT id FROM m_places WHERE id=? AND kind='county' AND parent_id=?",[$county,$province])){throw new Problem('INVALID_PLACE','شهرستان متعلق به استان انتخاب‌شده نیست.');}
        return [$province,$county?:null];
    }
    private function change(array $a,string $id,string $action,?array $before,array $after):void
    {$this->db->run('INSERT INTO m_atlas_changes VALUES(?,?,?,?,?,?,?)',[Security::id(),$a['user_id'],$id,$action,json_encode($before??[],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),time()]);(new Audit($this->db))->add($a['user_id'],'admin',$action,$id);}
    public function organizations(bool $admin=false):array
    {
        $this->storage();(new Contributions($this->db))->storage();
        if($admin){return $this->db->all('SELECT o.*,COALESCE((SELECT approved FROM m_atlas_visibility av WHERE av.entity_kind=\'organization\' AND av.entity_id=o.id),1) AS atlas_approved,p.description,p.org_type,p.province_id,p.county_id,p.verified_at,p.merged_into,pr.name AS province_name,co.name AS county_name FROM m_organizations o LEFT JOIN m_organization_profiles p ON p.org_id=o.id LEFT JOIN m_organization_details od ON od.org_id=o.id LEFT JOIN m_places pr ON pr.id=p.province_id LEFT JOIN m_places co ON co.id=p.county_id ORDER BY o.name LIMIT 201');}
        return $this->discover(['layer'=>'organizations'])['rows'];
    }
    public function saveOrganization(array $a,array $d):string
    {
        (new Policy($this->db))->admin($a,true);$this->storage();$four=new Classifications($this->db);$four->storage();$extra=new Contributions($this->db);$values=$extra->prepareDetails($a,$d);
        return $this->db->tx(function()use($a,$d,$extra,$values){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');
            $id=($d['id']??'')?:Security::id();if(!Security::validId($id)){throw new Problem('INVALID_INPUT','شناسه معتبر نیست.');}
            $old=$this->db->one('SELECT o.*,p.* FROM m_organizations o LEFT JOIN m_organization_profiles p ON p.org_id=o.id WHERE o.id=?',[$id]);
            if(!empty($old['merged_into'])){throw new Problem('MERGED','این مجموعه ادغام شده است.',409);}
            [$province,$county]=$this->location($d['province_id']??'',$d['county_id']??'');
            $name=preg_replace('/\s+/u',' ',str_replace(['ي','ك'],['ی','ک'],Policy::text($d['name']??'',180)));$type=Policy::text($d['org_type']??'other',24);if(!$this->db->one('SELECT id FROM m_org_types WHERE id=? AND (active=1 OR id=?)',[$type,$old['org_type']??''])){throw new Problem('INVALID_ORGANIZATION','نوع مجموعه معتبر نیست.');}$description=Policy::text($d['description']??'',6000,0);$active=Policy::integer($d['active']??1,0,1);$verified=($d['verified']??'')==='1'?time():null;
            if($this->db->one('SELECT o.id FROM m_organizations o JOIN m_organization_profiles p ON p.org_id=o.id WHERE o.id<>? AND o.name=? AND p.org_type=? AND p.province_id<=>? AND p.county_id<=>? AND p.merged_into IS NULL',[$id,$name,$type,$province,$county])){throw new Problem('DUPLICATE_ORGANIZATION','این نام و نوع در همین مکان ثبت شده است؛ مجموعه موجود را انتخاب کنید.',409);}
            if($old){$this->db->run('UPDATE m_organizations SET name=?,active=? WHERE id=?',[$name,$active,$id]);}else{$this->db->run('INSERT INTO m_organizations(id,name,active) VALUES(?,?,?)',[$id,$name,$active]);}
            $this->db->run('INSERT INTO m_organization_profiles(org_id,description,org_type,province_id,county_id,verified_at,verified_by,updated_at) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE description=VALUES(description),org_type=VALUES(org_type),province_id=VALUES(province_id),county_id=VALUES(county_id),verified_at=VALUES(verified_at),verified_by=VALUES(verified_by),updated_at=VALUES(updated_at)',[$id,$description,$type,$province,$county,$verified,$verified?$a['user_id']:null,time()]);
            $this->db->run('INSERT INTO m_organization_type_links VALUES(?,?) ON DUPLICATE KEY UPDATE type_id=VALUES(type_id)',[$id,$type]);
            $aliases=Policy::text($d['aliases']??'',1600,0);$this->db->run('DELETE FROM m_organization_aliases WHERE org_id=?',[$id]);foreach(array_unique(array_filter(array_map('trim',explode("\n",$aliases)))) as $alias){$this->db->run('INSERT INTO m_organization_aliases(org_id,alias) VALUES(?,?)',[$id,Policy::text($alias,180)]);}
            $extra->saveDetails($id,$values);if(isset($d['_atlas_present']))$extra->atlasDecision($a,'organization',$id,($d['atlas_approved']??'')==='1');$this->change($a,$id,'organization.saved',$old,['name'=>$name,'type'=>$type,'province'=>$province,'county'=>$county,'verified'=>$verified,'active'=>$active,'public_details'=>$values]);return $id;
        });
    }
    public function merge(array $a,string $from,string $into):void
    {
        (new Policy($this->db))->admin($a,true);$this->storage();
        $this->db->tx(function()use($a,$from,$into){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');
            $source=$this->db->one('SELECT o.*,p.merged_into FROM m_organizations o JOIN m_organization_profiles p ON p.org_id=o.id WHERE o.id=?',[$from]);
            $target=$this->db->one('SELECT o.id FROM m_organizations o JOIN m_organization_profiles p ON p.org_id=o.id WHERE o.id=? AND o.active=1 AND p.merged_into IS NULL',[$into]);
            if(!$source||!$target||$from===$into||$source['merged_into']){throw new Problem('INVALID_MERGE','دو مجموعه مستقل و معتبر انتخاب کنید.');}
            // Historical attribution rows keep their original identity; reads resolve the alias.
            $this->db->run('UPDATE m_organization_profiles SET merged_into=?,updated_at=? WHERE org_id=?',[$into,time(),$from]);
            $this->db->run('UPDATE m_organization_profiles SET merged_into=? WHERE merged_into=?',[$into,$from]);
            $this->db->run('UPDATE m_organizations SET active=0 WHERE id=?',[$from]);$this->db->run('INSERT IGNORE INTO m_organization_aliases(org_id,alias) VALUES(?,?)',[$into,$source['name']]);
            $this->change($a,$from,'organization.merged',$source,['into'=>$into]);
        });
    }
    public function cloneVersion(string $version,?string $previous):void
    {
        if(!$previous){return;}
        $this->db->run('INSERT INTO m_experience_organizations(version_id,org_id,relation_type,is_primary,status,raw_name,internal_note,reviewed_by,reviewed_at,evidence) SELECT ?,org_id,relation_type,is_primary,status,raw_name,internal_note,reviewed_by,reviewed_at,evidence FROM m_experience_organizations WHERE version_id=?',[$version,$previous]);
        $this->db->run('INSERT INTO m_experience_places(version_id,province_id,county_id,location_type,is_primary,status,reviewed_by,reviewed_at,place_key) SELECT ?,province_id,county_id,location_type,is_primary,status,reviewed_by,reviewed_at,place_key FROM m_experience_places WHERE version_id=?',[$version,$previous]);
    }
    public function versionData(string $version):array
    {$this->storage();(new Classifications($this->db))->storage();return ['organizations'=>$this->db->all('SELECT x.*,o.name FROM m_experience_organizations x JOIN m_organizations o ON o.id=x.org_id WHERE x.version_id=?',[$version]),'places'=>$this->db->all('SELECT x.*,p.name AS province_name,c.name AS county_name FROM m_experience_places x JOIN m_places p ON p.id=x.province_id LEFT JOIN m_places c ON c.id=x.county_id WHERE x.version_id=?',[$version])];}
    public function attribution(array $a,array $d):void
    {
        (new Policy($this->db))->admin($a);$this->storage();
        $this->db->tx(function()use($a,$d){
            $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$version=Policy::text($d['version_id']??'',32);
            if(!$this->db->one('SELECT id FROM m_experience_versions WHERE id=?',[$version])){throw new Problem('NOT_FOUND','نسخه پیدا نشد.',404);}
            $kind=Policy::choice($d['kind']??'organization',['organization','place']);$status=Policy::choice($d['status']??'proposed',['proposed','approved','rejected','revoked']);$primary=Policy::integer($d['is_primary']??0,0,1);
            $before=$this->versionData($version);
            if($kind==='organization'){
                $org=Policy::text($d['org_id']??'',32);if(!$this->db->one('SELECT o.id FROM m_organizations o JOIN m_organization_profiles p ON p.org_id=o.id WHERE o.id=? AND o.active=1 AND p.merged_into IS NULL',[$org])){throw new Problem('INVALID_ORGANIZATION','مجموعه معتبر انتخاب کنید.');}
                $type=Policy::choice($d['relation_type']??'formed_at',['formed_at','co_implementer','other']);$note=Policy::text($d['internal_note']??'',3000,0);$raw=Policy::text($d['raw_name']??'',180,0);$evidence=Policy::text($d['evidence']??'',500,0);
                if($primary){$this->db->run('UPDATE m_experience_organizations SET is_primary=0 WHERE version_id=?',[$version]);}
                $this->db->run('INSERT INTO m_experience_organizations VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE relation_type=VALUES(relation_type),is_primary=VALUES(is_primary),status=VALUES(status),raw_name=VALUES(raw_name),internal_note=VALUES(internal_note),reviewed_by=VALUES(reviewed_by),reviewed_at=VALUES(reviewed_at),evidence=VALUES(evidence)',[$version,$org,$type,$primary,$status,$raw,$note,$status==='proposed'?null:$a['user_id'],$status==='proposed'?null:time(),$evidence]);
            }else{
                [$province,$county]=$this->location($d['province_id']??'',$d['county_id']??'');if(!$province){throw new Problem('INVALID_PLACE','استان تجربه را انتخاب کنید.');}
                $type=Policy::choice($d['location_type']??'execution',['execution','formation']);$key=$county??$province;
                if($primary){$this->db->run('UPDATE m_experience_places SET is_primary=0 WHERE version_id=?',[$version]);}
                $this->db->run('INSERT INTO m_experience_places(version_id,province_id,county_id,location_type,is_primary,status,reviewed_by,reviewed_at,place_key) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_primary=VALUES(is_primary),status=VALUES(status),reviewed_by=VALUES(reviewed_by),reviewed_at=VALUES(reviewed_at)',[$version,$province,$county,$type,$primary,$status,$status==='proposed'?null:$a['user_id'],$status==='proposed'?null:time(),$key]);
            }
            $this->change($a,$version,'experience.attribution',$before,$this->versionData($version));
        });
    }
    public function inlineDecision(array $a,string $version,string $experience,array $d):void
    {
        if(!isset($d['_atlas_present']))return;(new Policy($this->db))->admin($a);$approved=($d['atlas_approved']??'')==='1';
        [$province,$county]=$this->location($d['province_id']??'',$d['county_id']??'');if($approved&&!$province)throw new Problem('INVALID_PLACE','برای نمایش در نقشه، استان محل اجرای تجربه را انتخاب کنید.');
        $before=$this->versionData($version);$status=$approved?'approved':'proposed';
        $this->db->run('UPDATE m_experience_places SET is_primary=0 WHERE version_id=?',[$version]);
        if($province)$this->db->run("INSERT INTO m_experience_places(version_id,province_id,county_id,location_type,is_primary,status,reviewed_by,reviewed_at,place_key) VALUES(?,?,?,'execution',1,?,?,?,?) ON DUPLICATE KEY UPDATE is_primary=1,status=VALUES(status),reviewed_by=VALUES(reviewed_by),reviewed_at=VALUES(reviewed_at)",[$version,$province,$county,$status,$approved?$a['user_id']:null,$approved?time():null,$county??$province]);
        $this->db->run('UPDATE m_experience_organizations SET status=?,reviewed_by=?,reviewed_at=? WHERE version_id=?',[$status,$approved?$a['user_id']:null,$approved?time():null,$version]);
        (new Contributions($this->db))->atlasDecision($a,'experience',$experience,$approved);$this->change($a,$version,'experience.attribution_inline',$before,$this->versionData($version));
    }
    public static function publicBase():string
    {return " FROM m_experiences e JOIN m_experience_versions v ON v.id=e.public_version_id AND v.experience_id=e.id JOIN m_mentors m ON m.user_id=e.mentor_id AND m.status='active' JOIN m_users u ON u.id=m.user_id AND u.status='active'";}
    private function filters(array $d,array &$args):string
    {
        $where="NOT EXISTS(SELECT 1 FROM m_atlas_visibility av WHERE av.entity_kind='experience' AND av.entity_id=e.id AND av.approved=0) AND NOT EXISTS(SELECT 1 FROM m_atlas_visibility av WHERE av.entity_kind='mentor' AND av.entity_id=e.mentor_id AND av.approved=0)";
        $where.=(new DiscoveryFilters($this->db))->sql($d,$args);if(isset($d['accept_novices'])&&$d['accept_novices']!==''){(new Membership($this->db))->storage();$where.=' AND COALESCE((SELECT ad.accept_novices FROM m_mentor_admission ad WHERE ad.mentor_id=m.user_id),1)=?';$args[]=Policy::integer($d['accept_novices'],0,1);}
        $q=Policy::text($d['q']??'',160,0);if($q!==''){$pattern=Policy::query($q);$where.=" AND (v.title LIKE ? OR v.body LIKE ? OR u.display_name LIKE ? OR EXISTS(SELECT 1 FROM m_experience_organizations sq JOIN m_organizations so ON so.id=sq.org_id LEFT JOIN m_organization_profiles sp ON sp.org_id=so.id LEFT JOIN m_organization_aliases sa ON sa.org_id=COALESCE(sp.merged_into,so.id) WHERE sq.version_id=v.id AND sq.status='approved' AND (so.name LIKE ? OR sa.alias LIKE ?)) OR EXISTS(SELECT 1 FROM m_terms ft LEFT JOIN m_term_aliases fa ON fa.term_id=ft.id WHERE ".Taxonomy::condition()." AND (REPLACE(REPLACE(REPLACE(ft.title,'ي','ی'),'ك','ک'),'‌',' ') LIKE ? OR REPLACE(REPLACE(REPLACE(fa.alias,'ي','ی'),'ك','ک'),'‌',' ') LIKE ?)))";array_push($args,$pattern,$pattern,$pattern,$pattern,$pattern,$pattern,$pattern);}
        if(!empty($d['mentor_id'])){if(!is_string($d['mentor_id'])||!Security::validId($d['mentor_id'])){throw new Problem('INVALID_FILTER','مرشد معتبر نیست.');}$where.=' AND e.mentor_id=?';$args[]=$d['mentor_id'];}
        if($q!==''){$where=str_replace('v.title LIKE ?', '(v.title LIKE ? OR '.(new DiscoveryFilters($this->db))->textSql().')',$where);$prefixCount=count($args)-7-(!empty($d['mentor_id'])?1:0);array_splice($args,$prefixCount+1,0,[$pattern,$pattern]);}
        return $where;
    }
    public function discover(array $d=[],bool $geographic=false,bool $regionsOnly=false):array
    {
        $this->storage();(new Classifications($this->db))->storage();(new Contributions($this->db))->storage();
        $layer=Policy::choice($d['layer']??'experiences',['experiences','organizations','mentors']);$page=Policy::integer($d['page']??1,1,10000);
        [$province,$county]=$this->location($d['province']??'',$d['county']??'');$args=[];$where=$this->filters($d,$args);$base=self::publicBase();
        if($layer==='organizations'){
            $base.=" JOIN m_experience_organizations ax ON ax.version_id=v.id AND ax.status='approved' JOIN m_organization_profiles ap ON ap.org_id=ax.org_id JOIN m_organizations o ON o.id=COALESCE(ap.merged_into,ax.org_id) AND o.active=1 JOIN m_organization_profiles op ON op.org_id=o.id AND op.verified_at IS NOT NULL AND op.merged_into IS NULL";
            $where.=" AND NOT EXISTS(SELECT 1 FROM m_atlas_visibility av WHERE av.entity_kind='organization' AND av.entity_id=o.id AND av.approved=0)";$entity='o.id';$geoProvince='op.province_id';$geoCounty='op.county_id';
        }else{
            $entity=$layer==='mentors'?'e.mentor_id':'e.id';
            $base.=" LEFT JOIN m_experience_places xp ON xp.version_id=v.id AND xp.status='approved'";$geoProvince='xp.province_id';$geoCounty='xp.county_id';
        }
        if($geographic){$where.=" AND $geoProvince IS NOT NULL";}
        if($province){$where.=" AND $geoProvince=?";$args[]=$province;}if($county){$where.=" AND $geoCounty=?";$args[]=$county;}
        $sql=$base.' WHERE '.$where;
        if($regionsOnly){$region=$province?$geoCounty:$geoProvince;return $this->db->all("SELECT $region AS id,COUNT(DISTINCT $entity) AS total".$sql." GROUP BY $region",$args);}
        $total=(int)$this->db->run("SELECT COUNT(DISTINCT $entity)".$sql,$args)->fetchColumn();
        $summary=$this->db->one('SELECT COUNT(DISTINCT e.id) AS experiences,COUNT(DISTINCT e.mentor_id) AS mentors'.$sql,$args);
        $summary['organizations']=$layer==='organizations'?$total:(int)$this->db->run("SELECT COUNT(DISTINCT zo.id)".$base." JOIN m_experience_organizations zx ON zx.version_id=v.id AND zx.status='approved' JOIN m_organization_profiles zp ON zp.org_id=zx.org_id JOIN m_organizations zo ON zo.id=COALESCE(zp.merged_into,zx.org_id) AND zo.active=1 JOIN m_organization_profiles zt ON zt.org_id=zo.id AND zt.verified_at IS NOT NULL AND zt.merged_into IS NULL WHERE ".$where,$args)->fetchColumn();
        $rows=$this->db->all("SELECT $entity AS id,COUNT(DISTINCT e.id) AS matched_experiences".$sql." GROUP BY $entity ORDER BY $entity LIMIT 20 OFFSET ".(($page-1)*20),$args);
        if($rows){
            $ids=array_column($rows,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
            if($layer==='organizations'){
                $details=$this->db->all("SELECT o.id,o.name AS title,p.description AS summary,od.logo_asset_id,pr.name AS province_name,co.name AS county_name,stats.total_experiences,stats.total_mentors FROM m_organizations o JOIN m_organization_profiles p ON p.org_id=o.id LEFT JOIN m_organization_details od ON od.org_id=o.id LEFT JOIN m_places pr ON pr.id=p.province_id LEFT JOIN m_places co ON co.id=p.county_id LEFT JOIN (SELECT COALESCE(sp.merged_into,x.org_id) AS org_id,COUNT(DISTINCT e.id) AS total_experiences,COUNT(DISTINCT e.mentor_id) AS total_mentors".self::publicBase()." JOIN m_experience_organizations x ON x.version_id=v.id AND x.status='approved' JOIN m_organization_profiles sp ON sp.org_id=x.org_id GROUP BY COALESCE(sp.merged_into,x.org_id)) stats ON stats.org_id=o.id WHERE o.id IN ($marks)",$ids);
            }elseif($layer==='mentors'){
                $details=$this->db->all("SELECT u.id,u.display_name AS title,m.bio AS summary,m.avatar_asset_id FROM m_users u JOIN m_mentors m ON m.user_id=u.id WHERE u.id IN ($marks)",$ids);
            }else{
                $details=$this->db->all("SELECT e.id,e.mentor_id,u.display_name,v.title,d.summary FROM m_experiences e JOIN m_experience_versions v ON v.id=e.public_version_id JOIN m_users u ON u.id=e.mentor_id LEFT JOIN m_experience_details d ON d.version_id=v.id WHERE e.id IN ($marks)",$ids);
            }
            $index=array_column($details,null,'id');foreach($rows as &$row){$row+=($index[$row['id']]??[]);}unset($row);
        }
        $regions=[];if($geographic){$region=$province?$geoCounty:$geoProvince;$regions=$this->db->all("SELECT $region AS id,COUNT(DISTINCT $entity) AS total".$sql." GROUP BY $region",$args);}
        return ['rows'=>$rows,'total'=>$total,'summary'=>$summary,'regions'=>$regions,'layer'=>$layer,'page'=>$page,'province'=>$province,'county'=>$county,'filters'=>$d];
    }
    public function organization(string $id,array $pages=[]):array
    {
        $this->storage();(new Classifications($this->db))->storage();(new Contributions($this->db))->storage();$o=$this->db->one('SELECT o.id,o.name,p.description,p.org_type,ot.title AS type_title,p.province_id,p.county_id,p.merged_into,pr.name AS province_name,co.name AS county_name FROM m_organizations o JOIN m_organization_profiles p ON p.org_id=o.id LEFT JOIN m_organization_details od ON od.org_id=o.id LEFT JOIN m_places pr ON pr.id=p.province_id LEFT JOIN m_places co ON co.id=p.county_id LEFT JOIN m_org_types ot ON ot.id=p.org_type WHERE o.id=? AND (o.active=1 OR p.merged_into IS NOT NULL) AND p.verified_at IS NOT NULL',[$id]);
        if(!$o){throw new Problem('NOT_FOUND','مجموعهٔ عمومی پیدا نشد.',404);}if($o['merged_into']){return $this->organization($o['merged_into'],$pages);}
        $experiences=$this->discover(['organization_id'=>$o['id'],'page'=>$pages['page']??1]);if(!$experiences['total']){throw new Problem('NOT_FOUND','مجموعهٔ دارای تجربهٔ منتشرشده پیدا نشد.',404);}
        $classifications=$this->db->all("SELECT ft.id,ft.kind,ft.title FROM m_terms ft WHERE ft.active=1 AND EXISTS(SELECT 1".self::publicBase()." JOIN m_experience_organizations cx ON cx.version_id=v.id AND cx.status='approved' JOIN m_organization_profiles cp ON cp.org_id=cx.org_id WHERE COALESCE(cp.merged_into,cx.org_id)=? AND ".Taxonomy::condition().') ORDER BY ft.kind,ft.title',[$o['id']]);
        return ['organization'=>$o,'experiences'=>$experiences,'mentors'=>$this->discover(['organization_id'=>$o['id'],'layer'=>'mentors','page'=>$pages['mentor_page']??1]),'classifications'=>$classifications];
    }
    public function publicLinks(string $version):array
    {
        $this->storage();(new Classifications($this->db))->storage();return ['organizations'=>$this->db->all("SELECT DISTINCT o.id,o.name,x.relation_type,p.org_type,ot.title AS type_title FROM m_experience_organizations x JOIN m_organization_profiles source ON source.org_id=x.org_id JOIN m_organizations o ON o.id=COALESCE(source.merged_into,x.org_id) AND o.active=1 JOIN m_organization_profiles p ON p.org_id=o.id AND p.verified_at IS NOT NULL AND p.merged_into IS NULL LEFT JOIN m_org_types ot ON ot.id=p.org_type WHERE x.version_id=? AND x.status='approved'",[$version]),'places'=>$this->db->all("SELECT DISTINCT x.province_id,x.county_id,p.name AS province_name,c.name AS county_name FROM m_experience_places x JOIN m_places p ON p.id=x.province_id LEFT JOIN m_places c ON c.id=x.county_id WHERE x.version_id=? AND x.status='approved'",[$version])];
    }
    public function mentorOrganizations(string $mentor):array
    {
        $this->storage();return $this->db->all("SELECT DISTINCT o.id,o.name".self::publicBase()." JOIN m_experience_organizations x ON x.version_id=v.id AND x.status='approved' JOIN m_organization_profiles source ON source.org_id=x.org_id JOIN m_organizations o ON o.id=COALESCE(source.merged_into,x.org_id) AND o.active=1 JOIN m_organization_profiles p ON p.org_id=o.id AND p.verified_at IS NOT NULL AND p.merged_into IS NULL WHERE e.mentor_id=? ORDER BY o.name LIMIT 50",[$mentor]);
    }
}
