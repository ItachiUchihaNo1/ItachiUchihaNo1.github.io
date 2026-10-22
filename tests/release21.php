<?php
require __DIR__.'/common.php';
use Morshed\Core\{Security,Problem};
use Morshed\Product\{MeetingRooms,Taxonomy,Content,Operations};
$checks=0;
function ok21(bool $ok,string $name):void{global $checks;if(!$ok){throw new RuntimeException($name);}echo "PASS $name\n";$checks++;}
function denied21(string $code,callable $fn):void{try{$fn();}catch(Problem $e){ok21($e->errorCode===$code,'denied '.$code);return;}throw new RuntimeException('Expected '.$code);}
$a=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='test_admin'")+['realm'=>'admin'];
$c=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='content_test'")+['realm'=>'admin'];
$mentor=$db->one("SELECT m.user_id FROM m_mentors m JOIN m_users u ON u.id=m.user_id AND u.status='active' WHERE m.status='active' LIMIT 1")['user_id'];$requester=$db->one("SELECT id FROM m_users WHERE status='active' AND id<>? LIMIT 1",[$mentor])['id'];$id=Security::id();$begin=time()+7200;
$db->run("INSERT INTO m_bookings(id,mentor_id,user_id,starts_at,ends_at,buffer_minutes,local_date,state,problem,idempotency_key,expires_at,created_at) VALUES(?,?,?,?,?,0,?,'confirmed',?,?,?,?)",[$id,$mentor,$requester,$begin,$begin+1800,gmdate('Y-m-d',$begin),'meeting test',Security::id(),$begin-60,time()]);$b=$db->one('SELECT * FROM m_bookings WHERE id=?',[$id]);ok21((bool)$b,'confirmed booking fixture exists');
$user=['realm'=>'site','user_id'=>$b['user_id'],'display_name'=>'متقاضی'];$host=['realm'=>'site','user_id'=>$b['mentor_id'],'display_name'=>'مرشد'];
$j=$cfg+['jitsi'=>['enabled'=>true,'live_verified'=>true,'origin'=>'https://meet.example.test','app_id'=>'morshed','app_secret'=>str_repeat('x',40)]];
ok21(MeetingRooms::origin($cfg)===null,'media disabled without configuration');
foreach(['http://meet.example.test','https://evil.test/?q=x','https://evil.test/path','https://user@evil.test','https://evil.test:444'] as $origin){$bad=$j;$bad['jitsi']['origin']=$origin;ok21(MeetingRooms::origin($bad)===null,'reject invalid media origin');}
$off=$j;$off['jitsi']['live_verified']=false;ok21(MeetingRooms::origin($off)===null,'verification gate required');
$room=new MeetingRooms($db,$j);$start=(int)$b['starts_at'];$end=(int)$b['ends_at'];
ok21(!$room->status($user,$b['id'],$start-301)['can_join'],'early room entry blocked');
ok21($room->status($user,$b['id'],$start-300)['can_join'],'five minute entry window');
ok21(!$room->status($user,$b['id'],$end)['can_join'],'ended room entry blocked');
denied21('ACCESS_DENIED',fn()=>$room->status($a,$b['id'],$start));
denied21('MEETING_UNAVAILABLE',fn()=>$room->token($user,$b['id'],'video',$start-301));
$token=$room->token($user,$b['id'],'audio',$start);$hostToken=$room->token($host,$b['id'],'video',$start);
ok21($token['room']===$hostToken['room'],'participants share one opaque room');
[$header,$payload,$signature]=explode('.',$token['jwt']);$claims=json_decode(base64_decode(strtr($payload,'-_','+/')),true);$expected=rtrim(strtr(base64_encode(hash_hmac('sha256',$header.'.'.$payload,$j['jitsi']['app_secret'],true)),'+/','-_'),'=');
ok21(hash_equals($expected,$signature),'server signed media token');
ok21($claims['room']===$token['room']&&$claims['room']!=='*','token restricted to exact room');
ok21($claims['exp']<=min($end,$start+300),'short lived token within booking');
ok21(!str_contains(json_encode($token),$j['jitsi']['app_secret']),'shared signing secret never returned');
$db->run("UPDATE m_bookings SET state='cancelled' WHERE id=?",[$b['id']]);denied21('MEETING_UNAVAILABLE',fn()=>$room->token($user,$b['id'],'audio',$start));$db->run("UPDATE m_bookings SET state='confirmed' WHERE id=?",[$b['id']]);
$content=new Content($db,$audit);$issue=$db->one("SELECT id FROM m_terms WHERE kind='issue' AND active=1 LIMIT 1")['id'];
$draft=$content->saveStore($c,[['type'=>'issues','title'=>'ignored','ids'=>[$issue],'mode'=>'auto']]);$blocks=$content->store($draft,true);ok21($blocks[0]['title']===''&&$blocks[0]['mode']==='manual'&&$blocks[0]['items'][0]['id']===$issue,'issue row uses only selected issues without heading');
denied21('INVALID_INPUT',fn()=>$content->saveStore($c,[['type'=>'issues','ids'=>[Security::id()]]]));
$ops=new Operations($db,$audit);$ticket=$ops->ticket($user,['kind'=>'support','title'=>'release21 support','body'=>'گزارش یک مشکل']);ok21($ops->getTicket($a,$ticket)['user_id']===$user['user_id'],'submitted support visible to system admin');denied21('ACCESS_DENIED',fn()=>$ops->getTicket($c,$ticket));
$ops->reply($a,$ticket,'پاسخ مدیر','open');ok21((bool)$db->one('SELECT id FROM m_notifications WHERE user_id=? AND destination=?',[$user['user_id'],'/ticket?id='.$ticket]),'support response notifies requester');
$tax=new Taxonomy($db);$tax->topics();$before=$db->queryCount();for($i=0;$i<10;$i++){$tax->topics();}ok21($db->queryCount()===$before,'repeated catalog reads add no queries in one request');
$db->run('UPDATE m_terms SET title=title WHERE id=?',[$issue]);$before=$db->queryCount();$tax->topics();ok21($db->queryCount()>$before,'write invalidates request catalog cache');
echo "$checks release21 checks passed\n";
