<?php
function permissions(): array { return require BASE_PATH . '/config/permissions.php'; }
function can(array $user, string $permission): bool {
    if (!array_key_exists($permission, permissions())) return false;
    if (in_array($user['role'], ['superadmin', 'shop_owner'], true)) return true;
    $stmt = db()->prepare('SELECT 1 FROM staff_permissions WHERE user_id = ? AND permission = ?');
    $stmt->execute([$user['id'], $permission]);
    return (bool)$stmt->fetchColumn();
}
