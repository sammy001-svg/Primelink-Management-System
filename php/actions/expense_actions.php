<?php
/**
 * Expense Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin(['admin', 'staff']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = $_POST['description'] ?? '';
    $amount = $_POST['amount'] ?? 0;
    $category = $_POST['category'] ?? 'Other';
    $property_id = !empty($_POST['property_id']) ? $_POST['property_id'] : null;
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');

    try {
        $stmt = $pdo->prepare("INSERT INTO expenses (id, description, amount, category, property_id, expense_date) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            generateUUID(),
            $description,
            $amount,
            $category,
            $property_id,
            $expense_date
        ]);

        header("Location: ../expenses.php?success=expense_recorded");
        exit();
    } catch (PDOException $e) {
        die("Error recording expense: " . $e->getMessage());
    }
} else {
    header("Location: ../expenses.php");
    exit();
}
?>
