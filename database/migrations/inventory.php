<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/app/bootstrap.php';
use App\Core\Database;
use App\Services\CategoryService;
try {
    $pdo=Database::connection();
    $definitions=file_get_contents(__DIR__.'/001_inventory.sql');
    // Check existing structures before any DDL. Never replace an existing table.
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) ENGINE=InnoDB;/s',$definitions,$tables,PREG_SET_ORDER);
    foreach ($tables as $table) {
        $query=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$query->execute([$table[1]]);
        $existing=$query->fetchAll(PDO::FETCH_COLUMN);
        if (!$existing) continue;
        preg_match_all('/^\s*(\w+)\s+(?:BIGINT|VARCHAR|TEXT|ENUM|INT|TIMESTAMP|DATETIME|TINYINT|DECIMAL)\b/im',$table[2],$columns);
        $missing=array_diff($columns[1],$existing);
        if ($missing) throw new RuntimeException('Existing table '.$table[1].' needs a reviewed migration for: '.implode(', ',$missing).'. No existing table was replaced.');
    }
    $pdo->exec($definitions);
    foreach (['UPDATE'=>'stock_movements_no_update','DELETE'=>'stock_movements_no_delete'] as $event=>$name) {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$stmt->execute([$name]);
        if (!$stmt->fetchColumn()) $pdo->exec("CREATE TRIGGER $name BEFORE $event ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Stock movements are append-only; insert a reversal instead.'");
    }
    $shops=$pdo->query('SELECT id FROM shops ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($shops as $shop) (new CategoryService((int)$shop))->seed();
    echo 'Inventory tables and append-only ledger guards ready. Catalogue seeded for '.count($shops)." shop(s).\n";
} catch (Throwable $exception) {
    fwrite(STDERR,'Inventory setup failed: '.$exception->getMessage()."\n");
    exit(1);
}
