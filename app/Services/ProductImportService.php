<?php
namespace App\Services;
use DomainException;
final class ProductImportService {
 const HEADERS=['name','category','subcategory','brand','part_number','description','aliases','specifications','purchase_price','selling_price','mrp','hsn_code','tax_rate','opening_quantity','opening_cost','opening_date','reorder_level','rack_location','status','sku'];
 const FITMENT_HEADERS=['sku','manufacturer','model','variant','year_from','year_to','notes'];
 public static function read(string $path,string $extension,bool $fitments=false):array {
  if(filesize($path)>5*1024*1024)throw new DomainException('Maximum upload size is 5 MB.');
  $rows=[];
  if($extension==='csv'){$fp=fopen($path,'r');while(($row=fgetcsv($fp,0,',','"',''))!==false){$rows[]=$row;if(count($rows)>1001)throw new DomainException('Maximum 1,000 rows per import.');}fclose($fp);}
  elseif($extension==='xlsx'){
   $zip=new \ZipArchive();if($zip->open($path)!==true)throw new DomainException('Invalid XLSX workbook.');$total=0;for($i=0;$i<$zip->numFiles;$i++){$total+=$zip->statIndex($i)['size'];if($total>20000000)throw new DomainException('Workbook expands beyond 20 MB.');}
   $xml=function(string $file)use($zip){$s=$zip->getFromName($file);if($s===false || stripos($s,'<!DOCTYPE')!==false || stripos($s,'<!ENTITY')!==false)throw new DomainException('Invalid workbook XML.');$x=simplexml_load_string($s,'SimpleXMLElement',LIBXML_NONET);if(!$x)throw new DomainException('Invalid workbook XML.');return $x;};
   $strings=[];if($zip->locateName('xl/sharedStrings.xml')!==false)foreach($xml('xl/sharedStrings.xml')->xpath('//*[local-name()="si"]') as $si){$strings[]=implode('',array_map('strval',$si->xpath('.//*[local-name()="t"]')));if(count($strings)>50000)throw new DomainException('Too many workbook strings.');}
   $book=$xml('xl/workbook.xml');$sheets=$book->xpath('//*[local-name()="sheet"]');if(!$sheets)throw new DomainException('Workbook has no sheets.');$relId=(string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];$target='';foreach($xml('xl/_rels/workbook.xml.rels')->xpath('//*[local-name()="Relationship"]') as $rel)if((string)$rel['Id']===$relId)$target=(string)$rel['Target'];
   $target=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;if(!preg_match('~^xl/worksheets/[A-Za-z0-9_.-]+\.xml$~D',$target))throw new DomainException('Unsupported worksheet location.');
   foreach($xml($target)->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row){$cells=[];foreach($row->xpath('./*[local-name()="c"]') as $cell){preg_match('/^([A-Z]+)/',(string)$cell['r'],$m);$col=0;foreach(str_split($m[1]??'A') as $c)$col=$col*26+ord($c)-64;$col--;if($col>49)throw new DomainException('Too many columns.');$v=$cell->xpath('./*[local-name()="v"]');$value=(string)($v[0]??'');if($cell->xpath('./*[local-name()="f"]'))$value='[FORMULA NOT ALLOWED]';elseif((string)$cell['t']==='s')$value=$strings[(int)$value]??'';elseif((string)$cell['t']==='inlineStr')$value=implode('',array_map('strval',$cell->xpath('.//*[local-name()="t"]')));$cells[$col]=$value;}if($cells){$line=array_fill(0,max(array_keys($cells))+1,'');foreach($cells as $k=>$v)$line[$k]=$v;$rows[]=$line;}if(count($rows)>1001)throw new DomainException('Maximum 1,000 rows per import.');}$zip->close();
  }else throw new DomainException('Upload CSV or XLSX. Legacy XLS is not supported.');
  if(!$rows)throw new DomainException('The file is empty.');$header=array_map(fn($v)=>strtolower(trim((string)$v)),array_shift($rows));$header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);
  if(count($header)!==count(array_unique($header)) || array_diff($header,$fitments?self::FITMENT_HEADERS:self::HEADERS) || array_diff($fitments?['sku','manufacturer','model']:['name','category','purchase_price','selling_price'],$header))throw new DomainException('Use the provided template headers. Required: name, category, purchase_price, selling_price.');
  $out=[];foreach($rows as $row){if(!array_filter($row,fn($v)=>trim((string)$v)!==''))continue;if(count($row)>count($header))throw new DomainException('A row has more cells than the header.');$out[]=array_combine($header,array_pad($row,count($header),''));}return $out;
 }
 public function __construct(private int $shop){}
 public function preview(array $rows,array $actor):array {
  $out=[];$skus=[];$identities=[];$service=new ProductService($this->shop);
  foreach($rows as $i=>$row){$errors=[];$warnings=[];$data=$row;try{
   if(in_array('[FORMULA NOT ALLOWED]',$row,true))throw new DomainException('Formula cells are not accepted. Paste values first.');
   foreach(['category'=>'categories','subcategory'=>'subcategories','brand'=>'brands','tax_rate'=>'tax_rates'] as $label=>$table){$name=trim($row[$label]??'');$field=$label==='tax_rate'?'tax_rate_id':$label.'_id';$data[$field]='';if($name!==''){$sql="SELECT id FROM $table WHERE shop_id=? AND name=? AND status='active'";$args=[$this->shop,$name];if(in_array($table,['categories','subcategories'],true))$sql.=' AND archived_at IS NULL';if($label==='subcategory'){$sql.=' AND category_id=?';$args[]=$data['category_id'];}$q=db()->prepare($sql);$q->execute($args);$data[$field]=$q->fetchColumn();if(!$data[$field])throw new DomainException('Unknown or inactive '.$label.': '.$name);}}
   $data['specifications']=[];foreach(explode(';',$row['specifications']??'') as $spec){if(trim($spec)==='')continue;$pair=explode('=',$spec,2);if(count($pair)!==2)throw new DomainException('Specifications must use Label=Value;Label=Value.');$data['specifications'][]=['label'=>trim($pair[0]),'value'=>trim($pair[1])];}
   foreach(['opening_quantity'=>'0','opening_date'=>date('Y-m-d'),'reorder_level'=>'0','status'=>'active'] as $k=>$default)if(($data[$k]??'')==='')$data[$k]=$default;
   $validated=$service->validate($data,$actor,null,true);
   $q=db()->prepare("SELECT sku FROM products WHERE shop_id=? AND name=? AND brand_id <=> ? AND COALESCE(part_number,'')=? LIMIT 1");$q->execute([$this->shop,$validated['name'],$validated['brand_id'],$validated['part_number']]);if($sku=$q->fetchColumn())$warnings[]='Possible duplicate of '.$sku.'; verify the exact physical part.';
   $identity=mb_strtolower($validated['name'].'|'.($validated['brand_id']??'').'|'.$validated['part_number']);if(isset($identities[$identity]))$warnings[]='Similar product also appears on row '.$identities[$identity];$identities[$identity]=$i+2;
  }catch(DomainException $e){$errors[]=$e->getMessage();}
  $sku=strtoupper(trim($row['sku']??''));if($sku!==''){if(isset($skus[$sku])){$errors[]='Duplicate SKU within this file.';$out[$skus[$sku]]['errors'][]='Duplicate SKU within this file.';}else$skus[$sku]=count($out);}
  $out[]=['row'=>$i+2,'name'=>$row['name']??'','data'=>$data,'errors'=>$errors,'warnings'=>$warnings];
  }return $out;
 }
 private function compatibilityInput(array $row,array $actor):array {
  $q=db()->prepare('SELECT id FROM products WHERE shop_id=? AND sku=? AND archived_at IS NULL');$q->execute([$this->shop,trim($row['sku']??'')]);$id=$q->fetchColumn();if(!$id)throw new DomainException('Unknown or archived product SKU.');
  $service=new ProductService($this->shop);$p=$service->get((int)$id);
  $q=db()->prepare('SELECT m.id,m.manufacturer_id FROM vehicle_models m JOIN vehicle_manufacturers f ON f.shop_id=m.shop_id AND f.id=m.manufacturer_id WHERE m.shop_id=? AND f.name=? AND m.name=?');$q->execute([$this->shop,trim($row['manufacturer']??''),trim($row['model']??'')]);$model=$q->fetch();if(!$model)throw new DomainException('Unknown manufacturer/model pair.');
  $variant=null;if(trim($row['variant']??'')!==''){$q=db()->prepare('SELECT id FROM vehicle_variants WHERE shop_id=? AND model_id=? AND name=?');$q->execute([$this->shop,$model['id'],trim($row['variant'])]);$variant=$q->fetchColumn();if(!$variant)throw new DomainException('Unknown variant under this model.');}
  foreach($p['fitments'] as &$old)$old['verified']=!empty($old['verified_at']) && can($actor,'products.verify_fitment')?'1':'0';unset($old);
  $p['fitments'][]=['manufacturer_id'=>$model['manufacturer_id'],'model_id'=>$model['id'],'variant_id'=>$variant,'year_from'=>$row['year_from']??'','year_to'=>$row['year_to']??'','notes'=>$row['notes']??''];return $p;
 }
 public function previewCompatibility(array $rows,array $actor):array {
  $out=[];foreach($rows as $i=>$row){$errors=[];$data=[];try{$p=$this->compatibilityInput($row,$actor);$d=(new ProductService($this->shop))->validate($p,$actor,$p);$last=end($d['fitments']);$data=['row'=>$row,'product_id'=>$p['id'],'hash'=>$last['identity_hash']];}catch(DomainException $e){$errors[]=$e->getMessage();}$out[]=['row'=>$i+2,'name'=>$row['sku']??'','data'=>$data,'errors'=>$errors,'warnings'=>[]];}return $out;
 }
 public function commitCompatibility(array $rows,array $actor):array {
  if(!can($actor,'products.import'))throw new DomainException('Import permission required.');$results=[];
  foreach($rows as $row){$entry=['row'=>$row['row'],'name'=>$row['name'],'errors'=>$row['errors'],'id'=>null];if(!$entry['errors'])try{
   $service=new ProductService($this->shop);$p=$service->get((int)$row['data']['product_id']);$found=false;foreach($p['fitments'] as $f)if($f['identity_hash']===$row['data']['hash'])$found=true;
   if(!$found){$p=$this->compatibilityInput($row['data']['row'],$actor);$service->save($p,$actor,(int)$p['id']);}$entry['id']=(int)$p['id'];
  }catch(\Throwable $e){error_log($e->getMessage());$entry['errors'][]=$e instanceof DomainException?$e->getMessage():'Mapping failed and was rolled back.';}$results[]=$entry;}return $results;
 }
 public function commit(array $stage,array $actor,string $token,bool $confirm):array {
  if(!can($actor,'products.import'))throw new DomainException('Product import permission required.');
  if(!$confirm)foreach($stage as $row)if($row['warnings'])throw new DomainException('Confirm that you have reviewed possible duplicate products.');
  $result=[];$service=new ProductService($this->shop);foreach($stage as $row){$entry=['row'=>$row['row'],'name'=>$row['name'],'errors'=>$row['errors'],'id'=>null];if(!$entry['errors'])try{$entry['id']=$service->save($row['data'],$actor,null,'import-'.$token.'-'.$row['row'],true);}catch(\Throwable $e){error_log($e->getMessage());$entry['errors'][]=$e instanceof DomainException?$e->getMessage():'Database error; this row was rolled back.';}$result[]=$entry;}return $result;
 }
}
