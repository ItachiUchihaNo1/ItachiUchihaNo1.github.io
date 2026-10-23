<?php
declare(strict_types=1);
namespace Morshed\Http;
final class ReturnTo
{
 public static function safe(mixed $v):?string {
  if(!is_string($v)||strlen($v)>2000||preg_match('/[\x00-\x20\\\\]/',$v)||!str_starts_with($v,'/')||str_starts_with($v,'//'))return null;
  if(preg_match('/[\x00-\x1f\x7f\\\\]/',rawurldecode($v))||str_starts_with(rawurldecode($v),'//'))return null;$p=parse_url($v);if($p===false||isset($p['host'])||isset($p['scheme'])||preg_match('~^/(?:login|logout|auth|api|password|setup)(?:/|$)~',$p['path']??'/'))return null;return $v;
 }
 public static function current():string {$q=$_GET;unset($q['r']);$p=is_string($_GET['r']??null)?$_GET['r']:(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');if($p==='/index.php')$p='/';return self::safe($p.($q?'?'.http_build_query($q):''))??'/account';}
}
