<?php
declare(strict_types=1);
if (getenv('RUN_MYSQL_TESTS')!=='1') { fwrite(STDERR,"Run with RUN_MYSQL_TESTS=1 against the configured local test database. Only generated test shops are modified.\n");exit(1); }
require dirname(__DIR__).'/app/bootstrap.php';
use App\Services\CategoryService;
$pdo=db();$shops=[];$checks=0;
function check(bool $condition,string $message): void { global $checks;if (!$condition) throw new RuntimeException($message);$checks++; }
function rejects(callable $action,string $message): void {
    try { $action(); } catch (DomainException|PDOException $e) { check(true,$message);return; }
    throw new RuntimeException($message);
}
function row(string $table,int $id): array { $stmt=db()->prepare("SELECT * FROM $table WHERE id=?");$stmt->execute([$id]);return $stmt->fetch(); }
function category(string $name,int $order=10,string $status='active'): array { return ['name'=>$name,'sort_order'=>$order,'status'=>$status,'description'=>'Test description']; }
function product(int $shop,int $category,?int $sub,int $unit,string $sku): int {
    $stmt=db()->prepare('INSERT INTO products (shop_id,category_id,subcategory_id,base_unit_id,name,sku) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$shop,$category,$sub,$unit,$sku,$sku]);return (int)db()->lastInsertId();
}
try {
    foreach ([1,2] as $i) { $pdo->prepare('INSERT INTO shops (name) VALUES (?)')->execute(['Category integration test '.bin2hex(random_bytes(6))]);$shops[]=(int)$pdo->lastInsertId(); }
    [$shop,$other]=$shops;$service=new CategoryService($shop);$otherService=new CategoryService($other);
    $service->seed();$service->seed();
    $initial=$service->all();
    check(count($initial)===10,'Seed must create exactly ten main categories.');
    check(array_sum(array_map(fn($c)=>count($c['subcategories']),$initial))===39,'Seed must create exactly 39 subcategories.');
    $seedId=(int)$initial[0]['id'];
    $service->save('category',$seedId,category($initial[0]['name'],900,'inactive'));
    $service->seed();
    check(row('categories',$seedId)['status']==='inactive' && (int)row('categories',$seedId)['sort_order']===900,'Seeding must preserve owner changes to status and order.');
    check(row('categories',$seedId)['description']==='Test description','Seeding must preserve existing descriptions.');
    $a=$service->save('category',null,category('Test A',100));$b=$service->save('category',null,category('Test B',110));
    $foreign=$otherService->save('category',null,category('Other category'));
    check(row('categories',$a)['slug']==='test-a','Slug must generate automatically.');
    rejects(fn()=>$service->save('category',null,category('test a')),'Duplicate category names must be rejected case-insensitively.');
    rejects(fn()=>$service->save('category',null,category('Different')+['slug'=>'test-a']),'Duplicate category slug must be rejected.');
    $service->save('category',$a,category('Test A updated',120,'inactive'));
    check(row('categories',$a)['name']==='Test A updated' && row('categories',$a)['status']==='inactive','Editing must persist name, description, status and order.');
    rejects(fn()=>$service->validateProductCategory($a),'Inactive category must not accept new product assignments.');
    $service->save('category',$a,category('Test A',100));
    $subA=$service->save('subcategory',null,category('Shared name',10)+['category_id'=>$a]);
    $subB=$service->save('subcategory',null,category('Shared name',10)+['category_id'=>$b]);
    check($subA!==$subB,'Same subcategory name is valid in different parents.');
    rejects(fn()=>$service->save('subcategory',null,category('shared name')+['category_id'=>$a]),'Duplicate sibling names must be rejected.');
    rejects(fn()=>$service->save('subcategory',null,category('Orphan')+['category_id'=>999999999]),'Invalid parent must be rejected.');
    rejects(fn()=>$service->save('subcategory',null,category('Cross shop')+['category_id'=>$foreign]),'Cross-shop parents must be rejected.');
    rejects(fn()=>$service->save('category',$foreign,category('Attempted overwrite')),'Cross-shop edits must fail.');
    rejects(fn()=>$service->save('subcategory',$subA,category('Shared name')+['category_id'=>$b]),'Editing must not change parent without reassignment.');
    $second=$service->save('subcategory',null,category('Second',20)+['category_id'=>$a]);
    $service->reorder('subcategory',$second,'up');
    check((int)row('subcategories',$second)['sort_order']<(int)row('subcategories',$subA)['sort_order'],'Reorder must move within siblings.');
    $service->reorder('category',$b,'up');
    check((int)row('categories',$b)['sort_order']<(int)row('categories',$a)['sort_order'],'Main category reorder must persist.');
    $service->save('subcategory',$second,category('Second edited',25,'inactive')+['category_id'=>$a]);
    check(row('subcategories',$second)['status']==='inactive','Subcategory editing/deactivation must persist.');
    rejects(fn()=>$service->validateProductCategory($a,$second),'Inactive subcategories must not accept assignments.');
    rejects(fn()=>$service->validateProductCategory($a,$subB),'Invalid parent/subcategory pair must fail application validation.');
    $service->validateProductCategory($a,$subA);check(true,'Valid category relationship must pass.');
    $pdo->prepare('INSERT INTO units (shop_id,name,code) VALUES (?,?,?)')->execute([$shop,'Piece','PCS']);$unit=(int)$pdo->lastInsertId();
    $p1=product($shop,$a,$subA,$unit,'P1');$p2=product($shop,$a,null,$unit,'P2');
    rejects(fn()=>product($shop,$a,$subB,$unit,'BAD-PAIR'),'Database must reject invalid subcategory-parent pair.');
    rejects(fn()=>product($shop,$foreign,null,$unit,'BAD-SHOP'),'Database must reject cross-shop category references.');
    rejects(fn()=>product($shop,999999999,null,$unit,'ORPHAN'),'Database must reject missing category references.');
    rejects(fn()=>$pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$a]),'Foreign keys must prevent direct deletion of referenced categories.');
    rejects(fn()=>$pdo->prepare('DELETE FROM subcategories WHERE id=?')->execute([$subA]),'Foreign keys must prevent direct deletion of referenced subcategories.');
    $found=array_values(array_filter($service->all(),fn($c)=>(int)$c['id']===$a))[0];
    check((int)$found['product_count']===2 && (int)$found['direct_product_count']===1,'Category counts must include direct and subcategory products.');
    rejects(fn()=>$service->archiveSubcategory($subA),'Referenced subcategory cannot archive without reassignment.');
    rejects(fn()=>$service->archiveSubcategory($subA,$a,$subB),'Reassignment must validate the destination pair.');
    rejects(fn()=>$service->archiveSubcategory($subA,$other,$foreign),'Cross-shop reassignment must fail.');
    $service->archiveSubcategory($subA,$b,$subB);
    check((int)row('products',$p1)['subcategory_id']===$subB && (int)row('products',$p1)['category_id']===$b,'Product reassignment must update both category fields.');
    check(row('subcategories',$subA)['archived_at']!==null,'Deletion must archive the original subcategory.');
    rejects(fn()=>$service->archiveCategory($a),'Category with remaining children or direct products cannot archive.');
    $service->archiveSubcategory($second);
    rejects(fn()=>$service->archiveCategory($a),'Direct products alone must still restrict category deletion.');
    $service->mergeCategory($a,$b);
    check(row('categories',$a)['archived_at']!==null && (int)row('products',$p2)['category_id']===$b && row('products',$p2)['subcategory_id']===null,'Merge must reassign direct products and archive source.');
    $c=$service->save('category',null,category('Merge source'));$d=$service->save('category',null,category('Merge target'));
    $cs=$service->save('subcategory',null,category('Matching')+['category_id'=>$c]);$ds=$service->save('subcategory',null,category('Matching')+['category_id'=>$d]);
    $copy=$service->save('subcategory',null,category('Copy me')+['category_id'=>$c]);
    $p3=product($shop,$c,$cs,$unit,'P3');$p4=product($shop,$c,$copy,$unit,'P4');
    $preview=$service->mergePreview($c,$d);
    check($preview['products']===2 && $preview['subcategories']===2,'Merge preview must report affected real product and subcategory counts.');
    check($preview['rows'][0]['action']==='Merge matching name' && $preview['rows'][1]['action']==='Move into destination','Preview must distinguish matching names from subcategories to move.');
    check((int)row('products',$p3)['category_id']===$c,'Preview must not mutate product references.');
    rejects(fn()=>$service->mergePreview($c,$c),'Preview must reject the source as destination.');
    rejects(fn()=>$service->mergePreview($c,$foreign),'Preview must reject cross-shop destinations.');
    $service->mergeCategory($c,$d);
    check((int)row('products',$p3)['subcategory_id']===$ds,'Matching subcategories must merge.');
    $newSub=row('subcategories',(int)row('products',$p4)['subcategory_id']);
    check((int)$newSub['category_id']===$d && $newSub['name']==='Copy me','Other subcategories must copy safely into the destination.');
    check(row('subcategories',$copy)['archived_at']!==null,'Original subcategory must remain archived for history.');
    $plain=$service->save('subcategory',null,category('Make direct')+['category_id'=>$d]);
    $p5=product($shop,$d,$plain,$unit,'P5');$service->archiveSubcategory($plain,$b);
    check((int)row('products',$p5)['category_id']===$b && row('products',$p5)['subcategory_id']===null,'Reassignment to a category without subcategory must work.');
    $empty=$service->save('category',null,category('Empty'));$service->archiveCategory($empty);
    check(row('categories',$empty)['archived_at']!==null,'Empty category must archive.');
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM products WHERE shop_id=?');$stmt->execute([$shop]);check((int)$stmt->fetchColumn()===5,'No deletion or merge may delete product records.');
    // A later conflict must roll back earlier moves in the same merge.
    $rollbackSource=$service->save('category',null,category('Rollback source'));
    $rollbackTarget=$service->save('category',null,category('Rollback target'));
    $first=$service->save('subcategory',null,category('First',10)+['category_id'=>$rollbackSource]);
    $service->save('subcategory',null,category('Conflict',20)+['category_id'=>$rollbackSource]);
    $service->save('subcategory',null,category('Conflict',20,'inactive')+['category_id'=>$rollbackTarget]);
    $rollbackProduct=product($shop,$rollbackSource,$first,$unit,'ROLLBACK');
    rejects(fn()=>$service->mergeCategory($rollbackSource,$rollbackTarget),'A conflicting destination must abort the whole merge.');
    check((int)row('products',$rollbackProduct)['category_id']===$rollbackSource && (int)row('products',$rollbackProduct)['subcategory_id']===$first,'Failed merge must roll back earlier product moves.');
    check(row('categories',$rollbackSource)['archived_at']===null && row('subcategories',$first)['archived_at']===null,'Failed merge must preserve original category structure.');
    $moveFrom=$service->save('category',null,category('Move from'));
    $moveTo=$service->save('category',null,category('Move to'));
    $moveSub=$service->save('subcategory',null,category('Moving child')+['category_id'=>$moveFrom]);
    $moveProduct=product($shop,$moveFrom,$moveSub,$unit,'MOVE');
    $service->relocateSubcategory($moveSub,$moveTo);
    $moved=row('products',$moveProduct);
    check((int)$moved['category_id']===$moveTo && row('subcategories',$moveSub)['archived_at']!==null,'Moving must reassign products and retain the source record.');
    $mergeTarget=$service->save('subcategory',null,category('Merge child target')+['category_id'=>$b]);
    $service->mergeSubcategory((int)$moved['subcategory_id'],$mergeTarget);
    check((int)row('products',$moveProduct)['subcategory_id']===$mergeTarget,'Subcategory merge must reassign products across main categories.');
    rejects(fn()=>$service->mergeSubcategory($mergeTarget,$mergeTarget),'Self merge must be rejected.');
    $service->restore('subcategory',$moveSub);
    check(row('subcategories',$moveSub)['archived_at']===null,'Archived subcategory must restore.');
    rejects(fn()=>$service->deleteSafely('subcategory',$moveSub),'Active entries cannot be permanently deleted.');
    $service->archiveSubcategory($moveSub);$service->archiveCategory($moveFrom);
    rejects(fn()=>$service->restore('subcategory',$moveSub),'Restore the parent before its child.');
    rejects(fn()=>$service->deleteSafely('category',$moveFrom),'Archived children must still restrict permanent parent deletion.');
    $service->restore('category',$moveFrom);$service->restore('subcategory',$moveSub);
    check(row('categories',$moveFrom)['status']==='active','Category restoration must activate the restored entry.');
    $service->archiveSubcategory($moveSub);$service->deleteSafely('subcategory',$moveSub);
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM subcategories WHERE id=?');$stmt->execute([$moveSub]);check(!(int)$stmt->fetchColumn(),'Empty archived subcategory may be safely deleted.');
    $service->archiveCategory($moveFrom);$service->deleteSafely('category',$moveFrom);
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM categories WHERE id=?');$stmt->execute([$moveFrom]);check(!(int)$stmt->fetchColumn(),'Empty archived main category may be safely deleted.');
    $otherService->archiveCategory($foreign);
    rejects(fn()=>$service->restore('category',$foreign),'Cross-shop restoration must fail.');
    rejects(fn()=>$service->deleteSafely('category',$foreign),'Cross-shop deletion must fail.');
    // Ledger immutability is tested inside a transaction which is rolled back.
    $pdo->prepare('INSERT INTO users (shop_id,name,email,password_hash,role) VALUES (?,?,?,?,?)')->execute([$shop,'Ledger tester','ledger-'.bin2hex(random_bytes(6)).'@test.invalid',password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'shop_owner']);$actor=(int)$pdo->lastInsertId();
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO stock_movements (shop_id,product_id,event_key,movement_type,quantity_delta,created_by) VALUES (?,?,?,'opening',1,?)")->execute([$shop,$p1,'test-ledger',$actor]);$movement=(int)$pdo->lastInsertId();
    rejects(fn()=>$pdo->prepare('UPDATE stock_movements SET quantity_delta=2 WHERE id=?')->execute([$movement]),'Ledger updates must be rejected.');
    rejects(fn()=>$pdo->prepare('DELETE FROM stock_movements WHERE id=?')->execute([$movement]),'Ledger deletions must be rejected.');
    $pdo->rollBack();
    echo "PASS: $checks MySQL category/inventory checks.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach (array_reverse($shops) as $shop) {
        foreach (['product_aliases','stock_balances','products','subcategories','categories','brands','units','tax_rates','users'] as $table) $pdo->prepare("DELETE FROM $table WHERE shop_id=?")->execute([$shop]);
        $pdo->prepare('DELETE FROM shops WHERE id=?')->execute([$shop]);
    }
}
