<?php
/**
 * Audit Log Helper
 * Primelink Management System
 */

/**
 * Record an auditable action.
 *
 * @param PDO    $pdo
 * @param string $action      Short verb, e.g. "payment_recorded", "lease_created"
 * @param string $module      e.g. "Financials", "Maintenance", "Leases"
 * @param string $recordId    The primary key of the affected record (can be empty)
 * @param string $description Human-readable summary
 */
function logAction(PDO $pdo, string $action, string $module, string $recordId = '', string $description = ''): void {
    try {
        $userId   = $_SESSION['user_id'] ?? null;
        $userName = $_SESSION['full_name'] ?? $_SESSION['email'] ?? 'System';
        $ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (id, user_id, user_name, action, module, record_id, description, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([generateUUID(), $userId, $userName, $action, $module, $recordId ?: null, $description, $ip]);
    } catch (PDOException $e) {
        // Non-fatal — audit must never break a transaction
    }
}
