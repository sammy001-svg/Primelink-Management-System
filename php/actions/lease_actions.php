<?php
/**
 * Lease Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $tenant_id   = $_POST['tenant_id'] ?? '';
        $property_id = $_POST['property_id'] ?? '';
        $start_date  = $_POST['start_date'] ?? '';
        $end_date    = $_POST['end_date'] ?? '';
        $monthly_rent= $_POST['monthly_rent'] ?? 0;
        $deposit_amount = $_POST['deposit'] ?? 0;
        $terms       = $_POST['terms'] ?? '';
        $id          = generateUUID();

        try {
            $stmt = $pdo->prepare("INSERT INTO leases (id, tenant_id, property_id, start_date, end_date, monthly_rent, deposit_amount, terms) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$id, $tenant_id, $property_id, $start_date, $end_date, $monthly_rent, $deposit_amount, $terms]);
            header("Location: ../leases.php?success=created");
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
                $stmt = $pdo->prepare("INSERT INTO leases (id, tenant_id, property_id, unit_id, start_date, end_date, monthly_rent, deposit_amount, terms) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$newId, $old['tenant_id'], $old['property_id'], $old['unit_id'], $old['end_date'], $new_end_date, $rent, $old['deposit_amount'], "Renewal of lease " . $lease_id]);
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
            header("Location: ../leases.php?success=terminated");
            exit();
        } catch (PDOException $e) {
            if ($e->getCode() == '42S22') {
                $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `termination_date` DATE NULL AFTER `signed_lease_url` ");
                $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `termination_reason` TEXT NULL AFTER `termination_date` ");
                // Retry
                $stmt = $pdo->prepare("UPDATE leases SET status = 'Terminated', termination_date = ?, termination_reason = ? WHERE id = ?");
                $stmt->execute([$date, $reason, $lease_id]);
                header("Location: ../leases.php?success=terminated");
                exit();
            }
            die("Error terminating lease: " . $e->getMessage());
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
