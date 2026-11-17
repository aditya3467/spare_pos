<?php
namespace App\Middleware;
final class CsrfMiddleware {
    public function handle(): void {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf(), $_POST['csrf'])) {
            http_response_code(403); exit('Your session expired. Please reload the page and try again.');
        }
    }
}
