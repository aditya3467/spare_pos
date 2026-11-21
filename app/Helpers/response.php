<?php
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function db(): PDO { return App\Core\Database::connection(); }
function url(string $route = '/'): string { return 'index.php?route=' . rawurlencode($route); }
function asset(string $path): string {
    $path = ltrim($path, '/');
    if ($path === '' || str_contains($path, '..') || !preg_match('#\A[a-zA-Z0-9_./-]+\z#D', $path)) return $path;
    $file = BASE_PATH . '/public/' . $path;
    if (!is_file($file)) return $path;
    $hash = hash_file('sha256', $file);
    return $path . '?v=' . substr($hash, 0, 16);
}
function redirect(string $route): never { header('Location: ' . url($route)); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function current_user(): ?array { return App\Core\Auth::user(); }
