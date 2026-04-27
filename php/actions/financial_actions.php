<?php
/**
 * Financial Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin(['admin', 'staff']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $tenant_id = $_POST['tenant_id'] ?? '';
        $amount = $_POST['amount'] ?? 0;
        $transaction_type = $_POST['transaction_type'] ?? 'Rent';
        $payment_method = $_POST['payment_method'] ?? 'Cash';
        $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
        $description = $_POST['description'] ?? '';

        // Get lease_id for the tenant
        $stmt = $pdo->prepare("SELECT id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$tenant_id]);
        $lease = $stmt->fetch();
        $lease_id = $lease['id'] ?? null;

        if (!$lease_id) {
            header("Location: ../tenant_payments.php?error=no_active_lease");
            exit();
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO transactions (id, tenant_id, lease_id, amount, transaction_type, status, payment_method, description, transaction_date) VALUES (?, ?, ?, ?, ?, 'Paid', ?, ?, ?)");
            $stmt->execute([
                generateUUID(),
                $tenant_id,
                $lease_id,
                $amount,
                $transaction_type,
                $payment_method,
                $description,
                $transaction_date
            ]);

            header("Location: ../tenant_payments.php?success=payment_recorded");
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

            header("Location: ../tenant_payments.php?success=invoice_generated");
            exit();
        } catch (PDOException $e) {
            die("Error generating invoice: " . $e->getMessage());
        }
    }
} else {
    header("Location: ../tenant_payments.php");
    exit();
}
?>
