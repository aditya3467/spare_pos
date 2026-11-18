<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{$q=db()->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sale_payments' GROUP BY INDEX_NAME");$exists=false;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $columns)if(str_starts_with($columns,'shop_id,payment_date'))$exists=true;if(!$exists)db()->exec('ALTER TABLE sale_payments ADD INDEX report_payment_date(shop_id,payment_date,id)');echo "Report payment-date index ready; no transaction data changed.\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
