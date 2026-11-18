<?php
declare(strict_types=1);
// Verify graceful setup states with no inventory, sales or payment tables present.
putenv('DB_DSN=sqlite::memory:');
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=db();
$pdo->exec('CREATE TABLE users (id INTEGER, shop_id INTEGER, name TEXT, role TEXT, last_login_at TEXT)');
$pdo->exec('CREATE TABLE staff_permissions (user_id INTEGER, permission TEXT)');
$service=new App\Services\DashboardService();
$owner=$service->overview(['id'=>1,'shop_id'=>1,'role'=>'shop_owner'],7);
if ($owner['errors'] || $owner['products']!==null || $owner['valuation']!==null || $owner['available']['products'] || $owner['available']['stock'] || $owner['available']['movements']) throw new RuntimeException('Missing modules must produce setup states without querying nonexistent tables.');
$staff=$service->overview(['id'=>2,'shop_id'=>1,'role'=>'staff'],7);
if ($staff['errors'] || $staff['products']!==null || $staff['low_stock_count']!==null || $staff['valuation']!==null) throw new RuntimeException('Restricted staff must not receive protected values.');
echo "PASS: dashboard setup states with missing module tables.\n";
