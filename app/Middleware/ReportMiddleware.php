<?php
namespace App\Middleware;
final class ReportMiddleware {
 public function handle():void {$user=current_user();if(!$user)redirect('/login');if(!$user['shop_id'] || !can($user,'reports.view')){http_response_code(403);exit('Reports permission required for a shop account.');}}
}
