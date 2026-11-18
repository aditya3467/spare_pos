<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Services\{InventoryService,StockService,ProductService};
use DomainException;
final class InventoryController extends Controller {
 private function service():InventoryService{return new InventoryService((int)current_user()['shop_id']);}
 private function filters():array {$f=[];foreach($_GET as $k=>$v)if(is_string($v))$f[$k]=$v;return $f;}
 public function index():void {
  $user=current_user();$filters=$this->filters();$error='';
  try{$listing=(new \App\Services\InventorySearchService((int)$user['shop_id']))->listing($filters);}catch(DomainException $e){$error=$e->getMessage();$listing=(new \App\Services\InventorySearchService((int)$user['shop_id']))->listing([]);}
  if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Content-Type: application/json');echo json_encode(['html'=>$this->render('inventory/results',compact('user','listing','filters','error')),'total'=>$listing['total'],'page'=>$listing['page']]);return;}
  $stats=$this->service()->overview();
  $bikes=(new \App\Services\InventorySearchService((int)$user['shop_id']))->catalogue();
  $recent=can($user,'inventory.movements')?$this->service()->history([])['rows']:[];
  $notice=$_SESSION['inventory_notice']??'';unset($_SESSION['inventory_notice']);$this->page('inventory/index',compact('user','filters','stats','listing','recent','error','notice','bikes'),'Inventory','Inventory');
 }
 public function search():void {
  header('Content-Type: application/json');$kind=is_string($_GET['kind']??null)?$_GET['kind']:'in';$q=is_string($_GET['q']??null)?mb_substr($_GET['q'],0,150):'';echo json_encode(['products'=>$this->service()->search($q,$kind)]);
 }
 public function form():void {
  $kind=is_string($_GET['kind']??null)?$_GET['kind']:'in';$data=['kind'=>$kind,'entry_date'=>(new \DateTimeImmutable('today',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d')];$product=null;$original=null;$error='';
  try{if($id=ProductService::optionalId($_GET['product_id']??''))$product=$this->service()->product($id);if($kind==='reverse'){$id=ProductService::optionalId($_GET['movement_id']??'');if(!$id)throw new DomainException('Choose a movement to reverse.');$original=$this->service()->movement($id);$product=$this->service()->product((int)$original['product_id']);$data['movement_id']=$id;}}catch(DomainException $e){$error=$e->getMessage();}
  $key=bin2hex(random_bytes(20));$_SESSION['inventory_tokens'][$key]=time();foreach($_SESSION['inventory_tokens'] as $token=>$created)if(time()-$created>7200)unset($_SESSION['inventory_tokens'][$token]);
  $this->renderForm($data,$key,$product,$original,$error);
 }
 private function renderForm(array $data,string $key,?array $product,?array $original,string $error):void {
  $notice=$_SESSION['inventory_notice']??'';unset($_SESSION['inventory_notice']);
  $formData=$data;$kind=$data['kind']??'in';$title=['in'=>'Stock In','out'=>'Stock Out','adjust'=>'Stock Adjustment','reverse'=>'Reverse Movement'][$kind]??'Stock operation';$this->page('inventory/form',compact('formData','key','product','original','error','kind','title','notice'),$title,'Inventory');
 }
 public function post():void {
  $key=is_string($_POST['request_key']??null)?$_POST['request_key']:'';$data=$_POST;$product=null;$original=null;
  try{
   if(!isset($_SESSION['inventory_tokens'][$key]) || time()-$_SESSION['inventory_tokens'][$key]>7200)throw new DomainException('This stock form expired. Open a fresh form.');
   $result=(new StockService((int)current_user()['shop_id']))->post($data,current_user(),$key);$_SESSION['inventory_notice']=$result['message'];
   if(can(current_user(),'inventory.movements') && $result['movement_id']){header('Location: '.url('/inventory/movement').'&id='.$result['movement_id']);exit;}
   if(can(current_user(),'inventory.view'))redirect('/inventory');
   header('Location: '.url('/inventory/form').'&kind='.rawurlencode($data['kind']));exit;
  }catch(DomainException $e){http_response_code(422);$error=$e->getMessage();}
  $safe=[];foreach($data as $field=>$value)if(is_string($value))$safe[$field]=$value;
  try{if($id=ProductService::optionalId($safe['product_id']??''))$product=$this->service()->product($id);if(($safe['kind']??'')==='reverse' && ($id=ProductService::optionalId($safe['movement_id']??'')))$original=$this->service()->movement($id);}catch(DomainException $e){}
  if(($safe['kind']??'')==='adjust' && str_contains($error,'Stock changed'))$error='Stock changed since the preview. Current stock and the preview have been refreshed. Recount and confirm again.';
  $this->renderForm($safe,$key,$product,$original,$error);
 }
 public function history():void {
  $filters=$this->filters();$error='';try{$history=$this->service()->history($filters);}catch(DomainException $e){$error=$e->getMessage();$history=$this->service()->history([]);}$actors=$this->service()->actors();$this->page('inventory/history',compact('history','filters','actors','error'),'Stock Movements','Inventory');
 }
 public function details():void {
  try{$id=ProductService::optionalId($_GET['id']??'');if(!$id)throw new DomainException('Choose a movement.');$movement=$this->service()->movement($id);}catch(DomainException $e){http_response_code(404);echo e($e->getMessage());return;}
  $notice=$_SESSION['inventory_notice']??'';unset($_SESSION['inventory_notice']);$this->page('inventory/movement',compact('movement','notice'),'Movement Details','Inventory');
 }
}
