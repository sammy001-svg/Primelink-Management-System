<?php
/**
 * Payout Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'process_payout') {
        $landlord_id = $_POST['landlord_id'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $method = $_POST['method'] ?? 'Bank';
        $fee_deducted = $amount * 0.10; // Default 10% management fee
        $net_amount = $amount - $fee_deducted;

        if ($net_amount <= 0) {
            die("Invalid payout amount.");
        }

        $id = generateUUID();
        $ref = 'PAY-' . strtoupper(substr(md5(uniqid()), 0, 8));

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO landlord_payouts (id, landlord_id, amount, fee_deducted, reference_code, status, method) VALUES (?, ?, ?, ?, ?, 'Completed', ?)");
            $stmt->execute([$id, $landlord_id, $net_amount, $fee_deducted, $ref, $method]);

            $pdo->commit();
            header("Location: ../payouts.php?success=processed&ref=" . $ref);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Error processing payout: " . $e->getMessage());
        }
    }
}
?>
