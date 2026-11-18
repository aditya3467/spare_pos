<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Services\{SaleService,SalesSettingsService,ProductService};
use DomainException;
final class SalesController extends Controller {
 private function service():SaleService{return new SaleService((int)current_user()['shop_id']);}
 private function input():array {$raw=$_POST['payload']??'';if(!is_string($raw))throw new DomainException('Invalid request.');$d=json_decode($raw,true);if(!is_array($d))throw new DomainException('Invalid request.');return $d;}
 private function json(callable $fn):void {header('Content-Type: application/json');try{echo json_encode($fn(),JSON_THROW_ON_ERROR);}catch(DomainException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}catch(\Throwable $e){error_log((string)$e);http_response_code(500);echo json_encode(['error'=>'The operation could not be completed. Nothing was partially posted. Retry with the same checkout.']);}}
 public function pos():void {$settings=(new SalesSettingsService((int)current_user()['shop_id']))->get(false);$states=SalesSettingsService::states();$key=bin2hex(random_bytes(20));$this->page('sales/pos',compact('settings','states','key'),'New Sale / POS','Sales');}
 public function search():void {$this->json(fn()=>['products'=>$this->service()->search(is_string($_GET['q']??null)?$_GET['q']:'')]);}
 public function customers():void {$this->json(fn()=>['customers'=>$this->service()->customers(is_string($_GET['q']??null)?$_GET['q']:'')]);}
 public function customer():void {$this->json(function(){$d=$this->input();$id=$this->service()->addCustomer($d,current_user());return ['customer'=>['id'=>$id]+$d];});}
 public function quote():void {$this->json(fn()=>['quote'=>$this->service()->quote($this->input(),current_user())]);}
 public function complete():void {$this->json(function(){$d=$this->input();$id=$this->service()->complete($d,current_user(),is_string($d['request_key']??null)?$d['request_key']:'');return ['id'=>$id,'url'=>url('/sales/invoice').'&id='.$id];});}
 public function history():void {$filters=[];foreach($_GET as $k=>$v)if(is_string($v))$filters[$k]=$v;$error='';try{$listing=$this->service()->history($filters);}catch(DomainException $e){$error=$e->getMessage();$listing=$this->service()->history([]);}$this->page('sales/history',compact('listing','filters','error'),'Sales History','Sales');}
 public function invoice():void {
  $printing=\App\Core\Request::route()==='/sales/print';
  try{$id=ProductService::optionalId($_GET['id']??'');if(!$id)throw new DomainException('Choose an invoice.');$sale=$this->service()->get($id);if(!$printing && !can(current_user(),'sales.view') && (int)$sale['created_by']!==(int)current_user()['id']){http_response_code(403);echo 'Sales history permission required.';return;}}
  catch(DomainException $e){http_response_code(404);echo e($e->getMessage());return;}
  $format=($_GET['format']??'')==='thermal'?'thermal':'a4';$notice=$_SESSION['sales_notice']??'';unset($_SESSION['sales_notice']);$error=$_SESSION['sales_error']??'';unset($_SESSION['sales_error']);
  if($printing){$this->view('sales/print',compact('sale','format'));return;}$this->page('sales/invoice',compact('sale','notice','error'),'Invoice '.$sale['invoice_number'],'Sales');
 }
 public function cancel():void {
  $id=ProductService::optionalId($_POST['id']??'');try{if(!$id)throw new DomainException('Choose an invoice.');$this->service()->cancel($id,$_POST,current_user());$_SESSION['sales_notice']='Invoice cancelled. Linked stock and payment reversals recorded.';}catch(DomainException $e){$_SESSION['sales_error']=$e->getMessage();}catch(\Throwable $e){error_log((string)$e);$_SESSION['sales_error']='Cancellation failed and was rolled back. Retry after reviewing the invoice.';}header('Location: '.url('/sales/invoice').'&id='.(int)$id);exit;
 }
 public function report():void {
  $filters=[];foreach($_GET as $k=>$v)if(is_string($v))$filters[$k]=$v;$error=$_SESSION['revenue_error']??'';unset($_SESSION['revenue_error']);$notice=$_SESSION['revenue_notice']??'';unset($_SESSION['revenue_notice']);
  try{$dates=\App\Services\RevenueReportService::dates($filters);}catch(DomainException $e){$error=$e->getMessage();$dates=\App\Services\RevenueReportService::dates([]);}
  $service=new \App\Services\RevenueReportService((int)current_user()['shop_id']);$summary=$service->summary($dates);$expenses=$service->expenses($dates);$key=bin2hex(random_bytes(20));$this->page('sales/report',compact('dates','summary','expenses','key','notice','error'),'Revenue & Profit','Reports');
 }
 public function expense():void {
  try{(new \App\Services\RevenueReportService((int)current_user()['shop_id']))->recordExpense($_POST,current_user(),is_string($_POST['request_key']??null)?$_POST['request_key']:'');$_SESSION['revenue_notice']='Labour expense recorded.';}catch(DomainException $e){$_SESSION['revenue_error']=$e->getMessage();}catch(\Throwable $e){error_log((string)$e);$_SESSION['revenue_error']='The expense could not be recorded. Retry using the same form.';}
  header('Location: '.url('/sales/report'));exit;
 }
 public function settings():void {
  $service=new SalesSettingsService((int)current_user()['shop_id']);$error='';$notice='';$settings=$service->get(false);
  if($_SERVER['REQUEST_METHOD']==='POST')try{
   if(($_POST['action']??'')==='tax_rate'){$name=SalesSettingsService::text($_POST,'name',80,true);$rate=ProductService::decimal($_POST['rate']??'',2,false,'Tax rate');if(bccomp($rate,'100',2)>0)throw new DomainException('Tax rate must be at most 100.');try{db()->prepare('INSERT INTO tax_rates(shop_id,name,rate) VALUES(?,?,?)')->execute([current_user()['shop_id'],$name,$rate]);}catch(\PDOException $e){if($e->getCode()==='23000')throw new DomainException('That tax name already exists.');throw $e;}$notice='Tax classification added.';}
   else{$service->save($_POST,current_user());$settings=$service->get(false);$notice='Settings saved.';}
  }catch(DomainException $e){http_response_code(422);$error=$e->getMessage();if(($_POST['action']??'')!=='tax_rate')$settings=$_POST;}
  $q=db()->prepare('SELECT * FROM tax_rates WHERE shop_id=? ORDER BY name');$q->execute([current_user()['shop_id']]);$rates=$q->fetchAll();$states=SalesSettingsService::states();$this->page('sales/settings',compact('settings','rates','states','notice','error'),'Tax & Invoice Settings','Settings');
 }
}
