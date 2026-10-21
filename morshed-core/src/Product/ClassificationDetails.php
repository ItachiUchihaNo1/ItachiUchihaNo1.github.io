<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Problem,Security};

/** Additive metadata and indexed ancestry for the existing m_terms catalog. */
final class ClassificationDetails
{
    public function __construct(private Database $db){}
    public function storage():void
    {
        if($this->db->featureReady('classification17')){return;}
        try{$this->db->run('SELECT id FROM m_classification_state LIMIT 0');}
        catch(\PDOException $e){
            if((int)($e->errorInfo[1]??0)!==1146||$this->db->pdo->inTransaction()){throw $e;}
            foreach(explode(';',file_get_contents(__DIR__.'/../../database/taxonomy17.sql')) as $sql){if(trim($sql)!==''){$this->db->run($sql);}}
            $this->db->tx(function(){
                $this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');$this->rebuildPaths();
                $this->db->run("INSERT IGNORE INTO m_issue_topics SELECT i.id,t.id FROM m_terms i JOIN m_terms t ON t.id=i.parent_id AND t.kind='topic' WHERE i.kind='issue'");
            });
            // Last marker: an interrupted bootstrap safely rebuilds the derived index.
            $this->db->run('CREATE TABLE IF NOT EXISTS m_classification_state (id TINYINT PRIMARY KEY) ENGINE=InnoDB');
        }$this->db->markFeatureReady('classification17');
    }
    public function validateParent(string $id,?string $parent):void
    {
        if(!$parent){return;}$rows=array_column($this->db->all("SELECT id,parent_id FROM m_terms WHERE kind='topic'"),null,'id');
        if(!isset($rows[$parent])||!$this->db->one('SELECT id FROM m_terms WHERE id=? AND active=1',[$parent])){throw new Problem('INVALID_TOPIC','موضوع والد معتبر نیست.');}
        $seen=[$id=>true];while($parent){if(isset($seen[$parent])){throw new Problem('TOPIC_CYCLE','موضوع نمی‌تواند والد خودش یا یکی از والدهایش باشد.',409);}$seen[$parent]=true;$parent=$rows[$parent]['parent_id']??null;}
    }
    public function rebuildPaths():void
    {
        $rows=array_column($this->db->all("SELECT id,parent_id FROM m_terms WHERE kind='topic'"),null,'id');$values=[];$args=[];
        foreach($rows as $id=>$row){$parent=$id;$seen=[];$depth=0;while($parent&&isset($rows[$parent])){
            if(isset($seen[$parent])){throw new Problem('TOPIC_CYCLE','چرخهٔ موضوعات باید اصلاح شود.',409);}$seen[$parent]=true;
            $values[]='(?,?,?)';array_push($args,$parent,$id,$depth++);$parent=$rows[$parent]['parent_id'];
        }}$this->db->run('DELETE FROM m_topic_paths');
        foreach(array_chunk($values,500) as $n=>$chunk){$this->db->run('INSERT INTO m_topic_paths VALUES'.implode(',',$chunk),array_slice($args,$n*1500,count($chunk)*3));}
    }
    public function save(string $id,array $d):void
    {
        $old=$this->db->one('SELECT * FROM m_term_metadata WHERE term_id=?',[$id])??[];
        $label=Policy::text($d['short_label']??$old['short_label']??'',400,0);$description=Policy::text($d['description']??$old['description']??'',24000,0);
        if(preg_match_all('/./us',$label)>100||preg_match_all('/./us',$description)>6000){throw new Problem('INVALID_INPUT','برچسب یا توضیح بیش از حد طولانی است.');}$icon=array_key_exists('icon_asset_id',$d)?$d['icon_asset_id']:($old['icon_asset_id']??null);
        $order=Policy::integer($d['display_order']??$old['display_order']??0,0,1000000);
        $this->db->run('INSERT INTO m_term_metadata VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE short_label=VALUES(short_label),description=VALUES(description),icon_asset_id=VALUES(icon_asset_id),display_order=VALUES(display_order),updated_at=VALUES(updated_at)',[$id,$label,$description,$icon?:null,$order,$old['created_at']??time(),time()]);
        if($label!==''){$this->db->run('INSERT IGNORE INTO m_term_aliases VALUES(?,?)',[$id,$label]);}
        if(array_key_exists('related_topics',$d)){
            if(!is_array($d['related_topics'])||count($d['related_topics'])>100){throw new Problem('INVALID_TOPIC','موضوعات مرتبط معتبر نیستند.');}
            $this->db->run('DELETE FROM m_issue_topics WHERE issue_id=?',[$id]);
            foreach(array_unique($d['related_topics']) as $topic){if(!is_string($topic)||!Security::validId($topic)||!$this->db->one("SELECT id FROM m_terms WHERE id=? AND kind='topic' AND active=1",[$topic])){throw new Problem('INVALID_TOPIC','موضوع مرتبط معتبر نیست.');}$this->db->run('INSERT INTO m_issue_topics VALUES(?,?)',[$id,$topic]);}
        }
    }
    public function metadata():array
    {$this->storage();return array_column($this->db->all('SELECT * FROM m_term_metadata'),null,'term_id');}
    public function breadcrumbs(string $id):array
    {$this->storage();return $this->db->all('SELECT t.id,t.title FROM m_topic_paths p JOIN m_terms t ON t.id=p.ancestor_id AND t.active=1 WHERE p.descendant_id=? ORDER BY p.depth DESC',[$id]);}
}
