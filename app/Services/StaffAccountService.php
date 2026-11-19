<?php
namespace App\Services;
use DomainException;
use PDOException;
use Throwable;
final class StaffAccountService {
 private function owner(array $actor):int {if(($actor['role']??'')!=='shop_owner' || (int)($actor['shop_id']??0)<1)throw new DomainException('Shop owner access required.');return (int)$actor['shop_id'];}
 private function identity(array $d):array {
  $name=$d['name']??null;$email=$d['email']??null;
  if(!is_string($name) || trim($name)==='' || mb_strlen(trim($name))>120)throw new DomainException('Enter a staff name up to 120 characters.');
  if(!is_string($email) || strlen(trim($email))>190 || !filter_var(trim($email),FILTER_VALIDATE_EMAIL))throw new DomainException('Enter a valid login email address.');
  return [trim($name),strtolower(trim($email))];
 }
 private function password(array $d):string {
  $password=$d['password']??null;$confirmation=$d['password_confirmation']??null;
  if(!is_string($password) || strlen($password)<12 || strlen($password)>72)throw new DomainException('Use a password between 12 and 72 bytes.');
  if(!is_string($confirmation) || !hash_equals($password,$confirmation))throw new DomainException('The password confirmation does not match.');
  return password_hash($password,PASSWORD_DEFAULT);
 }
 public function get(array $actor,int $id,bool $lock=false):array {
  $shop=$this->owner($actor);$q=db()->prepare("SELECT id,name,email,active,last_login_at FROM users WHERE shop_id=? AND id=? AND role='staff'".($lock?' FOR UPDATE':''));$q->execute([$shop,$id]);$staff=$q->fetch();if(!$staff)throw new DomainException('Staff account not found in your shop.');return $staff;
 }
 public function save(array $actor,string $action,array $d):int {
  $shop=$this->owner($actor);if(!in_array($action,['create','edit','reset','status'],true))throw new DomainException('Choose a valid staff action.');
  $id=$action==='create'?null:ProductService::optionalId($d['staff_id']??'');if($action!=='create' && !$id)throw new DomainException('Choose a staff account.');
  if(in_array($action,['create','edit'],true))[$name,$email]=$this->identity($d);
  if(in_array($action,['create','reset'],true))$hash=$this->password($d);
  if($action==='status' && !in_array($d['active']??null,['0','1'],true))throw new DomainException('Choose an account status.');
  if(in_array($action,['reset','status'],true) && ($d['confirmed']??'')!=='1')throw new DomainException('Confirm this account change before saving.');
  $pdo=db();$pdo->beginTransaction();try{
   if($action==='create'){
    $pdo->prepare("INSERT INTO users(shop_id,name,email,password_hash,role,active) VALUES(?,?,?,?,'staff',1)")->execute([$shop,$name,$email,$hash]);$id=(int)$pdo->lastInsertId();
   }else{
    $staff=$this->get($actor,$id,true);
    if($action==='edit'){$changed=strcasecmp($staff['email'],$email)!==0;$pdo->prepare("UPDATE users SET name=?,email=?,session_version=session_version+? WHERE shop_id=? AND id=? AND role='staff'")->execute([$name,$email,$changed?1:0,$shop,$id]);}
    elseif($action==='reset')$pdo->prepare("UPDATE users SET password_hash=?,session_version=session_version+1 WHERE shop_id=? AND id=? AND role='staff'")->execute([$hash,$shop,$id]);
    elseif((int)$staff['active']!==(int)$d['active'])$pdo->prepare("UPDATE users SET active=?,session_version=session_version+1 WHERE shop_id=? AND id=? AND role='staff'")->execute([(int)$d['active'],$shop,$id]);
   }
   $pdo->commit();return $id;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException && $e->getCode()==='23000' && (int)($e->errorInfo[1]??0)===1062)throw new DomainException('This login email is already registered. Choose another email.');throw $e;}
 }
}
