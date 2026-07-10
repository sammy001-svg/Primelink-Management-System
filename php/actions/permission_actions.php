<?php
/**
 * Staff Permissions — Action Handler
 * Admin only.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/audit.php';

// Only admins can change permissions
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Location: ../permissions.php?error=unauthorized');
    exit();
}

_ensurePermissionsTable($pdo);

$action   = $_POST['action']    ?? '';
$redirect = trim($_POST['_redirect'] ?? '../permissions.php');

if ($action === 'save') {
    $targetUserId = trim($_POST['target_user_id'] ?? '');
    if (!$targetUserId) {
        header("Location: {$redirect}?error=no_user");
        exit();
    }

    // Build the flat permission map from submitted checkboxes
    $submitted = $_POST['perms'] ?? [];  // array of "module.action" keys that are checked
    $permMap   = [];

    foreach (PERM_MODULES as $module => $actions) {
        foreach ($actions as $action_key) {
            $key = "{$module}.{$action_key}";
            $permMap[$key] = in_array($key, $submitted) ? 1 : 0;
        }
    }

    $json = json_encode($permMap);

    // Upsert
    $stmt = $pdo->prepare("
        INSERT INTO staff_permissions (user_id, permissions, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE permissions = VALUES(permissions), updated_by = VALUES(updated_by)
    ");
    $stmt->execute([$targetUserId, $json, $_SESSION['user_id']]);

    logAction($pdo, 'permissions_updated', 'Users', $targetUserId, 'Permissions updated by admin');

    // Clear permission cache for the target user (if they're currently logged in,
    // their next request will reload from DB).
    // We can't directly clear another user's session, but marking the update time
    // is enough — their session will expire or they can re-login.

    header("Location: {$redirect}?user_id={$targetUserId}&success=saved");
    exit();
}

if ($action === 'reset') {
    $targetUserId = trim($_POST['target_user_id'] ?? '');
    $pdo->prepare("DELETE FROM staff_permissions WHERE user_id = ?")->execute([$targetUserId]);
    logAction($pdo, 'permissions_reset', 'Users', $targetUserId, 'Permissions reset to full access');
    header("Location: {$redirect}?user_id={$targetUserId}&success=reset");
    exit();
}

header("Location: {$redirect}");
exit();
