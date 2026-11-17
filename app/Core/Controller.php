<?php
namespace App\Core;
abstract class Controller {
    protected function view(string $name, array $data = []): void {
        extract($data, EXTR_SKIP);
        require BASE_PATH . '/resources/views/' . $name . '.php';
    }
}
