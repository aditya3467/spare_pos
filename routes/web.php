<?php
use App\Controllers\{AuthController, DashboardController};
use App\Middleware\{AuthMiddleware, RoleMiddleware, CsrfMiddleware};
$router->add('GET', '/', [AuthController::class, 'login']);
$router->add('GET', '/login', [AuthController::class, 'login']);
$router->add('POST', '/login', [AuthController::class, 'login'], [CsrfMiddleware::class]);
$router->add('GET', '/dashboard', [DashboardController::class, 'index'], [AuthMiddleware::class]);
$router->add('POST', '/staff/permissions', [App\Controllers\StaffController::class, 'permissions'], [AuthMiddleware::class, RoleMiddleware::class, CsrfMiddleware::class]);
$router->add('POST', '/logout', [AuthController::class, 'logout'], [CsrfMiddleware::class]);
$router->add('GET', '/categories', [App\Controllers\CategoryController::class, 'index'], [AuthMiddleware::class, RoleMiddleware::class]);
$router->add('POST', '/categories', [App\Controllers\CategoryController::class, 'mutate'], [AuthMiddleware::class, RoleMiddleware::class, CsrfMiddleware::class]);

$router->add('GET', '/staff', [App\Controllers\StaffController::class, 'index'], [AuthMiddleware::class, RoleMiddleware::class]);

// Product catalogue; write routes retain CSRF and shop/permission middleware.
foreach ([['GET','/products','ProductController','index'],['GET','/products/form','ProductController','form'],['GET','/products/view','ProductController','details'],['POST','/products/save','ProductController','save'],['POST','/products/archive','ProductController','archive'],['GET','/products/import','ProductImportController','index'],['GET','/products/template','ProductImportController','template'],['GET','/products/import-report','ProductImportController','report'],['POST','/products/import-preview','ProductImportController','preview'],['POST','/products/import-commit','ProductImportController','commit']] as [$method,$path,$controller,$action]) {
 $middleware=[AuthMiddleware::class,App\Middleware\ProductMiddleware::class];if($method==='POST')$middleware[]=CsrfMiddleware::class;
 $router->add($method,$path,['App\\Controllers\\'.$controller,$action],$middleware);
}
$router->add('GET','/product-masters',[App\Controllers\ProductMasterController::class,'index'],[AuthMiddleware::class,RoleMiddleware::class]);
$router->add('POST','/product-masters',[App\Controllers\ProductMasterController::class,'save'],[AuthMiddleware::class,RoleMiddleware::class,CsrfMiddleware::class]);

foreach([['GET','/inventory','index'],['GET','/inventory/stock','index'],['GET','/inventory/form','form'],['GET','/inventory/in','form'],['GET','/inventory/out','form'],['GET','/inventory/adjust','form'],['GET','/inventory/search','search'],['POST','/inventory/post','post'],['GET','/inventory/movements','history'],['GET','/inventory/movement','details']] as [$method,$path,$action]){
 $middleware=[AuthMiddleware::class,App\Middleware\InventoryMiddleware::class];if($method==='POST')$middleware[]=CsrfMiddleware::class;
 $router->add($method,$path,[App\Controllers\InventoryController::class,$action],$middleware);
}

foreach([['GET','/sales/report','report'],['POST','/sales/labour-expense','expense'],['GET','/pos','pos'],['GET','/pos/search','search'],['GET','/pos/customers','customers'],['POST','/pos/customer','customer'],['POST','/pos/quote','quote'],['POST','/pos/complete','complete'],['GET','/sales','history'],['GET','/sales/invoice','invoice'],['GET','/sales/print','invoice'],['POST','/sales/cancel','cancel'],['GET','/sales/settings','settings'],['POST','/sales/settings','settings']] as [$method,$path,$action]){
 $middleware=[AuthMiddleware::class,App\Middleware\SalesMiddleware::class];if($method==='POST')$middleware[]=CsrfMiddleware::class;
 $router->add($method,$path,[App\Controllers\SalesController::class,$action],$middleware);
}

foreach(['/reports','/reports/sales','/reports/profit','/reports/inventory','/reports/export'] as $path){
 $router->add('GET',$path,[App\Controllers\ReportController::class,$path==='/reports/export'?'export':'index'],[AuthMiddleware::class,App\Middleware\ReportMiddleware::class]);
}

$router->add('GET','/staff/form',[App\Controllers\StaffController::class,'form'],[AuthMiddleware::class,RoleMiddleware::class]);
$router->add('POST','/staff/save',[App\Controllers\StaffController::class,'save'],[AuthMiddleware::class,RoleMiddleware::class,CsrfMiddleware::class]);
