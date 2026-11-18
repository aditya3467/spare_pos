<?php
namespace App\Services;
use DomainException;
use PDO;
use Throwable;
final class StockService {
 public function __construct(private int $shop){}
 public static function permission(string $kind):string {return match($kind){'in'=>'inventory.stock_in','out'=>'inventory.stock_out','adjust','reverse'=>'inventory.adjust',default=>throw new DomainException('Invalid stock operation.')};}
 public static function label(array $movement):string {
  return match($movement['movement_type']){'opening'=>'Opening Stock','stock_in'=>'Stock In','stock_out'=>'Stock Out','adjustment_increase'=>'Adjustment Increase','adjustment_decrease'=>'Adjustment Decrease','reversal'=>'Reversal',default=>ucwords(str_replace('_',' ',$movement['movement_type']))};
 }
 private static function text(array $d,string $field,int $max,bool $required=false):string {
  $value=$d[$field]??'';if(!is_string($value) || mb_strlen(trim($value))>$max || ($required && trim($value)===''))throw new DomainException('Enter a valid '.str_replace('_',' ',$field).' (up to '.$max.' characters).');return trim($value);
 }
 public static function opening(PDO $pdo,int $shop,int $product,string $quantity,string $cost,string $date,int $actor,string $key):void {
  if(!$pdo->inTransaction())throw new \LogicException('Opening stock requires the product creation transaction.');
  $movement=null;
  if(bccomp($quantity,'0',6)>0){
   $q=$pdo->prepare("INSERT INTO stock_movements (shop_id,product_id,event_key,movement_type,quantity_delta,unit_cost,reference_type,reference_id,created_by,previous_quantity,resulting_quantity,previous_average_cost,resulting_average_cost,reason,entry_date,cost_provided) VALUES (?,?,?,'opening',?,?,'product_creation',?,?,0,?,0,?,'Opening stock',?,1)");
   $q->execute([$shop,$product,'opening:'.$key,$quantity,$cost,(string)$product,$actor,$quantity,$cost,$date]);$movement=$pdo->lastInsertId();
  }
  $pdo->prepare('INSERT INTO stock_balances (shop_id,product_id,quantity,average_unit_cost,last_movement_id) VALUES (?,?,?,?,?)')->execute([$shop,$product,$quantity,bccomp($quantity,'0',6)>0?$cost:'0.000000',$movement]);
 }
 public function post(array $d,array $actor,string $key):array {
  $kind=is_string($d['kind']??null)?$d['kind']:'';$permission=self::permission($kind);
  if((int)($actor['shop_id']??0)!==$this->shop || !can($actor,$permission))throw new DomainException('You do not have permission for this stock operation.');
  if($kind==='reverse' && !can($actor,'inventory.movements'))throw new DomainException('Movement history permission is required to reverse entries.');
  if(!preg_match('/^[a-f0-9]{40}$/D',$key))throw new DomainException('Open a fresh stock form.');
  if(($d['confirmed']??'')!=='1')throw new DomainException('Confirm the stock operation before submitting.');
  $product=ProductService::optionalId($d['product_id']??'');if(!$product)throw new DomainException('Choose a product.');
  $reason=self::text($d,'reason',150,true);$reference=self::text($d,'reference',100);$notes=self::text($d,'notes',500);
  $date=self::text($d,'entry_date',10,true);$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Asia/Kolkata'));$today=(new \DateTimeImmutable('today',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
  if(!$parsed || $parsed->format('Y-m-d')!==$date || $date<'1900-01-01' || $date>$today)throw new DomainException('Entry date must be a valid date no later than today.');
  $amount=null;$expected=null;$originalId=null;$cost=null;
  if($kind==='reverse'){$originalId=ProductService::optionalId($d['movement_id']??'');if(!$originalId)throw new DomainException('Choose an original movement.');}
  else{$amount=ProductService::decimal($d[$kind==='adjust'?'physical_quantity':'quantity']??'',6,false,$kind==='adjust'?'Physically counted stock':'Quantity');if($kind!=='adjust' && bccomp($amount,'0',6)<=0)throw new DomainException('Quantity must be greater than zero.');}
  if($kind==='adjust')$expected=ProductService::decimal($d['expected_quantity']??'',6,false,'Previously displayed stock');
  if($kind==='in')$cost=ProductService::decimal($d['unit_cost']??'',6,true,'Purchase cost per item');
  $hash=hash('sha256',json_encode([$kind,$product,$amount,$expected,$originalId,$cost,$reason,$reference,$notes,$date,$actor['id']]));
  $pdo=db();$pdo->beginTransaction();try{
   // Product first: serializes balance creation and concurrent operations on this SKU.
   $q=$pdo->prepare('SELECT id,name,sku,status,archived_at FROM products WHERE shop_id=? AND id=? FOR UPDATE');$q->execute([$this->shop,$product]);$p=$q->fetch();if(!$p)throw new DomainException('Product not found in your shop.');
   $q=$pdo->prepare('SELECT * FROM inventory_requests WHERE shop_id=? AND request_key=? FOR UPDATE');$q->execute([$this->shop,$key]);if($receipt=$q->fetch()){
    if($receipt['payload_hash']!==$hash)throw new DomainException('This request was already posted with different values. Open a new form.');$pdo->commit();return json_decode($receipt['response_json'],true);
   }
   if($kind==='in' && ($p['archived_at'] || $p['status']!=='active'))throw new DomainException('Stock In requires an active, unarchived product.');
   $q=$pdo->prepare('SELECT * FROM stock_balances WHERE shop_id=? AND product_id=? FOR UPDATE');$q->execute([$this->shop,$product]);$balance=$q->fetch();
   $sum=$pdo->prepare('SELECT COALESCE(SUM(quantity_delta),0) FROM stock_movements WHERE shop_id=? AND product_id=?');$sum->execute([$this->shop,$product]);$ledger=(string)$sum->fetchColumn();
   if(!$balance){if(bccomp($ledger,'0',6)!==0)throw new DomainException('Balance is missing for a non-empty ledger. Owner reconciliation is required.');$pdo->prepare('INSERT INTO stock_balances (shop_id,product_id) VALUES (?,?)')->execute([$this->shop,$product]);$balance=['quantity'=>'0.000000','average_unit_cost'=>'0.000000'];}
   $before=$balance['quantity'];$average=$balance['average_unit_cost'];
   if(bccomp($before,$ledger,6)!==0)throw new DomainException('Stock balance does not match its ledger. Owner reconciliation is required before posting.');
   $reverses=null;
   if($kind==='adjust'){
    if(bccomp($before,$expected,6)!==0)throw new DomainException('Stock changed since it was displayed. Reload the product and recount before adjusting.');
    $delta=bcsub($amount,$before,6);$type=bccomp($delta,'0',6)>0?'adjustment_increase':'adjustment_decrease';$appliedCost=$average;
   }elseif($kind==='reverse'){
    $q=$pdo->prepare('SELECT * FROM stock_movements WHERE shop_id=? AND product_id=? AND id=? FOR UPDATE');$q->execute([$this->shop,$product,$originalId]);$original=$q->fetch();if(!$original || $original['movement_type']==='reversal')throw new DomainException('Choose an original movement in this product’s history, not another reversal.');
    $q=$pdo->prepare('SELECT id FROM stock_movements WHERE shop_id=? AND reverses_movement_id=?');$q->execute([$this->shop,$originalId]);if($q->fetchColumn())throw new DomainException('This movement has already been reversed.');
    if($original['reference_type']==='sale')throw new DomainException('Cancel the linked invoice to reverse sale stock and payments together.');
    $delta=bcsub('0',$original['quantity_delta'],6);$type='reversal';$reverses=$originalId;$appliedCost=$original['unit_cost'];
   }else{$delta=$kind==='in'?$amount:bcsub('0',$amount,6);$type=$kind==='in'?'stock_in':'stock_out';$appliedCost=$kind==='in'?($cost??$average):$average;}
   [$movement,$after]=$this->applyLocked($pdo,$product,$before,$average,$delta,$appliedCost,$kind,$type,$reverses,$notes,$actor['id'],$reason,$date,'manual:'.$key,'manual_inventory',$reference,$kind==='in' && $cost!==null?1:0);
   $response=['movement_id'=>$movement,'product_id'=>$product,'previous'=>$before,'resulting'=>$after,'delta'=>$delta,'message'=>$movement?'Stock posted successfully.':'Physical stock matches system stock. No movement was created.'];
   $pdo->prepare('INSERT INTO inventory_requests (shop_id,request_key,payload_hash,response_json) VALUES (?,?,?,?)')->execute([$this->shop,$key,$hash,json_encode($response)]);$pdo->commit();return $response;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
 // Shared ledger writer: callers must hold the product and balance locks in one transaction.
 private function applyLocked(PDO $pdo,int $product,string $before,string $average,string $delta,string $appliedCost,string $kind,string $type,?int $reverses,string $notes,int $actorId,string $reason,string $date,string $event,string $referenceType,string $reference,int $costProvided):array {
   $after=bcadd($before,$delta,6);if(bccomp($after,'0',6)<0)throw new DomainException('Insufficient stock. Available: '.quantity($before).'; requested removal: '.quantity(bcsub('0',$delta,6)).'.');
   if(bccomp($after,'999999999999.999999',6)>0)throw new DomainException('Resulting stock exceeds the supported quantity.');
   $movement=null;$afterAverage=$average;
   if(bccomp($delta,'0',6)!==0){
    if(bccomp($after,'0',6)===0)$afterAverage='0.000000';
    elseif(bccomp($delta,'0',6)>0 || $kind==='reverse'){
     $value=bcadd(bcmul($before,$average,12),bcmul($delta,$appliedCost,12),12);
     if(bccomp($value,'0',12)<0)throw new DomainException('This reversal would produce negative stock value. Use an audited counted-stock correction instead.');$afterAverage=bcdiv($value,$after,6);
    }
    if(bccomp($afterAverage,'999999999999.999999',6)>0)throw new DomainException('Resulting average cost exceeds the supported amount.');
    $q=$pdo->prepare('INSERT INTO stock_movements (shop_id,product_id,event_key,movement_type,quantity_delta,unit_cost,reference_type,reference_id,reverses_movement_id,note,created_by,previous_quantity,resulting_quantity,previous_average_cost,resulting_average_cost,reason,entry_date,cost_provided) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$this->shop,$product,$event,$type,$delta,$appliedCost,$referenceType,$reference?:null,$reverses,$notes?:null,$actorId,$before,$after,$average,$afterAverage,$reason,$date,$costProvided]);$movement=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE stock_balances SET quantity=?,average_unit_cost=?,last_movement_id=? WHERE shop_id=? AND product_id=?')->execute([$after,$afterAverage,$movement,$this->shop,$product]);
   }
  return [$movement,$after];
 }
 public function saleMovement(int $product,string $quantity,int $saleId,int $actorId,?int $originalId=null):array {
  $pdo=db();if(!$pdo->inTransaction())throw new \LogicException('Sale stock posting requires a transaction.');
  $q=$pdo->prepare('SELECT id,status,archived_at FROM products WHERE shop_id=? AND id=? FOR UPDATE');$q->execute([$this->shop,$product]);$p=$q->fetch();if(!$p || (!$originalId && ($p['status']!=='active' || $p['archived_at'])))throw new DomainException('Only active products can be sold.');
  $q=$pdo->prepare('SELECT * FROM stock_balances WHERE shop_id=? AND product_id=? FOR UPDATE');$q->execute([$this->shop,$product]);$b=$q->fetch();if(!$b)throw new DomainException('Product has no stock balance.');
  $q=$pdo->prepare('SELECT COALESCE(SUM(quantity_delta),0) FROM stock_movements WHERE shop_id=? AND product_id=? FOR UPDATE');$q->execute([$this->shop,$product]);if(bccomp($b['quantity'],(string)$q->fetchColumn(),6)!==0)throw new DomainException('Stock ledger mismatch. Owner reconciliation required.');
  $cost=$b['average_unit_cost'];$delta=bcsub('0',$quantity,6);$type='sale';$kind='sale';
  if($originalId){
   $q=$pdo->prepare("SELECT * FROM stock_movements WHERE shop_id=? AND product_id=? AND id=? AND movement_type='sale' AND reference_type='sale' AND reference_id=? FOR UPDATE");$q->execute([$this->shop,$product,$originalId,(string)$saleId]);$o=$q->fetch();if(!$o || bccomp(bcsub('0',$o['quantity_delta'],6),$quantity,6)!==0)throw new DomainException('Sale movement does not match cancellation.');
   $q=$pdo->prepare('SELECT id FROM stock_movements WHERE shop_id=? AND reverses_movement_id=?');$q->execute([$this->shop,$originalId]);if($q->fetchColumn())throw new DomainException('Sale stock was already reversed.');
   $cost=$o['unit_cost'];$delta=$quantity;$type='reversal';$kind='reverse';
  }
  [$movement,$after]=$this->applyLocked($pdo,$product,$b['quantity'],$b['average_unit_cost'],$delta,$cost,$kind,$type,$originalId,'',$actorId,$originalId?'Invoice cancellation':'Counter sale',(new \DateTimeImmutable('today',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d'),($originalId?'sale-cancel:':'sale:').$saleId.':'.$product,'sale',(string)$saleId,0);
  return ['movement_id'=>$movement,'unit_cost'=>$cost,'resulting'=>$after];
 }

}
