<?php
$manufacturerName='';foreach($bikes['vehicle_manufacturers'] as $r)if((string)$r['id']===(string)($filters['manufacturer_id']??''))$manufacturerName=$r['name'];
$modelName='';foreach($bikes['vehicle_models'] as $r)if((string)$r['id']===(string)($filters['model_id']??''))$modelName=$r['name'];
?>
<label>Bike manufacturer<input type="search" list="inventory-makers" data-bike-manufacturer-search value="<?= e($manufacturerName) ?>" placeholder="Type to find manufacturer"><input type="hidden" name="manufacturer_id" value="<?= e($filters['manufacturer_id']??'') ?>"><datalist id="inventory-makers"><?php foreach($bikes['vehicle_manufacturers'] as $r): ?><option value="<?= e($r['name']) ?>"><?php endforeach; ?></datalist></label>
<label>Compatible bike model<input type="search" list="inventory-models" data-bike-model-search value="<?= e($modelName) ?>" placeholder="Type to find model"><input type="hidden" name="model_id" value="<?= e($filters['model_id']??'') ?>"><datalist id="inventory-models"><?php foreach($bikes['vehicle_models'] as $r): ?><option value="<?= e($r['name']) ?>"><?php endforeach; ?></datalist></label>
<label>Variant (optional)<select name="variant_id"><option value="">All variants</option><?php foreach($bikes['vehicle_variants'] as $r): ?><option value="<?= (int)$r['id'] ?>" data-model="<?= (int)$r['model_id'] ?>" <?= (string)$r['id']===(string)($filters['variant_id']??'')?'selected':'' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></label>
<button type="button" class="button" data-clear-inventory-bike>Clear bike filter</button>
