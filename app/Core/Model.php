<?php
namespace App\Core;
use PDO;
abstract class Model {
    protected static function db(): PDO { return Database::connection(); }
}
