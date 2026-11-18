<?php
namespace App\Models;
use App\Core\Model;
final class Shop extends Model {
    public static function name(?int $id): string {
        if (!$id) return 'Platform administration';
        $stmt=self::db()->prepare('SELECT name FROM shops WHERE id=?');$stmt->execute([$id]);
        return $stmt->fetchColumn() ?: 'Shop';
    }
}
