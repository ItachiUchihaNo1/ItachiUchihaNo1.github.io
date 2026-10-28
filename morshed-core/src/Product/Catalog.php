<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Problem,Security};

/** Public card facts and account-owned saved mentors. No private profile fields leave this class. */
final class Catalog
{
    private bool $ready=false;
    public function __construct(private Database $db){}
    private function storage():void
    {
        if($this->ready||$this->db->featureReady('catalog13')){return;}
        try{$this->db->run('SELECT mentor_id FROM m_saved_mentors LIMIT 0');$this->db->run('SELECT experience_id FROM m_experience_metrics LIMIT 0');$this->db->run('SELECT experience_id FROM m_saved_experiences LIMIT 0');}
        catch(\PDOException $e){
            if((int)($e->errorInfo[1]??0)!==1146){throw $e;}
            // Additive, idempotent feature storage; existing identity/content schemas are untouched.
            $this->db->run('CREATE TABLE IF NOT EXISTS m_saved_mentors (user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at BIGINT UNSIGNED NOT NULL,PRIMARY KEY(user_id,mentor_id),FOREIGN KEY(user_id) REFERENCES m_users(id),FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id)) ENGINE=InnoDB');
            $this->db->run('CREATE TABLE IF NOT EXISTS m_experience_metrics (experience_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,views BIGINT UNSIGNED NOT NULL DEFAULT 0,FOREIGN KEY(experience_id) REFERENCES m_experiences(id)) ENGINE=InnoDB');
            $this->db->run('CREATE TABLE IF NOT EXISTS m_saved_experiences (user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,experience_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at BIGINT UNSIGNED NOT NULL,PRIMARY KEY(user_id,experience_id),FOREIGN KEY(user_id) REFERENCES m_users(id),FOREIGN KEY(experience_id) REFERENCES m_experiences(id)) ENGINE=InnoDB');
        }
        $this->ready=true;$this->db->markFeatureReady('catalog13');
    }
    public function savedIds(string $uid):array
    {$this->storage();return array_column($this->db->all('SELECT mentor_id FROM m_saved_mentors WHERE user_id=? ORDER BY created_at DESC,mentor_id',[$uid]),'mentor_id');}
    public function save(array $actor,string $mentor,bool $saved):void
    {
        $uid=(new Policy($this->db))->user($actor);if(!Security::validId($mentor)){throw new Problem('INVALID_INPUT','مرشد معتبر نیست.');}
        if($saved&&!$this->db->one("SELECT m.user_id FROM m_mentors m JOIN m_users u ON u.id=m.user_id WHERE m.user_id=? AND m.status='active' AND u.status='active'",[$mentor])){throw new Problem('NOT_FOUND','مرشد در دسترس نیست.',404);}
        $this->storage();
        if($saved){$this->db->run('INSERT IGNORE INTO m_saved_mentors(user_id,mentor_id,created_at) VALUES(?,?,?)',[$uid,$mentor,time()]);}
        else{$this->db->run('DELETE FROM m_saved_mentors WHERE user_id=? AND mentor_id=?',[$uid,$mentor]);}
    }
    public function savedExperiences(string $uid):array
    {$this->storage();return array_column($this->db->all('SELECT experience_id FROM m_saved_experiences WHERE user_id=? ORDER BY created_at DESC,experience_id',[$uid]),'experience_id');}
    public function saveExperience(array $actor,string $id,bool $saved):void
    {
        $uid=(new Policy($this->db))->user($actor);if(!Security::validId($id)){throw new Problem('INVALID_INPUT','تجربه معتبر نیست.');}
        if($saved&& !$this->db->one("SELECT e.id FROM m_experiences e JOIN m_mentors m ON m.user_id=e.mentor_id JOIN m_users u ON u.id=m.user_id WHERE e.id=? AND e.public_version_id IS NOT NULL AND m.status='active' AND u.status='active'",[$id])){throw new Problem('NOT_FOUND','تجربه در دسترس نیست.',404);}
        $this->storage();if($saved){$this->db->run('INSERT IGNORE INTO m_saved_experiences(user_id,experience_id,created_at) VALUES(?,?,?)',[$uid,$id,time()]);}else{$this->db->run('DELETE FROM m_saved_experiences WHERE user_id=? AND experience_id=?',[$uid,$id]);}
    }
    public function mentors(array $rows):array
    {
        if(!$rows){return [];}$ids=array_values(array_unique(array_column($rows,'user_id')));$marks=implode(',',array_fill(0,count($ids),'?'));
        (new Membership($this->db))->storage();$admissions=array_column($this->db->all("SELECT mentor_id,accept_novices FROM m_mentor_admission WHERE mentor_id IN ($marks)",$ids),'accept_novices','mentor_id');
        $cities=\Morshed\Core\Upgrade::ready($this->db)?array_column($this->db->all("SELECT user_id,city FROM m_user_profiles WHERE user_id IN ($marks)",$ids),'city','user_id'):[];
        $counts=[];
        $talks=array_column($this->db->all("SELECT mentor_id,COUNT(*) AS total FROM m_bookings WHERE mentor_id IN ($marks) AND state='completed' GROUP BY mentor_id",$ids),'total','mentor_id');
        $versions=$this->db->all("SELECT e.mentor_id,v.id FROM m_experiences e JOIN m_experience_versions v ON v.id=e.public_version_id AND v.experience_id=e.id WHERE e.mentor_id IN ($marks)",$ids);$labels=(new Taxonomy($this->db))->labels(array_column($versions,'id'));$tags=[];foreach($versions as $version){$counts[$version['mentor_id']]=($counts[$version['mentor_id']]??0)+1;foreach($labels[$version['id']]['issues']??[] as $tag){$tags[$version['mentor_id']][$tag['id']]=$tag;}}
        foreach($rows as &$row){
            $id=$row['user_id'];$row['accept_novices']=(bool)($admissions[$id]??1);$row['city']=$cities[$id]??'';$row['experience_count']=(int)($counts[$id]??0);$row['conversation_count']=(int)($talks[$id]??0);$row['issue_tags']=array_values($tags[$id]??[]);
        }unset($row);return $rows;
    }
    public function experiences(array $rows):array
    {
        if(!$rows){return [];}$this->storage();$ids=array_values(array_unique(array_column($rows,'id')));$marks=implode(',',array_fill(0,count($ids),'?'));
        $views=array_column($this->db->all("SELECT experience_id,views FROM m_experience_metrics WHERE experience_id IN ($marks)",$ids),'views','experience_id');
        $facts=[];foreach($this->db->all("SELECT e.id,m.avatar_asset_id FROM m_experiences e JOIN m_mentors m ON m.user_id=e.mentor_id WHERE e.id IN ($marks)",$ids) as $fact){$facts[$fact['id']]=$fact;}
        $covers=[];foreach($this->db->all("SELECT e.id,MIN(a.id) AS asset_id FROM m_experiences e JOIN m_experience_assets ea ON ea.version_id=e.public_version_id JOIN m_content_assets a ON a.id=ea.asset_id AND a.media_type IN ('image/png','image/jpeg','image/webp') WHERE e.id IN ($marks) GROUP BY e.id",$ids) as $cover)$covers[$cover['id']]=$cover['asset_id'];
        $labels=(new Taxonomy($this->db))->labels(array_column($rows,'version_id'));
        foreach($rows as &$row){
            $row['cover_asset_id']=$covers[$row['id']]??null;$fact=$facts[$row['id']]??[];$row['views']=(int)($views[$row['id']]??0);$row['mentor_avatar_asset_id']=$fact['avatar_asset_id']??null;
            $label=$labels[$row['version_id']]??[];$row['topic_id']=$label['topic']['id']??null;$row['topic_title']=$label['topic']['title']??'تجربهٔ عملی';$row['topic_icon']=$label['topic']['icon']??'book';$row['issue_tags']=$label['issues']??[];
        }unset($row);return $rows;
    }
    public function view(string $id,array &$history,int $now):void
    {
        if(!Security::validId($id)||!$this->db->one("SELECT e.id FROM m_experiences e JOIN m_mentors m ON m.user_id=e.mentor_id JOIN m_users u ON u.id=m.user_id WHERE e.id=? AND e.public_version_id IS NOT NULL AND m.status='active' AND u.status='active'",[$id])){return;}
        if(($history[$id]??0)>$now-1800){return;}$this->storage();
        $this->db->run('INSERT INTO m_experience_metrics(experience_id,views) VALUES(?,1) ON DUPLICATE KEY UPDATE views=views+1',[$id]);
        $history[$id]=$now;if(count($history)>100){asort($history);$history=array_slice($history,-100,null,true);}
    }
}
