<?php
namespace App\Services;
use DomainException;
use PDOException;
final class ProductMasterService {
 public function __construct(private int $shop){}
 public function all():array {
  $out=[];
  foreach(['categories','subcategories','brands','tax_rates','vehicle_manufacturers','vehicle_models','vehicle_variants'] as $table){
   $q=db()->prepare("SELECT * FROM $table WHERE shop_id=? ORDER BY name,id");$q->execute([$this->shop]);$out[$table]=$q->fetchAll();
  }return $out;
 }
 public function save(string $type,array $data):int {
  if(!in_array($type,['brands','tax_rates','vehicle_manufacturers','vehicle_models','vehicle_variants'],true))throw new DomainException('Invalid catalogue type.');
  $name=trim(is_string($data['name']??null)?$data['name']:'');if(!$name || mb_strlen($name)>($type==='tax_rates'?80:150))throw new DomainException('Enter a name up to 150 characters.');
  $id=ProductService::optionalId($data['id']??'');$fields=['name'=>$name];
  if(in_array($type,['brands','tax_rates'],true)){
   $status=$data['status']??'active';if(!in_array($status,['active','inactive'],true))throw new DomainException('Invalid status.');$fields['status']=$status;
  }
  if($type==='tax_rates'){$rate=ProductService::decimal($data['rate']??'',2);if(bccomp($rate,'100',2)>0)throw new DomainException('Tax rate must be between 0 and 100.');$fields['rate']=$rate;}
  if($type==='vehicle_models' || $type==='vehicle_variants'){
   $parent=$type==='vehicle_models'?'manufacturer_id':'model_id';$parentTable=$type==='vehicle_models'?'vehicle_manufacturers':'vehicle_models';
   $parentId=ProductService::optionalId($data[$parent]??'');$q=db()->prepare("SELECT id FROM $parentTable WHERE shop_id=? AND id=?");$q->execute([$this->shop,$parentId]);if(!$q->fetchColumn())throw new DomainException('Choose a valid parent in your shop.');$fields[$parent]=$parentId;
  }
  try{
   if($id){$q=db()->prepare("SELECT id FROM $type WHERE shop_id=? AND id=?");$q->execute([$this->shop,$id]);if(!$q->fetchColumn())throw new DomainException('Entry not found.');
    // Parent changes would invalidate existing fitments; keep masters stable.
    if(isset($parent)){ $q=db()->prepare("SELECT $parent FROM $type WHERE shop_id=? AND id=?");$q->execute([$this->shop,$id]);if((int)$q->fetchColumn()!==$fields[$parent])throw new DomainException('Parent changes are not allowed for existing bike entries.');}
    $q=db()->prepare("UPDATE $type SET ".implode(',',array_map(fn($k)=>"$k=?",array_keys($fields))).' WHERE shop_id=? AND id=?');$q->execute([...array_values($fields),$this->shop,$id]);return $id;
   }
   $fields=['shop_id'=>$this->shop]+$fields;$q=db()->prepare("INSERT INTO $type (".implode(',',array_keys($fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');$q->execute(array_values($fields));return (int)db()->lastInsertId();
  }catch(PDOException $e){if(($e->errorInfo[1]??0)===1062)throw new DomainException('That name already exists under this parent.');throw $e;}
 }
 public function deleteBrand(int $id):void {
  $pdo=db();$pdo->beginTransaction();try{
   $q=$pdo->prepare('SELECT id FROM shops WHERE id=? FOR UPDATE');$q->execute([$this->shop]);
   $q=$pdo->prepare('SELECT COUNT(*) FROM products WHERE shop_id=? AND brand_id=?');$q->execute([$this->shop,$id]);if($q->fetchColumn())throw new DomainException('This brand is linked to products. Deactivate it instead.');
   $pdo->prepare('DELETE FROM brands WHERE shop_id=? AND id=?')->execute([$this->shop,$id]);$pdo->commit();
  }catch(\Throwable $e){$pdo->rollBack();throw $e;}
 }
 public function seedVehicles():void {
  foreach(require BASE_PATH.'/database/seeders/vehicles.php' as $maker=>$models){
   $q=db()->prepare('SELECT id FROM vehicle_manufacturers WHERE shop_id=? AND name=?');$q->execute([$this->shop,$maker]);$id=$q->fetchColumn();if(!$id)$id=$this->save('vehicle_manufacturers',['name'=>$maker]);
   foreach($models as $name){$q=db()->prepare('SELECT id FROM vehicle_models WHERE shop_id=? AND manufacturer_id=? AND name=?');$q->execute([$this->shop,$id,$name]);if(!$q->fetchColumn())$this->save('vehicle_models',['manufacturer_id'=>$id,'name'=>$name]);}
  }
 }
}
