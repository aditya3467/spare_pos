<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/app/bootstrap.php';
db()->exec("CREATE TABLE IF NOT EXISTS sale_custom_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shop_id BIGINT UNSIGNED NOT NULL, sale_id BIGINT UNSIGNED NOT NULL,
 revenue DECIMAL(15,2) NOT NULL, cost DECIMAL(15,2) NOT NULL,
 snapshot TEXT NOT NULL, INDEX custom_invoice(shop_id,sale_id),
 FOREIGN KEY(shop_id,sale_id) REFERENCES sales(shop_id,id) ON DELETE RESTRICT,
 CHECK(revenue>=0 AND cost>=0)
) ENGINE=InnoDB");
foreach (['UPDATE','DELETE'] as $operation) {
    $name = 'sale_custom_items_immutable_' . strtolower($operation);
    $q = db()->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
    $q->execute([$name]);
    if (!$q->fetchColumn()) db()->exec("CREATE TRIGGER $name BEFORE $operation ON sale_custom_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted custom items are immutable'");
}
