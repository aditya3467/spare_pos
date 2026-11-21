<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{
 $pdo=db();
 foreach(['purchase_price_mode'=>"ENUM('automatic','manual') NOT NULL DEFAULT 'manual'",'selling_price_mode'=>"ENUM('automatic','manual') NOT NULL DEFAULT 'manual'",'purchase_discount'=>'DECIMAL(5,2) NULL','selling_discount'=>'DECIMAL(5,2) NULL'] as $column=>$definition){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'products\' AND COLUMN_NAME=?');$q->execute([$column]);if(!(int)$q->fetchColumn())$pdo->exec("ALTER TABLE products ADD COLUMN $column $definition");}
 echo "Product pricing modes ready; existing products remain manual.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
