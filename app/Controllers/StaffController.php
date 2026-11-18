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
