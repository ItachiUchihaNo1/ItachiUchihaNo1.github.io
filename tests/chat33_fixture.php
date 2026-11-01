<?php
require __DIR__.'/common.php';
$scratch=dirname($argv[1]);$auth=json_decode(file_get_contents($scratch.'/chat29-auth.json'),true);$mode=$argv[2]??'prepare';$id=$auth['id'];
if($mode==='reset-upload'){
 $b=$db->one('SELECT user_id,mentor_id FROM m_bookings WHERE id=?',[$id]);foreach([$b['user_id'],$b['mentor_id']] as $uid)$db->run('DELETE FROM m_rate_limits WHERE key_hash=?',[hash_hmac('sha256','chat-upload:'.$uid.':60',$cfg['app_key'])]);
}
elseif($mode==='revoke'){$db->run('UPDATE m_archive_grants SET revoked_at=? WHERE booking_id=?',[time(),$id]);}
else{
 $db->run("UPDATE m_bookings SET state='confirmed',starts_at=?,ends_at=? WHERE id=?",[time()-60,time()+899,$id]);
 $v=$db->run("SELECT version_id FROM m_legal_publications WHERE document_key='recording'")->fetchColumn();
 $b=$db->one('SELECT * FROM m_bookings WHERE id=?',[$id]);foreach([$b['user_id'],$b['mentor_id']] as $uid)$db->run('INSERT IGNORE INTO m_user_acceptances VALUES(?,?,?)',[$uid,$v,time()]);
 $admin=$db->run("SELECT user_id FROM m_admin_accounts WHERE username='test_admin'")->fetchColumn();
 $ticket=Morshed\Core\Security::id();$db->run("INSERT INTO m_tickets(id,user_id,booking_id,kind,title,state,created_at,updated_at) VALUES(?,?,?,'complaint','LOCAL FILE ARCHIVE','open',?,?)",[$ticket,$b['user_id'],$id,time(),time()]);
 $grant=Morshed\Core\Security::id();$db->run('INSERT INTO m_archive_grants(id,ticket_id,booking_id,reviewer_id,approved_by,purpose,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?)',[$grant,$ticket,$id,$admin,$b['mentor_id'],'LOCAL ONLY',time()+900,time()]);
 $token=$sessions->issue($admin,'admin','admin_password',$db->run('SELECT id FROM m_admin_accounts WHERE user_id=?',[$admin])->fetchColumn())['token'];
 file_put_contents($scratch.'/chat33-auth.json',json_encode(['admin'=>$token,'id'=>$id]));
}
echo "PASS local chat file fixture: $mode\n";
