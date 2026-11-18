<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Services\{ProductService,ProductMasterService};
use DomainException;
final class ProductController extends Controller {
 private function service():ProductService{return new ProductService((int)current_user()['shop_id']);}
 private function masters():array{return (new ProductMasterService((int)current_user()['shop_id']))->all();}
 public function index():void {
  $filters=[];foreach($_GET as $k=>$v)if(is_string($v))$filters[$k]=$v;
  $error='';try{$queryFilters=$filters;if(mb_strlen(trim($filters['q']??''))<2)unset($queryFilters['q']);$listing=$this->service()->listing($queryFilters);}catch(DomainException $e){$error=$e->getMessage();$listing=$this->service()->listing([]);}
  if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) {
   header('Content-Type: application/json');
   echo json_encode(['html'=>$this->render('products/results',compact('listing','filters','error')),'total'=>$listing['total'],'page'=>$listing['page']]);return;
  }
  $notice=$_SESSION['product_notice']??'';unset($_SESSION['product_notice']);$masters=$this->masters();$this->page('products/index',compact('listing','filters','error','notice','masters'),'Products','Catalogue');
 }
 public function form():void {
  $id=ProductService::optionalId($_GET['id']??'');$duplicate=ProductService::optionalId($_GET['duplicate']??'');$error='';
  try{$product=$id?$this->service()->get($id):($duplicate?$this->service()->duplicate($duplicate):[]);if($id && $product['archived_at'])throw new DomainException('Archived products cannot be edited.');}catch(DomainException $e){http_response_code(404);echo e($e->getMessage());return;}
  $key=bin2hex(random_bytes(20));$_SESSION['product_tokens'][$key]=time();$this->renderForm($product,$id,$key,$error,$duplicate!==null);
 }
 private function renderForm(array $product,?int $id,string $key,string $error,bool $duplicated=false):void {
  $masters=$this->masters();$this->page('products/form',compact('product','id','key','error','masters','duplicated'),$id?'Edit Product':'Add Product','Catalogue');
 }
 public function save():void {
  $id=null;$key=is_string($_POST['creation_key']??null)?$_POST['creation_key']:'';
  try{$id=ProductService::optionalId($_POST['id']??'');if(!$id && (!isset($_SESSION['product_tokens'][$key]) || time()-$_SESSION['product_tokens'][$key]>7200))throw new DomainException('Creation form expired. Open a new form.');$saved=$this->service()->save($_POST,current_user(),$id,$key);$_SESSION['product_notice']=$id?'Product updated. Stock was not changed.':'Product created with its own stock record.';header('Location: '.url('/products/view').'&id='.$saved);exit;}catch(DomainException $e){http_response_code(422);$this->renderForm($_POST,$id,$key,$e->getMessage());}
 }
 public function details():void {
  try{$id=ProductService::optionalId($_GET['id']??'');if(!$id)throw new DomainException('Choose a product.');$product=$this->service()->get($id);}catch(DomainException $e){http_response_code(404);echo e($e->getMessage());return;}
  $notice=$_SESSION['product_notice']??'';unset($_SESSION['product_notice']);$this->page('products/view',compact('product','notice'),'Product Details','Catalogue');
 }
 public function archive():void {
  try{$id=ProductService::optionalId($_POST['id']??'');if(!$id)throw new DomainException('Invalid product.');$this->service()->archive($id,current_user());$_SESSION['product_notice']='Product archived. Inventory and history were preserved.';}catch(DomainException $e){$_SESSION['product_notice']=$e->getMessage();}redirect('/products');
 }
}
