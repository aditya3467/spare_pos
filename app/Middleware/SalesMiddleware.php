<?php
namespace App\Middleware;
use App\Core\Request;
final class SalesMiddleware {
 public function handle():void {
  $u=current_user();$route=Request::route();if(!$u)redirect('/login');if(!$u['shop_id']){http_response_code(403);exit('Choose a shop account.');}
  if($route==='/sales/settings'){if($u['role']!=='shop_owner'){http_response_code(403);exit('Shop owner access required.');}return;}
  $permission=match($route){'/sales/report'=>'reports.view','/sales/labour-expense'=>'expenses.manage','/sales'=>'sales.view','/sales/cancel'=>'sales.cancel','/sales/print'=>'sales.reprint',default=>'sales.create'};
  if($route==='/sales/invoice' && can($u,'sales.view'))return;
  if(!can($u,$permission) || (str_starts_with($route,'/pos') && !can($u,'pos.access'))){http_response_code(403);exit('Sales permission required.');}
 }
}
