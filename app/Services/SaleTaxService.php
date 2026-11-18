<?php
namespace App\Services;
use DomainException;
final class SaleTaxService {
 public static function round(string $n):string {return bcadd($n,bccomp($n,'0',8)<0?'-0.005':'0.005',2);}
 public static function amount(mixed $n,string $label):string {return ProductService::decimal($n,2,false,$label);}
 // Discounts are fixed currency amounts in the configured price basis, allocated before GST.
 public static function calculate(array $lines,string $billDiscount,array $settings,string $place):array {
  $subtotal='0.00';$lineDiscount='0.00';$net='0.00';
  foreach($lines as &$l){$l['gross']=self::round(bcmul($l['price'],$l['quantity'],8));if(bccomp($l['discount'],$l['gross'],2)>0)throw new DomainException('Line discount exceeds the line value.');$l['net']=bcsub($l['gross'],$l['discount'],2);$subtotal=bcadd($subtotal,$l['gross'],2);$lineDiscount=bcadd($lineDiscount,$l['discount'],2);$net=bcadd($net,$l['net'],2);}unset($l);
  if(bccomp($billDiscount,$net,2)>0)throw new DomainException('Bill discount exceeds the remaining cart value.');
  $remaining=$billDiscount;$remainingBase=$net;$totals=['subtotal'=>$subtotal,'discount'=>bcadd($lineDiscount,$billDiscount,2),'taxable'=>'0.00','cgst'=>'0.00','sgst'=>'0.00','igst'=>'0.00','total'=>'0.00'];
  foreach($lines as &$l){
   $allocation=bccomp($remainingBase,'0',2)>0?self::round(bcdiv(bcmul($remaining,$l['net'],8),$remainingBase,8)):'0.00';if(bccomp($allocation,$remaining,2)>0)$allocation=$remaining;if(bccomp($allocation,$l['net'],2)>0)$allocation=$l['net'];$remaining=bcsub($remaining,$allocation,2);$remainingBase=bcsub($remainingBase,$l['net'],2);$l['bill_discount']=$allocation;$value=bcsub($l['net'],$allocation,2);$regular=$settings['registration_type']==='regular';$rate=$regular?$l['tax_rate']:'0.00';
   if($regular && $rate===null)throw new DomainException('Configure a product or default tax rate for '.$l['name'].'.');
   $l['tax_rate']=$rate;$l['taxable']=$regular && $settings['price_mode']==='inclusive'?self::round(bcdiv(bcmul($value,'100',8),bcadd('100',$rate,4),8)):$value;
   $tax=$regular?($settings['price_mode']==='inclusive'?bcsub($value,$l['taxable'],2):self::round(bcdiv(bcmul($l['taxable'],$rate,8),'100',8))):'0.00';
   $l['cgst']=$l['sgst']=$l['igst']='0.00';if($place===$settings['state_code']){$l['cgst']=self::round(bcdiv($tax,'2',8));$l['sgst']=bcsub($tax,$l['cgst'],2);}else $l['igst']=$tax;
   $l['total']=bcadd($l['taxable'],$tax,2);foreach(['taxable','cgst','sgst','igst','total'] as $k)$totals[$k]=bcadd($totals[$k],$l[$k],2);
  }unset($l);if(bccomp($totals['total'],'9999999999999.99',2)>0)throw new DomainException('Invoice total exceeds the supported amount.');return ['lines'=>$lines]+$totals;
 }
}
