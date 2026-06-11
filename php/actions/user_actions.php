<?php
/**
 * User Management Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin(['admin']);

require_once __DIR__ . '/../includes/audit.php';

$action = $_POST['action'] ?? '';

// ──────────────────────────────────────────────────────────
// CREATE
// ──────────────────────────────────────────────────────────
if ($action === 'create') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $role     = in_array($_POST['role'] ?? '', ['admin', 'staff']) ? $_POST['role'] : 'staff';
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (strlen($password) < 8) {
        header("Location: ../users.php?error=Password must be at least 8 characters");
        exit;
    }

    // Check duplicate email
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetch()) {
        header("Location: ../users.php?error=email_exists");
        exit;
    }

    $userId = generateUUID();
    $hash   = password_hash($password, PASSWORD_BCRYPT);

    $pdo->prepare("INSERT INTO users (id, email, password, role, status, created_at) VALUES (?, ?, ?, ?, 'Active', NOW())")
        ->execute([$userId, $email, $hash, $role]);

    // Create profile
    $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone) VALUES (?, ?, ?, ?)")
        ->execute([$userId, $fullName, $email, $phone]);

    logAction($pdo, 'user_created', 'Users', $userId, "{$role} — {$email}");

    header("Location: ../users.php?success=created");
    exit;
}

// ──────────────────────────────────────────────────────────
// UPDATE
// ──────────────────────────────────────────────────────────
if ($action === 'update') {
    $userId   = $_POST['user_id'] ?? '';
    $fullName = trim($_POST['full_name'] ?? '');
    $role     = in_array($_POST['role'] ?? '', ['admin', 'staff']) ? $_POST['role'] : 'staff';
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    // Prevent demoting self
    if ($userId === $_SESSION['user_id'] && $role !== 'admin') {
        header("Location: ../users.php?error=Cannot change your own role");
        exit;
    }

    $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")
        ->execute([$role, $userId]);

    $pdo->prepare("UPDATE profiles SET full_name = ?, phone = ? WHERE id = ?")
        ->execute([$fullName, $phone, $userId]);

    if (!empty($password) && strlen($password) >= 8) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
            ->execute([$hash, $userId]);
    }

    logAction($pdo, 'user_updated', 'Users', $userId, "Role: {$role}");

    header("Location: ../users.php?success=updated");
    exit;
}

// ──────────────────────────────────────────────────────────
// TOGGLE STATUS
// ──────────────────────────────────────────────────────────
if ($action === 'toggle_status') {
    $userId = $_POST['user_id'] ?? '';

    if ($userId === $_SESSION['user_id']) {
        header("Location: ../users.php?error=Cannot deactivate your own account");
        exit;
    }

    $curr = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $curr->execute([$userId]);
    $current = $curr->fetchColumn();

    $newStatus = $current === 'Active' ? 'Inactive' : 'Active';
    $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")
        ->execute([$newStatus, $userId]);

    logAction($pdo, 'user_status_changed', 'Users', $userId, "Status changed to {$newStatus}");

    header("Location: ../users.php?success=status_changed");
    exit;
}

header("Location: ../users.php");
exit;
