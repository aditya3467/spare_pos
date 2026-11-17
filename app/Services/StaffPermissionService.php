<?php
namespace App\Services;
use App\Core\Database;
use DomainException;
use Throwable;
final class StaffPermissionService {
    public function update(array $owner, int $staffId, array $permissions): void {
        if ($owner['role'] !== 'shop_owner' || !valid_permissions($permissions)) throw new DomainException('Access denied.');
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND shop_id = ? AND role = 'staff' FOR UPDATE");
            $stmt->execute([$staffId, $owner['shop_id']]);
            if (!$stmt->fetch()) throw new DomainException('Access denied.');
            $pdo->prepare('DELETE FROM staff_permissions WHERE user_id = ?')->execute([$staffId]);
            $stmt = $pdo->prepare('INSERT INTO staff_permissions (user_id, permission) VALUES (?, ?)');
            foreach (array_unique($permissions) as $permission) $stmt->execute([$staffId, $permission]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}
