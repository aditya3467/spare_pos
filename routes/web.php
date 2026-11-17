<?php
use App\Controllers\{AuthController, DashboardController};
use App\Middleware\{AuthMiddleware, RoleMiddleware, CsrfMiddleware};
$router->add('GET', '/', [AuthController::class, 'login']);
$router->add('GET', '/login', [AuthController::class, 'login']);
$router->add('POST', '/login', [AuthController::class, 'login'], [CsrfMiddleware::class]);
$router->add('GET', '/dashboard', [DashboardController::class, 'index'], [AuthMiddleware::class]);
$router->add('POST', '/staff/permissions', [DashboardController::class, 'permissions'], [AuthMiddleware::class, RoleMiddleware::class, CsrfMiddleware::class]);
$router->add('POST', '/logout', [AuthController::class, 'logout'], [CsrfMiddleware::class]);
