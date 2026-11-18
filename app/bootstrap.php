<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
// Composer is optional: this fallback keeps local XAMPP setup dependency-free.
if (is_file(BASE_PATH . '/vendor/autoload.php')) require BASE_PATH . '/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    }
});
$environment = BASE_PATH . '/.env';
if (is_file($environment)) {
    foreach (file($environment, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (getenv($key) === false) putenv($key . '=' . trim(trim($value), "\"'"));
    }
}
require BASE_PATH . '/app/Helpers/response.php';
require BASE_PATH . '/app/Helpers/validation.php';
require BASE_PATH . '/app/Helpers/permissions.php';
require BASE_PATH . '/app/Helpers/ui.php';
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', BASE_PATH . '/storage/logs/php.log');
}
