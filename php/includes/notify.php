<?php
/**
 * In-App Notification Helper
 * Primelink Management System
 */

/**
 * Create a notification for a specific user.
 */
function createNotification(PDO $pdo, string $userId, string $title, string $message, string $type = 'info'): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (id, user_id, title, message, type, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())"
        );
        $stmt->execute([generateUUID(), $userId, $title, $message, $type]);
    } catch (PDOException $e) {
        // Non-fatal — notifications must never break a transaction
    }
}

/**
 * Notify all admins and staff users.
 */
function notifyAllStaff(PDO $pdo, string $title, string $message, string $type = 'info'): void {
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE role IN ('admin','staff') AND status = 'Active'");
        foreach ($stmt->fetchAll() as $row) {
            createNotification($pdo, $row['id'], $title, $message, $type);
        }
    } catch (PDOException $e) {
        // Non-fatal
    }
}

/**
 * Get unread notification count for a user.
 */
function getUnreadCount(PDO $pdo, string $userId): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
