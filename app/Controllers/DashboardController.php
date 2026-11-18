<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Services\DashboardService;
use DateTimeImmutable;
use DateTimeZone;
final class DashboardController extends Controller {
    public function index(): void {
        $user=current_user();
        $days=filter_var($_GET['days'] ?? 7,FILTER_VALIDATE_INT);
        if (!in_array($days,[7,30,90],true)) $days=7;
        $dashboard=(new DashboardService())->overview($user,$days);
        $date=new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata'));
        $this->page('dashboard/index',compact('user','dashboard','date','days'),'Dashboard','Overview');
    }
    // Retained for code calling the original controller action.
    public function permissions(): void { (new StaffController())->permissions(); }
}
