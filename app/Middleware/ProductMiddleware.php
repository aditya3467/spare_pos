<?php
namespace App\Middleware;
final class ProductMiddleware {
 public function handle():void {
  $u=current_user();if(!$u)redirect('/login');
  if(!$u['shop_id'] || !can($u,'products.manage')){http_response_code(403);exit('Product access is not permitted.');}
 }
}
