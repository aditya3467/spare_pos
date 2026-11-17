<?php
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function db(): PDO { return App\Core\Database::connection(); }
function url(string $route = '/'): string { return 'index.php?route=' . rawurlencode($route); }
function redirect(string $route): never { header('Location: ' . url($route)); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function current_user(): ?array { return App\Core\Auth::user(); }
