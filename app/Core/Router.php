<?php
namespace App\Core;
final class Router {
    private array $routes = [];
    public function add(string $method, string $path, array $handler, array $middleware = []): void {
        $this->routes[$path][$method] = [$handler, $middleware];
    }
    public function dispatch(): void {
        $path = Request::route(); $method = Request::method();
        if (!isset($this->routes[$path])) { http_response_code(404); echo 'Page not found.'; return; }
        if (!isset($this->routes[$path][$method])) {
            http_response_code(405); header('Allow: ' . implode(', ', array_keys($this->routes[$path]))); echo 'Method not allowed.'; return;
        }
        [$handler, $middleware] = $this->routes[$path][$method];
        foreach ($middleware as $class) (new $class())->handle();
        [$class, $action] = $handler;
        (new $class())->$action();
    }
}
