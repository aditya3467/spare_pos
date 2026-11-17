<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
App\Core\Auth::start();
try {
    $router = new App\Core\Router();
    require BASE_PATH . '/routes/web.php';
    $router->dispatch();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    echo 'The workspace is temporarily unavailable. Please try again shortly.';
}
