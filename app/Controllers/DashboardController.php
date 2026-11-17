<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Models\User;
use App\Services\StaffPermissionService;
use DomainException;
final class DashboardController extends Controller {
    public function index(): void {
        $user = current_user();
        $staff = $user['role'] === 'shop_owner' ? User::staff((int)$user['shop_id']) : [];
        $notice = $_SESSION['notice'] ?? ''; unset($_SESSION['notice']);
        $message = '';
        $role = ['superadmin' => 'Superadmin', 'shop_owner' => 'Shop owner', 'staff' => 'Staff'][$user['role']];
        $this->view('dashboard/index', compact('user', 'staff', 'notice', 'message', 'role'));
    }
    public function permissions(): void {
        $id = filter_var($_POST['staff_id'] ?? '', FILTER_VALIDATE_INT);
        $selected = $_POST['permissions'] ?? [];
        if (!$id || !valid_permissions($selected)) { http_response_code(422); echo 'Invalid staff or permissions.'; return; }
        try { (new StaffPermissionService())->update(current_user(), $id, $selected); }
        catch (DomainException $exception) { http_response_code(403); echo 'Access denied.'; return; }
        $_SESSION['notice'] = 'Staff permissions updated.';
        redirect('/dashboard');
    }
}
