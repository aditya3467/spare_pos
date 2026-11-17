<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/app/bootstrap.php';
try {
    App\Core\Database::connection()->exec(file_get_contents(BASE_PATH . '/database/schema.sql'));
    echo "Database tables ready.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Setup failed: " . $exception->getMessage() . "\n"); exit(1);
}
