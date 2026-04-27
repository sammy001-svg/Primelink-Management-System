<?php
/**
 * Financial Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $tenant_id = $_POST['tenant_id'] ?? null;
        $amount = $_POST['amount'] ?? 0;
        $transaction_type = $_POST['transaction_type'] ?? 'Rent';
        $payment_method = $_POST['payment_method'] ?? 'M-Pesa';
        $status = $_POST['status'] ?? 'Paid';
        $description = $_POST['description'] ?? '';

        $id = generateUUID();

        try {
            $stmt = $pdo->prepare("INSERT INTO transactions (id, tenant_id, amount, transaction_type, payment_method, status, description, transaction_date) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$id, $tenant_id, $amount, $transaction_type, $payment_method, $status, $description]);
            header("Location: ../financials.php?success=created");
            exit();
        } catch (PDOException $e) {
            die("Error recording transaction: " . $e->getMessage());
        }
    }
}
?>
