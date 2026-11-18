<?php
namespace App\Core;
use App\Models\Shop;
abstract class Controller {
    protected function view(string $name, array $data = []): void {
        extract($data, EXTR_SKIP);
        require BASE_PATH . '/resources/views/' . $name . '.php';
    }
    protected function render(string $name, array $data = []): string {
        ob_start();
        try { $this->view($name, $data); return ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); throw $e; }
    }
    protected function page(string $name, array $data, string $title, string $section): void {
        $user=$data['user'] ?? current_user();
        $shopName=Shop::name($user['shop_id'] ? (int)$user['shop_id'] : null);
        $data+=compact('user','shopName');
        $content=$this->render($name,$data);
        $this->view('layouts/app',compact('content','user','shopName','title','section'));
    }
}
