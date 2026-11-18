<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/app/bootstrap.php';
$f=json_decode(file_get_contents($argv[1]),true);$shop=(int)$f['shop_id'];
$guard=db()->prepare('SELECT name FROM shops WHERE id=?');$guard->execute([$shop]);if($guard->fetchColumn()!=='HTTP workspace fixture '.$f['token'])throw new Exception('Fixture ownership check failed.');
if(($argv[2]??'')==='cleanup'){
 $q=db()->prepare('SELECT COUNT(*) FROM stock_movements WHERE shop_id=?');$q->execute([$shop]);if($q->fetchColumn())throw new Exception('Cleanup refused: ledger exists.');
 foreach(['product_fitments','product_specifications','product_aliases','stock_balances','products','vehicle_fitments','vehicle_variants','vehicle_models','vehicle_manufacturers','brands','units'] as $t)db()->prepare("DELETE FROM $t WHERE shop_id=?")->execute([$shop]);exit;
}
$master=new App\Services\ProductMasterService($shop);$master->seedVehicles();$m=$master->all();$brand=$master->save('brands',['name'=>'Search Brand']);$cat=$m['categories'][0]['id'];$sub=null;foreach($m['subcategories'] as $s)if($s['category_id']===$cat){$sub=$s['id'];break;}$model=$m['vehicle_models'][0];
$service=new App\Services\ProductService($shop);$owner=['id'=>$f['accounts']['shop_owner']['id'],'shop_id'=>$shop,'role'=>'shop_owner'];
for($i=0;$i<28;$i++){$p=['name'=>$i===0?'Needle pad':'General part '.str_pad((string)$i,2,'0',STR_PAD_LEFT),'category_id'=>$cat,'subcategory_id'=>$sub,'purchase_price'=>'10','selling_price'=>(string)(100+$i),'opening_quantity'=>'0','reorder_level'=>'5'];if($i===0){$p['part_number']='OEM-NEEDLE-99';$p['brand_id']=$brand;$p['fitments']=[['manufacturer_id'=>$model['manufacturer_id'],'model_id'=>$model['id']]];}$id=$service->save($p,$owner,null,'search-fixture-'.$i);if($i===0)$first=$service->get($id);}
file_put_contents($argv[1].'.search.json',json_encode(['category_id'=>$cat,'subcategory_id'=>$sub,'brand_id'=>$brand,'model_id'=>$model['id'],'sku'=>$first['sku']]));
