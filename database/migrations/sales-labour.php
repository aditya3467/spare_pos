<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{
 foreach(['labour_tax_rate_id'=>'BIGINT UNSIGNED NULL','labour_sac_code'=>'VARCHAR(20) NULL'] as $field=>$definition){$q=db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_tax_settings' AND COLUMN_NAME=?");$q->execute([$field]);if(!$q->fetchColumn())db()->exec("ALTER TABLE shop_tax_settings ADD COLUMN $field $definition");}
 $q=db()->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='shop_tax_settings' AND CONSTRAINT_NAME='labour_tax_scope'");if(!$q->fetchColumn())db()->exec('ALTER TABLE shop_tax_settings ADD CONSTRAINT labour_tax_scope FOREIGN KEY(shop_id,labour_tax_rate_id) REFERENCES tax_rates(shop_id,id) ON DELETE RESTRICT');
 db()->exec(file_get_contents(__DIR__.'/sales-labour.sql'));
 foreach(['sale_services','service_expenses'] as $table)foreach(['UPDATE','DELETE'] as $operation){$name=$table.'_immutable_'.strtolower($operation);$q=db()->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);if(!$q->fetchColumn())db()->exec("CREATE TRIGGER $name BEFORE $operation ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted service records are immutable'");}
 echo "Labour service lines, tax settings and expense records ready; existing invoices preserved.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
