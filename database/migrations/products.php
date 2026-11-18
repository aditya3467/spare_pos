<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try {
 $pdo=db();
 foreach(['purchase_price'=>'DECIMAL(15,2) NOT NULL DEFAULT 0','part_number'=>'VARCHAR(120) NULL','rack_location'=>'VARCHAR(80) NULL','creation_key'=>'VARCHAR(100) NULL','lock_version'=>'INT UNSIGNED NOT NULL DEFAULT 1'] as $column=>$definition){
  $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute(['products',$column]);
  if(!$q->fetchColumn())$pdo->exec("ALTER TABLE products ADD COLUMN $column $definition");
 }
 $q=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND INDEX_NAME='product_creation_key'");
 if(!$q->fetchColumn())$pdo->exec('ALTER TABLE products ADD UNIQUE KEY product_creation_key(shop_id,creation_key)');
 $pdo->exec('ALTER TABLE products MODIFY mrp DECIMAL(15,2) NULL DEFAULT NULL');
 $pdo->exec(file_get_contents(__DIR__.'/002_products.sql'));
 foreach($pdo->query('SELECT id FROM shops')->fetchAll(PDO::FETCH_COLUMN) as $shop)(new App\Services\ProductMasterService((int)$shop))->seedVehicles();
 echo "Product fields, compatibility tables and vehicle catalogue ready. Existing data preserved.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
