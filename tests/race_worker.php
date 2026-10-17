<?php
require __DIR__.'/common.php';
$job=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
try{
    $result=match($job['operation']){
        'setup'=>$admin->setup($cfg['test_setup_token'],'test_admin','مدیر آزمون','Correct-Test-Admin-471!','127.0.0.1'),
        'otp'=>$accounts->verifyLogin($job['id'],$job['code'],'127.0.0.1'),
        'booking'=>(new Morshed\Product\Consultations($db,$audit))->reserve($job['actor'],array_replace($job['data'],['idempotency_key'=>bin2hex(random_bytes(16))]),true),
        default=>throw new RuntimeException('Invalid job'),
    };
    echo json_encode(['ok'=>true,'result'=>$result]);
}catch(Morshed\Core\Problem $e){echo json_encode(['ok'=>false,'code'=>$e->errorCode]);}
