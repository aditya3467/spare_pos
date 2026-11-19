<?php
// Isolated test server router. Never deployed or served by the application.
$public=dirname(__DIR__).'/public';$prefix='/pwa-shop/';$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(!str_starts_with($path,$prefix)){http_response_code(404);exit;}$relative=substr($path,strlen($prefix));if($relative==='')$relative='index.php';$file=realpath($public.'/'.$relative);
if(!$file || !is_file($file) || !str_starts_with($file,realpath($public).DIRECTORY_SEPARATOR)){http_response_code(404);exit;}
if($relative==='index.php'){require $file;return;}
if(str_ends_with($file,'.php')){http_response_code(403);exit;}
$types=['js'=>'application/javascript','css'=>'text/css','html'=>'text/html','webmanifest'=>'application/manifest+json','svg'=>'image/svg+xml','png'=>'image/png'];$extension=pathinfo($file,PATHINFO_EXTENSION);header('Content-Type: '.($types[$extension]??'application/octet-stream'));header('X-Content-Type-Options: nosniff');header('Cache-Control: public, max-age=3600');
$state=json_decode(@file_get_contents(getenv('PWA_TEST_STATE')),true)?:['version'=>'test-v1'];
if($relative==='service-worker.js'){header('Cache-Control: no-cache, max-age=0, must-revalidate');$source=file_get_contents($file);echo preg_replace("/const VERSION = '[^']+';/","const VERSION = '".$state['version']."';",$source);return;}
if(($state['fail']??false) && $relative==='assets/css/app.css'){header('Content-Type: text/html');header('Cache-Control: no-store');echo '<html>Unsafe test asset; must never enter the static cache.</html>';return;}
if($relative==='manifest.webmanifest')header('Cache-Control: no-cache, max-age=0, must-revalidate');readfile($file);
