<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Problem,Security};

/** Topics organize experiences; reusable issues connect published experiences across topics. */
final class Taxonomy
{
    public const ICONS=['book'=>'کتاب','users'=>'افراد','chat'=>'گفت‌وگو','calendar'=>'رویداد','home'=>'خانه و جامعه','money'=>'منابع مالی','briefcase'=>'کسب‌وکار','lightbulb'=>'ایده و آموزش','flag'=>'فعالیت فرهنگی','heart'=>'همکاری','topic'=>'دسته‌بندی'];
    private bool $ready=false;
    public function __construct(private Database $db){}
    public function storage():void
    {
        if($this->ready||$this->db->featureReady('taxonomy15')){return;}
        try{$this->db->run('SELECT term_id FROM m_term_icons LIMIT 0');$this->db->run('SELECT version_id FROM m_experience_issues LIMIT 0');$this->db->run('SELECT version_id FROM m_experience_topics LIMIT 0');$this->db->run('SELECT source_id FROM m_term_redirects LIMIT 0');$this->db->run('SELECT alias FROM m_term_aliases LIMIT 0');}
        catch(\PDOException $e){
            if((int)($e->errorInfo[1]??0)!==1146){throw $e;}
            $this->db->run("CREATE TABLE IF NOT EXISTS m_term_icons (term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,icon VARCHAR(24) CHARACTER SET ascii NOT NULL,FOREIGN KEY(term_id) REFERENCES m_terms(id)) ENGINE=InnoDB");
            $this->db->run('CREATE TABLE IF NOT EXISTS m_experience_issues (version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,PRIMARY KEY(version_id,term_id),INDEX(term_id,version_id),FOREIGN KEY(version_id) REFERENCES m_experience_versions(id),FOREIGN KEY(term_id) REFERENCES m_terms(id)) ENGINE=InnoDB');
            $this->db->run('CREATE TABLE IF NOT EXISTS m_experience_topics (version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,PRIMARY KEY(version_id,term_id),INDEX(term_id,version_id),FOREIGN KEY(version_id) REFERENCES m_experience_versions(id),FOREIGN KEY(term_id) REFERENCES m_terms(id)) ENGINE=InnoDB');
            $this->db->run('CREATE TABLE IF NOT EXISTS m_term_redirects (source_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,target_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,merged_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,merged_at BIGINT NOT NULL,FOREIGN KEY(source_id) REFERENCES m_terms(id),FOREIGN KEY(target_id) REFERENCES m_terms(id)) ENGINE=InnoDB');
            $this->db->run('CREATE TABLE IF NOT EXISTS m_term_aliases (term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,alias VARCHAR(160) NOT NULL,PRIMARY KEY(term_id,alias),INDEX(alias),FOREIGN KEY(term_id) REFERENCES m_terms(id)) ENGINE=InnoDB');
        }$this->ready=true;$this->db->markFeatureReady('taxonomy15');
    }
    public function decorate(array $rows):array
    {$this->storage();$icons=array_column($this->db->all('SELECT term_id,icon FROM m_term_icons'),'icon','term_id');foreach($rows as &$row){$row['icon']=$icons[$row['id']]??'book';}unset($row);return $rows;}
    public function icon(string $id,string $icon):void
    {Policy::choice($icon,array_keys(self::ICONS));$this->db->run('INSERT INTO m_term_icons(term_id,icon) VALUES(?,?) ON DUPLICATE KEY UPDATE icon=VALUES(icon)',[$id,$icon]);}
    public static function condition(string $version='v',string $term='ft'):string
    {
        // Aliases come only from source code. Every filter binds the chosen identifier separately.
        if(!preg_match('/^[a-z]+$/D',$version)||!preg_match('/^[a-z]+$/D',$term)){throw new \LogicException('Invalid query alias.');}
        return "$term.active=1 AND (($term.kind='topic' AND ($version.term_id=$term.id OR EXISTS(SELECT 1 FROM m_term_redirects rd WHERE rd.source_id=$version.term_id AND rd.target_id=$term.id) OR EXISTS(SELECT 1 FROM m_experience_topics xt LEFT JOIN m_term_redirects xr ON xr.source_id=xt.term_id WHERE xt.version_id=$version.id AND COALESCE(xr.target_id,xt.term_id)=$term.id) OR EXISTS(SELECT 1 FROM m_terms legacy WHERE legacy.id=$version.term_id AND legacy.kind='issue' AND legacy.active=1 AND legacy.parent_id=$term.id))) OR ($term.kind='issue' AND ($version.term_id=$term.id OR EXISTS(SELECT 1 FROM m_term_redirects rd WHERE rd.source_id=$version.term_id AND rd.target_id=$term.id) OR EXISTS(SELECT 1 FROM m_experience_issues xi LEFT JOIN m_term_redirects xr ON xr.source_id=xi.term_id WHERE xi.version_id=$version.id AND COALESCE(xr.target_id,xi.term_id)=$term.id))))";
    }
    public function labels(array $versions):array
    {
        if(!$versions){return [];}$this->storage();$versions=array_values(array_unique($versions));$marks=implode(',',array_fill(0,count($versions),'?'));$out=[];
        foreach($this->db->all("SELECT v.id,t.id AS term_id,t.title,t.kind,p.id AS parent_id,p.title AS parent_title,ic.icon AS own_icon,ip.icon AS parent_icon FROM m_experience_versions v LEFT JOIN m_term_redirects vr ON vr.source_id=v.term_id LEFT JOIN m_terms t ON t.id=COALESCE(vr.target_id,v.term_id) AND t.active=1 LEFT JOIN m_terms p ON p.id=t.parent_id AND p.active=1 LEFT JOIN m_term_icons ic ON ic.term_id=t.id LEFT JOIN m_term_icons ip ON ip.term_id=p.id WHERE v.id IN ($marks)",$versions) as $row){
            $topic=null;$issues=[];
            if($row['kind']==='topic'){$topic=['id'=>$row['term_id'],'title'=>$row['title'],'icon'=>$row['own_icon']??'book'];}
            elseif($row['kind']==='issue'){$issues[$row['term_id']]=['id'=>$row['term_id'],'title'=>$row['title']];if($row['parent_id']){$topic=['id'=>$row['parent_id'],'title'=>$row['parent_title'],'icon'=>$row['parent_icon']??'book'];}}
            $out[$row['id']]=['topic'=>$topic,'issues'=>$issues];
        }
        foreach($out as &$labels){$labels['topics']=$labels['topic']?[$labels['topic']['id']=>$labels['topic']]:[];}unset($labels);
        foreach($this->db->all("SELECT x.version_id,t.id,t.title,t.kind,COALESCE(i.icon,'book') AS icon FROM (SELECT version_id,term_id FROM m_experience_issues WHERE version_id IN ($marks) UNION SELECT version_id,term_id FROM m_experience_topics WHERE version_id IN ($marks)) x LEFT JOIN m_term_redirects xr ON xr.source_id=x.term_id JOIN m_terms t ON t.id=COALESCE(xr.target_id,x.term_id) AND t.active=1 LEFT JOIN m_term_icons i ON i.term_id=t.id ORDER BY t.title",[...$versions,...$versions]) as $row){$key=$row['kind']==='topic'?'topics':'issues';$out[$row['version_id']][$key][$row['id']]=['id'=>$row['id'],'title'=>$row['title'],'icon'=>$row['icon']];}
        foreach($out as &$labels){$labels['issues']=array_values($labels['issues']);$labels['topics']=array_values($labels['topics']);}unset($labels);return $out;
    }
    public function assign(string $version,array $input,?string $previous):void
    {
        $topics=$input['topics']??(isset($input['_topics_present'])?[]:($previous?array_column($this->labels([$previous])[$previous]['topics']??[],'id'):[]));
        if(!is_array($topics)||!array_is_list($topics)||count($topics)>20){throw new Problem('INVALID_TOPIC','حداکثر بیست موضوع انتخاب کنید.');}
        if(!empty($input['term_id'])){$term=$this->db->one("SELECT id FROM m_terms WHERE id=? AND kind='topic' AND active=1",[$input['term_id']]);if($term){$topics[]=$term['id'];}}
        foreach(array_unique($topics) as $topic){if(!is_string($topic)||!Security::validId($topic)||!$this->db->one("SELECT id FROM m_terms WHERE id=? AND kind='topic' AND active=1",[$topic])){throw new Problem('INVALID_TOPIC','موضوع معتبر نیست.');}$this->db->run('INSERT IGNORE INTO m_experience_topics VALUES(?,?)',[$version,$topic]);}
        $ids=$input['issues']??(isset($input['_issues_present'])?[]:($previous?array_column($this->labels([$previous])[$previous]['issues']??[],'id'):[]));
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>20){throw new Problem('INVALID_TOPIC','حداکثر بیست مسئله از فهرست انتخاب کنید.');}
        foreach($ids as $id){if(!is_string($id)||!Security::validId($id)||!$this->db->one("SELECT id FROM m_terms WHERE id=? AND kind='issue' AND active=1",[$id])){throw new Problem('INVALID_TOPIC','مسئلهٔ انتخاب‌شده معتبر نیست.');}}
        $this->db->run('DELETE FROM m_experience_issues WHERE version_id=?',[$version]);foreach(array_unique($ids) as $id){$this->db->run('INSERT INTO m_experience_issues(version_id,term_id) VALUES(?,?)',[$version,$id]);}
    }
    public function topics():array
    {
        $this->storage();$condition=self::condition();
        return $this->decorate($this->db->all("SELECT ft.*, (SELECT COUNT(*) FROM m_experiences e JOIN m_experience_versions v ON v.id=e.public_version_id AND v.experience_id=e.id JOIN m_mentors m ON m.user_id=e.mentor_id JOIN m_users u ON u.id=m.user_id WHERE m.status='active' AND u.status='active' AND $condition) AS experience_count FROM m_terms ft WHERE ft.kind='topic' AND ft.active=1 ORDER BY ft.title"));
    }
    public function resolve(string $id):string
    {$this->storage();return $this->db->one('SELECT target_id FROM m_term_redirects WHERE source_id=?',[$id])['target_id']??$id;}
    public function topic(string $id):array
    {$id=$this->resolve($id);foreach($this->topics() as $topic){if($topic['id']===$id){return $topic;}}throw new Problem('NOT_FOUND','موضوع در دسترس نیست.',404);}
}
