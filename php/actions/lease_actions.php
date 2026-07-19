<?php
/**
 * Lease Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notify.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $tenant_id   = $_POST['tenant_id'] ?? '';
        $property_id = $_POST['property_id'] ?? '';
        $unit_id     = $_POST['unit_id'] ?? null;
        $start_date  = $_POST['start_date'] ?? '';
        $end_date    = $_POST['end_date'] ?? '';
        $monthly_rent= $_POST['monthly_rent'] ?? 0;
        $deposit_amount = $_POST['deposit_amount'] ?? 0;
        $terms       = $_POST['terms'] ?? '';
        $id          = generateUUID();

        try {
            $stmt = $pdo->prepare("INSERT INTO leases (id, tenant_id, property_id, unit_id, start_date, end_date, monthly_rent, deposit_amount, terms, status) VALUES (?,?,?,?,?,?,?,?,?,'Active')");
            $stmt->execute([$id, $tenant_id, $property_id, $unit_id, $start_date, $end_date, $monthly_rent, $deposit_amount, $terms]);
            
            if ($unit_id) {
                $stmt = $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE id = ?");
                $stmt->execute([$unit_id]);
            }

            logAction($pdo, 'lease_created', 'Leases', $id, "Tenant {$tenant_id} — Unit {$unit_id} — from {$start_date} to {$end_date}");
            // Notify the tenant
            $tu = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $tu->execute([$tenant_id]);
            $tuid = $tu->fetchColumn();
            if ($tuid) createNotification($pdo, $tuid, 'Lease Created', "Your tenancy agreement has been created, effective {$start_date}.", 'success');

            $redirect = $_POST['redirect'] ?? '../leases.php?success=created';
            header("Location: " . $redirect);
            exit();
        } catch (PDOException $e) {
            die("Error creating lease: " . $e->getMessage());
        }
    }

    else if ($action === 'renew') {
        $lease_id = $_POST['lease_id'] ?? '';
        $new_end_date = $_POST['new_end_date'] ?? '';
        $new_rent = $_POST['new_rent'] ?? null;

        try {
            $stmt = $pdo->prepare("SELECT * FROM leases WHERE id = ?");
            $stmt->execute([$lease_id]);
            $old = $stmt->fetch();

            if ($old) {
                // Update old lease to Expired/Renewed status if needed? 
                // Usually we just mark it as Terminated/Expired and create a new one.
                $pdo->prepare("UPDATE leases SET status = 'Expired' WHERE id = ?")->execute([$lease_id]);

                $newId = generateUUID();
                $rent = $new_rent ?: $old['monthly_rent'];
                // Try inserting with parent_lease_id (self-healing if column missing)
                try {
                    $stmt = $pdo->prepare("INSERT INTO leases (id, tenant_id, property_id, unit_id, start_date, end_date, monthly_rent, deposit_amount, terms, parent_lease_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$newId, $old['tenant_id'], $old['property_id'], $old['unit_id'], $old['end_date'], $new_end_date, $rent, $old['deposit_amount'], "Renewal of lease " . $lease_id, $lease_id]);
                } catch (PDOException $e) {
                    // Fall back without parent_lease_id
                    $stmt = $pdo->prepare("INSERT INTO leases (id, tenant_id, property_id, unit_id, start_date, end_date, monthly_rent, deposit_amount, terms) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$newId, $old['tenant_id'], $old['property_id'], $old['unit_id'], $old['end_date'], $new_end_date, $rent, $old['deposit_amount'], "Renewal of lease " . $lease_id]);
                }
            }
            if ($old) {
                logAction($pdo, 'lease_renewed', 'Leases', $newId, "Renewed from lease {$lease_id} — new end: {$new_end_date}");
                $tu = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
                $tu->execute([$old['tenant_id']]);
                $tuid = $tu->fetchColumn();
                if ($tuid) createNotification($pdo, $tuid, 'Lease Renewed', "Your lease has been renewed until " . date('M d, Y', strtotime($new_end_date)) . '.', 'success');
            }
            header("Location: ../leases.php?success=renewed");
            exit();
        } catch (PDOException $e) {
            die("Error renewing lease: " . $e->getMessage());
        }
    }

    else if ($action === 'terminate') {
        $lease_id = $_POST['lease_id'] ?? '';
        $reason = $_POST['reason'] ?? '';
        $date = $_POST['termination_date'] ?? date('Y-m-d');

        try {
            $stmt = $pdo->prepare("UPDATE leases SET status = 'Terminated', termination_date = ?, termination_reason = ? WHERE id = ?");
            $stmt->execute([$date, $reason, $lease_id]);
            logAction($pdo, 'lease_terminated', 'Leases', $lease_id, "Terminated on {$date}. Reason: {$reason}");
            header("Location: ../leases.php?success=terminated&deposit_lease=" . urlencode($lease_id));
            exit();
        } catch (PDOException $e) {
            if ($e->getCode() == '42S22') {
                $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `termination_date` DATE NULL AFTER `signed_lease_url` ");
                $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `termination_reason` TEXT NULL AFTER `termination_date` ");
                $stmt = $pdo->prepare("UPDATE leases SET status = 'Terminated', termination_date = ?, termination_reason = ? WHERE id = ?");
                $stmt->execute([$date, $reason, $lease_id]);
                header("Location: ../leases.php?success=terminated&deposit_lease=" . urlencode($lease_id));
                exit();
            }
            die("Error terminating lease: " . $e->getMessage());
        }
    }

    else if ($action === 'process_deposit') {
        $leaseId        = trim($_POST['lease_id']         ?? '');
        $tenantId       = trim($_POST['tenant_id']        ?? '');
        $totalDeposit   = (float)($_POST['total_deposit'] ?? 0);
        $dedArrears     = (float)($_POST['deduct_arrears']     ?? 0);
        $dedMaint       = (float)($_POST['deduct_maintenance'] ?? 0);
        $dedCleaning    = (float)($_POST['deduct_cleaning']    ?? 0);
        $dedDamages     = (float)($_POST['deduct_damages']     ?? 0);
        $dedOther       = (float)($_POST['deduct_other']       ?? 0);
        $dedNotes       = trim($_POST['deduct_notes']     ?? '');
        $netRefund      = (float)($_POST['net_refund']    ?? 0);
        $scheduledDate  = trim($_POST['scheduled_date']   ?? '');
        $refundMethod   = trim($_POST['refund_method']    ?? 'Bank Transfer');
        $refundRef      = trim($_POST['refund_reference'] ?? '');
        $notes          = trim($_POST['notes']            ?? '');
        $totalDeductions= $dedArrears + $dedMaint + $dedCleaning + $dedDamages + $dedOther;

        // Self-heal deposit table
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS lease_deposits (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            lease_id VARCHAR(36) NOT NULL,
            tenant_id VARCHAR(36) NOT NULL,
            total_deposit DECIMAL(15,2) NOT NULL DEFAULT 0,
            deduct_arrears DECIMAL(15,2) NOT NULL DEFAULT 0,
            deduct_maintenance DECIMAL(15,2) NOT NULL DEFAULT 0,
            deduct_cleaning DECIMAL(15,2) NOT NULL DEFAULT 0,
            deduct_damages DECIMAL(15,2) NOT NULL DEFAULT 0,
            deduct_other DECIMAL(15,2) NOT NULL DEFAULT 0,
            deduct_notes TEXT NULL,
            total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
            net_refund DECIMAL(15,2) NOT NULL DEFAULT 0,
            scheduled_date DATE NULL,
            refund_method VARCHAR(50) NULL DEFAULT 'Bank Transfer',
            refund_reference VARCHAR(100) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Scheduled',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (PDOException $e) {}

        try {
            // Upsert: replace if already exists for this lease
            $existing = $pdo->prepare("SELECT id FROM lease_deposits WHERE lease_id = ?");
            $existing->execute([$leaseId]);
            $existingId = $existing->fetchColumn();

            if ($existingId) {
                $pdo->prepare("UPDATE lease_deposits SET
                    total_deposit=?, deduct_arrears=?, deduct_maintenance=?, deduct_cleaning=?,
                    deduct_damages=?, deduct_other=?, deduct_notes=?, total_deductions=?,
                    net_refund=?, scheduled_date=?, refund_method=?, refund_reference=?, notes=?
                    WHERE id=?"
                )->execute([$totalDeposit, $dedArrears, $dedMaint, $dedCleaning, $dedDamages,
                    $dedOther, $dedNotes ?: null, $totalDeductions, $netRefund,
                    $scheduledDate ?: null, $refundMethod, $refundRef ?: null, $notes ?: null, $existingId]);
            } else {
                require_once __DIR__ . '/../includes/auth.php';
                $pdo->prepare("INSERT INTO lease_deposits
                    (id, lease_id, tenant_id, total_deposit, deduct_arrears, deduct_maintenance,
                     deduct_cleaning, deduct_damages, deduct_other, deduct_notes, total_deductions,
                     net_refund, scheduled_date, refund_method, refund_reference, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([generateUUID(), $leaseId, $tenantId, $totalDeposit, $dedArrears, $dedMaint,
                    $dedCleaning, $dedDamages, $dedOther, $dedNotes ?: null, $totalDeductions, $netRefund,
                    $scheduledDate ?: null, $refundMethod, $refundRef ?: null, $notes ?: null]);
            }

            logAction($pdo, 'deposit_processed', 'Leases', $leaseId,
                "Deposit refund scheduled: net KSh " . number_format($netRefund, 2) . " on {$scheduledDate}");
            header("Location: ../leases.php?success=deposit_saved");
            exit();
        } catch (PDOException $e) {
            die("Error saving deposit refund: " . $e->getMessage());
        }
    }

    else if ($action === 'mark_deposit_paid') {
        $leaseId = trim($_POST['lease_id'] ?? '');
        try {
            $pdo->prepare("UPDATE lease_deposits SET status='Paid' WHERE lease_id=?")->execute([$leaseId]);
            logAction($pdo, 'deposit_paid', 'Leases', $leaseId, 'Deposit refund marked as paid');
            header("Location: ../leases.php?success=deposit_paid");
            exit();
        } catch (PDOException $e) {
            header("Location: ../leases.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    }

    else if ($action === 'mark_renewal_status') {
        $lease_id       = $_POST['lease_id'] ?? '';
        $renewal_status = $_POST['renewal_status'] ?? '';
        $allowed        = ['Offered', 'Accepted', 'Declined'];
        $redirect       = $_POST['redirect'] ?? '../leases.php';

        if (!in_array($renewal_status, $allowed) || !$lease_id) {
            header("Location: ../leases.php?error=invalid");
            exit();
        }

        try {
            $pdo->prepare("UPDATE leases SET renewal_status = ? WHERE id = ?")
                ->execute([$renewal_status, $lease_id]);

            logAction($pdo, 'lease_renewal_' . strtolower($renewal_status), 'Leases', $lease_id,
                "Renewal status set to {$renewal_status}");

            // Notify tenant
            $row = $pdo->prepare("
                SELECT t.user_id, t.full_name, p.title, u.unit_number
                FROM leases l
                JOIN tenants t ON l.tenant_id = t.id
                JOIN units u ON l.unit_id = u.id
                JOIN properties p ON u.property_id = p.id
                WHERE l.id = ?
            ");
            $row->execute([$lease_id]);
            $info = $row->fetch();

            if ($info) {
                $msgs = [
                    'Offered'  => "Your lease renewal offer for {$info['title']} Unit {$info['unit_number']} has been submitted. Please respond at your earliest convenience.",
                    'Accepted' => "Great news! Your lease renewal for {$info['title']} Unit {$info['unit_number']} has been accepted. We will process the new agreement shortly.",
                    'Declined' => "Your lease renewal for {$info['title']} Unit {$info['unit_number']} has been marked as declined. Please contact us if you have questions.",
                ];
                $notifType = $renewal_status === 'Accepted' ? 'success' : ($renewal_status === 'Declined' ? 'warning' : 'info');
                createNotification($pdo, $info['user_id'],
                    "Lease Renewal: {$renewal_status}",
                    $msgs[$renewal_status],
                    $notifType
                );
            }

            $slug = strtolower($renewal_status);
            $sep  = str_contains($redirect, '?') ? '&' : '?';
            header("Location: {$redirect}{$sep}success={$slug}");
            exit();
        } catch (PDOException $e) {
            die("Error updating renewal status: " . $e->getMessage());
        }
    }

    else if ($action === 'upload_signed') {
        $lease_id = $_POST['lease_id'] ?? '';
        
        if (isset($_FILES['signed_lease']) && $_FILES['signed_lease']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/signed_leases/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $ext = pathinfo($_FILES['signed_lease']['name'], PATHINFO_EXTENSION);
            $fileName = "lease_signed_" . substr($lease_id, 0, 8) . "_" . time() . "." . $ext;
            
            if (move_uploaded_file($_FILES['signed_lease']['tmp_name'], $uploadDir . $fileName)) {
                $fileUrl = 'php/uploads/signed_leases/' . $fileName;
                try {
                    $stmt = $pdo->prepare("UPDATE leases SET signed_lease_url = ? WHERE id = ?");
                    $stmt->execute([$fileUrl, $lease_id]);
                    header("Location: ../leases.php?success=uploaded");
                    exit();
                } catch (PDOException $e) {
                    if ($e->getCode() == '42S22' && strpos($e->getMessage(), 'signed_lease_url') !== false) {
                        $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `signed_lease_url` VARCHAR(255) NULL AFTER `status` ");
                        // Retry
                        $stmt = $pdo->prepare("UPDATE leases SET signed_lease_url = ? WHERE id = ?");
                        $stmt->execute([$fileUrl, $lease_id]);
                        header("Location: ../leases.php?success=uploaded");
                        exit();
                    }
                    throw $e;
                }
            }
        }
        header("Location: ../leases.php?error=upload_failed");
        exit();
    }
}
?>
