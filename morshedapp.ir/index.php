<?php
declare(strict_types=1);
if(PHP_SAPI==='cli-server'){
    $p=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$f=realpath(__DIR__.(is_string($p)?$p:''));
    if($f&&is_file($f)&&str_starts_with(str_replace('\\','/',$f),str_replace('\\','/',__DIR__).'/assets/')){return false;}
}
require dirname(__DIR__).'/morshed-core/bootstrap.php';
morshed_run('site');
