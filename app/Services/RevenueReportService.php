<?php
namespace App\Services;
use DomainException;
use Throwable;
final class RevenueReportService {
 public function __construct(private int $shop){}
 public static function dates(array $f):array {
  $today=(new \DateTimeImmutable('today',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');$from=$f['from']??substr($today,0,7).'-01';$to=$f['to']??$today;
  foreach([$from,$to] as $v){if(!is_string($v))throw new DomainException('Choose valid report dates.');$dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$dt || $dt->format('Y-m-d')!==$v)throw new DomainException('Choose valid report dates.');}if($from>$to)throw new DomainException('Start date must not follow end date.');return ['from'=>$from,'to'=>$to];
 }
 private function sum(string $sql,array $args):string {$q=db()->prepare($sql);$q->execute($args);return SaleTaxService::round((string)($q->fetchColumn()?:'0'));}
 // Revenue is after discounts and excludes collected GST; refunds use cancellation date.
 public function summary(array $dates):array {
  $pdo=db();$own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();try{$result=$this->totals($dates);if($own)$pdo->commit();return $result;}catch(Throwable $e){if($own && $pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
 private function totals(array $dates):array {
  $from=$dates['from'].' 00:00:00';$to=$dates['to'].' 23:59:59';$args=[$this->shop,$from,$to,$this->shop,$from,$to];
  $events='(SELECT s.id,s.shop_id,1 sign FROM sales s WHERE s.shop_id=? AND s.created_at BETWEEN ? AND ? UNION ALL SELECT s.id,s.shop_id,-1 sign FROM sale_cancellations c JOIN sales s ON s.shop_id=c.shop_id AND s.id=c.sale_id WHERE c.shop_id=? AND c.created_at BETWEEN ? AND ?) ev';
  $product=$this->sum("SELECT COALESCE(SUM(ev.sign*CAST(JSON_UNQUOTE(JSON_EXTRACT(i.snapshot,'$.taxable')) AS DECIMAL(15,2))),0) FROM $events JOIN sale_items i ON i.shop_id=ev.shop_id AND i.sale_id=ev.id",$args);
  $cogs=$this->sum("SELECT COALESCE(SUM(ev.sign*i.quantity*i.unit_cost),0) FROM $events JOIN sale_items i ON i.shop_id=ev.shop_id AND i.sale_id=ev.id",$args);
  $service=$this->sum("SELECT COALESCE(SUM(ev.sign*l.revenue),0) FROM $events JOIN sale_services l ON l.shop_id=ev.shop_id AND l.sale_id=ev.id",$args);
  $billed=$this->sum("SELECT COALESCE(SUM(ev.sign*s.total),0) FROM $events JOIN sales s ON s.shop_id=ev.shop_id AND s.id=ev.id",$args);
  $expenses=$this->sum('SELECT COALESCE(SUM(amount),0) FROM service_expenses WHERE shop_id=? AND incurred_on BETWEEN ? AND ?',[$this->shop,$dates['from'],$dates['to']]);
  $q=db()->prepare('SELECT COUNT(*) FROM sales WHERE shop_id=? AND created_at BETWEEN ? AND ?');$q->execute([$this->shop,$from,$to]);$count=(int)$q->fetchColumn();
  $q=db()->prepare('SELECT COUNT(*) FROM sale_cancellations WHERE shop_id=? AND created_at BETWEEN ? AND ?');$q->execute([$this->shop,$from,$to]);$cancelled=(int)$q->fetchColumn();
  $productProfit=bcsub($product,$cogs,2);$gross=bcadd($productProfit,$service,2);return ['product_revenue'=>$product,'service_revenue'=>$service,'revenue'=>bcadd($product,$service,2),'product_cogs'=>$cogs,'product_gross_profit'=>$productProfit,'service_gross_profit'=>$service,'gross_profit'=>$gross,'labour_expenses'=>$expenses,'service_net_profit'=>bcsub($service,$expenses,2),'net_profit'=>bcsub($gross,$expenses,2),'billed'=>$billed,'invoice_count'=>$count,'cancellation_count'=>$cancelled];
 }
 public function expenses(array $dates):array {$q=db()->prepare('SELECT e.*,u.name AS recorder,s.invoice_number,r.id AS reversed_by FROM service_expenses e JOIN users u ON u.id=e.created_by LEFT JOIN sales s ON s.shop_id=e.shop_id AND s.id=e.sale_id LEFT JOIN service_expenses r ON r.shop_id=e.shop_id AND r.reverses_expense_id=e.id WHERE e.shop_id=? AND e.incurred_on BETWEEN ? AND ? ORDER BY e.incurred_on DESC,e.id DESC LIMIT 50');$q->execute([$this->shop,$dates['from'],$dates['to']]);return $q->fetchAll();}
 public function recordExpense(array $d,array $actor,string $key):int {
  if((int)($actor['shop_id']??0)!==$this->shop || !can($actor,'expenses.manage'))throw new DomainException('Expense management permission required.');
  if(!preg_match('/^[a-f0-9]{40}$/D',$key))throw new DomainException('Open a fresh expense form.');$description=SalesSettingsService::text($d,'description',190,true);$payee=SalesSettingsService::text($d,'payee',150);$date=SalesSettingsService::text($d,'incurred_on',10,true);self::dates(['from'=>$date,'to'=>$date]);if($date>(new \DateTimeImmutable('today',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d'))throw new DomainException('Expense date cannot be in the future.');
  $original=ProductService::optionalId($d['reverses_expense_id']??'');$sale=ProductService::optionalId($d['sale_id']??'');$amount=$original?null:SaleTaxService::amount($d['amount']??'','Labour expense');if(!$original && bccomp($amount,'0',2)<=0)throw new DomainException('Labour expense must be greater than zero.');
  $hash=hash('sha256',json_encode([$description,$payee,$date,$original,$sale,$amount,$actor['id']]));$pdo=db();$pdo->beginTransaction();try{
   // Serialize retry keys and expense reversals without changing any posted invoice.
   $q=$pdo->prepare('SELECT id FROM shops WHERE id=? FOR UPDATE');$q->execute([$this->shop]);
   $q=$pdo->prepare('SELECT id,payload_hash FROM service_expenses WHERE shop_id=? AND request_key=? FOR UPDATE');$q->execute([$this->shop,$key]);if($old=$q->fetch()){if(!hash_equals($old['payload_hash'],$hash))throw new DomainException('This expense request was already used.');$pdo->commit();return (int)$old['id'];}
   if($original){$q=$pdo->prepare('SELECT * FROM service_expenses WHERE shop_id=? AND id=? FOR UPDATE');$q->execute([$this->shop,$original]);$old=$q->fetch();if(!$old || $old['reverses_expense_id'])throw new DomainException('Choose an original labour expense.');$q=$pdo->prepare('SELECT id FROM service_expenses WHERE shop_id=? AND reverses_expense_id=?');$q->execute([$this->shop,$original]);if($q->fetchColumn())throw new DomainException('This expense is already reversed.');$amount=bcsub('0',$old['amount'],2);$sale=$old['sale_id'];$payee=$old['payee'];}
   if($sale){$q=$pdo->prepare('SELECT id FROM sales WHERE shop_id=? AND id=?');$q->execute([$this->shop,$sale]);if(!$q->fetchColumn())throw new DomainException('Invoice not found in your shop.');}
   $pdo->prepare('INSERT INTO service_expenses(shop_id,sale_id,description,payee,amount,incurred_on,created_by,request_key,payload_hash,reverses_expense_id) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$this->shop,$sale,$description,$payee?:null,$amount,$date,$actor['id'],$key,$hash,$original]);$id=(int)$pdo->lastInsertId();$pdo->commit();return $id;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
}
