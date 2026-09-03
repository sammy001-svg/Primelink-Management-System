<?php
/**
 * Financial Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin(['admin', 'staff', 'tenant']);

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/corrections.php';
require_once __DIR__ . '/../includes/bank_accounts.php';
require_once __DIR__ . '/../includes/payment_alloc.php';

ensureTransactionColumns($pdo);
ensureBankAccountSchema($pdo);
ensurePaymentAllocSchema($pdo);
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
        $bank_account_id = trim($_POST['bank_account_id'] ?? '') ?: null;

        // A payment may be split across several charges (rent, water, garbage).
        // When it is, each charge is posted separately — see includes/payment_alloc.php.
        $alloc = parseAllocationInput($_POST);
        if ($alloc['error']) {
            header('Location: ../tenant_payments.php?error=' . urlencode($alloc['error']));
            exit();
        }
        if ($alloc['lines']) {
            $amount = $alloc['total'];
        }

        $role = $_SESSION['role'];
        $status = ($role === 'tenant') ? 'Pending' : 'Paid';

        if ((float)$amount <= 0) {
            header('Location: ../tenant_payments.php?error=' . urlencode('Enter what the tenant is paying for.'));
            exit();
        }

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
            // A tenant submitting a payment cannot say which company account it
            // landed in — staff assign that when they confirm it.
            if ($role === 'tenant') $bank_account_id = null;

            $insert = $pdo->prepare(
                "INSERT INTO transactions
                    (id, tenant_id, lease_id, invoice_id, amount, transaction_type, status,
                     payment_method, description, bank_account_id, payment_group, transaction_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            // Lines that name an invoice are grouped so one receipt covers them all
            $groupId = count($alloc['lines']) > 1 ? generateUUID() : null;
            $txId    = null;

            if ($alloc['lines']) {
                foreach ($alloc['lines'] as $line) {
                    $lineId = generateUUID();
                    $txId   = $txId ?: $lineId;
                    $insert->execute([
                        $lineId, $tenant_id, $lease_id, $line['invoice_id'],
                        $line['amount'], $line['type'], $status,
                        $payment_method, $description, $bank_account_id,
                        $groupId, $transaction_date,
                    ]);
                    if ($status === 'Paid') resettleInvoice($pdo, $line['invoice_id']);
                }
            } else {
                $txId = generateUUID();
                $insert->execute([
                    $txId, $tenant_id, $lease_id, $invoice_id,
                    $amount, $transaction_type, $status,
                    $payment_method, $description, $bank_account_id,
                    null, $transaction_date,
                ]);
                if ($status === 'Paid') resettleInvoice($pdo, $invoice_id);
            }

            $currencySym = getSetting($pdo, 'currency_symbol', 'KSh');
            $amtFmt = $currencySym . ' ' . number_format((float)$amount, 2);
            $breakdown = summariseAllocation($alloc['lines'], $currencySym);

            logAction($pdo, 'payment_recorded', 'Financials', (string)$txId,
                $amtFmt . ' via ' . $payment_method
                . ($breakdown ? ' — ' . $breakdown : ' — ' . $transaction_type)
                . ($bank_account_id ? ' | Into: ' . (getBankAccount($pdo, $bank_account_id)['name'] ?? '') : ''));

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
    if ($action === 'send_reminder') {
        requireLogin(['admin', 'staff']);
        require_once __DIR__ . '/../includes/mailer.php';

        $tenantId = $_POST['tenant_id'] ?? '';
        if (!$tenantId) {
            header("Location: ../command_center.php");
            exit();
        }

        // Fetch tenant + user info
        $tStmt = $pdo->prepare("
            SELECT t.full_name, t.email, u.id as user_id
            FROM tenants t JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
        ");
        $tStmt->execute([$tenantId]);
        $tenant = $tStmt->fetch();

        if ($tenant) {
            // Sum overdue
            $ovStmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM invoices WHERE tenant_id = ? AND status = 'Overdue'");
            $ovStmt->execute([$tenantId]);
            $ov = $ovStmt->fetch();
            $cnt   = (int)$ov['cnt'];
            $total = number_format((float)$ov['total']);
            $name  = $tenant['full_name'];

            // In-app notification
            createNotification(
                $pdo, $tenant['user_id'],
                'Overdue Payment Reminder',
                "You have {$cnt} overdue invoice(s) totalling KSh {$total}. Please settle your balance to avoid penalties.",
                'warning'
            );

            // Email reminder
            $htmlBody = "
                <p>Dear {$name},</p>
                <p>This is a reminder that you have <strong>{$cnt} overdue invoice(s)</strong> with a total balance of <strong>KSh {$total}</strong>.</p>
                <p>Please log in to your tenant portal to view and settle your invoices at your earliest convenience.</p>
                <p>If you believe this is an error, please contact our office immediately.</p>
                <p>Thank you for your prompt attention.</p>
            ";
            sendSystemEmail($pdo, $tenant['email'], 'Overdue Payment Reminder — Action Required', $htmlBody, $name);

            logAction($pdo, 'reminder_sent', 'Financials', $tenantId, "Overdue reminder sent to {$name} ({$cnt} invoice(s), KSh {$total})");
        }

        header("Location: ../command_center.php?success=reminded");
        exit();
    }
} else {
    header("Location: ../tenant_payments.php");
    exit();
}
?>
