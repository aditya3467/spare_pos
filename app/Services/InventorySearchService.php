<?php
namespace App\Services;
use DomainException;
final class InventorySearchService {
 public function __construct(private int $shop){}
 public static function normalize(string $q):string{return mb_strtolower(trim(preg_replace('/\s+/u',' ',mb_substr($q,0,150))));}
 private function bike(string $condition):string{return "EXISTS(SELECT 1 FROM product_fitments pf JOIN vehicle_fitments f ON f.shop_id=pf.shop_id AND f.id=pf.fitment_id JOIN vehicle_models vm ON vm.shop_id=f.shop_id AND vm.id=f.model_id JOIN vehicle_manufacturers mf ON mf.shop_id=vm.shop_id AND mf.id=vm.manufacturer_id LEFT JOIN vehicle_variants vv ON vv.shop_id=f.shop_id AND vv.model_id=f.model_id AND vv.id=f.variant_id WHERE pf.shop_id=p.shop_id AND pf.product_id=p.id AND $condition)";}
 public function catalogue():array {
  $out=[];foreach(['vehicle_manufacturers','vehicle_models','vehicle_variants'] as $t){$q=db()->prepare("SELECT * FROM $t WHERE shop_id=? ORDER BY name,id");$q->execute([$this->shop]);$out[$t]=$q->fetchAll();}return $out;
 }
 public function listing(array $filters,int $limit=25):array {
  $where=['p.shop_id=?'];$args=[$this->shop];$query=self::normalize(is_string($filters['q']??null)?$filters['q']:'');
  $bikeText="LOWER(CONCAT(mf.name,' ',vm.name,' ',COALESCE(vv.name,''))) LIKE ?";
  foreach(array_slice(array_values(array_filter(explode(' ',$query))),0,12) as $term){
   $where[]='(p.name LIKE ? OR p.sku LIKE ? OR p.part_number LIKE ? OR b.name LIKE ? OR EXISTS(SELECT 1 FROM product_aliases a WHERE a.shop_id=p.shop_id AND a.product_id=p.id AND a.alias LIKE ?) OR '.$this->bike($bikeText).')';array_push($args,...array_fill(0,6,'%'.$term.'%'));
  }
  $rank='0';$rankArgs=[];
  if($query!==''){$rank="CASE WHEN LOWER(TRIM(p.sku))=? THEN 0 WHEN LOWER(TRIM(p.part_number))=? THEN 1 WHEN LOWER(TRIM(p.name))=? THEN 2 WHEN LOWER(p.name) LIKE ? THEN 3 WHEN LOWER(TRIM(b.name))=? THEN 4 WHEN ".$this->bike($bikeText)." THEN 5 WHEN EXISTS(SELECT 1 FROM product_aliases a WHERE a.shop_id=p.shop_id AND a.product_id=p.id AND a.alias LIKE ?) THEN 6 ELSE 7 END";$rankArgs=[$query,$query,$query,'%'.$query.'%',$query,'%'.$query.'%','%'.$query.'%'];}
  $status=$filters['status']??'active';if($status==='archived')$where[]='p.archived_at IS NOT NULL';elseif($status!=='all'){$where[]='p.archived_at IS NULL';$where[]='p.status=?';$args[]=in_array($status,['active','inactive'],true)?$status:'active';}
  $stock=$filters['stock']??'';if($stock==='low')$where[]='sb.quantity>0 AND p.reorder_level>0 AND sb.quantity<=p.reorder_level';elseif($stock==='out')$where[]='sb.quantity=0';elseif($stock==='in')$where[]='sb.quantity>p.reorder_level';elseif($stock==='untracked')$where[]='sb.product_id IS NULL';
  $bike=[];$bikeArgs=[];$selected=[];
  foreach(['manufacturer_id'=>['vehicle_manufacturers','mf.id'],'model_id'=>['vehicle_models','vm.id'],'variant_id'=>['vehicle_variants','vv.id']] as $field=>[$table,$column])if(($filters[$field]??'')!==''){
   $id=ProductService::optionalId($filters[$field]);$q=db()->prepare("SELECT * FROM $table WHERE shop_id=? AND id=?");$q->execute([$this->shop,$id]);$row=$q->fetch();if(!$row)throw new DomainException('Choose a valid bike entry in your shop.');$selected[$field]=$row;$bike[]="$column=?";$bikeArgs[]=$id;
  }
  if(isset($selected['manufacturer_id'],$selected['model_id']) && $selected['model_id']['manufacturer_id']!=$selected['manufacturer_id']['id'])throw new DomainException('Bike model does not belong to manufacturer.');
  if(isset($selected['variant_id'],$selected['model_id']) && $selected['variant_id']['model_id']!=$selected['model_id']['id'])throw new DomainException('Variant does not belong to model.');
  if(($filters['year']??'')!==''){$year=$filters['year'];if(!preg_match('/^\d{4}$/D',$year) || (int)$year<1900 || (int)$year>(int)date('Y')+2)throw new DomainException('Choose a valid manufacturing year.');$bike[]='(f.year_from IS NULL OR f.year_from<=?) AND (f.year_to IS NULL OR f.year_to>=?)';array_push($bikeArgs,(int)$year,(int)$year);}
  if($bike){$where[]=$this->bike(implode(' AND ',$bike));array_push($args,...$bikeArgs);}
  $join=' FROM products p JOIN categories c ON c.shop_id=p.shop_id AND c.id=p.category_id LEFT JOIN subcategories s ON s.shop_id=p.shop_id AND s.id=p.subcategory_id LEFT JOIN brands b ON b.shop_id=p.shop_id AND b.id=p.brand_id LEFT JOIN stock_balances sb ON sb.shop_id=p.shop_id AND sb.product_id=p.id WHERE '.implode(' AND ',$where);
  $q=db()->prepare('SELECT COUNT(*)'.$join);$q->execute($args);$total=(int)$q->fetchColumn();$page=max(1,min((int)($filters['page']??1),max(1,(int)ceil($total/$limit))));
  $sort=['name'=>'p.name','sku'=>'p.sku','stock'=>'sb.quantity'][$filters['sort']??'name']??'p.name';$direction=($filters['direction']??'asc')==='desc'?'DESC':'ASC';
  $summary="(SELECT GROUP_CONCAT(DISTINCT CONCAT(mf.name,' ',vm.name,IF(vv.name IS NULL,'',CONCAT(' / ',vv.name))) ORDER BY mf.name,vm.name SEPARATOR ', ') FROM product_fitments pf JOIN vehicle_fitments f ON f.shop_id=pf.shop_id AND f.id=pf.fitment_id JOIN vehicle_models vm ON vm.shop_id=f.shop_id AND vm.id=f.model_id JOIN vehicle_manufacturers mf ON mf.shop_id=vm.shop_id AND mf.id=vm.manufacturer_id LEFT JOIN vehicle_variants vv ON vv.shop_id=f.shop_id AND vv.model_id=f.model_id AND vv.id=f.variant_id WHERE pf.shop_id=p.shop_id AND pf.product_id=p.id)";
  $q=db()->prepare('SELECT p.id,p.name,p.sku,p.part_number,p.status,p.archived_at,p.reorder_level,c.name AS category_name,s.name AS subcategory_name,b.name AS brand_name,sb.quantity,'.$summary.' AS bikes,'.$rank.' AS search_rank'.$join.' ORDER BY search_rank ASC,'.$sort.' '.$direction.',p.id ASC LIMIT '.$limit.' OFFSET '.(($page-1)*$limit));$q->execute([...$rankArgs,...$args]);$rows=$q->fetchAll();
  foreach($rows as &$row)$row['match_info']=$query===''?'':([0=>'Exact SKU',1=>'Exact OEM / part number',2=>'Exact product name',3=>'Product name',4=>'Brand',5=>'Compatible bike',6=>'Alias / local name',7=>'Matching search terms'][(int)$row['search_rank']]);
  return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit))];
 }
}
