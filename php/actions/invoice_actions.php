<?php
/**
 * Invoice & Receipt Correction Handler
 * Primelink Management System
 *
 * Posted documents are corrected, never silently overwritten. Each accepted
 * change bumps the document's revision, snapshots the previous state into
 * `document_revisions`, stamps the printed copy as CORRECTED, and (by default)
 * issues the tenant a corrected notice showing exactly what changed.
 *
 * See includes/corrections.php for the engine.
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/corrections.php';

ensureCorrectionSchema($pdo);

$currency = getSetting($pdo, 'currency_symbol', 'KSh');
$action   = $_POST['action'] ?? '';
$redirect = trim($_POST['_redirect'] ?? '../invoices.php');

/** Redirect back with a message. */
function backTo(string $redirect, string $key, string $value): void {
    $sep = str_contains($redirect, '?') ? '&' : '?';
    header('Location: ' . $redirect . $sep . $key . '=' . urlencode($value));
    exit;
}

$fail = fn(string $msg) => backTo($redirect, 'error', $msg);

/* ═══════════════════════════════════════════════════════════════════════
   CORRECT AN INVOICE
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'edit_invoice') {
    requirePermission($pdo, 'invoices', 'edit');

    $invoiceId    = trim($_POST['invoice_id']        ?? '');
    $amount       = round((float)($_POST['amount']   ?? 0), 2);
    $dueDate      = trim($_POST['due_date']          ?? '');
    $type         = trim($_POST['invoice_type']      ?? 'Rent');
    $newStatus    = trim($_POST['status']            ?? '');
    $description  = trim($_POST['description']       ?? '');
    $reason       = trim($_POST['correction_reason'] ?? '');
    $notifyTenant = !empty($_POST['notify_tenant']);

    if (!$invoiceId)          $fail('No invoice specified.');
    if ($amount <= 0)         $fail('Amount must be greater than zero.');
    if (!$dueDate)            $fail('A due date is required.');
    if (strlen($reason) < 5)  $fail('A reason for the correction is required — it is recorded on the audit trail and shown to the tenant.');

    $oldStmt = $pdo->prepare("
        SELECT i.*, t.full_name, t.email, t.user_id AS tenant_user_id, u.unit_number
        FROM invoices i
        JOIN tenants t ON i.tenant_id = t.id
        LEFT JOIN leases l ON i.lease_id = l.id
        LEFT JOIN units  u ON l.unit_id  = u.id
        WHERE i.id = ?
    ");
    $oldStmt->execute([$invoiceId]);
    $old = $oldStmt->fetch();
    if (!$old) $fail('Invoice not found.');

    // A cancelled invoice is closed — it must be re-issued, not amended.
    if (($old['status'] ?? '') === 'Cancelled') {
        $fail('This invoice is cancelled and can no longer be corrected. Issue a new invoice instead.');
    }

    $validStatuses = ['Unpaid', 'Paid', 'Partial', 'Overdue', 'Cancelled'];
    if (!in_array($newStatus, $validStatuses, true)) $newStatus = $old['status'];

    $validTypes = ['Rent', 'Water', 'Garbage', 'Electricity', 'Deposit', 'Service Charge', 'Penalty', 'Other'];
    if (!in_array($type, $validTypes, true)) $type = $old['invoice_type'];

    // Reducing an invoice below what has already been receipted leaves a credit
    // the system cannot represent — block it and let the user refund instead.
    $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE invoice_id = ? AND status = 'Paid'");
    $paidStmt->execute([$invoiceId]);
    $totalPaid = (float)$paidStmt->fetchColumn();
    if ($amount < $totalPaid - 0.001) {
        $fail(sprintf(
            'Cannot reduce this invoice to %s %s — %s %s has already been receipted against it. Correct or reverse the payment first.',
            $currency, number_format($amount, 2), $currency, number_format($totalPaid, 2)
        ));
    }

    $newValues = [
        'invoice_type' => $type,
        'amount'       => $amount,
        'due_date'     => $dueDate,
        'status'       => $newStatus,
        'description'  => $description,
    ];
    $diff = buildDiff($old, $newValues, [
        'invoice_type' => ['label' => 'Invoice Type', 'format' => 'text'],
        'amount'       => ['label' => 'Amount',       'format' => 'money'],
        'due_date'     => ['label' => 'Due Date',     'format' => 'date'],
        'status'       => ['label' => 'Status',       'format' => 'text'],
        'description'  => ['label' => 'Notes',        'format' => 'text'],
    ], $currency);

    if (!$diff) backTo($redirect, 'info', 'No changes were made — the invoice is unchanged.');

    $newRevision = (int)($old['revision_no'] ?? 0) + 1;

    try {
        $pdo->beginTransaction();

        // Snapshot the pre-change state before touching the row
        recordRevision($pdo, DOC_INVOICE, $invoiceId, $newRevision, $old, $diff, $reason, $notifyTenant);

        $pdo->prepare("
            UPDATE invoices
               SET amount = ?, due_date = ?, invoice_type = ?, status = ?, description = ?,
                   revision_no = ?, last_corrected_at = NOW(), last_correction_reason = ?, corrected_by = ?
             WHERE id = ?
        ")->execute([
            $amount, $dueDate, $type, $newStatus, $description ?: null,
            $newRevision, $reason, $_SESSION['user_id'] ?? null,
            $invoiceId,
        ]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $fail('Could not save the correction: ' . $e->getMessage());
    }

    $docNo = docNumber(DOC_INVOICE, $invoiceId, $newRevision);
    logAction($pdo, 'invoice_corrected', 'Invoices', $invoiceId,
        "CORRECTED {$docNo} (rev {$newRevision}) — " . summariseDiff($diff) . " | Reason: {$reason}"
        . ($notifyTenant ? ' | Tenant notified' : ' | Tenant NOT notified'));

    if ($notifyTenant) {
        sendCorrectionNotice(
            $pdo, DOC_INVOICE, $invoiceId, $newRevision,
            ['full_name' => $old['full_name'], 'email' => $old['email'], 'tenant_user_id' => $old['tenant_user_id']],
            $diff, $reason,
            [
                'Invoice No.' => $docNo,
                'Type'        => $type,
                'Unit'        => $old['unit_number'] ?: '—',
                'Amount Due'  => $currency . ' ' . number_format($amount, 2),
                'Due Date'    => date('d M Y', strtotime($dueDate)),
            ]
        );
    }

    backTo($redirect, 'success', 'Invoice corrected — now ' . $docNo
        . ($notifyTenant ? '. Corrected notice issued to the tenant.' : '.'));
}

/* ═══════════════════════════════════════════════════════════════════════
   CORRECT A RECEIPT (PAYMENT)
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'edit_payment') {
    requirePermission($pdo, 'payments', 'edit');

    $paymentId    = trim($_POST['payment_id']         ?? '');
    $amount       = round((float)($_POST['amount']    ?? 0), 2);
    $method       = trim($_POST['payment_method']     ?? 'Cash');
    $txDate       = trim($_POST['transaction_date']   ?? date('Y-m-d'));
    $description  = trim($_POST['description']        ?? '');
    $reference    = trim($_POST['reference_number']   ?? '');
    $reason       = trim($_POST['correction_reason']  ?? '');
    $notifyTenant = !empty($_POST['notify_tenant']);

    if (!$paymentId)          $fail('No receipt specified.');
    if ($amount <= 0)         $fail('Amount must be greater than zero.');
    if (!$txDate)             $fail('A transaction date is required.');
    if (strlen($reason) < 5)  $fail('A reason for the correction is required — it is recorded on the audit trail and shown to the tenant.');

    $oldStmt = $pdo->prepare("
        SELECT tr.*, t.full_name, t.email, t.user_id AS tenant_user_id, u.unit_number
        FROM transactions tr
        LEFT JOIN tenants t ON tr.tenant_id = t.id
        LEFT JOIN leases  l ON tr.lease_id  = l.id
        LEFT JOIN units   u ON l.unit_id    = u.id
        WHERE tr.id = ?
    ");
    $oldStmt->execute([$paymentId]);
    $old = $oldStmt->fetch();
    if (!$old) $fail('Payment record not found.');

    if (strtotime($txDate) > time()) $fail('A receipt cannot be dated in the future.');

    $validMethods = ['Cash', 'M-Pesa', 'Bank Transfer', 'Cheque', 'Other'];
    if (!in_array($method, $validMethods, true)) $method = $old['payment_method'] ?: 'Cash';

    $newValues = [
        'amount'           => $amount,
        'payment_method'   => $method,
        'transaction_date' => $txDate,
        'reference_number' => $reference,
        'description'      => $description,
    ];
    $diff = buildDiff($old, $newValues, [
        'amount'           => ['label' => 'Amount Received', 'format' => 'money'],
        'payment_method'   => ['label' => 'Payment Method',  'format' => 'text'],
        'transaction_date' => ['label' => 'Payment Date',    'format' => 'date'],
        'reference_number' => ['label' => 'Reference No.',   'format' => 'text'],
        'description'      => ['label' => 'Notes',           'format' => 'text'],
    ], $currency);

    if (!$diff) backTo($redirect, 'info', 'No changes were made — the receipt is unchanged.');

    $newRevision = (int)($old['revision_no'] ?? 0) + 1;
    $invoiceId   = $old['invoice_id'] ?? null;
    $balanceNote = '';

    try {
        $pdo->beginTransaction();

        recordRevision($pdo, DOC_RECEIPT, $paymentId, $newRevision, $old, $diff, $reason, $notifyTenant);

        $pdo->prepare("
            UPDATE transactions
               SET amount = ?, payment_method = ?, transaction_date = ?, description = ?, reference_number = ?,
                   revision_no = ?, last_corrected_at = NOW(), last_correction_reason = ?, corrected_by = ?
             WHERE id = ?
        ")->execute([
            $amount, $method, $txDate, $description ?: null, $reference ?: null,
            $newRevision, $reason, $_SESSION['user_id'] ?? null,
            $paymentId,
        ]);

        // A changed receipt amount changes what the linked invoice still owes
        if ($invoiceId) {
            $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE invoice_id = ? AND status = 'Paid'");
            $paidStmt->execute([$invoiceId]);
            $totalPaid = (float)$paidStmt->fetchColumn();

            $invStmt = $pdo->prepare("SELECT amount, due_date, status FROM invoices WHERE id = ?");
            $invStmt->execute([$invoiceId]);
            $inv = $invStmt->fetch();

            if ($inv && (float)$inv['amount'] > 0 && $inv['status'] !== 'Cancelled') {
                $invAmount = (float)$inv['amount'];
                if ($totalPaid >= $invAmount - 0.001) {
                    $recalc = 'Paid';
                } elseif ($totalPaid > 0) {
                    $recalc = strtotime($inv['due_date']) < strtotime('today') ? 'Overdue' : 'Partial';
                } else {
                    $recalc = strtotime($inv['due_date']) < strtotime('today') ? 'Overdue' : 'Unpaid';
                }
                $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$recalc, $invoiceId]);

                $balance     = max(0, $invAmount - $totalPaid);
                $balanceNote = $balance > 0
                    ? $currency . ' ' . number_format($balance, 2) . ' outstanding'
                    : 'Settled in full';
            }
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $fail('Could not save the correction: ' . $e->getMessage());
    }

    $docNo = docNumber(DOC_RECEIPT, $paymentId, $newRevision);
    logAction($pdo, 'receipt_corrected', 'Payments', $paymentId,
        "CORRECTED {$docNo} (rev {$newRevision}) — " . summariseDiff($diff) . " | Reason: {$reason}"
        . ($notifyTenant ? ' | Tenant notified' : ' | Tenant NOT notified'));

    if ($notifyTenant) {
        $summary = [
            'Receipt No.'     => $docNo,
            'Amount Received' => $currency . ' ' . number_format($amount, 2),
            'Payment Method'  => $method,
            'Payment Date'    => date('d M Y', strtotime($txDate)),
        ];
        if ($old['unit_number']) $summary['Unit'] = $old['unit_number'];
        if ($balanceNote)        $summary['Invoice Balance'] = $balanceNote;

        sendCorrectionNotice(
            $pdo, DOC_RECEIPT, $paymentId, $newRevision,
            ['full_name' => $old['full_name'], 'email' => $old['email'], 'tenant_user_id' => $old['tenant_user_id']],
            $diff, $reason, $summary
        );
    }

    backTo($redirect, 'success', 'Receipt corrected — now ' . $docNo
        . ($notifyTenant ? '. Corrected notice issued to the tenant.' : '.'));
}

/* ═══════════════════════════════════════════════════════════════════════
   RE-ISSUE THE CURRENT CORRECTED COPY TO THE TENANT
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'resend_correction') {
    $docType = ($_POST['doc_type'] ?? '') === DOC_RECEIPT ? DOC_RECEIPT : DOC_INVOICE;
    $docId   = trim($_POST['doc_id'] ?? '');
    if (!$docId) $fail('No document specified.');

    requirePermission($pdo, $docType === DOC_RECEIPT ? 'payments' : 'invoices', 'edit');

    if ($docType === DOC_INVOICE) {
        $stmt = $pdo->prepare("
            SELECT i.*, t.full_name, t.email, t.user_id AS tenant_user_id, u.unit_number
            FROM invoices i
            JOIN tenants t ON i.tenant_id = t.id
            LEFT JOIN leases l ON i.lease_id = l.id
            LEFT JOIN units  u ON l.unit_id  = u.id
            WHERE i.id = ?
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT tr.*, t.full_name, t.email, t.user_id AS tenant_user_id, u.unit_number
            FROM transactions tr
            LEFT JOIN tenants t ON tr.tenant_id = t.id
            LEFT JOIN leases  l ON tr.lease_id  = l.id
            LEFT JOIN units   u ON l.unit_id    = u.id
            WHERE tr.id = ?
        ");
    }
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if (!$doc) $fail('Document not found.');

    $revision = (int)($doc['revision_no'] ?? 0);
    if ($revision < 1) $fail('This document has never been corrected, so there is no corrected copy to re-issue.');
    if (empty($doc['email'])) $fail('This tenant has no email address on file.');

    // Re-send the notice built from the latest recorded revision
    $revisions = getRevisions($pdo, $docType, $docId);
    $latest    = end($revisions) ?: [];
    $diff      = $latest['changes'] ?? [];
    $reason    = (string)($doc['last_correction_reason'] ?? '');
    $docNo     = docNumber($docType, $docId, $revision);

    $summary = $docType === DOC_INVOICE
        ? [
            'Invoice No.' => $docNo,
            'Type'        => (string)$doc['invoice_type'],
            'Unit'        => $doc['unit_number'] ?: '—',
            'Amount Due'  => $currency . ' ' . number_format((float)$doc['amount'], 2),
            'Due Date'    => date('d M Y', strtotime((string)$doc['due_date'])),
        ]
        : [
            'Receipt No.'     => $docNo,
            'Amount Received' => $currency . ' ' . number_format((float)$doc['amount'], 2),
            'Payment Method'  => (string)$doc['payment_method'],
            'Payment Date'    => date('d M Y', strtotime((string)$doc['transaction_date'])),
        ];

    $sent = sendCorrectionNotice(
        $pdo, $docType, $docId, $revision,
        ['full_name' => $doc['full_name'], 'email' => $doc['email'], 'tenant_user_id' => $doc['tenant_user_id']],
        $diff, $reason, $summary
    );

    logAction($pdo, 'correction_resent', $docType === DOC_INVOICE ? 'Invoices' : 'Payments', $docId,
        "Re-issued corrected copy {$docNo} to " . $doc['email'] . ($sent ? '' : ' (delivery failed)'));

    $sent
        ? backTo($redirect, 'success', 'Corrected copy ' . $docNo . ' re-issued to ' . $doc['email'] . '.')
        : backTo($redirect, 'error', 'Could not send the email. Check the mail settings — the in-app notice was still delivered.');
}

header('Location: ' . $redirect);
exit;
