<?php
/**
 * Financial Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin(['admin', 'staff', 'tenant']);

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notify.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $tenant_id = $_POST['tenant_id'] ?? '';
        $amount = $_POST['amount'] ?? 0;
        $transaction_type = $_POST['transaction_type'] ?? 'Rent';
        $payment_method = $_POST['payment_method'] ?? 'Cash';
        $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
        $description = $_POST['description'] ?? '';
        $invoice_id = $_POST['invoice_id'] ?? null;

        $role = $_SESSION['role'];
        $status = ($role === 'tenant') ? 'Pending' : 'Paid';

        // Security check for tenants
        if ($role === 'tenant') {
            $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $realTenant = $stmt->fetch();
            if (!$realTenant || $realTenant['id'] !== $tenant_id) {
                header("Location: ../financials.php?error=unauthorized");
                exit();
            }
        }

        // Get lease_id for the tenant
        $stmt = $pdo->prepare("SELECT id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$tenant_id]);
        $lease = $stmt->fetch();
        $lease_id = $lease['id'] ?? null;

        if (!$lease_id) {
            header("Location: ../financials.php?error=no_active_lease");
            exit();
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO transactions (id, tenant_id, lease_id, invoice_id, amount, transaction_type, status, payment_method, description, transaction_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                generateUUID(),
                $tenant_id,
                $lease_id,
                $invoice_id,
                $amount,
                $transaction_type,
                $status,
                $payment_method,
                $description,
                $transaction_date
            ]);

            $txId = $pdo->lastInsertId() ?: generateUUID();
            $amtFmt = 'KSh ' . number_format((float)$amount);
            logAction($pdo, 'payment_recorded', 'Financials', '', "{$transaction_type} — {$amtFmt} via {$payment_method}");

            // Notify staff when tenant submits; notify tenant when staff records
            if ($role === 'tenant') {
                notifyAllStaff($pdo, 'Payment Submitted', "A tenant submitted a {$transaction_type} payment of {$amtFmt} — awaiting confirmation.", 'info');
            } else {
                $tenantUser = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
                $tenantUser->execute([$tenant_id]);
                $tuid = $tenantUser->fetchColumn();
                if ($tuid) createNotification($pdo, $tuid, 'Payment Recorded', "Your {$transaction_type} payment of {$amtFmt} has been recorded.", 'success');
            }

            $redirect = ($role === 'tenant') ? '../financials.php?success=payment_submitted' : '../tenant_payments.php?success=payment_recorded';
            header("Location: $redirect");
            exit();
        } catch (PDOException $e) {
            die("Error recording payment: " . $e->getMessage());
        }
    }

    if ($action === 'generate_invoice') {
        $tenant_id = $_POST['tenant_id'] ?? '';
        $amount = $_POST['amount'] ?? 0;
        $invoice_type = $_POST['invoice_type'] ?? 'Rent';
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days'));

        // Get lease_id for the tenant
        $stmt = $pdo->prepare("SELECT id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$tenant_id]);
        $lease = $stmt->fetch();
        if (!$lease) {
            header("Location: ../tenant_payments.php?error=no_active_lease");
            exit();
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO invoices (id, tenant_id, lease_id, amount, due_date, status, invoice_type) VALUES (?, ?, ?, ?, ?, 'Unpaid', ?)");
            $stmt->execute([
                generateUUID(),
                $tenant_id,
                $lease['id'],
                $amount,
                $due_date,
                $invoice_type
            ]);

            $invId = generateUUID();
            $amtFmt = 'KSh ' . number_format((float)$amount);
            logAction($pdo, 'invoice_generated', 'Financials', $invId, "{$invoice_type} invoice — {$amtFmt} due {$due_date}");
            $tenantUser = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $tenantUser->execute([$tenant_id]);
            $tuid = $tenantUser->fetchColumn();
            if ($tuid) createNotification($pdo, $tuid, 'New Invoice', "A {$invoice_type} invoice for {$amtFmt} has been issued, due on " . date('M d, Y', strtotime($due_date)) . '.', 'warning');

            header("Location: ../tenant_payments.php?success=invoice_generated");
            exit();
        } catch (PDOException $e) {
            // Self-healing: If invoices table is missing or columns are missing
            if ($e->getCode() == '42S02') { // Table not found
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `invoices` (
                        `id` VARCHAR(36) PRIMARY KEY,
                        `tenant_id` VARCHAR(36),
                        `lease_id` VARCHAR(36),
                        `amount` DECIMAL(15, 2) NOT NULL,
                        `due_date` DATE,
                        `status` ENUM('Paid', 'Unpaid', 'Overdue', 'Cancelled') DEFAULT 'Unpaid',
                        `invoice_type` VARCHAR(50) DEFAULT 'Rent',
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    
                    // Retry
                    $stmt = $pdo->prepare("INSERT INTO invoices (id, tenant_id, lease_id, amount, due_date, status, invoice_type) VALUES (?, ?, ?, ?, ?, 'Unpaid', ?)");
                    $stmt->execute([generateUUID(), $tenant_id, $lease['id'], $amount, $due_date, $invoice_type]);
                    header("Location: ../tenant_payments.php?success=invoice_generated");
                    exit();
                } catch (PDOException $subE) {
                    die("Invoice System Repair Failed: " . $subE->getMessage());
                }
            }
            die("Error generating invoice: " . $e->getMessage());
        }
    }
} else {
    header("Location: ../tenant_payments.php");
    exit();
}
?>
