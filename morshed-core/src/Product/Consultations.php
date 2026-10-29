<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\{Database,Security,Audit,Problem};
final class Consultations
{
    private Policy $policy;private ConsultationData $data;private array $availabilityCache=[];private bool $browsing=false;
    public function __construct(private Database $db,private Audit $audit){$this->policy=new Policy($db);$this->data=new ConsultationData($db);}
    private function lock():void{$this->db->one('SELECT id FROM m_installation WHERE id=1 FOR UPDATE');}
    public function settings(array $a,array $d):void
    {$this->data->storage();$this->db->tx(function()use($a,$d){$this->lock();$this->policy->mentor($a,true);$uid=$a['user_id'];$old=$this->db->one('SELECT lead_hours,lead_minutes FROM m_schedule_settings WHERE mentor_id=?',[$uid]);$lead=isset($d['lead_minutes'])?Policy::integer($d['lead_minutes'],0,43200):(isset($d['lead_hours'])?Policy::integer($d['lead_hours'],0,720)*60:(int)($old['lead_minutes']??$old['lead_hours']*60));
        $this->db->run('UPDATE m_schedule_settings SET duration_minutes=?,buffer_minutes=?,lead_hours=?,lead_minutes=?,daily_capacity=?,auto_accept=?,acceptance_paused=?,access_scope=? WHERE mentor_id=?',[Policy::integer($d['duration_minutes']??30,15,120),Policy::integer($d['buffer_minutes']??10,0,120),max(1,(int)ceil($lead/60)),$lead,Policy::integer($d['daily_capacity']??4,1,30),0,Policy::integer($d['acceptance_paused']??0,0,1),Policy::choice($d['access_scope']??'public',['public','organization']),$uid]);$this->availabilityCache=[];$this->audit->add($uid,'site','schedule.settings_saved',$uid);});}
    public function rule(array $a,array $d):void
    {$this->data->storage();$this->db->tx(function()use($a,$d){$this->lock();$this->policy->mentor($a,true);$uid=$a['user_id'];$weekday=Policy::integer($d['weekday']??'',0,6);foreach(['start_time'=>'start_minute','end_time'=>'end_minute'] as $timeKey=>$minuteKey){if(isset($d[$timeKey])){$value=Policy::text($d[$timeKey],5);if(!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D',$value,$parts)){throw new Problem('INVALID_TIME','ساعت معتبر انتخاب کنید.');}$d[$minuteKey]=(int)$parts[1]*60+(int)$parts[2];}}$start=Policy::integer($d['start_minute']??'',0,1439);$end=Policy::integer($d['end_minute']??'',$start+1,1440);
        $id=Policy::text($d['id']??'',32,0);$kind=Policy::choice($d['kind']??'weekly',['daily','weekly','monthly','date']);
        if(in_array($kind,['daily','monthly'],true)){
            $ordinal=$kind==='monthly'?Policy::integer($d['ordinal']??1,1,5):1;
            if($id&&!$this->db->one('SELECT id FROM m_schedule_recurrences WHERE id=? AND mentor_id=?',[$id,$uid])){throw new Problem('NOT_FOUND','بازه پیدا نشد.',404);}
            if(!$id&&(int)$this->db->run('SELECT COUNT(*) FROM m_schedule_recurrences WHERE mentor_id=?',[$uid])->fetchColumn()>=100){throw new Problem('LIMIT_REACHED','حداکثر صد بازهٔ تکرارشونده مجاز است.');}
            if($this->db->one('SELECT id FROM m_schedule_recurrences WHERE mentor_id=? AND kind=? AND weekday=? AND ordinal=? AND id<>? AND start_minute<? AND end_minute>?',[$uid,$kind,$weekday,$ordinal,$id,$end,$start])){throw new Problem('OVERLAP','این بازه با برنامهٔ ثبت‌شده هم‌پوشانی دارد.',409);}
            if($id){$this->db->run('UPDATE m_schedule_recurrences SET kind=?,weekday=?,ordinal=?,start_minute=?,end_minute=? WHERE id=? AND mentor_id=?',[$kind,$weekday,$ordinal,$start,$end,$id,$uid]);}
            else{$id=Security::id();$this->db->run('INSERT INTO m_schedule_recurrences VALUES(?,?,?,?,?,?,?)',[$id,$uid,$kind,$weekday,$ordinal,$start,$end]);}
            $this->availabilityCache=[];$this->audit->add($uid,'site','schedule.rule_saved',$id);return;
        }$table=$kind==='weekly'?'m_schedule_rules':'m_schedule_dates';
        $date=$kind==='date'?$this->date(Policy::text($d['date']??'',10))->format('Y-m-d'):null;
        if($date&&($date<(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tehran')))->format('Y-m-d')||$date>date('Y-m-d',time()+366*86400))){throw new Problem('INVALID_DATE','زمان موردی را از امروز تا یک سال آینده انتخاب کنید.');}
        if($id&&!$this->db->one('SELECT id FROM '.$table.' WHERE id=? AND mentor_id=?',[$id,$uid])){throw new Problem('NOT_FOUND','بازه پیدا نشد.',404);}
        if(!$id&&(int)$this->db->run('SELECT COUNT(*) FROM '.$table.' WHERE mentor_id=?',[$uid])->fetchColumn()>=100){throw new Problem('LIMIT_REACHED','حداکثر صد بازه برای هر نوع مجاز است.');}
        $column=$kind==='weekly'?'weekday':'local_date';$value=$kind==='weekly'?$weekday:$date;
        if($this->db->one('SELECT id FROM '.$table.' WHERE mentor_id=? AND '.$column.'=? AND id<>? AND start_minute<? AND end_minute>?',[$uid,$value,$id,$end,$start])){throw new Problem('OVERLAP','این بازه با برنامه ثبت‌شده هم‌پوشانی دارد.',409);}
        if($id){$this->db->run('UPDATE '.$table.' SET '.$column.'=?,start_minute=?,end_minute=? WHERE id=? AND mentor_id=?',[$value,$start,$end,$id,$uid]);}
        else{$id=Security::id();$this->db->run('INSERT INTO '.$table.' VALUES(?,?,?,?,?)',[$id,$uid,$value,$start,$end]);}
        $this->availabilityCache=[];$this->audit->add($uid,'site','schedule.rule_saved',$id);});}
    public function removeRule(array $a,string $id,string $kind='weekly'):void
    {$this->data->storage();$kind=Policy::choice($kind,['daily','weekly','monthly','date']);$table=match($kind){'weekly'=>'m_schedule_rules','date'=>'m_schedule_dates',default=>'m_schedule_recurrences'};$this->db->tx(function()use($a,$id,$table){$this->lock();$this->policy->mentor($a,true);$this->db->run('DELETE FROM '.$table.' WHERE id=? AND mentor_id=?',[$id,$a['user_id']]);$this->availabilityCache=[];$this->audit->add($a['user_id'],'site','schedule.rule_removed',$id);});}
    private function date(string $s):\DateTimeImmutable
    {$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$s,new \DateTimeZone('Asia/Tehran'));if(!$d||$d->format('Y-m-d')!==$s){throw new Problem('INVALID_DATE','تاریخ میلادی با قالب YYYY-MM-DD لازم است.');}return $d;}
    public function exception(array $a,string $date,bool $remove=false):void
    {$date=$this->date($date)->format('Y-m-d');$this->db->tx(function()use($a,$date,$remove){$this->lock();$this->policy->mentor($a,true);if($remove){$this->db->run('DELETE FROM m_schedule_exceptions WHERE mentor_id=? AND local_date=?',[$a['user_id'],$date]);}else{$this->db->run('INSERT IGNORE INTO m_schedule_exceptions VALUES(?,?)',[$a['user_id'],$date]);}$this->availabilityCache=[];$this->audit->add($a['user_id'],'site','schedule.exception_changed',$a['user_id']);});}
    public function expire():void
    {
        (new Membership($this->db))->storage();
        $work=function(){ $this->lock();$now=time();
            $this->db->run("INSERT INTO m_booking_events(booking_id,event,note,created_at) SELECT id,'expired','مهلت پذیرش پایان یافت.',? FROM m_bookings WHERE state='pending' AND expires_at<=?",[$now,$now]);
            $this->db->run("INSERT INTO m_audit_logs(realm,event,subject_id,created_at) SELECT 'system','booking.expired',id,? FROM m_bookings WHERE state='pending' AND expires_at<=?",[$now,$now]);
            foreach($this->db->all("SELECT id,user_id,mentor_id FROM m_bookings WHERE state='pending' AND expires_at<=?",[$now]) as $b){(new ConsultationCredits($this->db))->settle($b,'expired',null,'پایان مهلت پاسخ مرشد');foreach([$b['user_id'],$b['mentor_id']] as $uid){$this->audit->notify($uid,'درخواست مشاوره منقضی شد','مهلت پاسخ پایان یافت و زمان آزاد شد.','/booking?id='.$b['id'],'sessions');}}
            $this->db->run("UPDATE m_bookings SET state='expired' WHERE state='pending' AND expires_at<=?",[$now]);
            $this->db->run("INSERT INTO m_booking_events(booking_id,event,note,created_at) SELECT booking_id,'proposal_expired','پیشنهاد تغییر زمان منقضی شد؛ زمان قبلی حفظ شد.',? FROM m_time_proposals WHERE state='pending' AND expires_at<=?",[$now,$now]);
            $this->db->run("UPDATE m_time_proposals SET state='expired' WHERE state='pending' AND expires_at<=?",[$now]);
        };if($this->db->pdo->inTransaction()){$work();}else{$this->db->tx($work);}
    }
    public static function localTimestamp(string $input):int
    {$d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$input,new \DateTimeZone('Asia/Tehran'));if(!$d||$d->format('Y-m-d\TH:i')!==$input){throw new Problem('INVALID_DATE','روز و ساعت معتبر به وقت تهران انتخاب کنید.');}return $d->getTimestamp();}
    /** Month/day in the Persian calendar, for the supported 1900–2100 scheduling window. */
    public static function persianMonthDay(\DateTimeImmutable $date):array
    {
        $gy=(int)$date->format('Y');$gm=(int)$date->format('n');$gd=(int)$date->format('j');
        $offset=[0,31,59,90,120,151,181,212,243,273,304,334];$gy2=$gm>2?$gy+1:$gy;
        $days=355666+365*$gy+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd+$offset[$gm-1];
        $days%=12053;$days%=1461;if($days>365){$days=($days-1)%365;}
        return $days<186?[1+intdiv($days,31),1+$days%31]:[7+intdiv($days-186,30),1+($days-186)%30];
    }
    public static function sessionLabel(int $timestamp):string {$d=(new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Asia/Tehran'));[$m,$day]=self::persianMonthDay($d);$year=(int)$d->format('Y')-((int)$d->format('n')<=3&&$m>=10?622:621);return sprintf('%04d/%02d/%02d',$year,$m,$day).' ساعت '.$d->format('H:i').' به وقت تهران';}
    private function availability(string $mentor):array
    {
        if($this->browsing&&isset($this->availabilityCache[$mentor])){return $this->availabilityCache[$mentor];}
        $s=$this->db->one("SELECT s.* FROM m_schedule_settings s JOIN m_mentors m ON m.user_id=s.mentor_id JOIN m_users u ON u.id=m.user_id WHERE s.mentor_id=? AND m.status='active' AND u.status='active'",[$mentor]);
        if(!$s){throw new Problem('NOT_FOUND','تقویم مرشد فعال پیدا نشد.',404);}
        return $this->availabilityCache[$mentor]=[
            'settings'=>$s,
            'ranges'=>$this->db->all("SELECT 'weekly' AS kind,weekday,1 AS ordinal,NULL AS local_date,start_minute,end_minute FROM m_schedule_rules WHERE mentor_id=? UNION ALL SELECT 'date',0,1,local_date,start_minute,end_minute FROM m_schedule_dates WHERE mentor_id=? UNION ALL SELECT kind,weekday,ordinal,NULL,start_minute,end_minute FROM m_schedule_recurrences WHERE mentor_id=?",[$mentor,$mentor,$mentor]),
            'exceptions'=>array_column($this->db->all('SELECT local_date FROM m_schedule_exceptions WHERE mentor_id=?',[$mentor]),'local_date'),
            'busy'=>$this->db->all("SELECT id,mentor_id,starts_at,ends_at,buffer_minutes,local_date FROM m_bookings WHERE (mentor_id=? OR user_id=?) AND (state='confirmed' OR (state='pending' AND expires_at>?)) AND starts_at<? AND ends_at>?",[$mentor,$mentor,time(),time()+92*86400,time()-86400])
        ];
    }
    public function slots(string $mentor,string $date,?string $exclude=null,?int $minutes=null,bool $legacy=false):array
    {
        $this->data->storage();$day=$this->date($date);$now=time();$today=(new \DateTimeImmutable('today',new \DateTimeZone('Asia/Tehran')));
        if($day<$today||$day>$today->modify('+90 days')){throw new Problem('INVALID_DATE','تقویم از امروز تا نود روز آینده قابل مشاهده است.');}
        if(!$legacy&&$minutes!==null&&!in_array($minutes,[15,30],true)){throw new Problem('INVALID_DURATION','مدت گفت‌وگو باید ۱۵ یا ۳۰ دقیقه باشد.');}
        $data=$this->availability($mentor);$s=$data['settings'];if((int)$s['acceptance_paused']===1||in_array($date,$data['exceptions'],true)){return [];}
        $busy=array_values(array_filter($data['busy'],fn($b)=>$b['id']!==$exclude));$count=count(array_filter($busy,fn($b)=>$b['mentor_id']===$mentor&&$b['local_date']===$date));if($count>=(int)$s['daily_capacity']){return [];}
        $minutes??=(int)$s['duration_minutes'];$duration=$minutes*60;$buffer=(int)$s['buffer_minutes']*60;
        [$month,$monthDay]=self::persianMonthDay($day);[$nextMonth]=self::persianMonthDay($day->modify('+7 days'));$weekday=(int)$day->format('w');$ranges=[];
        foreach($data['ranges'] as $r){$match=match($r['kind']){'daily'=>true,'date'=>$r['local_date']===$date,'monthly'=>(int)$r['weekday']===$weekday&&((int)$r['ordinal']===5?$nextMonth!==$month:intdiv($monthDay-1,7)+1===(int)$r['ordinal']),default=>(int)$r['weekday']===$weekday};if($match){$ranges[]=$r;}}
        // Merge overlapping free ranges first: differing recurrence types must not create off-grid duplicates.
        usort($ranges,fn($a,$b)=>(int)$a['start_minute']<=>(int)$b['start_minute']);$merged=[];foreach($ranges as $r){$i=count($merged)-1;if($i>=0&&(int)$r['start_minute']<=(int)$merged[$i]['end_minute']){$merged[$i]['end_minute']=max($merged[$i]['end_minute'],$r['end_minute']);}else{$merged[]=$r;}}
        $out=[];foreach($merged as $r){for($minute=(int)$r['start_minute'];$minute+$minutes<=(int)$r['end_minute'];$minute+=$minutes+(int)$s['buffer_minutes']){
            $start=$day->getTimestamp()+$minute*60;$end=$start+$duration;if($start<$now+(int)($s['lead_minutes']??$s['lead_hours']*60)*60||$start<=$now+60){continue;}$free=true;
            foreach($busy as $b){if($start<(int)$b['ends_at']+(int)$b['buffer_minutes']*60&&$end+$buffer>(int)$b['starts_at']){$free=false;break;}}
            if($free){$out[]=['starts_at'=>$start,'ends_at'=>$end,'label'=>(new \DateTimeImmutable('@'.$start))->setTimezone($day->getTimezone())->format('H:i'),'date'=>$date,'buffer_minutes'=>(int)$s['buffer_minutes']];}
        }}return $out;
    }
    public function browse(string $mentor,string $requested='',int $minutes=30):array
    {
        $this->availabilityCache=[];$this->browsing=true;try{
        $today=new \DateTimeImmutable('today',new \DateTimeZone('Asia/Tehran'));$max=$today->modify('+90 days');$day=$today;$corrected=false;
        if($requested!==''){try{$day=$this->date($requested);}catch(Problem){$corrected=true;}if($day<$today||$day>$max){$day=$today;$corrected=true;}}
        $selected=$day->format('Y-m-d');$slots=$this->slots($mentor,$selected,null,$minutes);$next=null;
        if(!$slots){for($d=$day->modify('+1 day');$d<=$max;$d=$d->modify('+1 day')){if($this->slots($mentor,$d->format('Y-m-d'),null,$minutes)){$next=$d->format('Y-m-d');break;}}}
        $settings=$this->availability($mentor)['settings'];
        return ['date'=>$selected,'slots'=>$slots,'nextDate'=>$next,'dateCorrected'=>$corrected,'duration'=>$minutes,'leadMinutes'=>(int)($settings['lead_minutes']??$settings['lead_hours']*60),'bufferMinutes'=>(int)$settings['buffer_minutes'],'minDate'=>$today->format('Y-m-d'),'maxDate'=>$max->format('Y-m-d')];
        }finally{$this->browsing=false;$this->availabilityCache=[];}
    }
    private function slot(string $mentor,int $start,?string $exclude=null,?int $minutes=null):array
    {
        // Revalidate under the booking transaction lock; never reuse a browsing snapshot.
        $this->availabilityCache=[];$date=(new \DateTimeImmutable('@'.$start))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d');
        $legacy=$minutes!==null&&!in_array($minutes,[15,30],true);
        foreach($this->slots($mentor,$date,$exclude,$minutes,$legacy) as $s){if($s['starts_at']===$start){return $s;}}throw new Problem('SLOT_UNAVAILABLE','ظرفیت یا زمان انتخاب‌شده دیگر قابل رزرو نیست.',409);
    }
    private function event(string $id,?string $actor,string $event,string $note='',string $realm='site'):void
    {$this->db->run('INSERT INTO m_booking_events(booking_id,actor_id,event,note,created_at) VALUES(?,?,?,?,?)',[$id,$actor,$event,$note,time()]);$this->audit->add($actor,$actor?$realm:'system','booking.'.$event,$id);}
    public static function publicBookingOpen():bool{return true;}
    public function reserve(array $a,array $d,bool $internal=false):string
    {
        // Public request intake is enabled by the owner; communication remains separately gated.
        if(!$internal&&!self::publicBookingOpen()){throw new Problem('BOOKING_CLOSED','رزرو عمومی تا آماده‌شدن زیرساخت برگزاری جلسه بسته است.',503);}
        $this->data->storage();(new Membership($this->db))->storage();return $this->db->tx(function()use($a,$d,$internal){$this->lock();if($internal){$this->policy->admin($a,true);$user=Policy::text($d['user_id']??'',32);}else{$user=$this->policy->user($a);}if((new Membership($this->db))->policy()['enabled']){$d=(new PersonalProblems($this->db))->requestInput($user,$d);}$mentor=Policy::text($d['mentor_id']??'',32);$key=Policy::text($d['idempotency_key']??'',64,12);$start=Policy::integer($d['starts_at']??'',time()+1,4102444800);if(isset($d['selected_date'])){$selectedDate=$this->date(Policy::text($d['selected_date'],10))->format('Y-m-d');if((new \DateTimeImmutable('@'.$start))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d')!==$selectedDate)throw new Problem('DATE_MISMATCH','زمان انتخاب‌شده متعلق به این تاریخ نیست؛ زمان‌های همین روز را دوباره نمایش دهید.',409);}$problem=Policy::text($d['problem']??'',6000);$subject=Policy::text($d['subject']??'درخواست مشاوره',180);$method=Policy::choice($d['requested_method']??'audio',['audio','video','chat']);$experience=Policy::text($d['experience_id']??'',32,0);$minutes=isset($d['duration_minutes'])?Policy::integer($d['duration_minutes'],15,30):($internal?null:30);if($minutes!==null&&!in_array($minutes,[15,30],true)){throw new Problem('INVALID_DURATION','مدت گفت‌وگو باید ۱۵ یا ۳۰ دقیقه باشد.');}$experienceTitle='';if($experience){$row=$this->db->one('SELECT v.title FROM m_experiences e JOIN m_experience_versions v ON v.id=e.public_version_id WHERE e.id=? AND e.mentor_id=?',[$experience,$mentor]);if(!$row){throw new Problem('INVALID_EXPERIENCE','تجربه منتشرشده متعلق به این مرشد نیست.');}$experienceTitle=$row['title'];}
            if($user===$mentor){throw new Problem('SELF_BOOKING','رزرو با خود مجاز نیست.');}$u=$this->db->one("SELECT id FROM m_users WHERE id=? AND status='active' AND phone_verified_at IS NOT NULL",[$user]);if(!$u){throw new Problem('VERIFIED_PHONE_REQUIRED','حساب فعال با شماره تأییدشده لازم است.',409);}
            $old=$this->db->one('SELECT * FROM m_bookings WHERE user_id=? AND idempotency_key=?',[$user,$key]);if($old){if($old['mentor_id']!==$mentor||(int)$old['starts_at']!==$start||$old['problem']!==$problem||($minutes!==null&&(int)$old['ends_at']-(int)$old['starts_at']!==$minutes*60)||($this->db->one('SELECT subject,experience_id,requested_method FROM m_booking_details WHERE booking_id=?',[$old['id']])??[])!==['subject'=>$subject,'experience_id'=>$experience?:null,'requested_method'=>$method]){throw new Problem('IDEMPOTENCY_CONFLICT','این شناسه درخواست قبلاً برای داده دیگری استفاده شده است.',409);}(new PersonalProblems($this->db))->assertReplay($user,$old['id'],$d);return $old['id'];}
            $this->db->one('SELECT mentor_id FROM m_schedule_settings WHERE mentor_id=? FOR UPDATE',[$mentor]);$scope=$this->db->run('SELECT access_scope FROM m_schedule_settings WHERE mentor_id=?',[$mentor])->fetchColumn();if($scope==='organization'&&!$this->db->one('SELECT a.org_id FROM m_memberships a JOIN m_memberships b ON b.org_id=a.org_id JOIN m_organizations o ON o.id=a.org_id WHERE a.user_id=? AND b.user_id=? AND o.active=1',[$mentor,$user])){throw new Problem('ORGANIZATION_REQUIRED','این مرشد فقط برای اعضای سازمان مشترک مشاوره می‌پذیرد.',403);}$this->expire();$pendingPolicy=(new Membership($this->db))->policy();$pendingLimit=$pendingPolicy['enabled']?(int)$pendingPolicy['max_pending']:2;if((int)$this->db->run("SELECT COUNT(*) FROM m_bookings WHERE user_id=? AND mentor_id=? AND state='pending'",[$user,$mentor])->fetchColumn()>=$pendingLimit){throw new Problem('PENDING_LIMIT','سقف درخواست‌های بی‌پاسخ این مرشد تکمیل است.',409);}(new ConsultationCredits($this->db))->check($user,$mentor);$s=$this->slot($mentor,$start,null,$minutes);if($this->db->one("SELECT id FROM m_bookings WHERE (user_id=? OR mentor_id=?) AND state IN ('pending','confirmed') AND starts_at<? AND ends_at>?",[$user,$user,$s['ends_at'],$start])){throw new Problem('OVERLAP','کاربر در این زمان رزرو دیگری دارد.',409);}
            $id=Security::id();$servicePolicy=(new Membership($this->db))->policy();$expiry=min($start-60,time()+($servicePolicy['enabled']?(int)$servicePolicy['response_hours']*3600:86400));
            $this->db->run('INSERT INTO m_bookings(id,mentor_id,user_id,starts_at,ends_at,buffer_minutes,local_date,state,problem,idempotency_key,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$mentor,$user,$start,$s['ends_at'],$s['buffer_minutes'],$s['date'],'pending',$problem,$key,$expiry,time()]);$this->db->run('INSERT INTO m_booking_details(booking_id,subject,experience_id,experience_title,requested_method) VALUES(?,?,?,?,?)',[$id,$subject,$experience?:null,$experienceTitle,$method]);$newBooking=['id'=>$id,'user_id'=>$user];(new PersonalProblems($this->db))->attach($a,$newBooking,$d);(new ConsultationCredits($this->db))->hold($newBooking,$a);if($servicePolicy['enabled'])(new Behavior($this->db))->record($user,$id,'requested',$a['user_id']);$this->event($id,$a['user_id'],$internal?'internal_created':'created','برگزاری جلسه پس از آماده‌شدن زیرساخت ارتباط ممکن است.',$internal?'admin':'site');$this->audit->notify($mentor,'درخواست مشاوره جدید',$subject.'؛ برای تأیید یا رد، درخواست را باز کنید.','/booking?id='.$id,'sessions');$this->audit->notify($user,'درخواست مشاوره ثبت شد','زمان موقتاً نگه داشته شد؛ جلسه پس از تأیید مرشد قطعی می‌شود.','/booking?id='.$id,'sessions');return $id;
        });
    }
    public function booking(array $a,string $id,bool $admin=false):array
    {
        $this->data->storage();if($admin){$this->policy->admin($a,true);}else{$this->policy->user($a);}
        $b=$this->db->one('SELECT b.*,u.display_name AS user_name,m.display_name AS mentor_name FROM m_bookings b JOIN m_users u ON u.id=b.user_id JOIN m_users m ON m.id=b.mentor_id WHERE b.id=?',[$id]);
        if(!$b||(!$admin&&!in_array($a['user_id'],[$b['user_id'],$b['mentor_id']],true))){throw new Problem('NOT_FOUND','رزرو در دسترس نیست.',404);}return $b+($this->db->one('SELECT subject,experience_id,experience_title,requested_method FROM m_booking_details WHERE booking_id=?',[$id])??['subject'=>'درخواست مشاوره','experience_id'=>null,'experience_title'=>'','requested_method'=>'audio']);
    }
    public function transition(array $a,string $id,string $action,string $note='',bool $admin=false):void
    {$this->data->storage();(new Membership($this->db))->storage();$this->db->tx(function()use($a,$id,$action,$note,$admin){$this->lock();$this->expire();$b=$this->booking($a,$id,$admin);$note=Policy::text($note,4000,0);$next='';
        if($action==='accept'&&(new Membership($this->db))->policy()['enabled']){$elig=(new Membership($this->db))->eligibility($b['user_id'],$b['mentor_id'],false);if(!$elig['allowed'])throw new Problem('CONSULTATION_RESTRICTED',$elig['reason'],403);$intake=(new PersonalProblems($this->db))->intake($id);if($intake&&$intake['needs_details'])throw new Problem('CLARIFICATION_REQUIRED','ابتدا پاسخ تکمیلی مخاطب را دریافت کنید.',409);}if($action==='accept'&&$b['state']==='pending'&&!$admin&&$a['user_id']===$b['mentor_id']&&(int)$b['starts_at']>time()){$this->activeMentor($b['mentor_id']);if($this->db->one("SELECT id FROM m_bookings WHERE id<>? AND (mentor_id IN (?,?) OR user_id IN (?,?)) AND (state='confirmed' OR (state='pending' AND expires_at>?)) AND starts_at<? AND ends_at+buffer_minutes*60>?",[$id,$b['mentor_id'],$b['user_id'],$b['mentor_id'],$b['user_id'],time(),(int)$b['ends_at']+(int)$b['buffer_minutes']*60,$b['starts_at']])){throw new Problem('OVERLAP','یکی از طرفین در این بازه جلسه دیگری دارد.',409);}$next='confirmed';if($note===''){$reply=$this->db->one("SELECT body FROM m_consultation_replies WHERE code='accept' AND active=1");$note=$reply['body']??'';}}
        if($action==='reject'&&$b['state']==='pending'&&($admin||$a['user_id']===$b['mentor_id'])){$next='rejected';}
        if($action==='cancel'&&in_array($b['state'],['pending','confirmed'],true)&&(int)$b['starts_at']>time()){$next='cancelled';}
        if($action==='complete'&&($admin||$a['user_id']===$b['mentor_id'])&&$b['state']==='confirmed'&&(int)$b['ends_at']<=time()){$next='completed';}
        if(!$next){throw new Problem('INVALID_STATE','این عملیات در وضعیت فعلی مجاز نیست.',409);}
        $this->db->run('UPDATE m_bookings SET state=? WHERE id=?',[$next,$id]);(new ConsultationCredits($this->db))->settle($b,$next,$a['user_id'],$note);if((new Membership($this->db))->policy()['enabled']){if($next==='completed'){foreach([$b['user_id'],$b['mentor_id']] as $person)(new Behavior($this->db))->record($person,$id,'completed',$a['user_id'],$note);}else{(new Behavior($this->db))->record($a['user_id'],$id,$next==='cancelled'?(((int)$b['starts_at']-time())<(int)(new Membership($this->db))->policy()['cancel_hours']*3600?'late_cancel':'early_cancel'):$next,$a['user_id'],$note);}}$this->db->run("UPDATE m_time_proposals SET state='rejected' WHERE booking_id=? AND state='pending'",[$id]);$this->event($id,$a['user_id'],$next,$note,$a['realm']);foreach([$b['user_id'],$b['mentor_id']] as $uid){$this->audit->notify($uid,['confirmed'=>'جلسه شما قطعی شد','rejected'=>'درخواست مشاوره رد شد','cancelled'=>'جلسه لغو شد','completed'=>'جلسه برگزار شد'][$next],'موضوع: '.$b['subject'].' — '.self::sessionLabel((int)$b['starts_at']).($note!==''?' — '.$note:''),'/booking?id='.$id,'sessions');}});}
    public function propose(array $a,string $id,int $start):string
    {$this->data->storage();return $this->db->tx(function()use($a,$id,$start){$this->lock();$this->expire();$b=$this->booking($a,$id);if($b['state']!=='confirmed'||(int)$b['starts_at']<=time()){throw new Problem('INVALID_STATE','تغییر زمان فقط پیش از شروع رزرو قطعی مجاز است.',409);}$this->slot($b['mentor_id'],$start,$id,intdiv((int)$b['ends_at']-(int)$b['starts_at'],60));$this->db->run("UPDATE m_time_proposals SET state='rejected' WHERE booking_id=? AND state='pending'",[$id]);$pid=Security::id();$this->db->run('INSERT INTO m_time_proposals(id,booking_id,proposer_id,starts_at,expires_at,created_at) VALUES(?,?,?,?,?,?)',[$pid,$id,$a['user_id'],$start,min(time()+86400,(int)$b['starts_at']-60),time()]);$this->event($id,$a['user_id'],'time_proposed');$other=$a['user_id']===$b['mentor_id']?$b['user_id']:$b['mentor_id'];$this->audit->notify($other,'پیشنهاد تغییر زمان جلسه','زمان قبلی تا پذیرش شما حفظ می‌شود.','/booking?id='.$id,'sessions');return $pid;});}
    public function answerProposal(array $a,string $pid,bool $accept):void
    {$this->data->storage();$this->db->tx(function()use($a,$pid,$accept){$this->lock();$this->expire();$p=$this->db->one('SELECT * FROM m_time_proposals WHERE id=? FOR UPDATE',[$pid]);if(!$p){throw new Problem('NOT_FOUND','پیشنهاد پیدا نشد.',404);}$b=$this->booking($a,$p['booking_id']);if($p['state']!=='pending'||$b['state']!=='confirmed'||$p['proposer_id']===$a['user_id']||(int)$b['starts_at']<=time()){throw new Problem('INVALID_STATE','پیشنهاد قابل پذیرش نیست.',409);}
        if($accept){$s=$this->slot($b['mentor_id'],(int)$p['starts_at'],$b['id'],intdiv((int)$b['ends_at']-(int)$b['starts_at'],60));if($this->db->one("SELECT id FROM m_bookings WHERE (user_id=? OR mentor_id=?) AND id<>? AND state IN ('pending','confirmed') AND starts_at<? AND ends_at>?",[$b['user_id'],$b['user_id'],$b['id'],$s['ends_at'],$s['starts_at']])){throw new Problem('OVERLAP','تعارض زمان در حساب کاربر وجود دارد.',409);}$this->db->run('UPDATE m_bookings SET starts_at=?,ends_at=?,buffer_minutes=?,local_date=? WHERE id=?',[$s['starts_at'],$s['ends_at'],$s['buffer_minutes'],$s['date'],$b['id']]);}
        $this->db->run('UPDATE m_time_proposals SET state=? WHERE id=?',[$accept?'accepted':'rejected',$pid]);$this->event($b['id'],$a['user_id'],$accept?'time_changed':'time_rejected');$this->audit->notify($p['proposer_id'],$accept?'زمان جلسه تغییر کرد':'پیشنهاد زمان پذیرفته نشد','جزئیات زمان قطعی را در درخواست ببینید.','/booking?id='.$b['id'],'sessions');});}

    public function calendar(array $a):array
    {$this->policy->mentor($a,true);$this->data->storage();$uid=$a['user_id'];return ['settings'=>$this->db->one('SELECT * FROM m_schedule_settings WHERE mentor_id=?',[$uid]),'rules'=>$this->db->all('SELECT * FROM m_schedule_rules WHERE mentor_id=? ORDER BY weekday,start_minute',[$uid]),'recurrences'=>$this->db->all('SELECT * FROM m_schedule_recurrences WHERE mentor_id=? ORDER BY kind,weekday,ordinal,start_minute',[$uid]),'dates'=>$this->db->all('SELECT * FROM m_schedule_dates WHERE mentor_id=? ORDER BY local_date,start_minute',[$uid]),'exceptions'=>$this->db->all('SELECT local_date FROM m_schedule_exceptions WHERE mentor_id=? ORDER BY local_date',[$uid])];}
    public function listing(array $a,string $direction='all',string $period='all',int $page=1):array
    {$uid=$this->policy->user($a);$this->data->storage();$this->expire();Policy::choice($direction,['all','incoming','outgoing']);Policy::choice($period,['all','future','past','cancelled']);if($direction==='incoming'){$this->policy->mentor($a,true);}$where=match($direction){'incoming'=>'b.mentor_id=?','outgoing'=>'b.user_id=?',default=>'(b.user_id=? OR b.mentor_id=?)'};$params=$direction==='all'?[$uid,$uid]:[$uid];
        $where.=match($period){'future'=>" AND b.ends_at>UNIX_TIMESTAMP() AND b.state IN ('pending','confirmed')",'past'=>" AND (b.ends_at<=UNIX_TIMESTAMP() OR b.state='completed')",'cancelled'=>" AND b.state IN ('cancelled','rejected','expired')",default=>''};
        return $this->db->all('SELECT b.*,u.display_name AS user_name,m.display_name AS mentor_name,d.subject,d.experience_id,d.experience_title,d.requested_method FROM m_bookings b JOIN m_users u ON u.id=b.user_id JOIN m_users m ON m.id=b.mentor_id LEFT JOIN m_booking_details d ON d.booking_id=b.id WHERE '.$where.' ORDER BY b.starts_at DESC LIMIT 51 OFFSET '.((Policy::integer($page,1,10000)-1)*50),$params);
    }
    public function remind():int
    {$this->data->storage();return $this->db->tx(function(){$this->lock();$count=0;foreach($this->db->all("SELECT id,user_id,mentor_id,starts_at FROM m_bookings WHERE state='confirmed' AND starts_at>? AND starts_at<=? ORDER BY starts_at LIMIT 200",[time(),time()+3600]) as $b){foreach([$b['user_id'],$b['mentor_id']] as $uid){if($this->db->run('INSERT IGNORE INTO m_booking_reminders VALUES(?,?,?,?)',[$b['id'],$b['starts_at'],$uid,time()])->rowCount()){$this->audit->notify($uid,'یادآوری جلسه مشاوره','یادآوری جلسهٔ شما: '.self::sessionLabel((int)$b['starts_at']).'؛ زمان قطعی را در جزئیات ببینید.','/booking?id='.$b['id'],'sessions');$count++;}}}return $count;});}
    private function recordingTerms(array $b):void
    {$v=$this->db->run("SELECT version_id FROM m_legal_publications WHERE document_key='recording'")->fetchColumn();if(!$v){throw new Problem('RECORDING_POLICY_BLOCKED','شرایط ضبط و نگهداری هنوز مصوب نیست؛ جلسه آغاز نمی‌شود.',503);}foreach([$b['user_id'],$b['mentor_id']] as $uid){if(!$this->db->one('SELECT user_id FROM m_user_acceptances WHERE user_id=? AND version_id=?',[$uid,$v])){throw new Problem('RECORDING_CONSENT_REQUIRED','پذیرش معتبر شرایط آرشیو توسط هر دو طرف لازم است.',409);}}}
    private function activeMentor(string $id):void{if(!$this->db->one("SELECT m.user_id FROM m_mentors m JOIN m_users u ON u.id=m.user_id WHERE m.user_id=? AND m.status='active' AND u.status='active'",[$id])){throw new Problem('MENTOR_UNAVAILABLE','مرشد در وضعیت مجاز جلسه نیست.',409);}}
    public function writable(array $a,string $id):array
    {$b=$this->booking($a,$id);if($b['state']!=='confirmed'||time()<(int)$b['starts_at']||time()>=(int)$b['ends_at']){throw new Problem('SESSION_NOT_ACTIVE','جلسه در بازه فعال نیست.',409);}$this->activeMentor($b['mentor_id']);$this->recordingTerms($b);if((int)$this->db->run('SELECT @@GLOBAL.innodb_flush_log_at_trx_commit')->fetchColumn()!==1){throw new Problem('DURABILITY_REQUIRED','تنظیم ذخیره پایدار دیتابیس برای جلسه تأیید نشده است.',503);}return $b;}
    public function message(array $a,string $id,string $key,string $body,bool $chatOnly=false):array
    {$this->data->storage();return $this->db->tx(function()use($a,$id,$key,$body,$chatOnly){$this->lock();$b=$this->booking($a,$id);if($chatOnly){if(($b['requested_method']??'')!=='chat')throw new Problem('CHAT_NOT_SELECTED','روش این جلسه چت نیست.',409);$this->writable($a,$id);}$key=Policy::text($key,64,12);$body=Policy::text($body,12000);$old=$this->db->one('SELECT id,body,created_at FROM m_messages WHERE booking_id=? AND sender_id=? AND client_key=?',[$id,$a['user_id'],$key]);if($old){if($old['body']!==$body){throw new Problem('IDEMPOTENCY_CONFLICT','شناسه پیام قبلاً برای متن دیگری ثبت شده است.',409);}return ['id'=>(int)$old['id'],'body'=>$old['body'],'created_at'=>(int)$old['created_at']];}
        $this->writable($a,$id);
        // ACK leaves this method only after Database::tx commits the durable row.
        $createdAt=time();$this->db->run('INSERT INTO m_messages(booking_id,sender_id,client_key,body,created_at) VALUES(?,?,?,?,?)',[$id,$a['user_id'],$key,$body,$createdAt]);return ['id'=>(int)$this->db->pdo->lastInsertId(),'body'=>$body,'created_at'=>$createdAt];});}
    public function archive(array $a,string $id,int $after=0):array
    {
        if(($a['realm']??'')==='admin'){$this->policy->admin($a);$grant=$this->db->one('SELECT id FROM m_archive_grants WHERE booking_id=? AND reviewer_id=? AND expires_at>? AND revoked_at IS NULL',[$id,$a['user_id'],time()]);if(!$grant){throw new Problem('ARCHIVE_GRANT_REQUIRED','مجوز موردی معتبر برای این آرشیو لازم است.',403);}$this->audit->add($a['user_id'],'admin','archive.read',$id);}
        else{$this->booking($a,$id);}
        return $this->db->all('SELECT id,sender_id,body,created_at FROM m_messages WHERE booking_id=? AND id>? ORDER BY id LIMIT 100',[$id,max(0,$after)]);
    }
}
