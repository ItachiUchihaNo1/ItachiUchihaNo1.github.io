<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Problem,Security};

/** One version must satisfy all dimensions; alternatives inside one dimension are OR. */
final class DiscoveryFilters
{
    public function __construct(private Database $db){}
    public static function values(array $d,string $key,bool $uuid=true):array
    {
        $v=$d[$key]??[];if(is_string($v)){$v=$v===''?[]:[$v];}
        if(!is_array($v)||!array_is_list($v)||count($v)>20){throw new Problem('INVALID_FILTER','حداکثر بیست مقدار برای هر فیلتر انتخاب کنید.');}
        foreach($v as $id){if(!is_string($id)||($uuid?!Security::validId($id):!preg_match('/^[a-z0-9_]{1,24}$/D',$id))){throw new Problem('INVALID_FILTER','مقدار فیلتر معتبر نیست.');}}
        return array_values(array_unique($v));
    }
    public function sql(array $d,array &$args,string $v='v'):string
    {
        if(!preg_match('/^[a-z]+$/D',$v)){throw new \LogicException('Invalid alias');}(new Classifications($this->db))->storage();$sql='';
        foreach(['term','topic','issue'] as $kind){$ids=self::values($d,$kind);if(!$ids){continue;}$ids=array_map(fn($id)=>(new Taxonomy($this->db))->resolve($id),$ids);$marks=implode(',',array_fill(0,count($ids),'?'));
            $sql.=" AND EXISTS(SELECT 1 FROM m_terms ft WHERE ft.id IN ($marks)".($kind==='term'?'':" AND ft.kind='$kind'").' AND '.Taxonomy::condition($v).')';array_push($args,...$ids);
        }
        $aud=self::values($d,'audience');if($aud){$marks=implode(',',array_fill(0,count($aud),'?'));$sql.=" AND EXISTS(SELECT 1 FROM m_experience_audiences ax JOIN m_audiences aa ON aa.id=ax.audience_id WHERE ax.version_id=$v.id AND aa.active=1 AND aa.id IN ($marks))";array_push($args,...$aud);}
        $org=self::values($d,'organization_id');$types=self::values($d,'org_type',false);
        if($org||$types){
            $sql.=" AND EXISTS(SELECT 1 FROM m_experience_organizations ox JOIN m_organization_profiles os ON os.org_id=ox.org_id JOIN m_organizations oo ON oo.id=COALESCE(os.merged_into,ox.org_id) AND oo.active=1 JOIN m_organization_profiles op ON op.org_id=oo.id AND op.verified_at IS NOT NULL AND op.merged_into IS NULL WHERE ox.version_id=$v.id AND ox.status='approved'";
            if($org){$sql.=' AND oo.id IN ('.implode(',',array_fill(0,count($org),'?')).')';array_push($args,...$org);}
            if($types){$sql.=' AND op.org_type IN ('.implode(',',array_fill(0,count($types),'?')).')';array_push($args,...$types);}$sql.=')';
        }
        return $sql;
    }
    public function textSql(string $v='v'):string
    {return "EXISTS(SELECT 1 FROM m_experience_problems rp WHERE rp.version_id=$v.id AND (REPLACE(REPLACE(REPLACE(rp.title,'ي','ی'),'ك','ک'),'‌',' ') LIKE ? OR REPLACE(REPLACE(REPLACE(rp.description,'ي','ی'),'ك','ک'),'‌',' ') LIKE ?))";}
    public function options():array
    {
        $catalog=new Classifications($this->db);$catalog->storage();$tax=new Taxonomy($this->db);
        $base=AtlasData::publicBase();$aud=$this->db->all("SELECT DISTINCT a.* FROM m_audiences a WHERE a.active=1 AND EXISTS(SELECT 1 $base JOIN m_experience_audiences ax ON ax.version_id=v.id WHERE ax.audience_id=a.id) ORDER BY a.family,a.display_order,a.title");
        $org=$this->db->all("SELECT DISTINCT o.id,o.name,p.org_type FROM m_organizations o JOIN m_organization_profiles p ON p.org_id=o.id AND p.verified_at IS NOT NULL AND p.merged_into IS NULL WHERE o.active=1 AND EXISTS(SELECT 1 $base JOIN m_experience_organizations ox ON ox.version_id=v.id AND ox.status='approved' JOIN m_organization_profiles source ON source.org_id=ox.org_id WHERE COALESCE(source.merged_into,ox.org_id)=o.id) ORDER BY o.name");
        $used=array_column($org,'org_type');return ['topics'=>array_values(array_filter($tax->topics(),fn($t)=>(int)$t['experience_count']>0)),'issues'=>array_values(array_filter($tax->issues(),fn($t)=>(int)$t['experience_count']>0)),'audiences'=>$aud,'types'=>array_values(array_filter($catalog->types(),fn($t)=>in_array($t['id'],$used,true))),'organizations'=>$org];
    }
}
