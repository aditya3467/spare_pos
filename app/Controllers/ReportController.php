<?php
namespace App\Controllers;
use App\Core\{Controller,Request};
use App\Services\{ReportService,ReportExportService};
use DomainException;
final class ReportController extends Controller {
 private function input():array {$f=[];foreach($_GET as $k=>$v)if(is_string($v))$f[$k]=$v;return $f;}
 private function service():ReportService {return new ReportService((int)current_user()['shop_id']);}
 public function index():void {
  $input=$this->input();$error='';try{$filters=ReportService::filters($input);}catch(DomainException $e){http_response_code(422);$error=$e->getMessage();$filters=ReportService::filters([]);}
  $tab=match(Request::route()){'/reports/sales'=>'sales','/reports/profit'=>'profit','/reports/inventory'=>'inventory',default=>'dashboard'};$r=$this->service();
  $report=$r->consistent(function()use($r,$filters,$tab){$summary=$r->summary($filters);$inventory=$r->inventorySummary();$out=compact('summary','inventory');
   if($tab==='dashboard'){$out['today']=$r->summary(ReportService::filters(['period'=>'today']));$out['month']=$r->summary(ReportService::filters(['period'=>'month']));}
   $out['trend']=$r->trend($filters);$out['payments']=$r->payments($filters);$out['top_quantity']=$r->leaders($filters);$out['top_revenue']=$r->leaders($filters,'revenue');
   $kind=match($tab){'profit'=>'products','inventory'=>'stock',default=>'invoices'};$out['listing']=$r->page($kind,$filters);$out['kind']=$kind;
   if($tab==='profit'){$out['services']=$r->page('services',array_replace($filters,['page'=>1]),10);$out['exceptions']=$r->page('exceptions',array_replace($filters,['page'=>1]),10);$out['invoice_exceptions']=$r->page('invoice_exceptions',array_replace($filters,['page'=>1]),10);}
   if($tab==='inventory'){$out['slow']=$r->leaders($filters,'quantity',true);$out['movements']=$r->movementSummary($filters);$out['movement_listing']=$r->page('movements',array_replace($filters,['page'=>1]),10);}
   return $out;
  });$categories=$r->categories();$this->page('reports/index',compact('report','filters','categories','tab','error'),'Reports & Analytics','Reports');
 }
 public function export():void {
  $input=$this->input();$file=null;
  try{$filters=ReportService::filters($input);$kind=$input['dataset']??'invoices';$format=$input['format']??'csv';$columns=ReportExportService::columns($kind);if(!in_array($format,['csv','xlsx'],true))throw new DomainException('Choose CSV or Excel export.');$r=$this->service();
   $file=$r->consistent(function()use($r,$kind,$format,$columns,$filters){$rows=$kind==='trend'?$r->trend($filters):$r->rows($kind,$filters);if($format==='xlsx')return ReportExportService::xlsx($columns,$rows);$path=tempnam(sys_get_temp_dir(),'spare-report-csv-');$stream=fopen($path,'wb');try{ReportExportService::csv($stream,$columns,$rows);fclose($stream);return $path;}catch(\Throwable $e){fclose($stream);unlink($path);throw $e;}});
   header('Content-Type: '.($format==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'text/csv; charset=UTF-8'));header('Content-Disposition: attachment; filename="'.$kind.'-'.$filters['from'].'-'.$filters['to'].'.'.$format.'"');header('Content-Length: '.filesize($file));readfile($file);
  }catch(DomainException $e){http_response_code(422);echo e($e->getMessage());}finally{if($file && file_exists($file))unlink($file);}
 }
}
