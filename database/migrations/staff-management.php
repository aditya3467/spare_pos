<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{$q=db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='session_version'");if(!$q->fetchColumn())db()->exec('ALTER TABLE users ADD COLUMN session_version BIGINT UNSIGNED NOT NULL DEFAULT 1');echo "Staff session version ready; existing accounts and permissions preserved.\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
