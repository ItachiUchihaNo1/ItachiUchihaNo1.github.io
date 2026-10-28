<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/autoload.php';
$config=require dirname(__DIR__).'/config/local.php';$db=new Morshed\Core\Database($config['database']);$messages=new Morshed\Product\Messaging($db,$config);$messages->storage();$lock='morshed.queue.'.substr(hash('sha256',$config['app_key']),0,24);if((int)$db->run('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1){echo "Another worker is active\n";exit;}
try{$db->run('INSERT INTO m_message_worker VALUES(1,?) ON DUPLICATE KEY UPDATE last_run=VALUES(last_run)',[time()]);(new Morshed\Product\Consultations($db,new Morshed\Core\Audit($db)))->expire();$eitaa=new Morshed\Product\EitaaDelivery($db,$config);$deadline=microtime(true)+45;$count=0;do{$a=$eitaa->enabled()?$eitaa->dispatch(2):[];$b=$messages->enabled('sms')?$messages->dispatchSms(1):[];$processed=count($a)+count($b);$count+=$processed;}while($processed&&microtime(true)<$deadline);$db->run('UPDATE m_message_worker SET last_run=? WHERE id=1',[time()]);echo 'Processed: '.$count.PHP_EOL;}finally{$db->run('SELECT RELEASE_LOCK(?)',[$lock]);}
