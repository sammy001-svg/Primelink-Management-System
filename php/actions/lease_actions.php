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
        $deposit     = $_POST['deposit'] ?? 0;
        $terms       = $_POST['terms'] ?? '';
        $id          = generateUUID();

        try {
            $stmt = $pdo->prepare("INSERT INTO leases (id, tenant_id, property_id, start_date, end_date, monthly_rent, deposit, terms) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$id, $tenant_id, $property_id, $start_date, $end_date, $monthly_rent, $deposit, $terms]);
            header("Location: ../leases.php?success=created");
            exit();
        } catch (PDOException $e) {
            die("Error creating lease: " . $e->getMessage());
        }
    }
}
?>
