<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Security,Problem};

/** Private, opt-in analytics. Never part of a booking transaction and never a public graph source. */
final class InteractionEvents
{
    public const CATALOG=[
        'SEARCH_SUBMITTED'=>['query_length','result_kind'], 'SEARCH_RESULT_OPENED'=>['position'],
        'EXPERIENCE_VIEWED'=>[], 'MENTOR_PROFILE_VIEWED'=>[],
        'CONSULTATION_REQUESTED'=>['experience_id'], 'CONSULTATION_CONFIRMED'=>[],
        'SESSION_STARTED'=>[], 'SESSION_COMPLETED'=>[], 'SESSION_CANCELLED'=>[], 'FEEDBACK_SUBMITTED'=>['score'],
    ];
    public static int $failures=0;
    public function __construct(private Database $db){}
    public function record(?array $actor,string $event,string $type,string $id,array $properties,string $key):bool
    {
        if($this->db->pdo->inTransaction()){self::$failures++;return false;}
        try{
            if(!isset(self::CATALOG[$event])||!in_array($type,Knowledge::TYPES,true)||strlen($id)>80||strlen($key)>200||$key===''){return false;}
            // SESSION_STARTED can only be emitted by a future real communication backend.
            if($event==='SESSION_STARTED'){return false;}
            (new Knowledge($this->db))->storage();$policy=$this->db->one('SELECT * FROM m_interaction_policy WHERE id=1');
            if(!$policy||!(int)$policy['enabled']||!$policy['approved_at']||!(int)$policy['retention_days']){return false;}
            if(array_diff(array_keys($properties),self::CATALOG[$event])){return false;}
            foreach($properties as $name=>$value){if($name==='score'&&(!is_int($value)||$value<1||$value>5)){return false;}if($name==='query_length'&&(!is_int($value)||$value<0||$value>160)){return false;}if($name==='position'&&(!is_int($value)||$value<0||$value>10000)){return false;}if($name==='experience_id'&&(!is_string($value)||!Security::validId($value))){return false;}if($name==='result_kind'&&!in_array($value,['mentors','experiences','atlas','organizations'],true)){return false;}}
            $uid=$actor['user_id']??null;if($uid&&!$this->db->one("SELECT id FROM m_users WHERE id=? AND status='active'",[$uid])){return false;}
            if(!($event==='SEARCH_SUBMITTED'&&$id==='')&&!(new Knowledge($this->db))->entity($type,$id)){return false;}
            $dedup=hash('sha256',($uid??'guest').'|'.$event.'|'.$type.'|'.$id.'|'.$key);
            $this->db->run('INSERT IGNORE INTO m_interaction_events VALUES(?,?,?,?,?,?,?,?)',[Security::id(),$event,$uid,$type,$id,json_encode($properties,JSON_THROW_ON_ERROR),$dedup,time()]);return true;
        }catch(\Throwable){self::$failures++;return false;}
    }
    public function configure(array $a,bool $enabled,?int $days,bool $approved):void
    {
        (new Policy($this->db))->admin($a,true);(new Knowledge($this->db))->storage();
        if($enabled&&(!$approved||$days===null||$days<1||$days>3650)){throw new Problem('POLICY_REQUIRED','فعال‌سازی به تأیید سیاست نگهداری و تعیین مدت نیاز دارد.');}
        $this->db->tx(function()use($a,$enabled,$days,$approved){$this->db->run('UPDATE m_interaction_policy SET enabled=?,retention_days=?,approved_by=?,approved_at=? WHERE id=1',[$enabled?1:0,$days,$approved?$a['user_id']:null,$approved?time():null]);(new \Morshed\Core\Audit($this->db))->add($a['user_id'],'admin','interaction.policy_changed','1');});
    }
    public function purge():int
    {
        (new Knowledge($this->db))->storage();$policy=$this->db->one('SELECT retention_days,approved_at FROM m_interaction_policy WHERE id=1');if(!$policy||!$policy['approved_at']||!(int)$policy['retention_days']){return 0;}
        return $this->db->run('DELETE FROM m_interaction_events WHERE created_at<? LIMIT 5000',[time()-(int)$policy['retention_days']*86400])->rowCount();
    }
}
