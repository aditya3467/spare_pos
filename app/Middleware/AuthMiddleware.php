<?php
namespace App\Middleware;
final class AuthMiddleware {
    public function handle(): void { if (!current_user()) redirect('/login'); }
}
