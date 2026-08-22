<?php
/**
 * Tenant Detail Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/tenant_notify.php';
require_once __DIR__ . '/../includes/bank_accounts.php';

ensureBankAccountSchema($pdo);

/**
 * Load the fields the notification dispatcher needs for a tenant, including
 * the unit number used as the M-Pesa account reference.
 */
function loadNotifyTenant(PDO $pdo, string $tenantId): ?array {
    $stmt = $pdo->prepare("
        SELECT t.id, t.full_name, t.email, t.phone, t.user_id, u.unit_number
        FROM tenants t
        LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'Active'
        LEFT JOIN units  u ON l.unit_id = u.id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    return $stmt->fetch() ?: null;
}

$currency = getSetting($pdo, 'currency_symbol', 'KSh');

// Schema self-heal: batch_id for bundle invoices, description for custom notes
foreach ([
    "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS batch_id    VARCHAR(36) NULL",
    "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS description TEXT NULL",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tenantId = $_POST['tenant_id'] ?? '';

    if ($action === 'update_profile') {
        $fullName      = trim($_POST['full_name']      ?? '');
        $phone         = trim($_POST['phone']          ?? '');
        $address       = trim($_POST['address']        ?? '');
        $profession    = trim($_POST['profession']     ?? '');
        $employerName  = trim($_POST['employer_name']  ?? '');
        $maritalStatus = trim($_POST['marital_status'] ?? 'Single');
        $idNo          = trim($_POST['id_no']          ?? '');

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $userId = $stmt->fetchColumn();

            if ($userId) {
                $pdo->prepare("UPDATE profiles SET full_name = ?, phone = ?, address = ? WHERE id = ?")
                    ->execute([$fullName, $phone, $address, $userId]);
            }

            $pdo->prepare("
                UPDATE tenants
                SET full_name = ?, phone = ?, current_address = ?, profession = ?,
                    employer_name = ?, marital_status = ?, id_no = ?
                WHERE id = ?
            ")->execute([$fullName, $phone, $address, $profession, $employerName, $maritalStatus, $idNo ?: null, $tenantId]);

            $pdo->commit();
            logAction($pdo, 'tenant_profile_updated', 'Tenants', $tenantId, "Profile updated: {$fullName}");
            header("Location: ../tenant_details.php?id={$tenantId}&tab=profile&success=profile_updated");
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header("Location: ../tenant_details.php?id={$tenantId}&tab=profile&error=" . urlencode($e->getMessage()));
            exit();
        }
    }

    if ($action === 'reset_password') {
        $newPass  = $_POST['new_password']    ?? '';
        $confPass = $_POST['confirm_password'] ?? '';
        $redir    = $_POST['_redirect']        ?? null;

        if ($newPass !== $confPass) {
            $back = $redir ?: "../tenant_details.php?id=$tenantId";
            header("Location: $back&error=passwords_mismatch");
            exit();
        }

        $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $userId = $stmt->fetchColumn();

        if ($userId) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $userId]);
            $dest = $redir ?: "../tenant_details.php?id=$tenantId&success=password_reset";
            // append success param if redirecting back to tenants list
            if ($redir && strpos($redir, '?') === false) $dest .= '?success=password_reset';
            elseif ($redir)                               $dest .= '&success=password_reset';
            header("Location: $dest");
            exit();
        }
    }

    if ($action === 'quick_edit') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone']     ?? '');
        $email    = trim($_POST['email']     ?? '');
        if (!$fullName) {
            header('Location: ../tenants.php?error=' . urlencode('Name is required.'));
            exit();
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $userId = $stmt->fetchColumn();
            $pdo->prepare("UPDATE tenants SET full_name = ?, phone = ?, email = ? WHERE id = ?")->execute([$fullName, $phone, $email, $tenantId]);
            if ($userId) {
                $pdo->prepare("UPDATE profiles SET full_name = ?, phone = ?, email = ? WHERE id = ?")->execute([$fullName, $phone, $email, $userId]);
            }
            $pdo->commit();
            header('Location: ../tenants.php?success=profile_updated');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header('Location: ../tenants.php?error=' . urlencode($e->getMessage()));
        }
        exit();
    }

    // ── generate_invoice: create a single invoice and view it ─────────
    if ($action === 'generate_invoice') {
        $invoiceType = trim($_POST['invoice_type'] ?? 'Rent');
        $amount      = (float)($_POST['amount'] ?? 0);
        $dueDate     = $_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days'));
        $description = trim($_POST['description'] ?? '');

        if (!$tenantId || $amount <= 0) {
            header("Location: ../tenant_details.php?id=$tenantId&tab=invoices&error=" . urlencode('Invalid amount.'));
            exit();
        }

        // Resolve active lease
        $ls = $pdo->prepare("SELECT id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
        $ls->execute([$tenantId]);
        $leaseId = $ls->fetchColumn() ?: null;

        try {
            $invId = generateUUID();
            $pdo->prepare("
                INSERT INTO invoices (id, tenant_id, lease_id, amount, due_date, status, invoice_type, description)
                VALUES (?, ?, ?, ?, ?, 'Unpaid', ?, ?)
            ")->execute([$invId, $tenantId, $leaseId, $amount, $dueDate, $invoiceType, $description ?: null]);

            // Notify the tenant across the channels the user asked for
            $channels = [
                'email' => !empty($_POST['notify_email']),
                'sms'   => !empty($_POST['notify_sms']),
            ];
            $noticeSummary = '';
            $notifyTenant  = loadNotifyTenant($pdo, $tenantId);

            if ($notifyTenant) {
                $notice = buildInvoiceNotice($pdo, $notifyTenant, [
                    'id'           => $invId,
                    'invoice_type' => $invoiceType,
                    'amount'       => $amount,
                    'due_date'     => $dueDate,
                    'description'  => $description,
                    'unit_number'  => $notifyTenant['unit_number'] ?? '',
                ]);
                $res = dispatchTenantNotice($pdo, $notifyTenant, $notice, $channels, 'invoice_issued');
                if ($channels['email'] || $channels['sms']) {
                    $noticeSummary = summariseNotices([$res]);
                }
            }

            logAction($pdo, 'invoice_generated', 'Invoices', $invId,
                "{$invoiceType}: {$currency} " . number_format($amount) . " | Due: {$dueDate}"
                . ($description ? " | {$description}" : '')
                . ($noticeSummary ? " | Notice: {$noticeSummary}" : ''));

            $q = $noticeSummary ? '&notice=' . urlencode($noticeSummary) : '';
            header("Location: ../view_invoice.php?id={$invId}&back_tenant={$tenantId}{$q}");
        } catch (PDOException $e) {
            header("Location: ../tenant_details.php?id=$tenantId&tab=invoices&error=" . urlencode($e->getMessage()));
        }
        exit();
    }

    // ── generate_bundle: create multiple invoices (bundle) ────────────
    if ($action === 'generate_bundle') {
        $dueDate     = $_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days'));
        $description = trim($_POST['description'] ?? '');
        $types       = $_POST['bundle_type']   ?? [];   // e.g. ['Rent', 'Water']
        $amounts     = $_POST['bundle_amount'] ?? [];   // e.g. ['Rent' => 5000, 'Water' => 300]

        if (!$tenantId || empty($types)) {
            header("Location: ../tenant_details.php?id=$tenantId&tab=invoices&error=" . urlencode('No items selected.'));
            exit();
        }

        $ls = $pdo->prepare("SELECT id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
        $ls->execute([$tenantId]);
        $leaseId = $ls->fetchColumn() ?: null;

        $batchId = generateUUID();
        $created = 0;
        $totalAmt = 0;
        $items    = [];

        try {
            foreach ($types as $type) {
                $amt = (float)($amounts[$type] ?? 0);
                if ($amt <= 0) continue;

                $invId = generateUUID();
                $pdo->prepare("
                    INSERT INTO invoices (id, tenant_id, lease_id, amount, due_date, status, invoice_type, description, batch_id)
                    VALUES (?, ?, ?, ?, ?, 'Unpaid', ?, ?, ?)
                ")->execute([$invId, $tenantId, $leaseId, $amt, $dueDate, $type, $description ?: null, $batchId]);
                $created++;
                $totalAmt += $amt;
                $items[]   = ['invoice_type' => $type, 'amount' => $amt];
            }

            if ($created === 0) {
                header("Location: ../tenant_details.php?id=$tenantId&tab=invoices&error=" . urlencode('All amounts were zero — no invoices created.'));
                exit();
            }

            // Notify the tenant across the channels the user asked for
            $channels = [
                'email' => !empty($_POST['notify_email']),
                'sms'   => !empty($_POST['notify_sms']),
            ];
            $noticeSummary = '';
            $notifyTenant  = loadNotifyTenant($pdo, $tenantId);

            if ($notifyTenant) {
                $notice = buildBundleNotice($pdo, $notifyTenant, [
                    'batch_id'    => $batchId,
                    'items'       => $items,
                    'total'       => $totalAmt,
                    'due_date'    => $dueDate,
                    'description' => $description,
                    'unit_number' => $notifyTenant['unit_number'] ?? '',
                ]);
                $res = dispatchTenantNotice($pdo, $notifyTenant, $notice, $channels, 'bundle_issued');
                if ($channels['email'] || $channels['sms']) {
                    $noticeSummary = summariseNotices([$res]);
                }
            }

            logAction($pdo, 'bundle_invoice_generated', 'Invoices', $batchId,
                "{$created} items — {$currency} " . number_format($totalAmt) . " | Batch: {$batchId}"
                . ($noticeSummary ? " | Notice: {$noticeSummary}" : ''));

            $q = $noticeSummary ? '&notice=' . urlencode($noticeSummary) : '';
            header("Location: ../view_combined_invoice.php?batch_id={$batchId}{$q}");
        } catch (PDOException $e) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=invoices&error=" . urlencode($e->getMessage()));
        }
        exit();
    }

    // ── update_nok: save next-of-kin details ─────────────────────────
    if ($action === 'update_nok') {
        $nokName = trim($_POST['nok_name']         ?? '');
        $nokRel  = trim($_POST['nok_relationship'] ?? '');
        $nokPhone= trim($_POST['nok_contact']      ?? '');
        try {
            $pdo->prepare("
                UPDATE tenants
                SET next_of_kin_name = ?, next_of_kin_relationship = ?, next_of_kin_contact = ?
                WHERE id = ?
            ")->execute([$nokName ?: null, $nokRel ?: null, $nokPhone ?: null, $tenantId]);
            logAction($pdo, 'nok_updated', 'Tenants', $tenantId, "NOK updated: {$nokName}");
            header("Location: ../tenant_details.php?id={$tenantId}&tab=profile&success=nok_updated");
        } catch (PDOException $e) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=profile&error=" . urlencode($e->getMessage()));
        }
        exit();
    }

    // ── update_spouse: save spouse details ───────────────────────────
    if ($action === 'update_spouse') {
        $spouseName  = trim($_POST['spouse_name']   ?? '');
        $spousePhone = trim($_POST['spouse_phone']  ?? '');
        $spouseIdNo  = trim($_POST['spouse_id_no']  ?? '');
        try {
            $pdo->prepare("
                UPDATE tenants
                SET spouse_name = ?, spouse_phone = ?, spouse_id_no = ?
                WHERE id = ?
            ")->execute([$spouseName ?: null, $spousePhone ?: null, $spouseIdNo ?: null, $tenantId]);
            logAction($pdo, 'spouse_updated', 'Tenants', $tenantId, "Spouse info updated");
            header("Location: ../tenant_details.php?id={$tenantId}&tab=profile&success=spouse_updated");
        } catch (PDOException $e) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=profile&error=" . urlencode($e->getMessage()));
        }
        exit();
    }

    // ── record_payment: log a manual payment transaction ─────────────
    if ($action === 'record_payment') {
        $amount      = (float)($_POST['amount']           ?? 0);
        $txType      = trim($_POST['transaction_type']    ?? 'Rent');
        $refCode     = trim($_POST['reference_code']      ?? '');
        $payDate     = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');

        if (!$tenantId || $amount <= 0) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=invoices&error=" . urlencode('Invalid amount.'));
            exit();
        }

        // Schema self-heal for transactions table
        foreach ([
            "CREATE TABLE IF NOT EXISTS transactions (
                id VARCHAR(36) PRIMARY KEY,
                tenant_id VARCHAR(36) NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                transaction_type VARCHAR(100) DEFAULT 'Rent',
                status VARCHAR(20) DEFAULT 'Paid',
                reference_code VARCHAR(255) NULL,
                transaction_date DATE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS reference_code VARCHAR(255) NULL",
            "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS transaction_type VARCHAR(100) DEFAULT 'Rent'",
        ] as $ddl) {
            try { $pdo->exec($ddl); } catch (PDOException $_e) {}
        }

        // Where the money landed — required once collection accounts exist
        $bankAccounts = getBankAccounts($pdo);
        $bankAccount  = getBankAccount($pdo, trim($_POST['bank_account_id'] ?? ''));
        if ($bankAccounts && !$bankAccount) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=invoices&error="
                 . urlencode('Choose the account this payment was deposited into.'));
            exit();
        }

        try {
            $txId = generateUUID();
            $pdo->prepare("
                INSERT INTO transactions (id, tenant_id, amount, transaction_type, status, reference_code, bank_account_id, transaction_date)
                VALUES (?, ?, ?, ?, 'Paid', ?, ?, ?)
            ")->execute([$txId, $tenantId, $amount, $txType, $refCode ?: null, $bankAccount['id'] ?? null, $payDate]);

            // Auto-apply: mark the oldest unpaid invoice as paid if amount matches
            $unpaid = $pdo->prepare("SELECT id, amount FROM invoices WHERE tenant_id = ? AND status != 'Paid' ORDER BY due_date ASC LIMIT 1");
            $unpaid->execute([$tenantId]);
            $inv = $unpaid->fetch();
            if ($inv && abs((float)$inv['amount'] - $amount) < 0.01) {
                $pdo->prepare("UPDATE invoices SET status = 'Paid' WHERE id = ?")->execute([$inv['id']]);
            }

            logAction($pdo, 'payment_recorded', 'Transactions', $txId,
                "{$txType}: {$currency} " . number_format($amount) . " | Ref: {$refCode} | Date: {$payDate}"
                . ($bankAccount ? " | Into: {$bankAccount['name']}" : ''));

            $uRow = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $uRow->execute([$tenantId]);
            $uid = $uRow->fetchColumn();
            if ($uid) {
                createNotification($pdo, $uid, 'Payment Recorded',
                    "Payment of {$currency} " . number_format($amount, 2) . " ({$txType}) received on " . date('d M Y', strtotime($payDate)) . ".", 'success');
            }

            header("Location: ../tenant_details.php?id={$tenantId}&tab=invoices&success=payment_recorded");
        } catch (PDOException $e) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=invoices&error=" . urlencode($e->getMessage()));
        }
        exit();
    }

    // ── toggle_status: activate / deactivate tenant ──────────────────
    if ($action === 'toggle_status') {
        $currentStatus = trim($_POST['current_status'] ?? 'Active');
        $newStatus     = $currentStatus === 'Active' ? 'Inactive' : 'Active';
        $redir         = trim($_POST['redirect'] ?? "tenant_details.php?id={$tenantId}");
        try {
            $pdo->prepare("UPDATE tenants SET status = ? WHERE id = ?")->execute([$newStatus, $tenantId]);
            logAction($pdo, 'tenant_status_changed', 'Tenants', $tenantId, "Status changed to {$newStatus}");
            header("Location: ../{$redir}&success=status_changed");
        } catch (PDOException $e) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=security&error=" . urlencode($e->getMessage()));
        }
        exit();
    }

    // ── upload_document: save a tenant document file ─────────────────
    if ($action === 'upload_document') {
        $title    = trim($_POST['title']    ?? '');
        $category = in_array($_POST['category'] ?? '', ['Lease','ID','Termination','Other'])
                        ? $_POST['category'] : 'Other';

        if (!$title || !isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=documents&error=" . urlencode('Title and file are required.'));
            exit();
        }

        $ext      = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));
        $allowed  = ['pdf','jpg','jpeg','png','doc','docx'];
        if (!in_array($ext, $allowed)) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=documents&error=" . urlencode('File type not allowed.'));
            exit();
        }

        $uploadDir = __DIR__ . '/../uploads/documents/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $fileName = 'doc_' . substr($tenantId, 0, 8) . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($_FILES['document_file']['tmp_name'], $uploadDir . $fileName)) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=documents&error=" . urlencode('File upload failed.'));
            exit();
        }

        $fileUrl = 'php/uploads/documents/' . $fileName;
        $fileSize = $_FILES['document_file']['size'];

        try {
            $docId = generateUUID();
            $pdo->prepare("
                INSERT INTO documents (id, tenant_id, title, category, file_url, file_size)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$docId, $tenantId, $title, $category, $fileUrl, $fileSize]);
            logAction($pdo, 'document_uploaded', 'Tenants', $tenantId, "Doc: {$title} ({$category})");
            header("Location: ../tenant_details.php?id={$tenantId}&tab=documents&success=document_uploaded");
        } catch (PDOException $e) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=documents&error=" . urlencode($e->getMessage()));
        }
        exit();
    }

    // ── delete_document: remove document record ───────────────────────
    if ($action === 'delete_document') {
        $docId = trim($_POST['document_id'] ?? '');
        if (!$docId) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=documents");
            exit();
        }
        try {
            $row = $pdo->prepare("SELECT file_url FROM documents WHERE id = ? AND tenant_id = ?");
            $row->execute([$docId, $tenantId]);
            $doc = $row->fetch();
            if ($doc && !empty($doc['file_url'])) {
                $physical = __DIR__ . '/../../' . $doc['file_url'];
                if (file_exists($physical)) @unlink($physical);
            }
            $pdo->prepare("DELETE FROM documents WHERE id = ? AND tenant_id = ?")->execute([$docId, $tenantId]);
            logAction($pdo, 'document_deleted', 'Tenants', $tenantId, "Document {$docId} removed");
            header("Location: ../tenant_details.php?id={$tenantId}&tab=documents&success=document_deleted");
        } catch (PDOException $e) {
            header("Location: ../tenant_details.php?id={$tenantId}&tab=documents&error=" . urlencode($e->getMessage()));
        }
        exit();
    }
}
