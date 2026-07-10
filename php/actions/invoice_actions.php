<?php
/**
 * Invoice & Payment Edit Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/mailer.php';

$currency = getSetting($pdo, 'currency_symbol', 'KSh');
$action   = $_POST['action'] ?? '';
$redirect = trim($_POST['_redirect'] ?? '../invoices.php');

// ── Edit Invoice ──────────────────────────────────────────────────────
if ($action === 'edit_invoice') {
    $invoiceId    = trim($_POST['invoice_id']        ?? '');
    $amount       = (float)($_POST['amount']         ?? 0);
    $dueDate      = trim($_POST['due_date']          ?? '');
    $type         = trim($_POST['invoice_type']      ?? 'Rent');
    $newStatus    = trim($_POST['status']            ?? '');
    $description  = trim($_POST['description']       ?? '');
    $notifyTenant = !empty($_POST['notify_tenant']);
    $reason       = trim($_POST['correction_reason'] ?? '');

    if (!$invoiceId || $amount <= 0 || !$dueDate) {
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'error=' . urlencode('Amount and due date are required.'));
        exit;
    }

    // Fetch existing invoice + tenant
    $oldStmt = $pdo->prepare("
        SELECT i.*, t.full_name, t.email, t.user_id AS tenant_user_id
        FROM invoices i
        JOIN tenants t ON i.tenant_id = t.id
        WHERE i.id = ?
    ");
    $oldStmt->execute([$invoiceId]);
    $old = $oldStmt->fetch();
    if (!$old) {
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'error=' . urlencode('Invoice not found.'));
        exit;
    }

    $validStatuses = ['Unpaid', 'Paid', 'Partial', 'Overdue', 'Cancelled'];
    if (!in_array($newStatus, $validStatuses, true)) $newStatus = $old['status'];

    $validTypes = ['Rent', 'Water', 'Garbage', 'Electricity', 'Deposit', 'Service Charge', 'Penalty', 'Other'];
    if (!in_array($type, $validTypes, true)) $type = $old['invoice_type'];

    // Self-heal description column
    try { $pdo->exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS description TEXT NULL"); } catch (PDOException $e) {}

    $pdo->prepare("UPDATE invoices SET amount = ?, due_date = ?, invoice_type = ?, status = ?, description = ? WHERE id = ?")
        ->execute([$amount, $dueDate, $type, $newStatus, $description ?: null, $invoiceId]);

    // Build audit change log
    $changes = [];
    if ((float)$old['amount'] !== $amount)       $changes[] = "Amount: {$currency} " . number_format((float)$old['amount']) . " → {$currency} " . number_format($amount);
    if ($old['due_date'] !== $dueDate)            $changes[] = "Due date: {$old['due_date']} → {$dueDate}";
    if ($old['invoice_type'] !== $type)           $changes[] = "Type: {$old['invoice_type']} → {$type}";
    if ($old['status'] !== $newStatus)            $changes[] = "Status: {$old['status']} → {$newStatus}";
    if ($description && $description !== ($old['description'] ?? '')) $changes[] = "Notes updated";

    logAction($pdo, 'invoice_edited', 'Invoices', $invoiceId,
        'CORRECTED — ' . (implode('; ', $changes) ?: 'minor update') . ($reason ? " | Reason: {$reason}" : ''));

    // Optional tenant notification
    if ($notifyTenant && !empty($old['email'])) {
        $changeHtml = $changes
            ? '<ul style="margin:8px 0 12px;padding-left:18px;">' . implode('', array_map(fn($c) => "<li style='font-size:13px;color:#475569;margin:3px 0;'>" . htmlspecialchars($c) . '</li>', $changes)) . '</ul>'
            : '<p style="font-size:13px;color:#475569;">Minor correction applied.</p>';
        $reasonHtml = $reason
            ? '<p style="font-size:13px;color:#475569;margin-top:8px;"><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>'
            : '';
        $invoiceUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(dirname($_SERVER['PHP_SELF'], 2), '/') . '/php/view_invoice.php?id=' . urlencode($invoiceId);

        $html = buildEmailHtml(
            $pdo,
            'Corrected Invoice Notification',
            '<p style="font-size:14px;color:#475569;">Dear <strong>' . htmlspecialchars($old['full_name']) . '</strong>,</p>'
            . '<p style="font-size:14px;color:#475569;margin-top:8px;">Your invoice has been corrected. Please review the updated details below:</p>'
            . $changeHtml . $reasonHtml
            . '<p style="font-size:12px;color:#94a3b8;margin-top:14px;">Please use the updated invoice for your records and discard any previous version.</p>',
            'View Updated Invoice',
            $invoiceUrl
        );
        sendSystemEmail($pdo, $old['email'],
            'CORRECTED: Invoice — ' . strtoupper(substr($invoiceId, 0, 8)),
            $html, $old['full_name']);

        if (!empty($old['tenant_user_id'])) {
            createNotification($pdo, $old['tenant_user_id'],
                'Invoice Corrected',
                "Your {$type} invoice has been corrected." . ($reason ? " Reason: {$reason}" : '') . ' Please check your updated invoice.',
                'info');
        }
    }

    header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'success=invoice_edited');
    exit;
}

// ── Edit Payment / Receipt ────────────────────────────────────────────
if ($action === 'edit_payment') {
    $paymentId    = trim($_POST['payment_id']         ?? '');
    $amount       = (float)($_POST['amount']          ?? 0);
    $method       = trim($_POST['payment_method']     ?? 'Cash');
    $txDate       = trim($_POST['transaction_date']   ?? date('Y-m-d'));
    $description  = trim($_POST['description']        ?? '');
    $reference    = trim($_POST['reference_number']   ?? '');
    $notifyTenant = !empty($_POST['notify_tenant']);
    $reason       = trim($_POST['correction_reason']  ?? '');

    if (!$paymentId || $amount <= 0) {
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'error=' . urlencode('Amount is required.'));
        exit;
    }

    // Fetch existing transaction + tenant
    $oldStmt = $pdo->prepare("
        SELECT tr.*, t.full_name, t.email, t.user_id AS tenant_user_id
        FROM transactions tr
        LEFT JOIN tenants t ON tr.tenant_id = t.id
        WHERE tr.id = ?
    ");
    $oldStmt->execute([$paymentId]);
    $old = $oldStmt->fetch();
    if (!$old) {
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'error=' . urlencode('Payment record not found.'));
        exit;
    }

    // Self-heal columns
    try { $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS description TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS reference_number VARCHAR(255) NULL"); } catch (PDOException $e) {}

    $pdo->prepare("UPDATE transactions SET amount = ?, payment_method = ?, transaction_date = ?, description = ?, reference_number = ? WHERE id = ?")
        ->execute([$amount, $method, $txDate, $description ?: null, $reference ?: null, $paymentId]);

    // Recalculate linked invoice status
    if (!empty($old['invoice_id'])) {
        $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE invoice_id = ? AND status = 'Paid'");
        $paidStmt->execute([$old['invoice_id']]);
        $totalPaid = (float)$paidStmt->fetchColumn();

        $invAmtStmt = $pdo->prepare("SELECT amount FROM invoices WHERE id = ?");
        $invAmtStmt->execute([$old['invoice_id']]);
        $invAmount = (float)($invAmtStmt->fetchColumn() ?? 0);

        if ($invAmount > 0) {
            $recalcStatus = $totalPaid >= $invAmount ? 'Paid' : ($totalPaid > 0 ? 'Partial' : 'Unpaid');
            $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ? AND status NOT IN ('Cancelled')")
                ->execute([$recalcStatus, $old['invoice_id']]);
        }
    }

    // Audit log
    $changes = [];
    if ((float)$old['amount'] !== $amount)        $changes[] = "Amount: {$currency} " . number_format((float)$old['amount']) . " → {$currency} " . number_format($amount);
    if ($old['payment_method'] !== $method)       $changes[] = "Method: {$old['payment_method']} → {$method}";
    if ($old['transaction_date'] !== $txDate)     $changes[] = "Date: {$old['transaction_date']} → {$txDate}";
    if ($reference && $reference !== ($old['reference_number'] ?? '')) $changes[] = "Ref updated";

    logAction($pdo, 'payment_edited', 'Transactions', $paymentId,
        'CORRECTED — ' . (implode('; ', $changes) ?: 'minor update') . ($reason ? " | Reason: {$reason}" : ''));

    // Optional tenant notification
    if ($notifyTenant && !empty($old['email'])) {
        $changeHtml = $changes
            ? '<ul style="margin:8px 0 12px;padding-left:18px;">' . implode('', array_map(fn($c) => "<li style='font-size:13px;color:#475569;margin:3px 0;'>" . htmlspecialchars($c) . '</li>', $changes)) . '</ul>'
            : '<p style="font-size:13px;color:#475569;">Minor correction applied.</p>';
        $reasonHtml = $reason
            ? '<p style="font-size:13px;color:#475569;margin-top:8px;"><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>'
            : '';
        $receiptUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(dirname($_SERVER['PHP_SELF'], 2), '/') . '/php/view_receipt.php?id=' . urlencode($paymentId);

        $html = buildEmailHtml(
            $pdo,
            'Corrected Payment Receipt',
            '<p style="font-size:14px;color:#475569;">Dear <strong>' . htmlspecialchars($old['full_name']) . '</strong>,</p>'
            . '<p style="font-size:14px;color:#475569;margin-top:8px;">Your payment receipt has been corrected. Please review the updated details:</p>'
            . $changeHtml . $reasonHtml
            . '<p style="font-size:12px;color:#94a3b8;margin-top:14px;">Please use the updated receipt for your records and discard any previous version.</p>',
            'View Updated Receipt',
            $receiptUrl
        );
        sendSystemEmail($pdo, $old['email'],
            'CORRECTED: Payment Receipt — ' . strtoupper(substr($paymentId, 0, 8)),
            $html, $old['full_name']);

        if (!empty($old['tenant_user_id'])) {
            createNotification($pdo, $old['tenant_user_id'],
                'Receipt Corrected',
                "Your payment receipt has been corrected." . ($reason ? " Reason: {$reason}" : '') . ' Please check the updated receipt.',
                'info');
        }
    }

    header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'success=payment_edited');
    exit;
}

header('Location: ' . $redirect);
exit;
