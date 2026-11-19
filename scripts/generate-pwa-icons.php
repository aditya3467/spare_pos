<?php
// Development-only deterministic icon generation. No runtime image dependency.
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__);
if(!extension_loaded('gd'))exit("Install PHP GD to regenerate PNG icons.\n");
// Match the existing s. brand using a local bold font. Generated PNGs ship with the app.
$font='/System/Library/Fonts/Supplemental/Arial Bold.ttf';
if(!is_file($font))exit("Set the font path to an installed Arial-compatible bold font.\n");
foreach(['icon-192.png'=>192,'icon-512.png'=>512,'maskable-192.png'=>192,'maskable-512.png'=>512,'apple-touch-icon.png'=>180,'favicon-32.png'=>32] as $file=>$size){
 $im=imagecreatetruecolor($size,$size);$green=imagecolorallocate($im,51,91,69);$white=imagecolorallocate($im,255,255,255);imagefill($im,0,0,$green);
 $fontSize=$size*.53;$box=imagettfbbox($fontSize,0,$font,'s.');$width=$box[2]-$box[0];$height=$box[1]-$box[7];$x=($size-$width)/2-$box[0];$baseline=($size-$height)/2-$box[7];imagettftext($im,$fontSize,0,(int)$x,(int)$baseline,$white,$font,'s.');imagepng($im,$root.'/public/assets/icons/'.$file);imagedestroy($im);
}
echo "PWA icons generated.\n";
