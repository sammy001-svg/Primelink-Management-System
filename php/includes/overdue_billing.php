<?php
/**
 * Overdue Invoice Engine
 * Marks unpaid invoices as Overdue when they are past their due date.
 * Called on every admin/staff dashboard load (non-fatal, runs silently).
 */

function runOverdueBilling(PDO $pdo): int {
    // Find all invoices still 'Unpaid' whose due_date has passed
    $stmt = $pdo->query("
        SELECT i.id, i.tenant_id, i.invoice_type, i.due_date
        FROM invoices i
        WHERE i.status = 'Unpaid' AND i.due_date < CURDATE()
    ");
    $toMark = $stmt->fetchAll();

    if (empty($toMark)) return 0;

    // Bulk mark as Overdue
    $ids = array_column($toMark, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE invoices SET status = 'Overdue' WHERE id IN ($placeholders)")->execute($ids);

    // Notify each affected tenant once (not per invoice to avoid spam)
    require_once __DIR__ . '/notify.php';
    $notifiedTenants = [];

    foreach ($toMark as $inv) {
        if (in_array($inv['tenant_id'], $notifiedTenants)) continue;
        $notifiedTenants[] = $inv['tenant_id'];

        try {
            $userStmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $userStmt->execute([$inv['tenant_id']]);
            $userId = $userStmt->fetchColumn();
            if ($userId) {
                createNotification(
                    $pdo,
                    $userId,
                    'Invoice Overdue',
                    'One or more of your invoices are now overdue. Please settle your outstanding balance immediately to avoid additional charges.',
                    'alert'
                );
            }
        } catch (Exception $e) {
            // Non-fatal — notification failure must never block billing
        }
    }

    return count($toMark);
}
