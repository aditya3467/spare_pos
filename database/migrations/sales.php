<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{db()->exec(file_get_contents(__DIR__.'/sales.sql'));foreach(['sales','sale_items','sale_payments','sale_cancellations'] as $table)foreach(['UPDATE','DELETE'] as $operation){$name=$table.'_immutable_'.strtolower($operation);$q=db()->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);if(!$q->fetchColumn())db()->exec("CREATE TRIGGER $name BEFORE $operation ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted sales records are immutable'");}echo "Sales tables ready; existing records preserved.\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
