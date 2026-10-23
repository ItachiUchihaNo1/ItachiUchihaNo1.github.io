<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\Problem;
/** Bounded PNG derivative; keeps original alpha and never enlarges a raster. */
final class LogoImage
{
 public static function normalize(string $input,string $output):void {
  if(!function_exists('imagecreatetruecolor'))throw new Problem('IMAGE_PROCESSOR_REQUIRED','پردازش تصویر روی میزبان فعال نیست؛ افزونه GD لازم است.',422);
  $size=@getimagesize($input);if(!$size||$size[0]*$size[1]>4000000||!in_array($size['mime'],['image/png','image/jpeg','image/webp'],true))throw new Problem('INVALID_FILE','تصویر لوگو معتبر نیست.');
  $source=match($size['mime']){'image/png'=>@imagecreatefrompng($input),'image/jpeg'=>@imagecreatefromjpeg($input),default=>function_exists('imagecreatefromwebp')?@imagecreatefromwebp($input):false};if(!$source)throw new Problem('INVALID_FILE','تصویر قابل پردازش نیست.');
  $ratio=min(1,256/max($size[0],$size[1]));$width=max(1,(int)round($size[0]*$ratio));$height=max(1,(int)round($size[1]*$ratio));$dest=imagecreatetruecolor($width,$height);
  try{imagealphablending($dest,false);imagesavealpha($dest,true);imagefill($dest,0,0,imagecolorallocatealpha($dest,0,0,0,127));imagecopyresampled($dest,$source,0,0,0,0,$width,$height,$size[0],$size[1]);if(!imagepng($dest,$output,9))throw new Problem('STORAGE_UNAVAILABLE','ذخیره لوگو انجام نشد.',503);chmod($output,0600);}finally{imagedestroy($source);imagedestroy($dest);}
 }
}
