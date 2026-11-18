<?php
namespace App\Services;
use DomainException;
final class InventoryService {
 public function __construct(private int $shop){}
 public function product(int $id):array {
  $q=db()->prepare('SELECT p.id,p.name,p.sku,p.part_number,p.status,p.archived_at,p.reorder_level,c.name AS category_name,s.name AS subcategory_name,b.quantity,b.average_unit_cost FROM products p JOIN categories c ON c.shop_id=p.shop_id AND c.id=p.category_id LEFT JOIN subcategories s ON s.shop_id=p.shop_id AND s.id=p.subcategory_id LEFT JOIN stock_balances b ON b.shop_id=p.shop_id AND b.product_id=p.id WHERE p.shop_id=? AND p.id=?');$q->execute([$this->shop,$id]);$p=$q->fetch();if(!$p)throw new DomainException('Product not found in your shop.');return $p;
 }
 public function search(string $query,string $kind):array {
  if(mb_strlen(trim($query))<2)return [];return (new InventorySearchService($this->shop))->listing(['q'=>$query,'status'=>$kind==='in'?'active':'all'],20)['rows'];
 }
 public function overview():array {
  $q=db()->prepare("SELECT COUNT(*) AS active_products,COALESCE(SUM(b.quantity>0 AND p.reorder_level>0 AND b.quantity<=p.reorder_level),0) AS low_products,COALESCE(SUM(b.quantity=0),0) AS zero_products,COALESCE(SUM(b.product_id IS NULL),0) AS untracked FROM products p LEFT JOIN stock_balances b ON b.shop_id=p.shop_id AND b.product_id=p.id WHERE p.shop_id=? AND p.status='active' AND p.archived_at IS NULL");$q->execute([$this->shop]);$stats=$q->fetch();$q=db()->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock_balances WHERE shop_id=?');$q->execute([$this->shop]);$stats['total_units']=$q->fetchColumn();
  foreach(['active'=>"p.status='active' AND p.archived_at IS NULL",'inactive'=>"p.status='inactive' AND p.archived_at IS NULL",'archived'=>"p.archived_at IS NOT NULL"] as $scope=>$condition){$q=db()->prepare('SELECT COUNT(*) AS products,COALESCE(SUM(b.quantity),0) AS units FROM products p LEFT JOIN stock_balances b ON b.shop_id=p.shop_id AND b.product_id=p.id WHERE p.shop_id=? AND '.$condition);$q->execute([$this->shop]);$row=$q->fetch();$stats[$scope.'_units']=$row['units'];$stats[$scope.'_products']=$row['products'];}return $stats;
 }
 private function movementSelect():string {
  return "SELECT m.*,p.name,p.sku,p.part_number,u.name AS actor_name,COALESCE(m.previous_quantity,(SELECT COALESCE(SUM(old.quantity_delta),0) FROM stock_movements old WHERE old.shop_id=m.shop_id AND old.product_id=m.product_id AND old.id<m.id)) AS previous_stock,COALESCE(m.resulting_quantity,(SELECT COALESCE(SUM(old.quantity_delta),0) FROM stock_movements old WHERE old.shop_id=m.shop_id AND old.product_id=m.product_id AND old.id<=m.id)) AS resulting_stock,(SELECT r.id FROM stock_movements r WHERE r.shop_id=m.shop_id AND r.reverses_movement_id=m.id LIMIT 1) AS reversed_by FROM stock_movements m JOIN products p ON p.shop_id=m.shop_id AND p.id=m.product_id JOIN users u ON u.id=m.created_by";
 }
 public function movement(int $id):array {
  $q=db()->prepare($this->movementSelect().' WHERE m.shop_id=? AND m.id=?');$q->execute([$this->shop,$id]);$row=$q->fetch();if(!$row)throw new DomainException('Movement not found in your shop.');return $row;
 }
 public function history(array $filters):array {
  $where=['m.shop_id=?'];$args=[$this->shop];$search=is_string($filters['q']??null)?trim($filters['q']):'';
  if($search!==''){$where[]='(p.name LIKE ? OR p.sku LIKE ? OR m.reference_id LIKE ?)';array_push($args,...array_fill(0,3,'%'.$search.'%'));}
  $types=['opening','stock_in','stock_out','adjustment_increase','adjustment_decrease','reversal','purchase','sale','customer_return','supplier_return','adjustment','damage'];
  if(in_array($filters['type']??'',$types,true)){$where[]='m.movement_type=?';$args[]=$filters['type'];}
  foreach(['from'=>'>=','to'=>'<='] as $field=>$operator)if(($filters[$field]??'')!==''){$v=$filters[$field];$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d || $d->format('Y-m-d')!==$v)throw new DomainException('Use a valid date filter.');$where[]="COALESCE(m.entry_date,DATE(m.created_at)) $operator ?";$args[]=$v;}
  if(!empty($filters['from']) && !empty($filters['to']) && $filters['from']>$filters['to'])throw new DomainException('From date must not be after To date.');
  foreach(['user_id'=>'m.created_by','product_id'=>'m.product_id'] as $field=>$column)if(($filters[$field]??'')!==''){$where[]="$column=?";$args[]=ProductService::optionalId($filters[$field]);}
  $base=' FROM stock_movements m JOIN products p ON p.shop_id=m.shop_id AND p.id=m.product_id WHERE '.implode(' AND ',$where);$q=db()->prepare('SELECT COUNT(*)'.$base);$q->execute($args);$total=(int)$q->fetchColumn();$page=max(1,min((int)($filters['page']??1),max(1,(int)ceil($total/25))));
  $sort=($filters['sort']??'date')==='quantity'?'m.quantity_delta':'m.created_at';$direction=($filters['direction']??'desc')==='asc'?'ASC':'DESC';
  $q=db()->prepare($this->movementSelect().' WHERE '.implode(' AND ',$where)." ORDER BY $sort $direction,m.id $direction LIMIT 25 OFFSET ".(($page-1)*25));$q->execute($args);return ['rows'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/25))];
 }
 public function actors():array {$q=db()->prepare('SELECT DISTINCT u.id,u.name FROM users u JOIN stock_movements m ON m.created_by=u.id WHERE m.shop_id=? ORDER BY u.name');$q->execute([$this->shop]);return $q->fetchAll();}
}
