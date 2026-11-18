<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/app/bootstrap.php';
use App\Services\CategoryService;
$pdo=db();
if (($argv[1] ?? '')==='create') {
    $token=bin2hex(random_bytes(8));$pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO shops (name) VALUES (?)')->execute(['HTTP workspace fixture '.$token]);$shop=(int)$pdo->lastInsertId();$accounts=[];
        foreach (['shop_owner','staff','superadmin'] as $role) {
            $email='http-'.$token.'-'.$role.'@test.invalid';$password=bin2hex(random_bytes(20));
            $pdo->prepare('INSERT INTO users (shop_id,name,email,password_hash,role) VALUES (?,?,?,?,?)')->execute([$role==='superadmin'?null:$shop,'HTTP '.$role,$email,password_hash($password,PASSWORD_DEFAULT),$role]);
            $accounts[$role]=['id'=>(int)$pdo->lastInsertId(),'email'=>$email,'password'=>$password];
        }
        $pdo->commit();(new CategoryService($shop))->seed();
        echo json_encode(['token'=>$token,'shop_id'=>$shop,'accounts'=>$accounts]);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();throw $e; }
} elseif (($argv[1] ?? '')==='cleanup') {
    $fixture=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$token=$fixture['token'];$shop=(int)$fixture['shop_id'];
    if (!preg_match('/^[a-f0-9]{16}$/D',$token)) throw new RuntimeException('Invalid fixture token.');
    $check=$pdo->prepare('SELECT name FROM shops WHERE id=?');$check->execute([$shop]);
    if ($check->fetchColumn()!=='HTTP workspace fixture '.$token) throw new RuntimeException('Fixture ownership check failed.');
    $check=$pdo->prepare('SELECT COUNT(*) FROM products WHERE shop_id=?');$check->execute([$shop]);
    if ($check->fetchColumn()) throw new RuntimeException('Cleanup refused: fixture contains products.');
    $pdo->beginTransaction();
    try {
        foreach (['subcategories','categories'] as $table) $pdo->prepare("DELETE FROM $table WHERE shop_id=?")->execute([$shop]);
        $pdo->prepare('DELETE FROM users WHERE email LIKE ?')->execute(['http-'.$token.'-%@test.invalid']);
        $pdo->prepare('DELETE FROM shops WHERE id=?')->execute([$shop]);$pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack();throw $e; }
} else { fwrite(STDERR,"Use create or cleanup.\n");exit(1); }
