<?php
namespace App\Services;
use DomainException;
use PDO;
use PDOException;
use Throwable;
final class ProductService {
 public function __construct(private int $shop){}
 public static function optionalId(mixed $value):?int {
  if($value==='' || $value===null)return null;
  if(!is_scalar($value) || !filter_var($value,FILTER_VALIDATE_INT) || (int)$value<1)throw new DomainException('Invalid selected record.');return (int)$value;
 }
 public static function decimal(mixed $value,int $scale=2,bool $optional=false,string $label="Value"):?string {
  if($optional && ($value==='' || $value===null))return null;
  if(!is_scalar($value) || !preg_match('/^\d{1,12}(?:\.\d{1,'.$scale.'})?$/D',(string)$value))throw new DomainException($label.': enter zero or a positive number (up to '.$scale.' decimal places).');
  return bcadd((string)$value,'0',$scale);
 }
 private function text(array $data,string $key,int $max,bool $required=false):string {
  $v=$data[$key]??'';if(!is_string($v) || mb_strlen(trim($v))>$max || ($required && trim($v)===''))throw new DomainException('Invalid '.str_replace('_',' ',$key).' (maximum '.$max.' characters).');return trim($v);
 }
 private function record(string $table,?int $id):?array {
  if(!$id)return null;$q=db()->prepare("SELECT * FROM $table WHERE shop_id=? AND id=?");$q->execute([$this->shop,$id]);$row=$q->fetch();if(!$row)throw new DomainException('Selected '.str_replace('_',' ',$table).' does not belong to this shop.');return $row;
 }
 public function get(int $id):array {
  $q=db()->prepare('SELECT p.*,c.name AS category_name,s.name AS subcategory_name,b.name AS brand_name,t.rate AS tax_rate,sb.quantity AS quantity,sb.average_unit_cost FROM products p JOIN categories c ON c.shop_id=p.shop_id AND c.id=p.category_id LEFT JOIN subcategories s ON s.shop_id=p.shop_id AND s.id=p.subcategory_id LEFT JOIN brands b ON b.shop_id=p.shop_id AND b.id=p.brand_id LEFT JOIN tax_rates t ON t.shop_id=p.shop_id AND t.id=p.tax_rate_id LEFT JOIN stock_balances sb ON sb.shop_id=p.shop_id AND sb.product_id=p.id WHERE p.shop_id=? AND p.id=?');$q->execute([$this->shop,$id]);$p=$q->fetch();if(!$p)throw new DomainException('Product not found in your shop.');
  $q=db()->prepare('SELECT alias FROM product_aliases WHERE shop_id=? AND product_id=? ORDER BY id');$q->execute([$this->shop,$id]);$p['aliases']=implode(', ',$q->fetchAll(PDO::FETCH_COLUMN));
  $q=db()->prepare('SELECT label,value FROM product_specifications WHERE shop_id=? AND product_id=? ORDER BY id');$q->execute([$this->shop,$id]);$p['specifications']=$q->fetchAll();
  $q=db()->prepare('SELECT f.*,pf.verified_by,pf.verified_at,m.manufacturer_id,m.name AS model_name,mf.name AS manufacturer_name,v.name AS variant_name FROM product_fitments pf JOIN vehicle_fitments f ON f.shop_id=pf.shop_id AND f.id=pf.fitment_id JOIN vehicle_models m ON m.shop_id=f.shop_id AND m.id=f.model_id JOIN vehicle_manufacturers mf ON mf.shop_id=m.shop_id AND mf.id=m.manufacturer_id LEFT JOIN vehicle_variants v ON v.shop_id=f.shop_id AND v.model_id=f.model_id AND v.id=f.variant_id WHERE pf.shop_id=? AND pf.product_id=? ORDER BY mf.name,m.name,f.id');$q->execute([$this->shop,$id]);$p['fitments']=$q->fetchAll();
  $q=db()->prepare('SELECT movement_type,quantity_delta,unit_cost,created_at,reference_type,reference_id FROM stock_movements WHERE shop_id=? AND product_id=? ORDER BY created_at DESC,id DESC LIMIT 50');$q->execute([$this->shop,$id]);$p['movements']=$q->fetchAll();return $p;
 }
 public function duplicate(int $id):array {
  $p=$this->get($id);foreach(['id','sku','quantity','average_unit_cost','creation_key','lock_version','archived_at','movements'] as $key)unset($p[$key]);
  foreach($p['fitments'] as &$f){$f['verified_by']=null;$f['verified_at']=null;unset($f['verified']);}
  return $p+['opening_quantity'=>'0','opening_cost'=>'','opening_date'=>date('Y-m-d'),'status'=>'active'];
 }
 public function validate(array $input,array $actor,?array $existing=null,bool $import=false):array {
  if((int)$actor['shop_id']!==$this->shop || !can($actor,'products.manage'))throw new DomainException('Product permission required.');
  $d=[];foreach(['name'=>190,'description'=>5000,'part_number'=>120,'rack_location'=>80,'hsn_code'=>20] as $k=>$max)$d[$k]=$this->text($input,$k,$max,$k==='name');
  foreach(['category_id'=>'categories','subcategory_id'=>'subcategories','brand_id'=>'brands','tax_rate_id'=>'tax_rates'] as $field=>$table){
   $d[$field]=self::optionalId($input[$field]??'');$row=$this->record($table,$d[$field]);
   if($field==='category_id' && !$row)throw new DomainException('Category is required.');
   if($row && (($row['status']??'active')!=='active' || !empty($row['archived_at'])) && (!$existing || (int)($existing[$field]??0)!==$d[$field]))throw new DomainException('Choose an active '.str_replace('_id','',$field).'.');
   if($field==='subcategory_id' && $row && (int)$row['category_id']!==$d['category_id'])throw new DomainException('Subcategory does not belong to the selected category.');
  }
  foreach(['purchase_price','selling_price'] as $field)$d[$field]=self::decimal($input[$field]??'',2,false,ucfirst(str_replace('_',' ',$field)));
  $d['mrp']=self::decimal($input['mrp']??'',2,true,'MRP');if($d['mrp']!==null&&bccomp($d['mrp'],'0',2)<0)throw new DomainException('MRP cannot be negative.');
  foreach(['purchase_discount'=>'purchase_price','selling_discount'=>'selling_price'] as $discountField=>$priceField){$raw=$input[$discountField]??'';if($raw===''||$raw===null)$d[$discountField]=null;else{$d[$discountField]=self::decimal($raw,2,false,ucfirst(str_replace('_',' ',$discountField)));if(bccomp($d[$discountField],'100',2)>0)throw new DomainException('Discount percentages must be between 0 and 100.');if($d['mrp']===null)throw new DomainException('Enter MRP when a '.str_replace('_',' ',$discountField).' is provided.');$d[$priceField]=number_format((float)$d['mrp']*(1-(float)$d[$discountField]/100),2,'.','');}}
  $d['reorder_level']=self::decimal($input['reorder_level']??'0',6,false,'Minimum stock level');
  $d['status']=$input['status']??'active';if(!in_array($d['status'],['active','inactive'],true))throw new DomainException('Invalid product status.');
  $aliases=$this->text($input,'aliases',3000);$d['aliases']=[];$seenAliases=[];foreach(array_filter(array_map('trim',explode(',',$aliases))) as $alias){$fold=mb_strtolower($alias);if(!isset($seenAliases[$fold]))$d['aliases'][]=$alias;$seenAliases[$fold]=true;}
  if(count($d['aliases'])>30)throw new DomainException('Use at most 30 aliases.');foreach($d['aliases'] as $alias)if(mb_strlen($alias)>190)throw new DomainException('Each alias must be under 190 characters.');
  $d['specifications']=[];$specs=$input['specifications']??[];if(!is_array($specs) || count($specs)>30)throw new DomainException('Use at most 30 specifications.');$labels=[];
  foreach($specs as $row){if(!is_array($row))throw new DomainException('Invalid specification.');$label=$this->text($row,'label',80);$value=$this->text($row,'value',250);if(!$label && !$value)continue;if(!$label || !$value || isset($labels[mb_strtolower($label)]))throw new DomainException('Specifications need a unique label and a value.');$labels[mb_strtolower($label)]=true;$d['specifications'][]=compact('label','value');}
  $d['fitments']=[];$fitments=$input['fitments']??[];if(!is_array($fitments) || count($fitments)>50)throw new DomainException('Use at most 50 bike mappings.');$hashes=[];
  foreach($fitments as $f){
   if(!is_array($f))throw new DomainException('Invalid bike mapping.');
   $maker=self::optionalId($f['manufacturer_id']??'');$modelId=self::optionalId($f['model_id']??'');$variantId=self::optionalId($f['variant_id']??'');
   if(!$maker || !$modelId)throw new DomainException('Each bike mapping requires a manufacturer and model.');
   $model=$this->record('vehicle_models',$modelId);if((int)$model['manufacturer_id']!==$maker)throw new DomainException('Bike model does not belong to manufacturer.');
   $variant=$this->record('vehicle_variants',$variantId);if($variant && (int)$variant['model_id']!==$modelId)throw new DomainException('Bike variant does not belong to model.');
   $years=[];foreach(['year_from','year_to'] as $key){$v=$f[$key]??'';if($v!=='' && $v!==null && (!is_scalar($v) || !preg_match('/^\d{4}$/D',(string)$v) || (int)$v<1900 || (int)$v>(int)date('Y')+2))throw new DomainException('Invalid bike year range.');$years[$key]=$v==='' || $v===null?null:(int)$v;}
   if($years['year_from'] && $years['year_to'] && $years['year_from']>$years['year_to'])throw new DomainException('Bike year range is reversed.');
   $notes=$this->text($f,'notes',500);$hash=hash('sha256',json_encode([$modelId,$variantId,$years['year_from'],$years['year_to'],$notes]));if(isset($hashes[$hash]))throw new DomainException('The same bike mapping was entered twice.');$hashes[$hash]=true;
   $verified=($f['verified']??'')==='1';if($verified && !can($actor,'products.verify_fitment'))throw new DomainException('Fitment verification permission required.');
   $d['fitments'][]=['model_id'=>$modelId,'variant_id'=>$variantId]+$years+['notes'=>$notes,'identity_hash'=>$hash,'verified'=>$verified];
  }
  if($existing){foreach(['opening_quantity','opening_cost','opening_date'] as $key)if(array_key_exists($key,$input))throw new DomainException('Use inventory operations to change existing stock.');}
  else {
   $d['opening_quantity']=self::decimal($input['opening_quantity']??'0',6,false,'Opening stock quantity');$positive=bccomp($d['opening_quantity'],'0',6)>0;
   if($positive && ($input['opening_cost']??'')==='')throw new DomainException('Opening purchase cost per item is required when opening stock quantity is greater than zero. Enter the cost for one item, or 0 if it was free.');
   $d['opening_cost']=self::decimal($input['opening_cost']??'',6,!$positive,'Opening purchase cost per item')??'0.000000';
   $date=$input['opening_date']??date('Y-m-d');$parsed=is_string($date)?\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Asia/Kolkata')):false;
   if(!$parsed || $parsed->format('Y-m-d')!==$date || $date<'1900-01-01' || $date>(new \DateTimeImmutable('today',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d'))throw new DomainException('Opening date must be a valid date no later than today.');$d['opening_date']=$date;
   $d['sku']=$import?$this->text($input,'sku',100):'';if($d['sku'] && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D',$d['sku']))throw new DomainException('SKU may contain letters, numbers, underscore and hyphen.');
   if($d['sku']){$q=db()->prepare('SELECT COUNT(*) FROM products WHERE shop_id=? AND sku=?');$q->execute([$this->shop,$d['sku']]);if($q->fetchColumn())throw new DomainException('SKU already exists.');}
  }
  return $d;
 }
 public function save(array $input,array $actor,?int $id=null,string $creationKey='',bool $import=false):int {
  $pdo=db();$pdo->beginTransaction();try{
   $lock=$pdo->prepare('SELECT id FROM shops WHERE id=? FOR UPDATE');$lock->execute([$this->shop]);if(!$lock->fetchColumn())throw new DomainException('Shop not found.');
   if((int)$actor['shop_id']!==$this->shop || !can($actor,'products.manage'))throw new DomainException('Product access denied.');
   if(!$id){if(!$creationKey || strlen($creationKey)>100)throw new DomainException('A valid creation token is required.');$q=$pdo->prepare('SELECT id FROM products WHERE shop_id=? AND creation_key=?');$q->execute([$this->shop,$creationKey]);if($saved=$q->fetchColumn()){$pdo->commit();return (int)$saved;}}
   $existing=$id?$this->get($id):null;if($existing && $existing['archived_at'])throw new DomainException('Archived products cannot be edited.');
   if($existing && (string)($input['lock_version']??'')!==(string)$existing['lock_version'])throw new DomainException('This product changed since you opened it. Reload before editing.');
   $d=$this->validate($input,$actor,$existing,$import);
   $fields=array_intersect_key($d,array_flip(['name','description','part_number','rack_location','hsn_code','category_id','subcategory_id','brand_id','tax_rate_id','purchase_price','selling_price','mrp','purchase_discount','selling_discount','reorder_level','status']));
   if($id){$q=$pdo->prepare('UPDATE products SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($fields))).',lock_version=lock_version+1 WHERE shop_id=? AND id=?');$q->execute([...array_values($fields),$this->shop,$id]);}
   else {
    $q=$pdo->prepare("SELECT id FROM units WHERE shop_id=? AND code='ITEM'");$q->execute([$this->shop]);$internal=$q->fetchColumn();if(!$internal){$pdo->prepare("INSERT INTO units (shop_id,name,code) VALUES (?,'Item','ITEM')")->execute([$this->shop]);$internal=$pdo->lastInsertId();}
    $fields+=['shop_id'=>$this->shop,'sku'=>$d['sku']?:'SP-'.strtoupper(bin2hex(random_bytes(8))),'base_unit_id'=>$internal,'creation_key'=>$creationKey];
    $q=$pdo->prepare('INSERT INTO products ('.implode(',',array_keys($fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');$q->execute(array_values($fields));$id=(int)$pdo->lastInsertId();
    StockService::opening($pdo,$this->shop,$id,$d['opening_quantity'],$d['opening_cost'],$d['opening_date'],(int)$actor['id'],$creationKey);
   }
   foreach(['product_aliases','product_specifications'] as $table)$pdo->prepare("DELETE FROM $table WHERE shop_id=? AND product_id=?")->execute([$this->shop,$id]);
   foreach($d['aliases'] as $alias)$pdo->prepare('INSERT INTO product_aliases (shop_id,product_id,alias) VALUES (?,?,?)')->execute([$this->shop,$id,$alias]);
   foreach($d['specifications'] as $spec)$pdo->prepare('INSERT INTO product_specifications (shop_id,product_id,label,value) VALUES (?,?,?,?)')->execute([$this->shop,$id,$spec['label'],$spec['value']]);
   $old=[];if($existing)foreach($existing['fitments'] as $f)$old[$f['identity_hash']]=$f;
   $pdo->prepare('DELETE FROM product_fitments WHERE shop_id=? AND product_id=?')->execute([$this->shop,$id]);
   foreach($d['fitments'] as $f){
    $q=$pdo->prepare('SELECT id FROM vehicle_fitments WHERE shop_id=? AND identity_hash=?');$q->execute([$this->shop,$f['identity_hash']]);$fit=$q->fetchColumn();if(!$fit){$pdo->prepare('INSERT INTO vehicle_fitments (shop_id,model_id,variant_id,year_from,year_to,notes,identity_hash) VALUES (?,?,?,?,?,?,?)')->execute([$this->shop,$f['model_id'],$f['variant_id'],$f['year_from'],$f['year_to'],$f['notes'],$f['identity_hash']]);$fit=$pdo->lastInsertId();}
    $previous=$old[$f['identity_hash']]??null;$by=null;$at=null;
    if(!can($actor,'products.verify_fitment') && $previous){$by=$previous['verified_by'];$at=$previous['verified_at'];}
    elseif($f['verified']){$by=$previous['verified_by']??$actor['id'];$at=$previous['verified_at']??(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');}
    $pdo->prepare('INSERT INTO product_fitments (shop_id,product_id,fitment_id,verified_by,verified_at) VALUES (?,?,?,?,?)')->execute([$this->shop,$id,$fit,$by,$at]);
   }
   $pdo->commit();return $id;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException && ($e->errorInfo[1]??0)===1062)throw new DomainException('An SKU, alias or specification is already in use. Check for duplicate values.');throw $e;}
 }
 public function archive(int $id,array $actor):void {
  if((int)$actor['shop_id']!==$this->shop || !can($actor,'products.manage'))throw new DomainException('Product access denied.');
  $q=db()->prepare("UPDATE products SET archived_at=NOW(),status='inactive',lock_version=lock_version+1 WHERE shop_id=? AND id=? AND archived_at IS NULL");$q->execute([$this->shop,$id]);if(!$q->rowCount())throw new DomainException('Product not found or already archived.');
 }
 public function listing(array $filters):array {
  $where=['p.shop_id=?'];$args=[$this->shop];$q=is_string($filters['q']??null)?trim($filters['q']):'';
  if($q!==''){$like='%'.$q.'%';$where[]='(p.name LIKE ? OR p.sku LIKE ? OR p.part_number LIKE ? OR b.name LIKE ? OR EXISTS(SELECT 1 FROM product_aliases a WHERE a.shop_id=p.shop_id AND a.product_id=p.id AND a.alias LIKE ?) OR EXISTS(SELECT 1 FROM product_fitments pf JOIN vehicle_fitments f ON f.shop_id=pf.shop_id AND f.id=pf.fitment_id JOIN vehicle_models vm ON vm.shop_id=f.shop_id AND vm.id=f.model_id WHERE pf.shop_id=p.shop_id AND pf.product_id=p.id AND vm.name LIKE ?))';array_push($args,...array_fill(0,6,$like));}
  foreach(['category_id','subcategory_id','brand_id'] as $field){if(($filters[$field]??'')!==''){$id=self::optionalId($filters[$field]);$where[]="p.$field=?";$args[]=$id;}}
  if(($filters['model_id']??'')!==''){$where[]='EXISTS(SELECT 1 FROM product_fitments pf JOIN vehicle_fitments f ON f.shop_id=pf.shop_id AND f.id=pf.fitment_id WHERE pf.shop_id=p.shop_id AND pf.product_id=p.id AND f.model_id=?)';$args[]=self::optionalId($filters['model_id']);}
  $status=$filters['status']??'active';if($status==='archived')$where[]='p.archived_at IS NOT NULL';elseif($status!=='all'){$where[]='p.archived_at IS NULL';if(in_array($status,['active','inactive'],true)){$where[]='p.status=?';$args[]=$status;}}
  $stock=$filters['stock']??'';if($stock==='low')$where[]='sb.product_id IS NOT NULL AND sb.quantity>0 AND p.reorder_level>0 AND sb.quantity<=p.reorder_level';elseif($stock==='out')$where[]='sb.quantity=0';elseif($stock==='in')$where[]='sb.quantity>p.reorder_level';elseif($stock==='untracked')$where[]='sb.product_id IS NULL';
  $join=' FROM products p JOIN categories c ON c.shop_id=p.shop_id AND c.id=p.category_id LEFT JOIN subcategories s ON s.shop_id=p.shop_id AND s.id=p.subcategory_id LEFT JOIN brands b ON b.shop_id=p.shop_id AND b.id=p.brand_id LEFT JOIN stock_balances sb ON sb.shop_id=p.shop_id AND sb.product_id=p.id WHERE '.implode(' AND ',$where);
  $stmt=db()->prepare('SELECT COUNT(*)'.$join);$stmt->execute($args);$total=(int)$stmt->fetchColumn();
  $page=max(1,min((int)($filters['page']??1),max(1,(int)ceil($total/25))));$sort=['name'=>'p.name','sku'=>'p.sku','price'=>'p.selling_price','stock'=>'sb.quantity','newest'=>'p.id'][$filters['sort']??'name']??'p.name';$direction=($filters['direction']??'asc')==='desc'?'DESC':'ASC';
  $select="SELECT p.*,c.name AS category_name,s.name AS subcategory_name,b.name AS brand_name,sb.quantity,(SELECT GROUP_CONCAT(DISTINCT CONCAT(mf.name,' ',vm.name) ORDER BY mf.name,vm.name SEPARATOR ', ') FROM product_fitments pf JOIN vehicle_fitments f ON f.shop_id=pf.shop_id AND f.id=pf.fitment_id JOIN vehicle_models vm ON vm.shop_id=f.shop_id AND vm.id=f.model_id JOIN vehicle_manufacturers mf ON mf.shop_id=vm.shop_id AND mf.id=vm.manufacturer_id WHERE pf.shop_id=p.shop_id AND pf.product_id=p.id) AS bikes";
  $stmt=db()->prepare($select.$join." ORDER BY $sort $direction,p.id ASC LIMIT 25 OFFSET ".(($page-1)*25));$stmt->execute($args);return ['rows'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/25))];
 }
}
