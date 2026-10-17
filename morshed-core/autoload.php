<?php
declare(strict_types=1);
spl_autoload_register(static function(string $name):void {
    if (!str_starts_with($name,'Morshed\\')) { return; }
    $parts=explode('\\',substr($name,8));
    foreach($parts as $part) { if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$part)) { return; } }
    $path=__DIR__.'/src/'.implode('/',$parts).'.php';
    if(is_file($path)) { require_once $path; }
});
