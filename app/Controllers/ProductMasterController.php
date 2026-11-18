<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Services\{ProductMasterService,ProductService};
use DomainException;
final class ProductMasterController extends Controller {
 public function index():void {$this->display();}
 private function display(string $error='',array $old=[]):void {
  $masters=(new ProductMasterService((int)current_user()['shop_id']))->all();$type=$_GET['type']??'brands';if(!in_array($type,['brands','vehicle_manufacturers','vehicle_models','vehicle_variants','tax_rates'],true))$type='brands';$q=is_string($_GET['q']??null)?trim($_GET['q']):'';
  $rows=$masters[$type];if($q!=='')$rows=array_values(array_filter($rows,fn($r)=>mb_stripos($r['name'],$q)!==false));
  if($type==='brands')foreach($rows as &$row){$stmt=db()->prepare('SELECT COUNT(*) FROM products WHERE shop_id=? AND brand_id=?');$stmt->execute([current_user()['shop_id'],$row['id']]);$row['product_count']=$stmt->fetchColumn();}unset($row);
  $notice=$_SESSION['master_notice']??'';unset($_SESSION['master_notice']);$this->page('products/masters',compact('masters','type','rows','q','error','old','notice'),$type==='brands'?'Brands':'Product Catalogue','Catalogue');
 }
 public function save():void {
  try{$s=new ProductMasterService((int)current_user()['shop_id']);if(($_POST['action']??'')==='delete_brand'){$id=ProductService::optionalId($_POST['id']??'');if(!$id)throw new DomainException('Choose a brand.');$s->deleteBrand($id);}else$s->save(is_string($_POST['type']??null)?$_POST['type']:'',$_POST);$_SESSION['master_notice']='Catalogue updated.';header('Location: '.url('/product-masters').'&type='.rawurlencode($_POST['type']??'brands'));exit;}catch(DomainException $e){http_response_code(422);$_GET['type']=$_POST['type']??'brands';$this->display($e->getMessage(),array_filter($_POST,'is_string'));}
 }
}
