<?php
require __DIR__.'/common.php';
// CLI-only, strictly isolated random local database (guarded in common.php).
$fixture=json_decode(file_get_contents($cfg['test_fixture_path']),true);$bid=$fixture['product']['booking_id'];$op=$argv[2]??'';
if($op==='start'){$v=$db->one("SELECT id FROM m_legal_versions WHERE document_key='recording' AND title='TEST ONLY'");if(!$v){throw new RuntimeException('Missing test fixture');}$db->run("UPDATE m_legal_publications SET version_id=? WHERE document_key='recording'",[$v['id']]);$db->run("UPDATE m_bookings SET state='confirmed',starts_at=?,ends_at=? WHERE id=?",[time()-30,time()+600,$bid]);}
elseif($op==='failure'){$db->pdo->exec("CREATE TRIGGER test_http_message_failure BEFORE INSERT ON m_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test storage failure'");}
elseif($op==='restore'){$db->pdo->exec('DROP TRIGGER IF EXISTS test_http_message_failure');}
elseif($op==='stop'){$db->run("UPDATE m_legal_publications SET version_id=NULL WHERE document_key='recording'");$db->run("UPDATE m_bookings SET state='completed',ends_at=? WHERE id=?",[time()-1,$bid]);}
else{throw new RuntimeException('Unsupported test operation');}
echo "PASS private fixture control $op\n";
