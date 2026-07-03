<?php
/**
 * Payment Recording Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/settings.php';

$currency = getSetting($pdo, 'currency_symbol', 'KSh');

// Schema self-heal: add Partial status to invoices
try { $pdo->exec("ALTER TABLE invoices MODIFY COLUMN status ENUM('Unpaid','Paid','Partial','Overdue','Cancelled') NOT NULL DEFAULT 'Unpaid'"); } catch (PDOException $e) {}
// Ensure transactions table has description and reference columns
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS description TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS reference_number VARCHAR(255) NULL"); } catch (PDOException $e) {}

$action   = $_POST['action']    ?? '';
$redirect = trim($_POST['_redirect'] ?? '../invoices.php');

// ── Record a partial or full payment against an invoice ───────────────
if ($action === 'record_payment') {
    $invoiceId = trim($_POST['invoice_id'] ?? '');
    $tenantId  = trim($_POST['tenant_id']  ?? '');
    $leaseId   = trim($_POST['lease_id']   ?? '') ?: null;
    $amount    = (float)($_POST['amount']  ?? 0);
    $method    = $_POST['payment_method']  ?? 'Cash';
    $reference = trim($_POST['reference']  ?? '');
    $notes     = trim($_POST['notes']      ?? '');

    if (!$invoiceId || !$tenantId || $amount <= 0) {
        header('Location: ' . $redirect . '?error=' . urlencode('Missing required fields or invalid amount.'));
        exit();
    }

    try {
        $pdo->beginTransaction();

        // 1. Create the transaction record
        $txId = generateUUID();
        $pdo->prepare("
            INSERT INTO transactions
                (id, tenant_id, lease_id, invoice_id, amount, transaction_type, status, payment_method, description, transaction_date)
            VALUES (?, ?, ?, ?, ?, 'Rent Payment', 'Paid', ?, ?, CURDATE())
        ")->execute([$txId, $tenantId, $leaseId, $invoiceId, $amount, $method, $reference ?: ($notes ?: null)]);

        // 2. Compute total paid on this invoice
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE invoice_id = ? AND status = 'Paid'");
        $stmt->execute([$invoiceId]);
        $totalPaid = (float)$stmt->fetchColumn();

        // 3. Get invoice amount
        $stmt2 = $pdo->prepare("SELECT amount, invoice_type FROM invoices WHERE id = ?");
        $stmt2->execute([$invoiceId]);
        $invRow = $stmt2->fetch();
        $invAmount = (float)($invRow['amount'] ?? 0);
        $invType   = $invRow['invoice_type'] ?? 'Invoice';

        // 4. Update invoice status
        if ($totalPaid >= $invAmount) {
            $newStatus = 'Paid';
        } elseif ($totalPaid > 0) {
            $newStatus = 'Partial';
        } else {
            $newStatus = 'Unpaid';
        }
        $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$newStatus, $invoiceId]);

        $pdo->commit();

        // 5. Notify tenant
        $tRow = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
        $tRow->execute([$tenantId]);
        $tUid = $tRow->fetchColumn();
        if ($tUid) {
            createNotification(
                $pdo, $tUid,
                'Payment Received',
                "A payment of {$currency} " . number_format($amount) . " has been recorded for your {$invType} invoice via {$method}. Status: <strong>{$newStatus}</strong>.",
                'success'
            );
        }

        logAction($pdo, 'payment_recorded', 'Invoices', $invoiceId,
            "{$currency} " . number_format($amount) . " via {$method}" . ($reference ? " (Ref: {$reference})" : '') . " — invoice now {$newStatus}");

        header('Location: ' . $redirect . '?success=payment_recorded');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header('Location: ' . $redirect . '?error=' . urlencode($e->getMessage()));
    }
    exit();
}

// ── Quick mark invoice as fully paid (no transaction record) ─────────
if ($action === 'mark_paid') {
    $invoiceId = trim($_POST['invoice_id'] ?? '');
    if (!$invoiceId) { header('Location: ' . $redirect); exit(); }

    try {
        $pdo->prepare("UPDATE invoices SET status = 'Paid' WHERE id = ?")->execute([$invoiceId]);
        logAction($pdo, 'invoice_marked_paid', 'Invoices', $invoiceId, 'Manually marked as Paid');
        header('Location: ' . $redirect . '?success=marked_paid');
    } catch (PDOException $e) {
        header('Location: ' . $redirect . '?error=' . urlencode($e->getMessage()));
    }
    exit();
}

// ── Revert paid invoice back to Unpaid ───────────────────────────────
if ($action === 'revert_unpaid') {
    $invoiceId = trim($_POST['invoice_id'] ?? '');
    if (!$invoiceId) { header('Location: ' . $redirect); exit(); }

    try {
        $pdo->prepare("UPDATE invoices SET status = 'Unpaid' WHERE id = ?")->execute([$invoiceId]);
        logAction($pdo, 'invoice_reverted', 'Invoices', $invoiceId, 'Reverted to Unpaid');
        header('Location: ' . $redirect . '?success=reverted');
    } catch (PDOException $e) {
        header('Location: ' . $redirect . '?error=' . urlencode($e->getMessage()));
    }
    exit();
}

header('Location: ' . $redirect);
exit();
?>
