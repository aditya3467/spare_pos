<?php
namespace App\Core;
final class Request {
    public static function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
    public static function route(): string {
        $route = $_GET['route'] ?? '/';
        return is_string($route) ? '/' . trim($route, '/') : '/invalid';
    }
}
