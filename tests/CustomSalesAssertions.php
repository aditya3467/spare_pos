<?php
// SalesTest provisions an isolated shop and restores these settings between scenarios.
$settings->save($setup,$owner);
$inventoryState=static fn()=>db()->query('SELECT (SELECT COUNT(*) FROM products), (SELECT COUNT(*) FROM stock_movements), (SELECT SUM(quantity) FROM stock_balances)')->fetch(PDO::FETCH_NUM);
$before=$inventoryState();
$line=['name'=>'Special order pad','unit_cost'=>'30','quantity'=>'2','price'=>'118','hsn_code'=>'8708','unit'=>'piece','tax_rate_id'=>(string)$tax];
foreach (['236','50','0'] as $received) {
    $customBill=['custom'=>[$line,array_replace($line,['name'=>'Included fitting kit','price'=>'0','unit_cost'=>'0'])],
        'customer_id'=>$received==='236'?'':(string)$customer,'confirmed'=>'1',
        'payments'=>$received==='0'?[]:[['method'=>'cash','amount'=>$received]]];
    $prior=$report->summary($dates);
    $quoted=$sales->quote($customBill,$owner);
    salecheck($quoted['total']==='236.00' && $quoted['taxable']==='200.00','Custom goods respect inclusive GST');
    $customBill['quote_hash']=$quoted['quote_hash'];
    $posted=$sales->complete($customBill,$owner,bin2hex(random_bytes(20)));
    $sale=$sales->get($posted);
    salecheck(count($sale['custom'])===2 && !$sale['items'] && $sale['balance_due']===bcsub('236',$received,2),'Custom snapshots and payment balance');
    $analytics=(new App\Services\ReportService(1))->summary(App\Services\ReportService::filters(['period'=>'today']));
    salecheck($analytics['custom_revenue']==='200.00' && $analytics['custom_cost']==='60.00' && $analytics['custom_profit']==='140.00','Analytics separate custom goods revenue and snapshot cost');
    $after=$report->summary($dates);
    salecheck(bcsub($after['gross_profit'],$prior['gross_profit'],2)==='140.00' && bcsub($after['custom_cost'],$prior['custom_cost'],2)==='60.00','Custom profit uses posted CP and excludes GST');
    salecheck($sales->history(['q'=>$sale['invoice_number']])['rows'][0]['item_count']===2,'Custom history count');
    $printed=(static function($sale,$root){ob_start();require $root.'/resources/views/sales/document.php';return ob_get_clean();})($sale,$root);
    salecheck(str_contains($printed,'Special order pad') && str_contains($printed,'Included fitting kit'),'Custom items appear on invoice');
    $sales->cancel($posted,$cancel,$owner);
    salecheck($report->summary($dates)['custom_profit']===$prior['custom_profit'] && count($sales->get($posted)['custom'])===2,'Custom cancellation preserves history and reverses profit');
}
salecheck($inventoryState()===$before,'Custom billing and cancellation preserve catalogue and stock');
