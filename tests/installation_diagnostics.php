<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../morshed-core/bootstrap.php';
$cases=[
    [new RuntimeException('Runtime prerequisites missing.'),'runtime','PHP_REQUIREMENTS_MISSING'],
    [new RuntimeException('Private configuration missing.'),'config_file','CONFIG_FILE_MISSING'],
    [new RuntimeException('Invalid configuration.'),'config_validate','CONFIG_APP_KEY_OR_ENVIRONMENT'],
    [new RuntimeException('Database configuration incomplete.'),'config_validate','CONFIG_DATABASE_INCOMPLETE'],
    [new RuntimeException('MySQL configuration required.'),'application','CONFIG_DATABASE_DSN'],
    [new RuntimeException('Database migration version mismatch.'),'application','SCHEMA_VERSION_MISMATCH'],
    [new RuntimeException('Session directory unavailable.'),'application','SESSION_DIRECTORY_UNWRITABLE'],
    [new RuntimeException('CSRF session unavailable.'),'application','SESSION_START_FAILED'],
    [new ParseError('SECRET /private/config.php'),'config_load','CONFIG_PHP_SYNTAX'],
    [new RuntimeException('SECRET /private/password'),'application','APPLICATION_FAILED'],
];
foreach([1045=>'DB_ACCESS_DENIED',1044=>'DB_PERMISSION_DENIED',1049=>'DB_NOT_FOUND',1046=>'DB_NOT_SELECTED',2002=>'DB_CONNECTION_FAILED',2003=>'DB_CONNECTION_FAILED',2006=>'DB_CONNECTION_LOST',2013=>'DB_CONNECTION_LOST',1146=>'DB_TABLE_MISSING',1054=>'DB_COLUMN_MISSING',1142=>'DB_QUERY_PERMISSION_DENIED',9999=>'DB_QUERY_FAILED'] as $driver=>$expected){$e=new PDOException('SECRET username password path');$e->errorInfo=['HY000',$driver,'SECRET'];$cases[]=[$e,'application',$expected];}
foreach($cases as [$error,$stage,$expected]){if(morshed_installation_reason($error,$stage)!==$expected){throw new RuntimeException('Diagnostic mismatch: '.$expected);}}
echo 'PASS '.count($cases).' installation diagnostics; no raw exception, path, username or password returned'.PHP_EOL;
$root=sys_get_temp_dir().'/morshed-diagnostics-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700)){throw new RuntimeException('Private diagnostic fixture unavailable.');}
try{
    if(!morshed_write_installation_log('123456abcdef','DB_ACCESS_DENIED',$root)){throw new RuntimeException('Private log write failed.');}
    $line=file_get_contents($root.'/storage/logs/installation.log');
    if(!str_contains($line,'Morshed incident 123456abcdef reason=DB_ACCESS_DENIED')||str_contains($line,'SECRET')){throw new RuntimeException('Unexpected private diagnostic contents.');}
    if(morshed_write_installation_log("bad\ninjection",'DB_ACCESS_DENIED',$root)||morshed_write_installation_log('123456abcdef',"PRIVATE/password",$root)){throw new RuntimeException('Diagnostic input validation failed.');}
    echo 'PASS private fixed-path diagnostic file and rejection of arbitrary log contents'.PHP_EOL;
}finally{
    if(is_file($root.'/storage/logs/installation.log')){unlink($root.'/storage/logs/installation.log');}
    if(is_dir($root.'/storage/logs')){rmdir($root.'/storage/logs');}
    if(is_dir($root.'/storage')){rmdir($root.'/storage');}
    rmdir($root);
}
