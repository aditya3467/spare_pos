<?php
namespace App\Services;
use DomainException;
use ZipArchive;
final class ReportExportService {
 public static function columns(string $kind):array {return match($kind){
  'invoices','invoice_exceptions'=>['invoice_number'=>['Invoice','text'],'created_at'=>['Date','text'],'customer'=>['Customer','text'],'total'=>['Invoice total incl GST','number'],'taxable'=>['Revenue excl GST','number'],'discount'=>['Discount','number'],'amount_paid'=>['Applied payment','number'],'balance_due'=>['Original balance due','number'],'status'=>['Status','text']],
  'exceptions'=>['invoice_number'=>['Invoice','text'],'id'=>['Invoice line ID','number'],'name'=>['Product','text'],'sku'=>['SKU','text'],'quantity'=>['Sold quantity','number'],'unit_cost'=>['Recorded unverified cost','number'],'movement_id'=>['Original movement ID','number'],'reason'=>['Exception','text']],
  'products'=>['name'=>['Product','text'],'sku'=>['SKU','text'],'quantity'=>['Quantity sold','number'],'revenue'=>['Revenue excl GST','number'],'cogs'=>['Recorded COGS','number'],'profit'=>['Gross profit','number'],'margin'=>['Margin %','number'],'missing_cost_lines'=>['Cost/revenue exceptions','number']],
  'services'=>['invoice_number'=>['Invoice','text'],'created_at'=>['Date','text'],'description'=>['Labour/service','text'],'revenue'=>['Revenue / gross profit excl GST','number'],'total'=>['Billed incl GST','number']],
  'stock'=>['name'=>['Product','text'],'sku'=>['SKU','text'],'category'=>['Category','text'],'brand'=>['Brand','text'],'status'=>['Product status','text'],'archived_at'=>['Archived at','text'],'quantity'=>['Current stock','number'],'average_unit_cost'=>['Current carrying cost','number'],'stock_value'=>['Current stock value','number'],'reorder_level'=>['Minimum stock','number'],'stock_status'=>['Stock status','text'],'sold_quantity'=>['Quantity sold in period','number'],'last_sale'=>['Last sale in period','text']],
  'movements'=>['id'=>['Movement ID','number'],'name'=>['Product','text'],'sku'=>['SKU','text'],'movement_type'=>['Movement','text'],'quantity_delta'=>['Quantity change','number'],'unit_cost'=>['Recorded carrying cost','number'],'reason'=>['Reason','text'],'reference_type'=>['Reference type','text'],'reference_id'=>['Reference','text'],'entry_date'=>['Entry date','text'],'created_at'=>['Posted at','text'],'previous_quantity'=>['Previous quantity','number'],'resulting_quantity'=>['Resulting quantity','number']],
  'payments'=>['invoice_number'=>['Invoice','text'],'payment_date'=>['Payment date','text'],'method'=>['Method','text'],'amount'=>['Applied amount / negative refund','number'],'received'=>['Received','number'],'change_returned'=>['Cash change','number'],'reference'=>['Reference','text'],'reverses_payment_id'=>['Reverses payment ID','number']],
  'trend'=>['period'=>['Period starting','text'],'invoices'=>['Invoice count','number'],'sales'=>['Sales incl GST','number'],'revenue'=>['Revenue excl GST','number']],
  default=>throw new DomainException('Choose a valid export dataset.')};}
 public static function csvCell(mixed $value,string $type):string {$v=(string)($value??'');if($type==='text' && preg_match('/^[\s]*[=+@\-]/u',$v))$v="'".$v;return $v;}
 public static function csv($stream,array $columns,iterable $rows):void {
  fwrite($stream,"\xEF\xBB\xBF");fputcsv($stream,array_column(array_values($columns),0),',','"','');
  foreach($rows as $row){$cells=[];foreach($columns as $field=>[$label,$type])$cells[]=self::csvCell($row[$field]??null,$type);fputcsv($stream,$cells,',','"','');}
 }
 private static function xml(string $v):string {return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',$v),ENT_XML1|ENT_QUOTES,'UTF-8');}
 private static function letter(int $n):string {$s='';for($n++;$n>0;$n=intdiv($n-1,26))$s=chr(65+($n-1)%26).$s;return $s;}
 public static function xlsx(array $columns,iterable $rows):string {
  if(!class_exists(ZipArchive::class))throw new DomainException('Excel export requires the PHP ZIP extension. CSV export is available.');
  $sheet=tempnam(sys_get_temp_dir(),'spare-report-sheet-');$file=tempnam(sys_get_temp_dir(),'spare-report-xlsx-');$fp=null;$zip=new ZipArchive();
  try{
   $fp=fopen($sheet,'wb');fwrite($fp,'<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><sheetData>');$index=0;
   $write=function(array $row,bool $header=false)use(&$index,$fp,$columns){$index++;if($index>1048576)throw new DomainException('Excel row limit reached. Export a smaller date range.');fwrite($fp,'<row r="'.$index.'">');$c=0;foreach($columns as $key=>[$label,$type]){$value=$header?$label:($row[$key]??null);$ref=self::letter($c++).$index;if($value===null){fwrite($fp,'<c r="'.$ref.'"/>');continue;}$numeric=!$header && $type==='number' && preg_match('/^-?[0-9]+(?:[.][0-9]+)?$/D',(string)$value);fwrite($fp,$numeric?'<c r="'.$ref.'"><v>'.self::xml((string)$value).'</v></c>':'<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml((string)$value).'</t></is></c>');}fwrite($fp,'</row>');};
   $write([],true);foreach($rows as $row)$write($row);fwrite($fp,'</sheetData><autoFilter ref="A1:'.self::letter(count($columns)-1).max(1,$index).'"/></worksheet>');fclose($fp);$fp=null;
   if($zip->open($file,ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Could not create Excel export.');
   $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
   $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
   $zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
   $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');$zip->addFile($sheet,'xl/worksheets/sheet1.xml');$zip->close();unlink($sheet);return $file;
  }catch(\Throwable $e){if(is_resource($fp))fclose($fp);if(file_exists($sheet))unlink($sheet);if(file_exists($file))unlink($file);throw $e;}
 }
}
