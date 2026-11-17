<?php
namespace App\Middleware;
final class RoleMiddleware {
    public function handle(array $roles = ['shop_owner']): void {
        $user = current_user();
        if (!$user) redirect('/login');
        if (!in_array($user['role'], $roles, true)) { http_response_code(403); exit('Access denied.'); }
    }
}
