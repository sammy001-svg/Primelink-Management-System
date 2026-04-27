<?php
/**
 * Authentication and Session Management
 * Primelink Management System
 */

session_start();

require_once __DIR__ . '/../config/db.php';

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Redirect to login if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
}

/**
 * Check user role
 */
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Require specific role (accepts string or array of allowed roles)
 */
function requireRole($roles) {
    requireLogin();
    $allowed = is_array($roles) ? $roles : [$roles];
    // admin/staff always passes through any requireRole check
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, $allowed) && !in_array($role, ['admin', 'staff'])) {
        header("Location: dashboard.php?error=unauthorized");
        exit();
    }
}

/**
 * Get landlords.id for the current logged-in landlord user
 * Returns null if user is not a landlord or not found
 */
function getLandlordId($pdo) {
    if (!isLoggedIn()) return null;
    $stmt = $pdo->prepare("SELECT id FROM landlords WHERE user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    return $row['id'] ?? null;
}


/**
 * Get current user profile
 */
function getCurrentUser($pdo) {
    if (!isLoggedIn()) return null;
    
    $stmt = $pdo->prepare("SELECT p.*, u.email FROM profiles p JOIN users u ON p.id = u.id WHERE p.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Helper to generate UUID v4
 */
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
?>
