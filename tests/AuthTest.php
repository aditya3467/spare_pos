<?php
declare(strict_types=1);
// Isolated in-memory database: never connects to the shop database.
putenv('DB_DSN=sqlite::memory:');
require dirname(__DIR__) . '/app/bootstrap.php';
ini_set('session.save_path', sys_get_temp_dir());
App\Core\Auth::start();
function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$pdo = db();
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, shop_id INTEGER, name TEXT, email TEXT, role TEXT, active INTEGER)');
$pdo->exec('CREATE TABLE staff_permissions (user_id INTEGER, permission TEXT)');
$pdo->exec("INSERT INTO users VALUES (1, NULL, 'Admin', 'admin@example.com', 'superadmin', 1), (2, 1, 'Owner', 'owner@example.com', 'shop_owner', 1), (3, 1, 'Staff', 'staff@example.com', 'staff', 1), (4, 2, 'Other staff', 'other@example.com', 'staff', 1)");
expect(current_user() === null, 'Guests must not authenticate.');
$_SESSION['user_id'] = 3;
$staff = current_user();
expect($staff['role'] === 'staff', 'Session must resolve the database user.');
expect(!can($staff, 'sales.create'), 'Staff must start without permissions.');
$pdo->exec("INSERT INTO staff_permissions VALUES (3, 'sales.create')");
expect(can($staff, 'sales.create'), 'Granted permission must apply immediately.');
expect(!can(['id' => 4, 'role' => 'staff'], 'sales.create'), 'Permissions must not leak to other staff.');
$pdo->exec('DELETE FROM staff_permissions');
expect(!can($staff, 'sales.create'), 'Revoked permission must apply immediately.');
foreach (['superadmin', 'shop_owner'] as $role) {
    expect(can(['role' => $role], 'sales.create'), 'Privileged role must have configured access.');
    expect(!can(['role' => $role], 'invented.permission'), 'Unknown permissions must be denied.');
}
expect(valid_permissions(['sales.create']), 'Known permission must validate.');
expect(!valid_permissions([['sales.create']]), 'Nested permission input must be rejected.');
expect(!valid_permissions(['unknown']), 'Unknown permission input must be rejected.');
try {
    (new App\Services\StaffPermissionService())->update($staff, 4, ['sales.create']);
    throw new RuntimeException('Staff must not change permissions.');
} catch (DomainException $expected) {}
$pdo->exec('UPDATE users SET active = 0 WHERE id = 3');
expect(current_user() === null, 'Disabled accounts must lose authenticated access.');
$_POST['csrf'] = csrf();
(new App\Middleware\CsrfMiddleware())->handle();
$oldSession = session_id();
$_SESSION['last_activity'] = time() - 1900;
session_write_close();
App\Core\Auth::start();
expect(!isset($_SESSION['user_id']), 'Idle expiry must clear authentication.');
expect(session_id() !== $oldSession, 'Idle expiry must rotate the session ID.');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['route' => '/login'];
$router = new App\Core\Router();
require BASE_PATH . '/routes/web.php';
ob_start(); $router->dispatch(); $html = ob_get_clean();
expect(str_contains($html, 'Welcome back.'), 'Login route must render.');
expect(str_contains($html, 'assets/css/app.css'), 'CSS must use the public asset directory.');
expect(str_contains($html, 'route=%2Flogin'), 'Login form must post to its routed endpoint.');
$_GET = ['route' => '/logout'];
ob_start(); $router->dispatch(); ob_end_clean();
expect(http_response_code() === 405, 'GET logout must be rejected.');
$_GET = ['route' => '/missing'];
ob_start(); $router->dispatch(); ob_end_clean();
expect(http_response_code() === 404, 'Unknown routes must return 404.');
App\Core\Auth::logout();
echo "Authentication foundation checks passed (SQLite; MySQL sign-in not exercised).\n";
