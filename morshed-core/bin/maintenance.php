<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/autoload.php';
$config=require dirname(__DIR__).'/config/local.php';$db=new Morshed\Core\Database($config['database']);
// Only expired rate-limit buckets; no account, session history, audit, legal or archive deletion.
$n=$db->run('DELETE FROM m_rate_limits WHERE expires_at<?',[time()])->rowCount();
(new Morshed\Product\Consultations($db,new Morshed\Core\Audit($db)))->expire();
$db->run('DELETE FROM m_external_flows WHERE expires_at<?',[time()-86400]);
$db->run('DELETE FROM m_identity_assertions WHERE expires_at<?',[time()]);
echo 'Expired rate buckets removed: '.$n.PHP_EOL;
