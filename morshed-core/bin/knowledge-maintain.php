<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/autoload.php';
$config=require dirname(__DIR__).'/config/local.php';
$db=new Morshed\Core\Database($config['database']);
$knowledge=new Morshed\Product\Knowledge($db);
$apply=in_array('--apply',$argv,true);$result=$knowledge->backfill(!$apply);
if(in_array('--purge-events',$argv,true)){$result['expired_events_removed']=(new Morshed\Product\InteractionEvents($db))->purge();}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
