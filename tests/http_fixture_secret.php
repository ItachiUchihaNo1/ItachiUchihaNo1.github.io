<?php
// CLI-only fixture rotation. common.php permits only the named disposable loopback database.
require __DIR__.'/common.php';
$secret=$cfg['test_manual_password']??null;
if(!is_string($secret)||strlen($secret)<16){throw new RuntimeException('Missing generated HTTP fixture password.');}
$user=$db->one("SELECT user_id FROM m_auth_identities WHERE provider='local_username' AND subject='manual_release03'");
if(!$user){throw new RuntimeException('Missing disposable HTTP fixture account.');}
$db->run('UPDATE m_users SET password_hash=? WHERE id=?',[Morshed\Core\Security::hashPassword($secret),$user['user_id']]);
echo "PASS disposable HTTP fixture uses a per-run generated password\n";
