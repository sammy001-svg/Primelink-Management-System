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
        $fullName = $_POST['full_name'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address = $_POST['address'] ?? '';
        $profession = $_POST['profession'] ?? '';
        $employerName = $_POST['employer_name'] ?? '';
        $maritalStatus = $_POST['marital_status'] ?? 'Single';

        try {
            $pdo->beginTransaction();

            // 1. Update Profile (linked by user_id)
            $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $userId = $stmt->fetchColumn();

            if ($userId) {
                $stmt = $pdo->prepare("UPDATE profiles SET full_name = ?, phone = ?, address = ? WHERE id = ?");
                $stmt->execute([$fullName, $phone, $address, $userId]);
            }

            // 2. Update Tenant record
            $stmt = $pdo->prepare("
                UPDATE tenants 
                SET full_name = ?, phone = ?, current_address = ?, profession = ?, employer_name = ?, marital_status = ?
                WHERE id = ?
            ");
            $stmt->execute([$fullName, $phone, $address, $profession, $employerName, $maritalStatus, $tenantId]);

            $pdo->commit();
            header("Location: ../tenant_details.php?id=$tenantId&success=profile_updated");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Error updating profile: " . $e->getMessage());
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

            // Notify tenant
            $uRow = $pdo->prepare("SELECT user_id, full_name FROM tenants WHERE id = ?");
            $uRow->execute([$tenantId]);
            $tRow = $uRow->fetch();
            if ($tRow && $tRow['user_id']) {
                createNotification(
                    $pdo, $tRow['user_id'],
                    'New Invoice Generated',
                    "A {$invoiceType} invoice of {$currency} " . number_format($amount, 2) . " has been issued. Due: " . date('d M Y', strtotime($dueDate)) . ".",
                    'warning'
                );
            }

            logAction($pdo, 'invoice_generated', 'Invoices', $invId,
                "{$invoiceType}: {$currency} " . number_format($amount) . " | Due: {$dueDate}" . ($description ? " | {$description}" : ''));

            header("Location: ../view_invoice.php?id={$invId}&back_tenant={$tenantId}");
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
            }

            if ($created === 0) {
                header("Location: ../tenant_details.php?id=$tenantId&tab=invoices&error=" . urlencode('All amounts were zero — no invoices created.'));
                exit();
            }

            // Notify tenant
            $uRow = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $uRow->execute([$tenantId]);
            $uid = $uRow->fetchColumn();
            if ($uid) {
                createNotification(
                    $pdo, $uid,
                    'Combined Invoice Generated',
                    "{$created} charge(s) totalling {$currency} " . number_format($totalAmt, 2) . " have been invoiced. Due: " . date('d M Y', strtotime($dueDate)) . ".",
                    'warning'
                );
            }

            logAction($pdo, 'bundle_invoice_generated', 'Invoices', $batchId,
                "{$created} items — {$currency} " . number_format($totalAmt) . " | Batch: {$batchId}");

            header("Location: ../view_combined_invoice.php?batch_id={$batchId}");
        } catch (PDOException $e) {
            header("Location: ../tenant_details.php?id=$tenantId&tab=invoices&error=" . urlencode($e->getMessage()));
        }
        exit();
    }
}
