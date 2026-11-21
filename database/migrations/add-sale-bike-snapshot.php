<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/app/bootstrap.php';
try {
    $pdo = App\Core\Database::connection();
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='vehicle_model_snapshot'");
    $check->execute();
    if (!(int)$check->fetchColumn()) {
        $pdo->exec('ALTER TABLE sales ADD COLUMN vehicle_model_snapshot VARCHAR(400) NULL AFTER shop_snapshot');
    }
    echo "Sale bike snapshot migration ready.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Sale bike snapshot migration failed: " . $exception->getMessage() . "\n");
    exit(1);
}
