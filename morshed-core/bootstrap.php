<?php
declare(strict_types=1);
ini_set('display_errors','0');
require __DIR__.'/autoload.php';
// Only allowlisted diagnostic codes leave the exception boundary. Never log raw
// exception messages: PDO messages can contain database usernames and paths.
function morshed_installation_reason(\Throwable $e,string $stage):string
{
    if($stage==='config_load'&&($e instanceof \ParseError||$e instanceof \TypeError)){return 'CONFIG_PHP_SYNTAX';}
    if($e instanceof \PDOException){
        $driver=(int)($e->errorInfo[1]??0);
        return [1045=>'DB_ACCESS_DENIED',1044=>'DB_PERMISSION_DENIED',1049=>'DB_NOT_FOUND',1046=>'DB_NOT_SELECTED',
            2002=>'DB_CONNECTION_FAILED',2003=>'DB_CONNECTION_FAILED',2006=>'DB_CONNECTION_LOST',2013=>'DB_CONNECTION_LOST',
            1146=>'DB_TABLE_MISSING',1054=>'DB_COLUMN_MISSING',1142=>'DB_QUERY_PERMISSION_DENIED'][$driver]??'DB_QUERY_FAILED';
    }
    $known=['Runtime prerequisites missing.'=>'PHP_REQUIREMENTS_MISSING','Private configuration missing.'=>'CONFIG_FILE_MISSING',
        'Invalid configuration.'=>'CONFIG_APP_KEY_OR_ENVIRONMENT','Database configuration incomplete.'=>'CONFIG_DATABASE_INCOMPLETE',
        'MySQL configuration required.'=>'CONFIG_DATABASE_DSN','Database migration version mismatch.'=>'SCHEMA_VERSION_MISMATCH',
        'Session directory unavailable.'=>'SESSION_DIRECTORY_UNWRITABLE','CSRF session unavailable.'=>'SESSION_START_FAILED'];
    if(isset($known[$e->getMessage()])){return $known[$e->getMessage()];}
    return in_array($stage,['runtime','config_file','config_load','config_validate','application'],true)?strtoupper($stage).'_FAILED':'APPLICATION_FAILED';
}
function morshed_write_installation_log(string $incident,string $reason,?string $privateRoot=null):bool
{
    if(!preg_match('/^[a-f0-9]{12}$/D',$incident)||!preg_match('/^[A-Z_]{3,64}$/D',$reason)){return false;}
    $root=$privateRoot??__DIR__;$storage=$root.'/storage';$dir=$storage.'/logs';$file=$dir.'/installation.log';
    if(is_link($storage)||is_link($dir)||is_link($file)){return false;}
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)){return false;}
    $handle=@fopen($file,'c+b');if($handle===false){return false;}
    @chmod($file,0600);$written=false;
    if(flock($handle,LOCK_EX)){
        $stat=fstat($handle);
        // Bound diagnostic storage. Business audit records remain in the DB.
        if($stat!==false&&$stat['size']<1048576){
            $line=gmdate('Y-m-d H:i:s').' UTC Morshed incident '.$incident.' reason='.$reason.PHP_EOL;
            if(fseek($handle,0,SEEK_END)===0){$written=fwrite($handle,$line)===strlen($line);fflush($handle);}
        }
        flock($handle,LOCK_UN);
    }
    fclose($handle);return $written;
}
function morshed_run(string $realm):void
{
    if(is_file(__DIR__.'/.deploy/maintenance.json')){
        http_response_code(503);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('Retry-After: 30');
        echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>به‌روزرسانی مرشد</title><p>مرشد در حال به‌روزرسانی است؛ چند لحظه دیگر دوباره تلاش کنید.</p></html>';return;
    }
    header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    $stage='runtime';
    try{
        if(PHP_VERSION_ID<80200||!extension_loaded('pdo_mysql')){throw new \RuntimeException('Runtime prerequisites missing.');}
        $stage='config_file';
        $file=__DIR__.'/config/local.php';if(!is_file($file)){throw new \RuntimeException('Private configuration missing.');}
        $stage='config_load';
        $config=require $file;
        $stage='config_validate';
        if(!is_array($config)||!preg_match('/^[a-f0-9]{64}$/D',$config['app_key']??'')||!in_array($config['environment']??'',['staging','production','testing'],true)){throw new \RuntimeException('Invalid configuration.');}
        if(!is_array($config['database']??null)||!is_string($config['database']['dsn']??null)||!is_string($config['database']['user']??null)||!is_string($config['database']['password']??null)){throw new \RuntimeException('Database configuration incomplete.');}
        $stage='application';
        // Redirects after a form submission are checked against the source page policy.
        $scripts="'self'";$forms="'self'";
        if($realm==='site'&&($config['google']['enabled']??false)&&($config['google']['live_verified']??false)){$forms.=' https://accounts.google.com';}
        if($realm==='site'&&($config['eitaa']['enabled']??false)&&($config['eitaa']['live_verified']??false)){$scripts.=' https://developer.eitaa.com';$forms.=' https://eitaa.com';}
        header("Content-Security-Policy: default-src 'self'; script-src $scripts; style-src 'self'; font-src 'self'; img-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action $forms");
        (new \Morshed\Http\Application($config,$realm))->run();
    }catch(\Morshed\Core\Problem $e){
        http_response_code($e->status);
        $requested=$_GET['r']??parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        if(is_string($requested)&&str_starts_with($requested,'/api/v1/')){header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],JSON_UNESCAPED_UNICODE);}
        else{echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مرشد</title><p>'.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</p></html>';}
    }catch(\Throwable $e){
        $incident=bin2hex(random_bytes(6));$reason=morshed_installation_reason($e,$stage);
        morshed_write_installation_log($incident,$reason);
        error_log('Morshed incident '.$incident.' reason='.$reason);http_response_code(503);
        $requested=$_GET['r']??parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        if(is_string($requested)&&str_starts_with($requested,'/api/v1/')){header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>['code'=>'SERVICE_UNAVAILABLE','message'=>'سامانه موقتاً در دسترس نیست.','incident'=>$incident]],JSON_UNESCAPED_UNICODE);}
        else{echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مرشد</title><p>سامانه موقتاً آماده پاسخ‌گویی نیست. تنظیمات نصب یا اتصال را بررسی کنید.</p><p>کد پیگیری: '.$incident.'</p></html>';}
    }
}
