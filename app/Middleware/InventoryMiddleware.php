<?php
namespace App\Middleware;
use App\Core\Request;
use App\Services\StockService;
final class InventoryMiddleware {
 public function handle():void {
  $u=current_user();if(!$u)redirect('/login');
  $route=Request::route();
  if(isset(['/inventory/in'=>'in','/inventory/out'=>'out','/inventory/adjust'=>'adjust'][$route]))$_GET['kind']=['/inventory/in'=>'in','/inventory/out'=>'out','/inventory/adjust'=>'adjust'][$route];
  if(in_array($route,['/inventory','/inventory/stock'],true))$permission='inventory.view';
  elseif(in_array($route,['/inventory/movements','/inventory/movement'],true))$permission='inventory.movements';
  else{try{$permission=StockService::permission(is_string($_POST['kind']??$_GET['kind']??null)?($_POST['kind']??$_GET['kind']):'in');}catch(\DomainException $e){http_response_code(422);exit('Invalid stock operation.');}}
  if(($_GET['kind']??$_POST['kind']??'')==='reverse' && !can($u,'inventory.movements')){http_response_code(403);exit('Movement history permission required.');}
  if(!$u['shop_id'] || !can($u,$permission)){http_response_code(403);exit('Inventory permission required.');}
 }
}
