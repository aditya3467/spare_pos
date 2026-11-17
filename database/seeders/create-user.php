<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function prompt(string $label): string { echo $label; return trim(fgets(STDIN) ?: ''); }
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$role = prompt('Role (superadmin / shop_owner / staff): ');
if (!in_array($role, ['superadmin', 'shop_owner', 'staff'], true)) exit("Invalid role.\n");
$name = prompt('Full name: '); $email = strtolower(prompt('Email: '));
if (!$name || strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) exit("Invalid name or email.\n");
echo 'Password (minimum 12 characters; input hidden): ';
$tty = function_exists('shell_exec') ? shell_exec('stty -g 2>/dev/null') : null;
if (!$tty) exit("Use an interactive terminal to enter a hidden password.\n");
system('stty -echo');
try { $password = rtrim(fgets(STDIN) ?: '', "\r\n"); } finally { system('stty ' . escapeshellarg(trim($tty))); echo "\n"; }
if (strlen($password) < 12 || strlen($password) > 72) exit("Use a password between 12 and 72 bytes.\n");
try {
    $pdo = App\Core\Database::connection();
    $shopId = null; $shopName = '';
    if ($role !== 'superadmin') {
        $shopId = prompt('Existing shop ID (leave blank to create a shop for an owner): ');
        if ($shopId === '' && $role === 'shop_owner') { $shopName = prompt('New shop name: '); if (!$shopName || strlen($shopName) > 150) exit("Invalid shop name.\n"); }
        elseif (!ctype_digit($shopId) || (int)$shopId < 1) exit("A valid shop ID is required.\n");
    }
    $pdo->beginTransaction();
    if ($shopName) { $pdo->prepare('INSERT INTO shops (name) VALUES (?)')->execute([$shopName]); $shopId = $pdo->lastInsertId(); }
    $pdo->prepare('INSERT INTO users (shop_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)')->execute([$shopId, $name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
    $pdo->commit(); echo 'Account created.' . ($shopId ? " Shop ID: $shopId" : '') . "\n";
} catch (Throwable $e) { if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); fwrite(STDERR, "Account creation failed. Check the database, shop ID, and whether the email is already registered.\n"); exit(1); }
