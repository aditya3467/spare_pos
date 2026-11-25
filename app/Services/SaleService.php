<?php
namespace App\Services;
use DomainException;
use Throwable;
final class SaleService {
 public function __construct(private int $shop){}
 public function authorize(array $actor,string $permission):void {if((int)($actor['shop_id']??0)!==$this->shop || !can($actor,$permission))throw new DomainException('Permission required: '.(permissions()[$permission]??$permission).'.');}
 public function customers(string $query):array {$q=db()->prepare('SELECT * FROM customers WHERE shop_id=? AND (name LIKE ? OR phone LIKE ?) ORDER BY name,id LIMIT 20');$q->execute([$this->shop,'%'.mb_substr(trim($query),0,100).'%','%'.mb_substr(trim($query),0,100).'%']);return $q->fetchAll();}
 public function addCustomer(array $d,array $actor):int {
  $this->authorize($actor,'sales.create');$name=SalesSettingsService::text($d,'name',150,true);$phone=SalesSettingsService::text($d,'phone',30);$address=SalesSettingsService::text($d,'address',500);$state=SalesSettingsService::text($d,'state_code',2);if($state!=='')SalesSettingsService::state($state);$gst=SalesSettingsService::gstin(SalesSettingsService::text($d,'gstin',15),$state);
  db()->prepare('INSERT INTO customers(shop_id,name,phone,address,state_code,gstin) VALUES(?,?,?,?,?,?)')->execute([$this->shop,$name,$phone?:null,$address?:null,$state?:null,$gst]);return (int)db()->lastInsertId();
 }
 public function search(string $query):array {
  $listing=(new InventorySearchService($this->shop))->listing(['q'=>$query,'status'=>'active'],20);$rows=$listing['rows'];if(!$rows)return [];$ids=array_column($rows,'id');$q=db()->prepare('SELECT p.id,p.selling_price,p.hsn_code,u.name AS unit_name,u.decimal_places AS unit_decimal_places,CASE WHEN st.registration_type=\'regular\' THEN COALESCE(t.rate,dt.rate) ELSE 0 END tax_rate FROM products p LEFT JOIN units u ON u.shop_id=p.shop_id AND u.id=p.base_unit_id LEFT JOIN tax_rates t ON t.shop_id=p.shop_id AND t.id=p.tax_rate_id LEFT JOIN shop_tax_settings st ON st.shop_id=p.shop_id LEFT JOIN tax_rates dt ON dt.shop_id=st.shop_id AND dt.id=st.default_tax_rate_id WHERE p.shop_id=? AND p.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute([$this->shop,...$ids]);$map=array_column($q->fetchAll(),null,'id');foreach($rows as &$row)$row+=$map[$row['id']];return $rows;
 }
 public function quote(array $d,array $actor,bool $lock=false):array {
  $this->authorize($actor,'sales.create');$settings=(new SalesSettingsService($this->shop))->get(true,$lock);
  $customer=null;$customerId=ProductService::optionalId($d['customer_id']??'');if($customerId){$q=db()->prepare('SELECT * FROM customers WHERE shop_id=? AND id=?'.($lock?' FOR UPDATE':''));$q->execute([$this->shop,$customerId]);$customer=$q->fetch();if(!$customer)throw new DomainException('Customer not found.');}
  $bike=null;$bikeVariant=null;$bikeModelId=ProductService::optionalId($d['bike_model_id']??'');$bikeVariantId=ProductService::optionalId($d['bike_variant_id']??'');if($bikeModelId){$q=db()->prepare('SELECT m.id,m.name AS model_name,mf.name AS manufacturer_name FROM vehicle_models m JOIN vehicle_manufacturers mf ON mf.shop_id=m.shop_id AND mf.id=m.manufacturer_id WHERE m.shop_id=? AND m.id=?');$q->execute([$this->shop,$bikeModelId]);$bike=$q->fetch();if(!$bike)throw new DomainException('Choose a valid bike model.');if($bikeVariantId){$q=db()->prepare('SELECT id,name FROM vehicle_variants WHERE shop_id=? AND model_id=? AND id=?');$q->execute([$this->shop,$bikeModelId,$bikeVariantId]);$bikeVariant=$q->fetch();if(!$bikeVariant)throw new DomainException('Choose a variant belonging to the selected bike model.');}}elseif($bikeVariantId)throw new DomainException('Select a bike model before choosing a variant.');
  if($customer && $customer['gstin'] && (!$customer['address'] || !$customer['state_code']))throw new DomainException('GST customer requires a billing address and state.');
  // Counter billing derives the state from recorded customer details, otherwise the shop.
  // Older integrations may still send an explicit state; the main POS no longer does.
  $place=isset($d['place_of_supply'])?SalesSettingsService::state(SalesSettingsService::text($d,'place_of_supply',2,true)):SalesSettingsService::state((string)(($customer['state_code']??'')?:$settings['state_code']));
  $productPlace=isset($d['place_of_supply'])?$place:(!empty($customer['gstin'])?$settings['state_code']:$place);
  $custom=$d['custom']??[];$cart=$d['items']??[];$labour=$d['labour']??[];
  if(!is_array($custom) || !is_array($cart) || !is_array($labour) || count($custom)+count($cart)+count($labour)<1 || count($custom)+count($cart)+count($labour)>100)throw new DomainException('Add between 1 and 100 bill lines.');$items=[];
  foreach($cart as $item){if(!is_array($item))throw new DomainException('Invalid cart.');$id=ProductService::optionalId($item['product_id']??'');if(!$id || isset($items[$id]))throw new DomainException('Each product must appear once in the cart.');$items[$id]=$item;}ksort($items,SORT_NUMERIC);$lines=[];
  foreach($items as $id=>$item){
   // Stable product order matches every inventory writer and prevents cross-cart deadlocks.
   $q=db()->prepare('SELECT p.*,b.quantity,b.average_unit_cost,t.rate FROM products p LEFT JOIN stock_balances b ON b.shop_id=p.shop_id AND b.product_id=p.id LEFT JOIN tax_rates t ON t.shop_id=p.shop_id AND t.id=p.tax_rate_id WHERE p.shop_id=? AND p.id=?'.($lock?' FOR UPDATE':''));$q->execute([$this->shop,$id]);$p=$q->fetch();if(!$p || $p['status']!=='active' || $p['archived_at'])throw new DomainException('A cart product is inactive, archived or unavailable.');
   $qty=ProductService::decimal($item['quantity']??'',6,false,'Quantity');if(bccomp($qty,'0',6)<=0)throw new DomainException('Quantity must be greater than zero.');if($p['quantity']===null || bccomp($qty,$p['quantity'],6)>0)throw new DomainException('Insufficient stock for '.$p['name'].'. Available: '.quantity($p['quantity']??'0').'.');
   $discount=SaleTaxService::amount($item['discount']??'0','Line discount');if(bccomp($discount,'0',2)>0)$this->authorize($actor,'sales.discount');
   if(isset($item['price']) && bccomp(SaleTaxService::amount($item['price'],'Price'),$p['selling_price'],2)!==0)throw new DomainException('Prices must use the current product selling price.');
   if($settings['registration_type']==='regular' && $settings['require_hsn'] && !$p['hsn_code'])throw new DomainException('HSN is required for '.$p['name'].'.');
   $lines[]=['line_type'=>'product','place_of_supply'=>$productPlace,'product_id'=>(int)$id,'name'=>$p['name'],'sku'=>$p['sku'],'hsn_code'=>$p['hsn_code'],'quantity'=>$qty,'available'=>$p['quantity'],'price'=>$p['selling_price'],'discount'=>$discount,'tax_rate'=>$p['rate']??$settings['default_rate']];
  }
  foreach ($custom as $entry) {
   if (!is_array($entry)) throw new DomainException('Invalid custom item.');
   $name=SalesSettingsService::text($entry,'name',190,true);
   $unit=SalesSettingsService::text($entry,'unit',30);
   $hsn=SalesSettingsService::text($entry,'hsn_code',20);
   if ($settings['registration_type']==='regular' && $settings['require_hsn'] && $hsn==='') throw new DomainException('HSN is required for '.$name.'.');
   $qty=ProductService::decimal($entry['quantity']??'',6,false,'Quantity');
   if (bccomp($qty,'0',6)<=0) throw new DomainException('Quantity must be greater than zero.');
   $cp=SaleTaxService::amount($entry['unit_cost']??'','Unit cost');
   $sp=SaleTaxService::amount($entry['price']??'','Selling price');
   $cost=SaleTaxService::round(bcmul($qty,$cp,8));
   if (bccomp($cost,'9999999999999.99',2)>0) throw new DomainException('Custom item cost exceeds the supported amount.');
   $rate=$settings['default_rate'];
   $rateId=ProductService::optionalId($entry['tax_rate_id']??'');
   if ($rateId) {
    $q=db()->prepare("SELECT rate FROM tax_rates WHERE shop_id=? AND id=? AND status='active'");
    $q->execute([$this->shop,$rateId]);$rate=$q->fetchColumn();
    if ($rate===false) throw new DomainException('Choose an active tax rate.');
   }
   $lines[]=['line_type'=>'custom','name'=>$name,'unit'=>$unit,'sku'=>null,'hsn_code'=>$hsn,
    'place_of_supply'=>$productPlace,'quantity'=>$qty,'unit_cost'=>$cp,'price'=>$sp,
    'cost'=>$cost,'tax_rate'=>$rate,'discount'=>'0.00'];
  }
  foreach(array_values($labour) as $index=>$entry){
   if(!is_array($entry))throw new DomainException('Invalid labour line.');
   $name=SalesSettingsService::text($entry,'description',190,true);$amount=SaleTaxService::amount($entry['amount']??'','Labour charge');if(bccomp($amount,'0',2)<=0)throw new DomainException('Labour charge must be greater than zero.');
   $discount=SaleTaxService::amount($entry['discount']??'0','Labour discount');if(bccomp($discount,'0',2)>0)$this->authorize($actor,'sales.discount');
   if($settings['registration_type']==='regular' && $settings['require_hsn'] && !$settings['labour_sac_code'])throw new DomainException('Configure a labour SAC code in Tax & Invoice Settings.');
   $lines[]=['line_type'=>'service','place_of_supply'=>$place,'service_index'=>$index,'name'=>$name,'sku'=>null,'hsn_code'=>$settings['labour_sac_code'],'quantity'=>'1.000000','price'=>$amount,'discount'=>$discount,'tax_rate'=>$settings['labour_rate']];
  }
  if($cart || $custom)$place=$productPlace;
  $baseQuote=SaleTaxService::calculate($lines,'0.00',$settings,$place);$bill=SaleTaxService::amount($d['bill_discount']??'0','Bill discount');
  if(array_key_exists('target_payable',$d) && $d['target_payable']!==''){$target=SaleTaxService::amount($d['target_payable'],'Amount collected');$bill=bccomp($target,$baseQuote['total'],2)>=0?'0.00':SaleTaxService::discountForPayable($lines,$target,$settings,$place);}
  if(bccomp($bill,'0',2)>0)$this->authorize($actor,'sales.discount');$quote=SaleTaxService::calculate($lines,$bill,$settings,$place);$quote['total_before_bill_discount']=$baseQuote['total'];
  if($settings['registration_type']==='regular' && bccomp($quote['total'],'50000',2)>=0 && (!$customer || !$customer['address'] || !$customer['state_code']))throw new DomainException('Select a customer with billing address and state for this invoice value.');
  $quote+=['customer'=>$customer,'bike'=>$bike?['manufacturer'=>$bike['manufacturer_name'],'model'=>$bike['model_name'],'variant'=>$bikeVariant['name']??null]:null,'settings'=>$settings,'place_of_supply'=>$place,'bill_discount'=>$bill,'document_type'=>match($settings['registration_type']){'regular'=>'Tax Invoice','composition'=>'Bill of Supply',default=>'Retail Receipt'}];
  $quote['quote_hash']=hash('sha256',json_encode($quote));return $quote;
 }
 private function payments(array $d,string $total,array $actor,?array $customer):array {
  $payments=$d['payments']??[];if(!is_array($payments) || count($payments)>10)throw new DomainException('Enter up to 10 payments.');$rows=[];$received='0.00';$cash='0.00';$noncash='0.00';
  foreach($payments as $p){if(!is_array($p))throw new DomainException('Invalid payment.');$method=$p['method']??'';if(!in_array($method,['cash','upi','card','bank'],true))throw new DomainException('Choose a payment method.');$amount=SaleTaxService::amount($p['amount']??'','Payment amount');if(bccomp($amount,'0',2)<=0)throw new DomainException('Payment amounts must be greater than zero.');$ref=SalesSettingsService::text($p,'reference',100,$method!=='cash');$rows[]=['method'=>$method,'received'=>$amount,'reference'=>$ref,'amount'=>$amount,'change_returned'=>'0.00'];$received=bcadd($received,$amount,2);if($method==='cash')$cash=bcadd($cash,$amount,2);else $noncash=bcadd($noncash,$amount,2);}
  if(bccomp($noncash,$total,2)>0)throw new DomainException('Non-cash payments cannot exceed the invoice total.');$change=bccomp($received,$total,2)>0?bcsub($received,$total,2):'0.00';$remaining=$change;
  for($i=count($rows)-1;$i>=0;$i--)if($rows[$i]['method']==='cash'){$v=bccomp($remaining,$rows[$i]['received'],2)>0?$rows[$i]['received']:$remaining;$rows[$i]['amount']=bcsub($rows[$i]['received'],$v,2);$rows[$i]['change_returned']=$v;$remaining=bcsub($remaining,$v,2);}
  $paid=bcsub($received,$change,2);$due=bcsub($total,$paid,2);if(bccomp($due,'0',2)>0){$this->authorize($actor,'sales.credit');if(!$customer)throw new DomainException('Select a customer for a partial payment or credit sale.');}return ['rows'=>$rows,'received'=>$received,'paid'=>$paid,'change'=>$change,'due'=>$due];
 }
 public function complete(array $d,array $actor,string $key):int {
  $this->authorize($actor,'sales.create');if(!preg_match('/^[a-f0-9]{40}$/D',$key))throw new DomainException('Open a fresh checkout.');if(($d['confirmed']??'')!=='1')throw new DomainException('Confirm the sale before submitting.');
  $hash=hash('sha256',json_encode([$d['items']??null,$d['bill_discount']??'0',$d['customer_id']??'',$d['bike_model_id']??'',$d['bike_variant_id']??'',$d['place_of_supply']??'',$d['payments']??[],$actor['id'],...(!empty($d['custom'])?['custom'=>$d['custom']]:[]),...(!empty($d['labour'])?[$d['labour']]:[])]));$pdo=db();$pdo->beginTransaction();try{
   // Settings lock serializes sequence allocation and repeated requests within a shop.
   (new SalesSettingsService($this->shop))->get(true,true);
   $q=$pdo->prepare('SELECT id,payload_hash FROM sales WHERE shop_id=? AND request_key=? FOR UPDATE');$q->execute([$this->shop,$key]);if($old=$q->fetch()){if($old['payload_hash']!==$hash)throw new DomainException('This checkout was already used with different values.');$pdo->commit();return (int)$old['id'];}
   $quote=$this->quote($d,$actor,true);if(!is_string($d['quote_hash']??null) || !hash_equals($quote['quote_hash'],$d['quote_hash']))throw new DomainException('Stock, prices or settings changed. Review the refreshed totals and confirm again.');$pay=$this->payments($d,$quote['total'],$actor,$quote['customer']);
   $now=new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata'));$year=(int)$now->format('Y')-((int)$now->format('n')<4?1:0);$fy=substr((string)$year,2).substr((string)($year+1),2);$prefix=$quote['settings']['invoice_prefix'];$pdo->prepare('INSERT IGNORE INTO invoice_sequences(shop_id,financial_year,prefix,last_number) VALUES(?,?,?,0)')->execute([$this->shop,$fy,$prefix]);$q=$pdo->prepare('SELECT last_number FROM invoice_sequences WHERE shop_id=? AND financial_year=? AND prefix=? FOR UPDATE');$q->execute([$this->shop,$fy,$prefix]);$number=(int)$q->fetchColumn()+1;if($number>999999)throw new DomainException('Invoice series exhausted. Configure another prefix.');$invoice=$prefix.'/'.$fy.'/'.str_pad((string)$number,6,'0',STR_PAD_LEFT);$pdo->prepare('UPDATE invoice_sequences SET last_number=? WHERE shop_id=? AND financial_year=? AND prefix=?')->execute([$number,$this->shop,$fy,$prefix]);
   $q=$pdo->prepare('INSERT INTO sales(shop_id,invoice_number,request_key,payload_hash,customer_id,customer_snapshot,shop_snapshot,vehicle_model_snapshot,place_of_supply,document_type,subtotal,discount,taxable,cgst,sgst,igst,total,amount_received,amount_paid,change_due,balance_due,created_by) VALUES('.implode(',',array_fill(0,22,'?')).')');$bikeSnapshot=$quote['bike']?implode(' · ',array_filter([$quote['bike']['manufacturer'],$quote['bike']['model'],$quote['bike']['variant']])):null;$q->execute([$this->shop,$invoice,$key,$hash,$quote['customer']['id']??null,json_encode($quote['customer']??['name'=>'Walk-in']),json_encode($quote['settings']),$bikeSnapshot,$quote['place_of_supply'],$quote['document_type'],$quote['subtotal'],$quote['discount'],$quote['taxable'],$quote['cgst'],$quote['sgst'],$quote['igst'],$quote['total'],$pay['received'],$pay['paid'],$pay['change'],$pay['due'],$actor['id']]);$id=(int)$pdo->lastInsertId();
   $stock=new StockService($this->shop);foreach($quote['lines'] as $line){
    if ($line['line_type']==='custom') {
     $pdo->prepare('INSERT INTO sale_custom_items(shop_id,sale_id,revenue,cost,snapshot) VALUES(?,?,?,?,?)')->execute([$this->shop,$id,$line['taxable'],$line['cost'],json_encode($line,JSON_THROW_ON_ERROR)]);
     continue;
    }
    if($line['line_type']==='service'){$pdo->prepare('INSERT INTO sale_services(shop_id,sale_id,line_number,description,revenue,snapshot) VALUES(?,?,?,?,?,?)')->execute([$this->shop,$id,$line['service_index'],$line['name'],$line['taxable'],json_encode($line)]);continue;}$m=$stock->saleMovement($line['product_id'],$line['quantity'],$id,(int)$actor['id']);$pdo->prepare('INSERT INTO sale_items(shop_id,sale_id,product_id,quantity,unit_cost,movement_id,snapshot) VALUES(?,?,?,?,?,?,?)')->execute([$this->shop,$id,$line['product_id'],$line['quantity'],$m['unit_cost'],$m['movement_id'],json_encode($line)]);}
   foreach($pay['rows'] as $p)$pdo->prepare('INSERT INTO sale_payments(shop_id,sale_id,method,amount,received,change_returned,reference,payment_date,recorded_by) VALUES(?,?,?,?,?,?,?,NOW(),?)')->execute([$this->shop,$id,$p['method'],$p['amount'],$p['received'],$p['change_returned'],$p['reference']?:null,$actor['id']]);
   $pdo->commit();return $id;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
 public function get(int $id):array {
  $q=db()->prepare('SELECT s.*,c.id AS cancellation_id,c.reason AS cancellation_reason,c.created_at AS cancelled_at,u.name AS cashier FROM sales s JOIN users u ON u.id=s.created_by LEFT JOIN sale_cancellations c ON c.shop_id=s.shop_id AND c.sale_id=s.id WHERE s.shop_id=? AND s.id=?');$q->execute([$this->shop,$id]);$s=$q->fetch();if(!$s)throw new DomainException('Invoice not found.');$s['shop']=json_decode($s['shop_snapshot'],true);$s['customer']=json_decode($s['customer_snapshot'],true);
  $q=db()->prepare('SELECT i.*,r.id AS reversal_id FROM sale_items i LEFT JOIN stock_movements r ON r.shop_id=i.shop_id AND r.reverses_movement_id=i.movement_id WHERE i.shop_id=? AND i.sale_id=? ORDER BY i.id');$q->execute([$this->shop,$id]);$s['items']=$q->fetchAll();foreach($s['items'] as &$item)$item['line']=json_decode($item['snapshot'],true);unset($item);
  $q=db()->prepare('SELECT snapshot FROM sale_custom_items WHERE shop_id=? AND sale_id=? ORDER BY id');
  $q->execute([$this->shop,$id]);
  $s['custom']=array_map(static fn($snapshot)=>['line'=>json_decode($snapshot,true)],$q->fetchAll(\PDO::FETCH_COLUMN));
  $q=db()->prepare('SELECT * FROM sale_services WHERE shop_id=? AND sale_id=? ORDER BY line_number,id');$q->execute([$this->shop,$id]);$s['services']=$q->fetchAll();foreach($s['services'] as &$service)$service['line']=json_decode($service['snapshot'],true);unset($service);
  $q=db()->prepare('SELECT p.*,u.name AS recorder FROM sale_payments p JOIN users u ON u.id=p.recorded_by WHERE p.shop_id=? AND p.sale_id=? ORDER BY p.id');$q->execute([$this->shop,$id]);$s['payments']=$q->fetchAll();return $s;
 }
 public function cancel(int $id,array $d,array $actor):int {
  $this->authorize($actor,'sales.cancel');$reason=SalesSettingsService::text($d,'reason',500,true);if(($d['confirmed']??'')!=='1' || ($d['refund_confirmed']??'')!=='1')throw new DomainException('Confirm stock restoration and completed payment refunds before cancellation.');
  $pdo=db();$pdo->beginTransaction();try{
   $q=$pdo->prepare('SELECT id FROM sales WHERE shop_id=? AND id=? FOR UPDATE');$q->execute([$this->shop,$id]);if(!$q->fetchColumn())throw new DomainException('Invoice not found.');$q=$pdo->prepare('SELECT id FROM sale_cancellations WHERE shop_id=? AND sale_id=?');$q->execute([$this->shop,$id]);if($old=$q->fetchColumn()){$pdo->commit();return (int)$old;}
   $s=$this->get($id);$items=$s['items'];usort($items,fn($a,$b)=>(int)$a['product_id']<=>(int)$b['product_id']);$stock=new StockService($this->shop);foreach($items as $item)$stock->saleMovement((int)$item['product_id'],$item['quantity'],$id,(int)$actor['id'],(int)$item['movement_id']);
   $pdo->prepare('INSERT INTO sale_cancellations(shop_id,sale_id,reason,created_by) VALUES(?,?,?,?)')->execute([$this->shop,$id,$reason,$actor['id']]);$cid=(int)$pdo->lastInsertId();
   foreach($s['payments'] as $p)if(!$p['reverses_payment_id'])$pdo->prepare('INSERT INTO sale_payments(shop_id,sale_id,method,amount,received,change_returned,reference,payment_date,recorded_by,reverses_payment_id,cancellation_id) VALUES(?,?,?,?,?,0,?,NOW(),?,?,?)')->execute([$this->shop,$id,$p['method'],bcsub('0',$p['amount'],2),bcsub('0',$p['amount'],2),'Cancellation #'.$cid,$actor['id'],$p['id'],$cid]);
   $pdo->commit();return $cid;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
 public function history(array $f):array {
  $where=['s.shop_id=?'];$args=[$this->shop];$query=is_string($f['q']??null)?mb_substr(trim($f['q']),0,150):'';if($query!==''){$where[]="(s.invoice_number LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.customer_snapshot,'$.name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.customer_snapshot,'$.phone')) LIKE ?)";array_push($args,...array_fill(0,3,'%'.$query.'%'));}
  foreach(['from'=>'>=','to'=>'<='] as $key=>$op)if(($f[$key]??'')!==''){$v=$f[$key];$dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$dt || $dt->format('Y-m-d')!==$v)throw new DomainException('Choose a valid date.');$where[]="s.created_at $op ?";$args[]=$v.($key==='from'?' 00:00:00':' 23:59:59');}
  $payment=$f['payment']??'';if($payment==='paid')$where[]='c.id IS NULL AND s.balance_due=0';elseif($payment==='partial')$where[]='c.id IS NULL AND s.amount_paid>0 AND s.balance_due>0';elseif($payment==='unpaid')$where[]='c.id IS NULL AND s.amount_paid=0 AND s.balance_due>0';elseif($payment==='reversed')$where[]='c.id IS NOT NULL';$status=$f['status']??'';if($status==='cancelled')$where[]='c.id IS NOT NULL';elseif($status==='posted')$where[]='c.id IS NULL';
  $sql=' FROM sales s LEFT JOIN sale_cancellations c ON c.shop_id=s.shop_id AND c.sale_id=s.id WHERE '.implode(' AND ',$where);$q=db()->prepare('SELECT COUNT(*)'.$sql);$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/25));$page=max(1,min((int)($f['page']??1),$pages));$sort=['date'=>'s.created_at','number'=>'s.invoice_number','total'=>'s.total','due'=>'s.balance_due'][$f['sort']??'date']??'s.created_at';$dir=($f['direction']??'desc')==='asc'?'ASC':'DESC';$q=db()->prepare('SELECT s.*,c.id AS cancellation_id,(SELECT COUNT(*) FROM sale_items i WHERE i.shop_id=s.shop_id AND i.sale_id=s.id )+(SELECT COUNT(*) FROM sale_services ls WHERE ls.shop_id=s.shop_id AND ls.sale_id=s.id)+(SELECT COUNT(*) FROM sale_custom_items cu WHERE cu.shop_id=s.shop_id AND cu.sale_id=s.id) item_count'.$sql.' ORDER BY '.$sort.' '.$dir.',s.id '.$dir.' LIMIT 25 OFFSET '.(($page-1)*25));$q->execute($args);return ['rows'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages];
 }
}
