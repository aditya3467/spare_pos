<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{
 $fields=['previous_quantity'=>'DECIMAL(18,6) NULL','resulting_quantity'=>'DECIMAL(18,6) NULL','previous_average_cost'=>'DECIMAL(18,6) NULL','resulting_average_cost'=>'DECIMAL(18,6) NULL','reason'=>'VARCHAR(150) NULL','entry_date'=>'DATE NULL','cost_provided'=>'TINYINT NOT NULL DEFAULT 0'];
 foreach($fields as $name=>$definition){$q=db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_movements' AND COLUMN_NAME=?");$q->execute([$name]);if(!$q->fetchColumn())db()->exec("ALTER TABLE stock_movements ADD COLUMN $name $definition");}
 db()->exec("ALTER TABLE stock_movements MODIFY movement_type ENUM('opening','purchase','sale','customer_return','supplier_return','adjustment','damage','reversal','stock_in','stock_out','adjustment_increase','adjustment_decrease') NOT NULL");
 $q=db()->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_movements' AND INDEX_NAME='single_reversal'");if(!$q->fetchColumn())db()->exec('ALTER TABLE stock_movements ADD UNIQUE KEY single_reversal(shop_id,reverses_movement_id)');
 $guard=db()->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='stock_balances' AND CONSTRAINT_NAME='stock_balances_nonnegative'");if(!$guard->fetchColumn())db()->exec('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_nonnegative CHECK(quantity>=0)');
 db()->exec("CREATE TABLE IF NOT EXISTS inventory_requests (shop_id BIGINT UNSIGNED NOT NULL, request_key VARCHAR(80) NOT NULL, payload_hash CHAR(64) NOT NULL, response_json TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(shop_id,request_key), FOREIGN KEY(shop_id) REFERENCES shops(id) ON DELETE RESTRICT) ENGINE=InnoDB");
 echo "Inventory ledger extended; existing immutable movements preserved. Retry receipts ready.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
