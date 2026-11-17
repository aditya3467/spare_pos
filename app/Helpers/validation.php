<?php
function valid_permissions(mixed $selected): bool {
    if (!is_array($selected)) return false;
    foreach ($selected as $permission) {
        if (!is_string($permission) || !array_key_exists($permission, permissions())) return false;
    }
    return true;
}
