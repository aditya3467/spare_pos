<?php
declare(strict_types=1);
if (getenv('RUN_MYSQL_TESTS')!=='1') { fwrite(STDERR,"Run with RUN_MYSQL_TESTS=1. Dashboard fixtures are rolled back.\n");exit(1); }
require dirname(__DIR__).'/app/bootstrap.php';
use App\Services\DashboardService;
$pdo=db();$checks=0;
function expectDashboard(bool $condition,string $message): void { global $checks;if (!$condition) throw new RuntimeException($message);$checks++; }
try {
    $pdo->beginTransaction();$shops=[];
    foreach ([1,2] as $unused) { $pdo->prepare('INSERT INTO shops (name) VALUES (?)')->execute(['Dashboard fixture '.bin2hex(random_bytes(5))]);$shops[]=(int)$pdo->lastInsertId(); }
    [$shop,$other]=$shops;
    $pdo->prepare('INSERT INTO categories (shop_id,name,slug) VALUES (?,?,?)')->execute([$shop,'Dashboard category','dashboard-category']);$category=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO subcategories (shop_id,category_id,name,slug) VALUES (?,?,?,?)')->execute([$shop,$category,'Dashboard subcategory','dashboard-subcategory']);
    $pdo->prepare('INSERT INTO units (shop_id,name,code) VALUES (?,?,?)')->execute([$shop,'Piece','PCS']);$unit=(int)$pdo->lastInsertId();
    $users=[];
    foreach (['shop_owner','staff'] as $role) {
        $pdo->prepare('INSERT INTO users (shop_id,name,email,password_hash,role,last_login_at) VALUES (?,?,?,?,?,NOW())')->execute([$shop,'Dashboard '.$role,'dash-'.bin2hex(random_bytes(5)).'@test.invalid',password_hash(bin2hex(random_bytes(10)),PASSWORD_DEFAULT),$role]);
        $users[$role]=['id'=>(int)$pdo->lastInsertId(),'shop_id'=>$shop,'role'=>$role];
    }
    $products=[];
    foreach ([['LOW','active',3],['ZERO','active',0],['INACTIVE','inactive',5],['UNTRACKED','active',2]] as [$sku,$status,$minimum]) {
        $pdo->prepare('INSERT INTO products (shop_id,category_id,base_unit_id,name,sku,status,reorder_level) VALUES (?,?,?,?,?,?,?)')->execute([$shop,$category,$unit,$sku,$sku,$status,$minimum]);$products[$sku]=(int)$pdo->lastInsertId();
    }
    foreach ([['LOW',2,'120.50'],['ZERO',0,'10'],['INACTIVE',3,'20']] as [$sku,$quantity,$cost]) $pdo->prepare('INSERT INTO stock_balances (shop_id,product_id,quantity,average_unit_cost) VALUES (?,?,?,?)')->execute([$shop,$products[$sku],$quantity,$cost]);
    $pdo->prepare("INSERT INTO stock_movements (shop_id,product_id,event_key,movement_type,quantity_delta,unit_cost,created_by,created_at) VALUES (?,?,?,'opening',2,120.50,?,NOW())")->execute([$shop,$products['LOW'],'current',$users['shop_owner']['id']]);
    $pdo->prepare("INSERT INTO stock_movements (shop_id,product_id,event_key,movement_type,quantity_delta,created_by,created_at) VALUES (?,?,?,'opening',1,?,DATE_SUB(NOW(),INTERVAL 20 DAY))")->execute([$shop,$products['UNTRACKED'],'old',$users['shop_owner']['id']]);
    // Another shop must never contribute to this shop's totals.
    $pdo->prepare('INSERT INTO categories (shop_id,name,slug) VALUES (?,?,?)')->execute([$other,'Other','other']);$otherCat=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO units (shop_id,name,code) VALUES (?,?,?)')->execute([$other,'Piece','PCS']);$otherUnit=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products (shop_id,category_id,base_unit_id,name,sku,reorder_level) VALUES (?,?,?,?,?,100)')->execute([$other,$otherCat,$otherUnit,'Secret other product','OTHER']);$otherProduct=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stock_balances (shop_id,product_id,quantity,average_unit_cost) VALUES (?,?,50,1000)')->execute([$other,$otherProduct]);
    $service=new DashboardService();$d=$service->overview($users['shop_owner'],7);
    expectDashboard($d['errors']===[],'Existing tables must query without errors.');
    expectDashboard($d['products']===3,'Only active, unarchived shop products count.');
    expectDashboard($d['low_stock_count']===1 && $d['low_stock'][0]['sku']==='LOW','Only known balances at a configured minimum trigger an alert.');
    expectDashboard((float)$d['valuation']===301.0,'Valuation must use quantities times average unit cost, including inactive stock.');
    expectDashboard($d['untracked_count']===1,'Missing stock balances must be disclosed, not silently treated as zero.');
    expectDashboard($d['category_count']===1 && $d['subcategory_count']===1,'Catalogue counts must be shop scoped.');
    expectDashboard(count($d['movements'])===1,'Seven-day activity must exclude older movements.');
    expectDashboard(count($service->overview($users['shop_owner'],30)['movements'])===2,'Thirty-day activity must include the older movement.');
    expectDashboard(count($d['activity'])===2,'Owners may see their shop team sign-ins.');
    $staff=$service->overview($users['staff'],7);
    expectDashboard($staff['products']===null && $staff['valuation']===null && $staff['movements']===[],'Staff without permissions must not receive protected inventory or finance data.');
    expectDashboard(count($staff['activity'])===1 && $staff['activity'][0]['role']==='staff','Staff may see only their own account activity.');
    $pdo->prepare('INSERT INTO staff_permissions (user_id,permission) VALUES (?,?)')->execute([$users['staff']['id'],'products.manage']);
    $staff=$service->overview($users['staff'],7);
    expectDashboard($staff['products']===3 && $staff['low_stock_count']===1 && $staff['valuation']===null,'Product permissions must not expose stock cost values.');
    $pdo->prepare('INSERT INTO staff_permissions (user_id,permission) VALUES (?,?)')->execute([$users['staff']['id'],'reports.view']);
    expectDashboard((float)$service->overview($users['staff'],7)['valuation']===301.0,'Report permission must enable valuation.');
    $admin=$service->overview(['id'=>999,'shop_id'=>null,'role'=>'superadmin'],7);
    expectDashboard($admin['products']===null && $admin['valuation']===null && $admin['activity']===[],'Unscoped admin must not receive invented global shop metrics.');
    $catalogue=[['id'=>1,'name'=>'Engine Parts','slug'=>'engine','description'=>'','status'=>'active','subcategories'=>[['id'=>2,'name'=>'Piston','slug'=>'piston','description'=>'','status'=>'inactive']]]];
    $filtered=category_view_state($catalogue,['q'=>'Piston','status'=>'all','view'=>'all']);
    expectDashboard($filtered['categories']===[1] && $filtered['subcategories']===[2] && $filtered['expanded']===[1],'Search must reveal a matching subcategory and its parent.');
    expectDashboard(category_view_state($catalogue,['q'=>'','status'=>'inactive','view'=>'subcategory'])['subcategories']===[2],'Level/status filtering must work independently of parent status.');
    expectDashboard(category_view_state($catalogue,['q'=>'missing','status'=>'all','view'=>'all'])['categories']===[],'No matches must yield an empty state.');
    echo "PASS: $checks dashboard data, permissions, shop isolation and category filter checks.\n";
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
