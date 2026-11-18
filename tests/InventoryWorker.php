<?php
require dirname(__DIR__).'/app/bootstrap.php';
try{(new App\Services\StockService((int)json_decode(getenv('INVENTORY_WORKER_ACTOR'),true)['shop_id']))->post(json_decode(getenv('INVENTORY_WORKER_PAYLOAD'),true),json_decode(getenv('INVENTORY_WORKER_ACTOR'),true),bin2hex(random_bytes(20)));echo 'posted';}catch(DomainException $e){echo 'blocked';}
