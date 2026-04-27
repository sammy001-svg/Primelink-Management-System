<?php
/**
 * Landlord Financial Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin(['admin', 'staff']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_advance') {
        $landlord_id = $_POST['landlord_id'] ?? '';
        $amount = $_POST['amount'] ?? 0;
        $purpose = $_POST['purpose'] ?? '';

        try {
            $stmt = $pdo->prepare("INSERT INTO landlord_advances (id, landlord_id, amount, purpose, status) VALUES (?, ?, ?, ?, 'Pending')");
            $stmt->execute([
                generateUUID(),
                $landlord_id,
                $amount,
                $purpose
            ]);

            header("Location: ../landlord_payouts.php?success=advance_requested");
            exit();
        } catch (PDOException $e) {
            die("Error creating advance: " . $e->getMessage());
        }
    }

    if ($action === 'approve_advance') {
        $id = $_POST['id'] ?? '';
        try {
            $stmt = $pdo->prepare("UPDATE landlord_advances SET status = 'Approved', approved_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            header("Location: ../landlord_payouts.php?success=advance_approved");
            exit();
        } catch (PDOException $e) {
            die("Error approving advance: " . $e->getMessage());
        }
    }

    if ($action === 'create_loan') {
        $landlord_id = $_POST['landlord_id'] ?? '';
        $principal_amount = $_POST['principal_amount'] ?? 0;
        $interest_rate = $_POST['interest_rate'] ?? 0;
        
        $total_repayable = $principal_amount * (1 + ($interest_rate / 100));

        try {
            $stmt = $pdo->prepare("INSERT INTO landlord_loans (id, landlord_id, principal_amount, interest_rate, total_repayable, status) VALUES (?, ?, ?, ?, ?, 'Active')");
            $stmt->execute([
                generateUUID(),
                $landlord_id,
                $principal_amount,
                $interest_rate,
                $total_repayable
            ]);

            header("Location: ../landlord_payouts.php?success=loan_issued");
            exit();
        } catch (PDOException $e) {
            die("Error issuing loan: " . $e->getMessage());
        }
    }
} else {
    header("Location: ../landlord_payouts.php");
    exit();
}
?>
