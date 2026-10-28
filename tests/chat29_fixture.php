<?php
require __DIR__.'/common.php';
$f=json_decode(file_get_contents(dirname($argv[1]).'/chat25-fixture.json'),true);$mode=$argv[2]??'prepare';$id=$f['id'];
if($mode==='prepare'){$auth=[];foreach(['mentor','user','other'] as $role){$uid=$f[$role]['user_id'];Morshed\Identity\Profile::save($db,$uid,['first_name'=>'حساب','last_name'=>'چت '.$role,'province'=>'قم','city'=>'قم','gender'=>'male']);$db->run("INSERT IGNORE INTO m_auth_identities VALUES(?,?,'local_username',?,?)",[Morshed\Core\Security::id(),$uid,'t29_'.substr($uid,0,12),time()]);$auth[$role]=$sessions->issue($uid,'site','otp')['token'];}$base=$legal->current();$auth['base_version']=$base['id'];$auth['id']=$id;file_put_contents(dirname($argv[1]).'/chat29-auth.json',json_encode($auth));}
if($mode==='future'){$db->run("UPDATE m_bookings SET state='confirmed',starts_at=?,ends_at=? WHERE id=?",[time()+600,time()+1500,$id]);}
elseif($mode==='ended'){$db->run("UPDATE m_bookings SET state='confirmed',starts_at=?,ends_at=? WHERE id=?",[time()-901,time()-1,$id]);}
elseif($mode==='cancelled'){$db->run("UPDATE m_bookings SET state='cancelled' WHERE id=?",[$id]);}
else{$db->run("UPDATE m_bookings SET state='confirmed',starts_at=?,ends_at=? WHERE id=?",[time()-1,time()+899,$id]);}
echo "PASS chat29 local fixture: $mode\n";
