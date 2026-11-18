<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Services\ProductImportService;
use DomainException;
final class ProductImportController extends Controller {
 private function allowed():void {if(!can(current_user(),'products.import')){http_response_code(403);exit('Import permission required.');}}
 private function path(string $token):string {if(!preg_match('/^[a-f0-9]{40}$/D',$token))throw new DomainException('Invalid import token.');return BASE_PATH.'/storage/imports/product-'.$token.'.json';}
 private function stage(string $token):array {
  $stage=json_decode(@file_get_contents($this->path($token))?:'null',true);
  if(!$stage || (int)$stage['shop']!==(int)current_user()['shop_id'] || (int)$stage['user']!==(int)current_user()['id'] || time()-$stage['created']>7200)throw new DomainException('Import preview expired or belongs to another user.');return $stage;
 }
 public function index():void {
  $this->allowed();$error='';$stage=null;$token=is_string($_GET['token']??null)?$_GET['token']:'';try{if($token)$stage=$this->stage($token);}catch(DomainException $e){$error=$e->getMessage();}$this->page('products/import',compact('stage','token','error'),'Import Products','Catalogue');
 }
 public function preview():void {
  $this->allowed();$stage=null;$token='';$error='';
  try{$file=$_FILES['file']??null;if(!$file || $file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']))throw new DomainException('Choose a valid CSV/XLSX upload (maximum 5 MB).');$compatibility=($_POST['kind']??'')==='compatibility';$rows=ProductImportService::read($file['tmp_name'],strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)),$compatibility);if(!$rows)throw new DomainException('No product rows found.');$token=bin2hex(random_bytes(20));$stage=['shop'=>current_user()['shop_id'],'user'=>current_user()['id'],'created'=>time(),'kind'=>$compatibility?'compatibility':'products','rows'=>$compatibility?(new ProductImportService((int)current_user()['shop_id']))->previewCompatibility($rows,current_user()):(new ProductImportService((int)current_user()['shop_id']))->preview($rows,current_user()),'results'=>null];file_put_contents($this->path($token),json_encode($stage),LOCK_EX);chmod($this->path($token),0600);header('Location: '.url('/products/import').'&token='.$token);exit;}catch(DomainException $e){http_response_code(422);$error=$e->getMessage();}$this->page('products/import',compact('stage','token','error'),'Import Products','Catalogue');
 }
 public function commit():void {
  $this->allowed();$token=is_string($_POST['token']??null)?$_POST['token']:'';
  try{$stage=$this->stage($token);if($stage['results']===null){$importer=new ProductImportService((int)current_user()['shop_id']);$stage['results']=($stage['kind']??'products')==='compatibility'?$importer->commitCompatibility($stage['rows'],current_user()): (new ProductImportService((int)current_user()['shop_id']))->commit($stage['rows'],current_user(),$token,($_POST['confirm_duplicates']??'')==='1');file_put_contents($this->path($token),json_encode($stage),LOCK_EX);}header('Location: '.url('/products/import').'&token='.$token);exit;}catch(DomainException $e){http_response_code(422);$error=$e->getMessage();$stage=$stage??null;$this->page('products/import',compact('stage','token','error'),'Import Products','Catalogue');}
 }
 public function template():void {
  $this->allowed();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="product-import-template.csv"');$fp=fopen('php://output','w');fputcsv($fp,($_GET['kind']??'')==='compatibility'?ProductImportService::FITMENT_HEADERS:ProductImportService::HEADERS,',','"','');fclose($fp);
 }
 public function report():void {
  $this->allowed();try{$s=$this->stage(is_string($_GET['token']??null)?$_GET['token']:'');}catch(DomainException $e){http_response_code(404);echo e($e->getMessage());return;}
  header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="product-import-report.csv"');$fp=fopen('php://output','w');fputcsv($fp,['Row','Name','Result','Errors / warnings'],',','"','');foreach($s['results']??$s['rows'] as $r){$name=$r['name'];if(preg_match('/^[=+@\-\t\r]/',$name))$name="'".$name;fputcsv($fp,[$r['row'],$name,$r['errors']?'Rejected':(isset($r['id'])?'Imported':'Valid'),implode('; ',[...$r['errors'],...($r['warnings']??[])])],',','"','');}fclose($fp);
 }
}
