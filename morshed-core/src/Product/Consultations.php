<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Security,Audit,Problem};
final class Consultations
{
    private Policy $policy;
    public function __construct(private Database $db,private Audit $audit){$this->policy=new Policy($db);}
    private function lock():void{$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');}
    public function settings(array $a,array $d):void
    {$this->db->tx(function()use($a,$d){$this->lock();$this->policy->mentor($a);$uid=$a['user_id'];$this->db->run('UPDATE m_schedule_settings SET duration_minutes=?,buffer_minutes=?,lead_hours=?,daily_capacity=?,auto_accept=?,acceptance_paused=?,access_scope=? WHERE mentor_id=?',[Policy::integer($d['duration_minutes']??30,15,120),Policy::integer($d['buffer_minutes']??10,0,120),Policy::integer($d['lead_hours']??24,1,720),Policy::integer($d['daily_capacity']??4,1,30),Policy::integer($d['auto_accept']??0,0,1),Policy::integer($d['acceptance_paused']??0,0,1),Policy::choice($d['access_scope']??'public',['public','organization']),$uid]);$this->audit->add($uid,'site','schedule.settings_saved',$uid);});}
    public function rule(array $a,array $d):void
    {$this->db->tx(function()use($a,$d){$this->lock();$this->policy->mentor($a);$uid=$a['user_id'];$weekday=Policy::integer($d['weekday']??'',0,6);foreach(['start_time'=>'start_minute','end_time'=>'end_minute'] as $timeKey=>$minuteKey){if(isset($d[$timeKey])){$value=Policy::text($d[$timeKey],5);if(!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D',$value,$parts)){throw new Problem('INVALID_TIME','ساعت معتبر انتخاب کنید.');}$d[$minuteKey]=(int)$parts[1]*60+(int)$parts[2];}}$start=Policy::integer($d['start_minute']??'',0,1439);$end=Policy::integer($d['end_minute']??'',$start+1,1440);
        if((int)$this->db->run('SELECT COUNT(*) FROM m_schedule_rules WHERE mentor_id=?',[$uid])->fetchColumn()>=30){throw new Problem('LIMIT_REACHED','حداکثر سی بازه هفتگی مجاز است.');}if($this->db->one('SELECT id FROM m_schedule_rules WHERE mentor_id=? AND weekday=? AND start_minute<? AND end_minute>?',[$uid,$weekday,$end,$start])){throw new Problem('OVERLAP','بازه هفتگی هم‌پوشانی دارد.',409);}
        $this->db->run('INSERT INTO m_schedule_rules VALUES(?,?,?,?,?)',[Security::id(),$uid,$weekday,$start,$end]);$this->audit->add($uid,'site','schedule.rule_added',$uid);});}
    public function removeRule(array $a,string $id):void
    {$this->db->tx(function()use($a,$id){$this->lock();$this->policy->mentor($a);$this->db->run('DELETE FROM m_schedule_rules WHERE id=? AND mentor_id=?',[$id,$a['user_id']]);$this->audit->add($a['user_id'],'site','schedule.rule_removed',$id);});}
    private function date(string $s):\DateTimeImmutable
    {$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$s,new \DateTimeZone('Asia/Tehran'));if(!$d||$d->format('Y-m-d')!==$s){throw new Problem('INVALID_DATE','تاریخ میلادی با قالب YYYY-MM-DD لازم است.');}return $d;}
    public function exception(array $a,string $date,bool $remove=false):void
    {$date=$this->date($date)->format('Y-m-d');$this->db->tx(function()use($a,$date,$remove){$this->lock();$this->policy->mentor($a);if($remove){$this->db->run('DELETE FROM m_schedule_exceptions WHERE mentor_id=? AND local_date=?',[$a['user_id'],$date]);}else{$this->db->run('INSERT IGNORE INTO m_schedule_exceptions VALUES(?,?)',[$a['user_id'],$date]);}$this->audit->add($a['user_id'],'site','schedule.exception_changed',$a['user_id']);});}
    public function expire():void
    {
        $work=function(){ $this->lock();$now=time();
            $this->db->run("INSERT INTO m_booking_events(booking_id,event,note,created_at) SELECT id,'expired','مهلت پذیرش پایان یافت.',? FROM m_bookings WHERE state='pending' AND expires_at<=?",[$now,$now]);
            $this->db->run("INSERT INTO m_audit_logs(realm,event,subject_id,created_at) SELECT 'system','booking.expired',id,? FROM m_bookings WHERE state='pending' AND expires_at<=?",[$now,$now]);
            $this->db->run("UPDATE m_bookings SET state='expired' WHERE state='pending' AND expires_at<=?",[$now]);
            $this->db->run("INSERT INTO m_booking_events(booking_id,event,note,created_at) SELECT booking_id,'proposal_expired','پیشنهاد تغییر زمان منقضی شد؛ زمان قبلی حفظ شد.',? FROM m_time_proposals WHERE state='pending' AND expires_at<=?",[$now,$now]);
            $this->db->run("UPDATE m_time_proposals SET state='expired' WHERE state='pending' AND expires_at<=?",[$now]);
        };if($this->db->pdo->inTransaction()){$work();}else{$this->db->tx($work);}
    }
    public static function localTimestamp(string $input):int
    {$d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$input,new \DateTimeZone('Asia/Tehran'));if(!$d||$d->format('Y-m-d\TH:i')!==$input){throw new Problem('INVALID_DATE','روز و ساعت معتبر به وقت تهران انتخاب کنید.');}return $d->getTimestamp();}
    public function slots(string $mentor,string $date,?string $exclude=null):array
    {
        $day=$this->date($date);$now=time();if($day->getTimestamp()<$now-86400||$day->getTimestamp()>$now+90*86400){throw new Problem('INVALID_DATE','تقویم تا نود روز آینده قابل مشاهده است.');}
        $s=$this->db->one("SELECT s.* FROM m_schedule_settings s JOIN m_mentors m ON m.user_id=s.mentor_id JOIN m_users u ON u.id=m.user_id WHERE s.mentor_id=? AND m.status='active' AND u.status='active'",[$mentor]);if(!$s){throw new Problem('NOT_FOUND','تقویم مرشد فعال پیدا نشد.',404);}
        if((int)$s['acceptance_paused']===1){return [];}
        if($this->db->one('SELECT mentor_id FROM m_schedule_exceptions WHERE mentor_id=? AND local_date=?',[$mentor,$date])){return [];}
        $busy=$this->db->all("SELECT id,starts_at,ends_at,buffer_minutes,local_date FROM m_bookings WHERE mentor_id=? AND (state='confirmed' OR (state='pending' AND expires_at>?)) AND starts_at<? AND ends_at>?",[$mentor,$now,$day->getTimestamp()+2*86400,$day->getTimestamp()-86400]);
        $busy=array_values(array_filter($busy,fn($b)=>$b['id']!==$exclude));$count=count(array_filter($busy,fn($b)=>$b['local_date']===$date));if($count>=(int)$s['daily_capacity']){return [];}
        $out=[];$duration=(int)$s['duration_minutes']*60;$buffer=(int)$s['buffer_minutes']*60;
        foreach($this->db->all('SELECT * FROM m_schedule_rules WHERE mentor_id=? AND weekday=? ORDER BY start_minute',[$mentor,(int)$day->format('w')]) as $r){for($minute=(int)$r['start_minute'];$minute*60+$duration<=(int)$r['end_minute']*60;$minute+=(int)$s['duration_minutes']+(int)$s['buffer_minutes']){$start=$day->getTimestamp()+$minute*60;$end=$start+$duration;if($start<$now+(int)$s['lead_hours']*3600){continue;}$free=true;foreach($busy as $b){if($start<(int)$b['ends_at']+(int)$b['buffer_minutes']*60&&$end+$buffer>(int)$b['starts_at']){$free=false;break;}}if($free){$out[]=['starts_at'=>$start,'ends_at'=>$end,'label'=>(new \DateTimeImmutable('@'.$start))->setTimezone($day->getTimezone())->format('H:i'),'date'=>$date,'buffer_minutes'=>(int)$s['buffer_minutes']];}}}
        return $out;
    }
    private function slot(string $mentor,int $start,?string $exclude=null):array
    {$date=(new \DateTimeImmutable('@'.$start))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d');foreach($this->slots($mentor,$date,$exclude) as $s){if($s['starts_at']===$start){return $s;}}throw new Problem('SLOT_UNAVAILABLE','ظرفیت یا زمان انتخاب‌شده دیگر قابل رزرو نیست.',409);}
    private function event(string $id,?string $actor,string $event,string $note='',string $realm='site'):void
    {$this->db->run('INSERT INTO m_booking_events(booking_id,actor_id,event,note,created_at) VALUES(?,?,?,?,?)',[$id,$actor,$event,$note,time()]);$this->audit->add($actor,$actor?$realm:'system','booking.'.$event,$id);}
    public function reserve(array $a,array $d,bool $internal=false):string
    {
        // Public gate deliberately has no configurable bypass. The owner requested it remain closed.
        if(!$internal){throw new Problem('BOOKING_CLOSED','رزرو عمومی تا تصویب سیاست نگهداری و شرایط ضبط بسته است.',503);}
        return $this->db->tx(function()use($a,$d){$this->lock();$this->policy->admin($a,true);$user=Policy::text($d['user_id']??'',32);$mentor=Policy::text($d['mentor_id']??'',32);$key=Policy::text($d['idempotency_key']??'',64,12);$start=Policy::integer($d['starts_at']??'',time()+1,4102444800);$problem=Policy::text($d['problem']??'',6000);
            if($user===$mentor){throw new Problem('SELF_BOOKING','رزرو با خود مجاز نیست.');}$u=$this->db->one("SELECT id FROM m_users WHERE id=? AND status='active' AND phone_verified_at IS NOT NULL",[$user]);if(!$u){throw new Problem('VERIFIED_PHONE_REQUIRED','حساب فعال با شماره تأییدشده لازم است.',409);}
            $old=$this->db->one('SELECT * FROM m_bookings WHERE user_id=? AND idempotency_key=?',[$user,$key]);if($old){if($old['mentor_id']!==$mentor||(int)$old['starts_at']!==$start||$old['problem']!==$problem){throw new Problem('IDEMPOTENCY_CONFLICT','این شناسه درخواست قبلاً برای داده دیگری استفاده شده است.',409);}return $old['id'];}
            $this->db->one('SELECT mentor_id FROM m_schedule_settings WHERE mentor_id=? FOR UPDATE',[$mentor]);$scope=$this->db->run('SELECT access_scope FROM m_schedule_settings WHERE mentor_id=?',[$mentor])->fetchColumn();if($scope==='organization'&&!$this->db->one('SELECT a.org_id FROM m_memberships a JOIN m_memberships b ON b.org_id=a.org_id JOIN m_organizations o ON o.id=a.org_id WHERE a.user_id=? AND b.user_id=? AND o.active=1',[$mentor,$user])){throw new Problem('ORGANIZATION_REQUIRED','این مرشد فقط برای اعضای سازمان مشترک مشاوره می‌پذیرد.',403);}$this->expire();$s=$this->slot($mentor,$start);if($this->db->one("SELECT id FROM m_bookings WHERE user_id=? AND state IN ('pending','confirmed') AND starts_at<? AND ends_at>?",[$user,$s['ends_at'],$start])){throw new Problem('OVERLAP','کاربر در این زمان رزرو دیگری دارد.',409);}
            $id=Security::id();$auto=(int)$this->db->run('SELECT auto_accept FROM m_schedule_settings WHERE mentor_id=?',[$mentor])->fetchColumn();$expiry=min($start-60,time()+86400);
            $this->db->run('INSERT INTO m_bookings(id,mentor_id,user_id,starts_at,ends_at,buffer_minutes,local_date,state,problem,idempotency_key,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$mentor,$user,$start,$s['ends_at'],$s['buffer_minutes'],$s['date'],$auto?'confirmed':'pending',$problem,$key,$expiry,time()]);$this->event($id,$a['user_id'],'internal_created','رزرو داخلی؛ شروع جلسه تابع شرایط مصوب است.','admin');$this->audit->notify($mentor,'درخواست مشاوره داخلی','درخواست جدید در پنل مرشد ثبت شد.','/booking?id='.$id,'sessions');return $id;
        });
    }
    public function booking(array $a,string $id,bool $admin=false):array
    {
        if($admin){$this->policy->admin($a,true);}else{$this->policy->user($a);}
        $b=$this->db->one('SELECT b.*,u.display_name AS user_name,m.display_name AS mentor_name FROM m_bookings b JOIN m_users u ON u.id=b.user_id JOIN m_users m ON m.id=b.mentor_id WHERE b.id=?',[$id]);
        if(!$b||(!$admin&&!in_array($a['user_id'],[$b['user_id'],$b['mentor_id']],true))){throw new Problem('NOT_FOUND','رزرو در دسترس نیست.',404);}return $b;
    }
    public function transition(array $a,string $id,string $action,string $note='',bool $admin=false):void
    {$this->db->tx(function()use($a,$id,$action,$note,$admin){$this->lock();$this->expire();$b=$this->booking($a,$id,$admin);$note=Policy::text($note,4000,0);$next='';
        if($action==='accept'&&$b['state']==='pending'&&($admin||$a['user_id']===$b['mentor_id'])&&(int)$b['starts_at']>time()){$this->activeMentor($b['mentor_id']);$next='confirmed';}
        if($action==='reject'&&$b['state']==='pending'&&($admin||$a['user_id']===$b['mentor_id'])){$next='rejected';}
        if($action==='cancel'&&in_array($b['state'],['pending','confirmed'],true)&&(int)$b['starts_at']>time()){$next='cancelled';}
        if($action==='complete'&&$b['state']==='confirmed'&&(int)$b['ends_at']<=time()){$next='completed';}
        if(!$next){throw new Problem('INVALID_STATE','این عملیات در وضعیت فعلی مجاز نیست.',409);}
        $this->db->run('UPDATE m_bookings SET state=? WHERE id=?',[$next,$id]);$this->db->run("UPDATE m_time_proposals SET state='rejected' WHERE booking_id=? AND state='pending'",[$id]);$this->event($id,$a['user_id'],$next,$note,$a['realm']);foreach([$b['user_id'],$b['mentor_id']] as $uid){$this->audit->notify($uid,'تغییر وضعیت رزرو','وضعیت درخواست مشاوره تغییر کرد.','/booking?id='.$id,'sessions');}});}
    public function propose(array $a,string $id,int $start):string
    {return $this->db->tx(function()use($a,$id,$start){$this->lock();$this->expire();$b=$this->booking($a,$id);if($b['state']!=='confirmed'||(int)$b['starts_at']<=time()){throw new Problem('INVALID_STATE','تغییر زمان فقط پیش از شروع رزرو قطعی مجاز است.',409);}$this->slot($b['mentor_id'],$start,$id);$this->db->run("UPDATE m_time_proposals SET state='rejected' WHERE booking_id=? AND state='pending'",[$id]);$pid=Security::id();$this->db->run('INSERT INTO m_time_proposals(id,booking_id,proposer_id,starts_at,expires_at,created_at) VALUES(?,?,?,?,?,?)',[$pid,$id,$a['user_id'],$start,min(time()+86400,(int)$b['starts_at']-60),time()]);$this->event($id,$a['user_id'],'time_proposed');return $pid;});}
    public function answerProposal(array $a,string $pid,bool $accept):void
    {$this->db->tx(function()use($a,$pid,$accept){$this->lock();$this->expire();$p=$this->db->one('SELECT * FROM m_time_proposals WHERE id=? FOR UPDATE',[$pid]);if(!$p){throw new Problem('NOT_FOUND','پیشنهاد پیدا نشد.',404);}$b=$this->booking($a,$p['booking_id']);if($p['state']!=='pending'||$b['state']!=='confirmed'||$p['proposer_id']===$a['user_id']||(int)$b['starts_at']<=time()){throw new Problem('INVALID_STATE','پیشنهاد قابل پذیرش نیست.',409);}
        if($accept){$s=$this->slot($b['mentor_id'],(int)$p['starts_at'],$b['id']);if($this->db->one("SELECT id FROM m_bookings WHERE user_id=? AND id<>? AND state IN ('pending','confirmed') AND starts_at<? AND ends_at>?",[$b['user_id'],$b['id'],$s['ends_at'],$s['starts_at']])){throw new Problem('OVERLAP','تعارض زمان در حساب کاربر وجود دارد.',409);}$this->db->run('UPDATE m_bookings SET starts_at=?,ends_at=?,buffer_minutes=?,local_date=? WHERE id=?',[$s['starts_at'],$s['ends_at'],$s['buffer_minutes'],$s['date'],$b['id']]);}
        $this->db->run('UPDATE m_time_proposals SET state=? WHERE id=?',[$accept?'accepted':'rejected',$pid]);$this->event($b['id'],$a['user_id'],$accept?'time_changed':'time_rejected');});}
    private function recordingTerms(array $b):void
    {$v=$this->db->run("SELECT version_id FROM m_legal_publications WHERE document_key='recording'")->fetchColumn();if(!$v){throw new Problem('RECORDING_POLICY_BLOCKED','شرایط ضبط و نگهداری هنوز مصوب نیست؛ جلسه آغاز نمی‌شود.',503);}foreach([$b['user_id'],$b['mentor_id']] as $uid){if(!$this->db->one('SELECT user_id FROM m_user_acceptances WHERE user_id=? AND version_id=?',[$uid,$v])){throw new Problem('RECORDING_CONSENT_REQUIRED','پذیرش معتبر شرایط آرشیو توسط هر دو طرف لازم است.',409);}}}
    private function activeMentor(string $id):void{if(!$this->db->one("SELECT m.user_id FROM m_mentors m JOIN m_users u ON u.id=m.user_id WHERE m.user_id=? AND m.status='active' AND u.status='active'",[$id])){throw new Problem('MENTOR_UNAVAILABLE','مرشد در وضعیت مجاز جلسه نیست.',409);}}
    public function writable(array $a,string $id):array
    {$b=$this->booking($a,$id);if($b['state']!=='confirmed'||time()<(int)$b['starts_at']||time()>=(int)$b['ends_at']){throw new Problem('SESSION_NOT_ACTIVE','جلسه در بازه فعال نیست.',409);}$this->activeMentor($b['mentor_id']);$this->recordingTerms($b);if((int)$this->db->run('SELECT @@GLOBAL.innodb_flush_log_at_trx_commit')->fetchColumn()!==1){throw new Problem('DURABILITY_REQUIRED','تنظیم ذخیره پایدار دیتابیس برای جلسه تأیید نشده است.',503);}return $b;}
    public function message(array $a,string $id,string $key,string $body):array
    {return $this->db->tx(function()use($a,$id,$key,$body){$this->lock();$b=$this->booking($a,$id);$key=Policy::text($key,64,12);$body=Policy::text($body,12000);$old=$this->db->one('SELECT id,body,created_at FROM m_messages WHERE booking_id=? AND sender_id=? AND client_key=?',[$id,$a['user_id'],$key]);if($old){if($old['body']!==$body){throw new Problem('IDEMPOTENCY_CONFLICT','شناسه پیام قبلاً برای متن دیگری ثبت شده است.',409);}return ['id'=>(int)$old['id'],'body'=>$old['body'],'created_at'=>(int)$old['created_at']];}
        $this->writable($a,$id);
        // ACK leaves this method only after Database::tx commits the durable row.
        $this->db->run('INSERT INTO m_messages(booking_id,sender_id,client_key,body,created_at) VALUES(?,?,?,?,?)',[$id,$a['user_id'],$key,$body,time()]);return ['id'=>(int)$this->db->pdo->lastInsertId(),'body'=>$body,'created_at'=>time()];});}
    public function archive(array $a,string $id,int $after=0):array
    {
        if(($a['realm']??'')==='admin'){$this->policy->admin($a);$grant=$this->db->one('SELECT id FROM m_archive_grants WHERE booking_id=? AND reviewer_id=? AND expires_at>? AND revoked_at IS NULL',[$id,$a['user_id'],time()]);if(!$grant){throw new Problem('ARCHIVE_GRANT_REQUIRED','مجوز موردی معتبر برای این آرشیو لازم است.',403);}$this->audit->add($a['user_id'],'admin','archive.read',$id);}
        else{$this->booking($a,$id);}
        return $this->db->all('SELECT id,sender_id,body,created_at FROM m_messages WHERE booking_id=? AND id>? ORDER BY id LIMIT 100',[$id,max(0,$after)]);
    }
}
