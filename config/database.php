<?php
return [
    'dsn' => getenv('DB_DSN') ?: sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_DATABASE') ?: 'spare_pos'),
    'username' => getenv('DB_USERNAME') !== false ? getenv('DB_USERNAME') : 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
];
