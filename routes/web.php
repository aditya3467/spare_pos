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
