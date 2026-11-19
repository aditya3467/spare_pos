<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Models\User;
use App\Services\StaffPermissionService;
use DomainException;
final class StaffController extends Controller {
    public function index(): void {
        $user=current_user();$staff=User::staff((int)$user['shop_id']);
        $notice=$_SESSION['notice'] ?? '';unset($_SESSION['notice']);
        $this->page('settings/staff',compact('user','staff','notice'),'Staff & Permissions','Settings');
    }
    public function form():void {
        $action=is_string($_GET['action']??null)?$_GET['action']:'create';$values=[];
        try{if(!in_array($action,['create','edit','reset','status'],true))throw new DomainException('Choose a valid staff action.');if($action!=='create'){$id=\App\Services\ProductService::optionalId($_GET['staff_id']??'');if(!$id)throw new DomainException('Choose a staff account.');$values=(new \App\Services\StaffAccountService())->get(current_user(),$id);$values['staff_id']=$id;}}
        catch(DomainException $e){http_response_code(404);echo e($e->getMessage());return;}
        $error='';$this->page('settings/staff-form',compact('action','values','error'),'Manage Staff','Settings');
    }
    public function save():void {
        $action=is_string($_POST['action']??null)?$_POST['action']:'';$json=str_contains($_SERVER['HTTP_ACCEPT']??'','application/json');$error='';
        try{(new \App\Services\StaffAccountService())->save(current_user(),$action,$_POST);$message=match($action){'create'=>'Staff account created. Choose and save its permissions below.','edit'=>'Staff details updated. If the login email changed, the staff member must sign in again.','reset'=>'Password reset. Existing sessions have been signed out.','status'=>(($_POST['active']??'')==='1'?'Staff account activated. The staff member can sign in again.':'Staff account deactivated. Existing sessions have been signed out.')};
            $_SESSION['notice']=$message;
            if($json){header('Content-Type: application/json');echo json_encode(['message'=>$message,'redirect'=>url('/staff')]);return;}
            $_SESSION['notice']=$message;redirect('/staff');
        }catch(DomainException $e){http_response_code(422);$error=$e->getMessage();}catch(\Throwable $e){error_log($e->getMessage());http_response_code(500);$error='The account could not be saved. Please try again.';}
        if($json){header('Content-Type: application/json');echo json_encode(['message'=>$error]);return;}
        $values=[];foreach(['staff_id','name','email','active'] as $key)if(is_string($_POST[$key]??null))$values[$key]=$_POST[$key];
        if($action==='status')$values['status_target']=$values['active']??'0';
        if(!in_array($action,['create','edit','reset','status'],true))$action='create';$this->page('settings/staff-form',compact('action','values','error'),'Manage Staff','Settings');
    }
    public function permissions(): void {
        $json=str_contains($_SERVER['HTTP_ACCEPT'] ?? '','application/json');
        $id=filter_var($_POST['staff_id'] ?? '',FILTER_VALIDATE_INT);$selected=$_POST['permissions'] ?? [];
        $status=200;$message='Staff permissions updated.';
        if (!$id || !valid_permissions($selected)) { $status=422;$message='Choose a valid staff member and permissions.'; }
        else {
            try { (new StaffPermissionService())->update(current_user(),$id,$selected); }
            catch (DomainException $e) { $status=403;$message='Access denied.'; }
        }
        if ($json) { http_response_code($status);header('Content-Type: application/json');echo json_encode(['message'=>$message]);return; }
        if ($status!==200) { http_response_code($status);echo e($message);return; }
        $_SESSION['notice']=$message;redirect('/staff');
    }
}
