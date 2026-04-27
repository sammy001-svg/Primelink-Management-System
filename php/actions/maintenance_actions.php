<?php
/**
 * Maintenance Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_role = $_SESSION['role'] ?? 'tenant';

    if ($action === 'update_status' && $user_role != 'tenant') {
        $id = $_POST['id'] ?? '';
        $status = $_POST['status'] ?? 'Pending';

        try {
            $stmt = $pdo->prepare("UPDATE maintenance_requests SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            header("Location: ../maintenance.php?success=updated");
            exit();
        } catch (PDOException $e) {
            die("Error updating maintenance request: " . $e->getMessage());
        }
    }

    if ($action === 'create') {
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $priority = $_POST['priority'] ?? 'Normal';
        $property_id = $_POST['property_id'] ?? null;
        
        // Find tenant_id for the current user
        $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $tenant = $stmt->fetch();
        $tenant_id = $tenant['id'] ?? null;

        $id = generateUUID();

        try {
            $stmt = $pdo->prepare("INSERT INTO maintenance_requests (id, property_id, tenant_id, title, description, priority, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->execute([$id, $property_id, $tenant_id, $title, $description, $priority]);
            header("Location: ../maintenance.php?success=created");
            exit();
        } catch (PDOException $e) {
            die("Error creating maintenance request: " . $e->getMessage());
        }
    }
}
?>
