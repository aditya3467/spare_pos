<?php
require dirname(__DIR__).'/app/bootstrap.php';
try{$actor=json_decode(getenv('SALES_WORKER_ACTOR'),true);$d=json_decode(getenv('SALES_WORKER_PAYLOAD'),true);(new App\Services\SaleService((int)$actor['shop_id']))->complete($d,$actor,getenv('SALES_WORKER_KEY')?:bin2hex(random_bytes(20)));echo 'posted';}catch(DomainException $e){echo 'blocked';}
