<?php
namespace App\Models;
use App\Core\Model;
final class User extends Model {
    public static function staff(int $shopId): array {
        $stmt = self::db()->prepare("SELECT id, name, email, active, last_login_at FROM users WHERE shop_id = ? AND role = 'staff' ORDER BY name");
        $stmt->execute([$shopId]);
        $staff = $stmt->fetchAll();
        $permissions = self::db()->prepare('SELECT permission FROM staff_permissions WHERE user_id = ?');
        foreach ($staff as &$member) {
            $permissions->execute([$member['id']]);
            $member['permissions'] = $permissions->fetchAll(\PDO::FETCH_COLUMN);
        }
        return $staff;
    }
}
